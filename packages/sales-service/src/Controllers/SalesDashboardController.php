<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Controllers;

use App\Helpers\NavbarFactory;
use App\Models\User;
use NM\Services\SalesService\Sales\Domain\SalesOrderState;
use NM\Services\SalesService\Sales\SalesCartConflictException;
use NM\Services\SalesService\Sales\SalesInventoryFinalizationException;
use NM\Services\SalesService\Sales\SalesApplicationRegistry;
use NM\Services\SalesService\Sales\SalesContext;
use NM\Services\SalesService\Sales\SalesOrderRepository;
use NM\Services\SalesService\Sales\SalesOrderService;
use NM\Services\SalesService\Sales\SalesStatisticsService;
use NM\Services\SalesService\Sales\SalesServiceState;
use NM\Services\SalesService\Sales\Adapters\LocalCatalogAdapter;
use Core\Database\Connection;
use Core\Exceptions\Http\GateException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Http\UploadedFile;
use Core\Database\Stats\Admin\StatsResource;
use Core\Database\Stats\Export\CsvExporter;
use Core\Database\Stats\Export\PdfExporter;
use Core\Support\PDF\Output\HttpOutput;
use Core\Security\CsrfTokenManager;
use Core\View\ViewFactory;
use InvalidArgumentException;
use RuntimeException;

final class SalesDashboardController extends Controller
{
    private const PAGE_SIZE = 10;
    private const PUBLIC_TENANT_SCOPE_NOTICE = 'Cette vue affiche les commandes sans tenant associé. Les commandes tenant nécessitent un contexte de tenant utilisateur avant de pouvoir être exposées.';
    private const STOREFRONT_MODULES = ['cart', 'catalog', 'availability', 'pricing', 'orders', 'checkout'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ViewFactory $factory,
        private readonly SessionInterface $session,
        private readonly SalesOrderRepository $orderRepository,
        private readonly SalesOrderService $orders,
        private readonly SalesApplicationRegistry $applications,
        private readonly SalesStatisticsService $statistics,
        private readonly SalesServiceState $serviceState,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->requireSalesUser('sales.orders.view', 'sales.orders.manage');
        $this->assertSalesEnabled();

        ['status' => $status, 'filters' => $filters, 'page' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => $perPage, 'orders' => $orders] = $this->ordersPage($request);
        $isSuper = $user->isSuper();
        $storefrontSetupRequired = $isSuper && $this->storefrontSetupRequired($user);
        $viewData = [
            'title' => 'Ventes',
            'csrf_token' => (new CsrfTokenManager($this->session, self::TOKEN_NAME))->generateToken(),
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user, 'sales-orders'),
            'admin_permissions' => $user->effectivePermissions('string'),
            'errors' => [],
            'content_name' => 'sales.dashboard',
            'activeNavTab' => 'sales-orders',
            'showHome' => true,
            'breadcrumbs' => [['label' => 'Ventes'], ['label' => 'Commandes']],
            'sales_orders' => $orders,
            'sales_status' => $status,
            'sales_page' => $page,
            'sales_pages' => $pages,
            'sales_total' => $total,
            'sales_per_page' => $perPage,
            'sales_can_manage' => $isSuper || $user->hasPermission('sales.orders.manage'),
            'sales_can_setup' => $isSuper,
            'sales_setup_required' => $storefrontSetupRequired,
            'sales_success' => $request->query->getString('success'),
            'sales_error' => $request->query->getString('error'),
            'sales_scope_notice' => self::PUBLIC_TENANT_SCOPE_NOTICE,
            'sales_filters' => $filters,
            'sales_statistics' => $this->orderRepository->dashboardStatistics($status, $filters),
            'sales_applications' => $this->orderRepository->activeDashboardApplications(),
            'sales_products' => $this->dashboardCatalogProducts(),
            'sales_mail_enabled' => (new \App\Services\Mail($this->factory))->isEnabled(),
            'sales_catalog_statistics_enabled' => $this->catalogStatisticsEnabled(),
        ];
        $viewData['sales_component_data'] = $viewData;
        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['html' => $this->factory->render('sales.dashboard', $viewData)]);
        }

        return new Response($this->factory->renderWithLayout('app', 'dashboard', $viewData));
    }

    public function statistics(Request $request): Response
    {
        $user = $this->requireSalesUser('sales.orders.view', 'sales.orders.manage');
        $this->assertSalesEnabled();
        $this->assertCatalogStatisticsEnabled();
        $productId = $request->query->getInt('product_id');
        $from = $request->query->getString('from');
        $to = $request->query->getString('to');
        try {
            $report = $productId > 0
                ? $this->statistics->productReport($productId, $from ?: null, $to ?: null)
                : $this->statistics->catalogReport($from ?: null, $to ?: null);
        } catch (InvalidArgumentException $exception) {
            return new Response($exception->getMessage(), 422);
        }
        if ($productId > 0 && $report === null) {
            throw new NotFoundHttpException();
        }

        return new Response($this->factory->renderWithLayout('app', 'dashboard', [
            'title' => $productId > 0 ? 'Statistiques du produit' : 'Statistiques des produits',
            'csrf_token' => (new CsrfTokenManager($this->session, self::TOKEN_NAME))->generateToken(),
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user, 'sales-statistics'),
            'admin_permissions' => $user->effectivePermissions('string'),
            'errors' => [],
            'content_name' => 'sales.statistics',
            'activeNavTab' => 'sales-statistics',
            'showHome' => true,
            'breadcrumbs' => [
                ['label' => 'Ventes', 'url' => '/dashboard/sales'],
                ['label' => 'Statistiques'],
            ],
            'statistics_report' => $report,
            'statistics_product' => $productId > 0,
            'statistics_query' => ['product_id' => $productId ?: '', 'from' => $from, 'to' => $to],
        ]));
    }

    public function statisticsExport(Request $request): Response
    {
        $this->requireSalesUser('sales.orders.view', 'sales.orders.manage');
        $this->assertSalesEnabled();
        $this->assertCatalogStatisticsEnabled();
        $productId = $request->query->getInt('product_id');
        $from = $request->query->getString('from');
        $to = $request->query->getString('to');
        try {
            $report = $productId > 0
                ? $this->statistics->productReport($productId, $from ?: null, $to ?: null)
                : $this->statistics->catalogReport($from ?: null, $to ?: null);
        } catch (InvalidArgumentException $exception) {
            return new Response($exception->getMessage(), 422);
        }
        if ($productId > 0 && $report === null) {
            throw new NotFoundHttpException();
        }
        $format = strtolower($request->query->getString('format', 'csv'));
        if (!in_array($format, ['csv', 'pdf'], true)) {
            throw new InvalidArgumentException('Le format d’export demandé est invalide.');
        }
        $results = $this->statisticsExportResults($report, $productId > 0);
        $resource = StatsResource::make('sales_statistics', \NM\Services\InventoryService\Models\CatalogProduct::class)
            ->label('Statistique de vente', 'Statistiques de vente');
        $stem = 'statistiques_ventes_' . ($productId > 0 ? 'produit_' . $productId : 'catalogue');

        if ($format === 'pdf') {
            $generatedAt = new \DateTimeImmutable('now', new \DateTimeZone((string) env('APP_TIMEZONE', 'UTC')));
            $content = (new PdfExporter())->render(
                $resource,
                $results,
                ['from' => $report['period']['from'], 'to' => $report['period']['to']],
                app()->publicPath('assets/images/logo.png'),
                $generatedAt,
            );

            return new Response($content, 200, HttpOutput::headers($stem . '.pdf', strlen($content)));
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Impossible d’ouvrir le flux CSV.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        $exporter = new CsvExporter();
        foreach ($results as $result) {
            fputcsv($stream, ['Indicateur', (string) ($result['label'] ?? '')], ';', '"', '');
            $exporter->write($result, $stream, ';', false);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($content)) {
            throw new RuntimeException('Impossible de lire le fichier CSV généré.');
        }

        return new Response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $stem . '.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, array<string, mixed>>
     */
    private function statisticsExportResults(array $report, bool $isProduct): array
    {
        $period = $report['period'];
        if ($isProduct) {
            $metrics = $report['metrics'];
            $points = array_map(static fn(array $point): array => [
                't' => $point['sale_date'],
                'v' => (int) $point['revenue_minor'] / 100,
            ], $report['sales']);

            return [
                'revenue' => ['key' => 'revenue', 'label' => 'Chiffre d’affaires', 'type' => 'kpi', 'value' => $metrics['revenue_minor'] / 100, 'period' => $period],
                'units' => ['key' => 'units', 'label' => 'Unités vendues', 'type' => 'kpi', 'value' => $metrics['units_sold'], 'period' => $period],
                'estimated_profit' => ['key' => 'estimated_profit', 'label' => 'Profit brut estimé', 'type' => 'kpi', 'value' => $metrics['estimated_profit_minor'] === null ? null : $metrics['estimated_profit_minor'] / 100, 'period' => $period],
                'sales' => ['key' => 'sales', 'label' => 'Chiffre d’affaires par jour', 'type' => 'timeseries', 'points' => $points, 'period' => $period],
                'movements' => [
                    'key' => 'movements',
                    'label' => 'Mouvements de stock',
                    'type' => 'breakdown',
                    'groups' => $this->movementGroups($report['movements']),
                    'period' => $period,
                ],
            ];
        }

        return [
            'ranking' => [
                'key' => 'ranking',
                'label' => 'Classement des ventes',
                'type' => 'breakdown',
                'groups' => array_map(static fn(array $row): array => ['label' => $row['name'], 'v' => $row['units_sold']], $report['ranking']),
                'period' => $period,
            ],
            'out_of_stock' => [
                'key' => 'out_of_stock',
                'label' => 'Produits en rupture de stock',
                'type' => 'breakdown',
                'groups' => array_map(static fn(array $row): array => ['label' => $row['name'], 'v' => 0], $report['out_of_stock']),
                'period' => $period,
            ],
        ];
    }

    /** @param list<array<string, mixed>> $movements
     *  @return list<array{label: string, v: int}>
     */
    private function movementGroups(array $movements): array
    {
        $groups = [];
        foreach ($movements as $movement) {
            $type = (string) $movement['movement_type'];
            $groups[$type] = ($groups[$type] ?? 0) + 1;
        }
        $result = [];
        foreach ($groups as $type => $count) {
            $result[] = ['label' => $type, 'v' => $count];
        }

        return $result;
    }

    public function search(Request $request): Response
    {
        $this->requireSalesUser('sales.orders.view', 'sales.orders.manage');
        $this->assertSalesEnabled();
        try {
            $result = $this->ordersPage($request);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'error' => $exception->getMessage(), 'message' => $exception->getMessage()], 422);
        }

        return $this->jsonResponse([
            'success' => true,
            'data' => [
                'items' => $result['orders'],
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total'],
                'per_page' => $result['per_page'],
                'meta' => [
                    'total' => $result['total'],
                    'per_page' => $result['per_page'],
                    'current_page' => $result['page'],
                    'last_page' => $result['pages'],
                ],
                'statistics' => $this->orderRepository->dashboardStatistics($result['status'], $result['filters']),
            ],
        ]);
    }

    /**
     * @return array{status: string, filters: array<string, string>, page: int, pages: int, total: int, per_page: int, orders: list<array<string, mixed>>}
     */
    private function ordersPage(Request $request): array
    {
        $status = $request->query->getString('status', 'all');
        if ($status !== 'all' && !SalesOrderState::isKnown($status)) {
            $status = 'all';
        }
        $filters = $this->dashboardFilters($request);
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', self::PAGE_SIZE)));
        $paginated = $this->orderRepository->pageForDashboard($status, $page, $perPage, $filters);
        $orders = $paginated->items();
        foreach ($orders as &$order) {
            $order['available_transitions'] = $order['orders_enabled']
                ? $this->availableTransitions((string) $order['status'])
                : [];
        }
        unset($order);

        return [
            'status' => $status,
            'filters' => $filters,
            'page' => $paginated->currentPage(),
            'pages' => $paginated->lastPage(),
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'orders' => $orders,
        ];
    }

    public function createOrder(Request $request): Response
    {
        $user = $this->requireSalesUser('sales.orders.manage');
        $this->assertSalesEnabled();
        $applicationId = $this->positiveInt($this->inputValue($request, 'application_id'), 'Application invalide.');
        $application = $this->activeApplication($applicationId);
        try {
            $created = $this->orders->createDashboardOrder(
                $this->dashboardContext($applicationId, $user),
                (string) $application['currency'],
                $this->customerInput($request),
                $this->orderItems($this->inputValue($request, 'items'), (string) $application['currency']),
                $this->textInput($this->inputValue($request, 'note'), 1000, 'Remarque invalide.'),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => $exception->getMessage()], 422);
            }
            return $this->redirect('sales?error=' . rawurlencode($exception->getMessage()), 303);
        }

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['success' => true, 'message' => 'La commande a été créée.', 'data' => $created]);
        }
        return $this->redirect('sales?success=' . rawurlencode('La commande a été créée.'), 303);
    }

    public function updateOrder(Request $request): Response
    {
        $user = $this->requireSalesUser('sales.orders.manage');
        $this->assertSalesEnabled();
        try {
            $applicationId = $this->positiveInt($this->inputValue($request, 'application_id'), 'Application invalide.');
            $orderId = $this->positiveInt($this->inputValue($request, 'order_id'), 'Commande invalide.');
            $version = $this->positiveInt($this->inputValue($request, 'version'), 'Version de commande invalide.');
            $application = $this->activeApplication($applicationId);
            $items = $this->inputValue($request, 'edit_items') === '1'
                ? $this->orderItems($this->inputValue($request, 'items'), (string) $application['currency'])
                : null;
            $this->orders->updateDashboardOrder(
                $this->dashboardContext($applicationId, $user),
                (string) $orderId,
                $version,
                $this->customerInput($request),
                $this->textInput($this->inputValue($request, 'note'), 1000, 'Remarque invalide.'),
                $items,
            );
        } catch (SalesCartConflictException) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => 'La commande a changé ; actualisez la liste avant de réessayer.'], 409);
            }
            return $this->redirect('sales?error=' . rawurlencode('La commande a changé ; rechargez la liste avant de réessayer.'), 303);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => $exception->getMessage()], 422);
            }
            return $this->redirect('sales?error=' . rawurlencode($exception->getMessage()), 303);
        }

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['success' => true, 'message' => 'La commande a été modifiée.']);
        }
        return $this->redirect('sales?success=' . rawurlencode('La commande a été modifiée.'), 303);
    }

    public function deleteOrders(Request $request): Response
    {
        $user = $this->requireSalesUser('sales.orders.manage');
        $this->assertSalesEnabled();
        $orderIds = $this->inputValue($request, 'order_ids', []);
        if (!is_array($orderIds) || $orderIds === [] || count($orderIds) > 100) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => 'Sélection de commandes invalide.'], 422);
            }
            return $this->redirect('sales?error=' . rawurlencode('Sélection de commandes invalide.'), 303);
        }
        try {
            $this->connection->transaction(function () use ($orderIds, $user): void {
                foreach (array_values(array_unique($orderIds)) as $value) {
                    $orderId = $this->positiveInt($value, 'Commande invalide.');
                    $applicationId = $this->orderRepository->applicationIdForDashboardOrder((string) $orderId);
                    if ($applicationId === null) {
                        throw new RuntimeException('Une commande sélectionnée est introuvable.');
                    }
                    $this->orders->deleteDashboardOrder(
                        new SalesContext(
                            applicationId: $applicationId,
                            tenantId: null,
                            scopes: ['orders:manage'],
                            actorReference: (string) $user->getAuthIdentifier(),
                        ),
                        (string) $orderId,
                    );
                }
            });
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => $exception->getMessage()], 422);
            }
            return $this->redirect('sales?error=' . rawurlencode($exception->getMessage()), 303);
        }

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['success' => true, 'message' => 'Les commandes sélectionnées ont été supprimées.']);
        }
        return $this->redirect('sales?success=' . rawurlencode('Les commandes sélectionnées ont été supprimées.'), 303);
    }

    public function orderPdf(Request $request): Response
    {
        $user = $this->requireSalesUser('sales.orders.view', 'sales.orders.manage');
        $this->assertSalesEnabled();
        $applicationId = $this->positiveInt($request->query->get('application_id'), 'Application invalide.');
        $orderId = $this->positiveInt($request->query->get('order_id'), 'Commande invalide.');
        $order = $this->orders->get($this->dashboardContext($applicationId, $user, 'orders:read'), (string) $orderId);
        $document = (new \NM\Services\SalesService\Sales\SalesOrderPdfService())->render($order);
        return new Response($document, 200, \Core\Support\PDF\Output\HttpOutput::headers(
            'commande-' . $order->id . '.pdf',
            strlen($document),
        ));
    }

    public function emailOrder(Request $request): Response
    {
        $user = $this->requireSalesUser('sales.orders.manage');
        $this->assertSalesEnabled();
        $applicationId = $this->positiveInt($this->inputValue($request, 'application_id'), 'Application invalide.');
        $orderId = $this->positiveInt($this->inputValue($request, 'order_id'), 'Commande invalide.');
        $order = $this->orders->get($this->dashboardContext($applicationId, $user, 'orders:read'), (string) $orderId);
        $checkout = is_array($order->details['checkout'] ?? null) ? $order->details['checkout'] : [];
        $customer = is_array($checkout['customer'] ?? null) ? $checkout['customer'] : [];
        $recipient = $this->textInput($this->inputValue($request, 'email', $customer['email'] ?? ''), 190, 'Adresse e-mail du client invalide.');
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            if ($this->wantsJson($request)) return $this->jsonResponse(['error' => 'Adresse e-mail du client invalide.'], 422);
            return $this->redirect('sales?error=' . rawurlencode('Adresse e-mail du client invalide.'), 303);
        }
        $mail = new \App\Services\Mail($this->factory);
        if (!$mail->isEnabled()) {
            if ($this->wantsJson($request)) return $this->jsonResponse(['error' => 'Le service de mailing est désactivé.'], 422);
            return $this->redirect('sales?error=' . rawurlencode('Le service de mailing est désactivé.'), 303);
        }
        $from = trim((string) env('MAIL_FROM_ADDRESS', ''));
        if ($from === '') {
            $fromKey = \App\Services\Mail::userName();
            $from = $fromKey === '' ? '' : trim((string) env($fromKey, ''));
        }
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            if ($this->wantsJson($request)) return $this->jsonResponse(['error' => 'Aucune adresse expéditeur valide n’est configurée pour le mailing.'], 422);
            return $this->redirect('sales?error=' . rawurlencode('Aucune adresse expéditeur valide n’est configurée pour le mailing.'), 303);
        }
        $message = $this->textInput($this->inputValue($request, 'message'), 5000, 'Message invalide.');
        $includeStatus = $this->inputValue($request, 'include_status') === '1';
        $subject = $this->textInput($this->inputValue($request, 'subject'), 190, 'Objet invalide.');
        if (str_contains($subject, "\r") || str_contains($subject, "\n")) {
            if ($this->wantsJson($request)) return $this->jsonResponse(['error' => 'L’objet du courriel ne peut pas contenir de retour à la ligne.'], 422);
            return $this->redirect('sales?error=' . rawurlencode('L’objet du courriel ne peut pas contenir de retour à la ligne.'), 303);
        }
        if ($subject === '') {
            $subject = 'Commande #' . $order->id . ($includeStatus ? ' — ' . $this->statusLabel($order->status) : '');
        }
        $statusLine = $includeStatus ? "\nÉtat de la commande : " . $this->statusLabel($order->status) : '';
        $referenceLine = $order->publicReference === null ? '' : "\nRéférence de suivi : " . $order->publicReference;
        $text = trim($message . $statusLine . $referenceLine);
        $html = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $mailable = $mail->make($from, $recipient, $subject, '<p>' . $html . '</p>', $text);
        $temporaryPdf = null;
        try {
            $upload = $request->file('attachment');
            if ($upload instanceof UploadedFile && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
                if (!$upload->isValid() || $upload->getSize() > 5_000_000) {
                    throw new InvalidArgumentException('La pièce jointe est invalide ou dépasse 5 Mo.');
                }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getTmpPath());
                if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'], true)) {
                    throw new InvalidArgumentException('Type de pièce jointe non autorisé (PDF, JPEG, PNG ou texte).');
                }
                $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($upload->getOriginalName())) ?: 'piece-jointe';
                $mailable->attach($upload->getTmpPath(), $filename, $mime);
            } elseif ($upload !== null && !$upload instanceof UploadedFile) {
                throw new InvalidArgumentException('Une seule pièce jointe est acceptée.');
            }
            if ($this->inputValue($request, 'attach_pdf') === '1') {
                $temporaryPdf = tempnam(sys_get_temp_dir(), 'sales-order-');
                if ($temporaryPdf === false || file_put_contents($temporaryPdf, (new \NM\Services\SalesService\Sales\SalesOrderPdfService())->render($order), LOCK_EX) === false) {
                    throw new RuntimeException('Impossible de préparer le PDF de la commande.');
                }
                $mailable->attach($temporaryPdf, 'commande-' . $order->id . '.pdf', 'application/pdf');
            }
            if ($text === '' && $mailable->getAttachments() === []) {
                throw new InvalidArgumentException('Saisissez un message, incluez l’état de la commande ou ajoutez une pièce jointe.');
            }
            $mail->send($mailable);
        } catch (\Throwable $exception) {
            if ($this->wantsJson($request)) return $this->jsonResponse(['error' => $exception->getMessage()], 422);
            return $this->redirect('sales?error=' . rawurlencode($exception->getMessage()), 303);
        } finally {
            if ($temporaryPdf !== null && is_file($temporaryPdf) && !unlink($temporaryPdf)) {
                throw new RuntimeException('Impossible de supprimer le PDF temporaire de la commande.');
            }
        }

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['success' => true, 'message' => 'Le message a été envoyé au client.']);
        }
        return $this->redirect('sales?success=' . rawurlencode('Le message a été envoyé au client.'), 303);
    }

    public function setupStorefront(Request $request): Response
    {
        $user = $this->requireSalesUser('sales.orders.manage');
        if (!$user->isSuper()) {
            throw new GateException();
        }
        $this->assertSalesEnabled();

        if (!$this->serviceState->isEnabled('inventory-service')) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => 'Installez et activez d’abord le service Produits et stocks.'], 422);
            }
            return $this->redirect(
                'sales?error=' . rawurlencode('Installez et activez d’abord le service Produits et stocks.')
                ,
                303
            );
        }

        $code = $this->storefrontCode();
        $currency = strtoupper((string) app()->env(
            'APP_CURRENCY',
            app()->config('APP.APP_CURRENCY', 'MAD')
        ));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Le paramètre APP_CURRENCY doit être une devise ISO à trois lettres.');
        }
        $currencyMismatch = $this->connection->selectOne(
            'SELECT v.id FROM catalog_variants v
             INNER JOIN catalog_products p ON p.id = v.product_id
             WHERE p.status = ? AND v.status = ? AND v.is_default = 1
               AND v.price IS NOT NULL AND v.currency <> ?
             LIMIT 1',
            ['active', 'active', $currency]
        );
        if ($currencyMismatch !== null) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => 'Certains produits actifs ne correspondent pas à APP_CURRENCY; harmonisez leur devise avant la configuration.'], 422);
            }
            return $this->redirect(
                'sales?error=' . rawurlencode('Certains produits actifs ne correspondent pas à APP_CURRENCY; harmonisez leur devise avant la configuration.')
                ,
                303
            );
        }

        try {
            $application = $this->applications->findByCode($user, $code);
            if ($application === null) {
                $applicationId = $this->applications->register(
                    $user,
                    $code,
                    'Boutique en ligne',
                    profile: 'retail',
                    currency: $currency,
                    locale: 'fr',
                );
            } else {
                if ($application['tenant_id'] !== null) {
                    if ($this->wantsJson($request)) {
                        return $this->jsonResponse(['error' => 'La boutique Ventes existante est associée à un tenant et ne peut pas être utilisée comme boutique publique.'], 422);
                    }
                    return $this->redirect(
                        'sales?error=' . rawurlencode('La boutique Ventes existante est associée à un tenant et ne peut pas être utilisée comme boutique publique.')
                        ,
                        303
                    );
                }
                $applicationId = (int) $application['id'];
                if ($application['currency'] !== $currency) {
                    $this->applications->setCurrency($user, $applicationId, $currency);
                }
            }

            $context = $this->applications->administrativeContext($user, $applicationId);
            $configurations = [
                'cart' => [],
                'catalog' => [],
                'availability' => [],
                'pricing' => ['tax_mode' => 'none', 'tax_basis_points' => 0],
                'orders' => [],
                'checkout' => [],
            ];
            foreach (self::STOREFRONT_MODULES as $module) {
                $configuration = $configurations[$module];
                $this->applications->enableModule($context, $module, $configuration);
            }
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => $exception->getMessage()], 422);
            }
            return $this->redirect('sales?error=' . rawurlencode($exception->getMessage()), 303);
        }

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['success' => true, 'message' => 'La boutique est reliée au service Ventes.']);
        }
        return $this->redirect(
            'sales?success=' . rawurlencode('La boutique est reliée au service Ventes. Aucun paiement en ligne n’est activé.')
            ,
            303
        );
    }

    public function transition(Request $request): Response
    {
        $user = $this->requireSalesUser('sales.orders.manage');
        $this->assertSalesEnabled();

        $applicationId = filter_var($this->inputValue($request, 'application_id'), FILTER_VALIDATE_INT);
        $orderId = filter_var($this->inputValue($request, 'order_id'), FILTER_VALIDATE_INT);
        $version = filter_var($this->inputValue($request, 'version'), FILTER_VALIDATE_INT);
        $status = $this->inputValue($request, 'status');
        if (
            $applicationId === false || $applicationId < 1
            || $orderId === false || $orderId < 1
            || $version === false || $version < 1
            || !is_string($status)
            || !SalesOrderState::isKnown($status)
        ) {
            throw new InvalidArgumentException('Les informations de transition de commande sont invalides.');
        }

        $application = $this->connection->selectOne(
            'SELECT id, tenant_id FROM sales_applications WHERE id = ? AND status = ? AND tenant_id IS NULL',
            [$applicationId, 'active']
        );
        if ($application === null) {
            throw new GateException();
        }

        try {
            $this->orders->transition(
                new SalesContext(
                    applicationId: (int) $application['id'],
                    tenantId: null,
                    scopes: ['orders:manage'],
                    actorReference: (string) $user->getAuthIdentifier(),
                ),
                (string) $orderId,
                $version,
                $status,
                'Updated by dashboard user ' . $user->getAuthIdentifier(),
            );
        } catch (SalesCartConflictException) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => 'La commande a changé ; actualisez la liste avant de réessayer.'], 409);
            }
            return $this->redirect('sales?error=' . rawurlencode('La commande a changé ; rechargez la liste avant de réessayer.'), 303);
        } catch (InvalidArgumentException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => $exception->getMessage()], 422);
            }
            return $this->redirect('sales?error=' . rawurlencode($exception->getMessage()), 303);
        } catch (SalesInventoryFinalizationException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['error' => $exception->getMessage()], 422);
            }
            return $this->redirect('sales?error=' . rawurlencode($exception->getMessage()), 303);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Sales order was not found.') {
                throw new GateException();
            }
            if ($exception->getMessage() === 'Sales orders module is not enabled for this application.') {
                if ($this->wantsJson($request)) {
                    return $this->jsonResponse(['error' => 'Le module Commandes est désactivé pour cette application.'], 422);
                }
                return $this->redirect('sales?error=' . rawurlencode('Le module Commandes est désactivé pour cette application.'), 303);
            }
            throw $exception;
        }

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['success' => true, 'message' => 'Le statut de la commande a été mis à jour.']);
        }
        return $this->redirect('sales?success=' . rawurlencode('Le statut de la commande a été mis à jour.'), 303);
    }

    /** @return array<string, string> */
    private function dashboardFilters(Request $request): array
    {
        $filters = [];
        foreach (['date_from', 'date_to'] as $key) {
            $value = trim($request->query->getString($key));
            if ($value !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    throw new InvalidArgumentException('Format de date invalide.');
                }
                $filters[$key] = $value;
            }
        }
        if (isset($filters['date_from'], $filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
            throw new InvalidArgumentException('La date de début doit précéder la date de fin.');
        }
        $search = trim($request->query->getString('search'));
        if ($search !== '') {
            $filters['search'] = mb_substr($search, 0, 120);
        }
        foreach (['min_total', 'max_total'] as $key) {
            $value = trim($request->query->getString($key));
            if ($value !== '') {
                $filters[$key . '_minor'] = (string) $this->parseAmount($value);
            }
        }
        foreach (['min_pieces', 'max_pieces'] as $key) {
            $value = trim($request->query->getString($key));
            if ($value !== '') {
                if (!preg_match('/^(?:0|[1-9][0-9]{0,8})(?:[.,][0-9]{1,6})?$/D', $value)) {
                    throw new InvalidArgumentException('Nombre de pièces invalide.');
                }
                $filters[$key] = str_replace(',', '.', $value);
            }
        }
        if (isset($filters['min_total_minor'], $filters['max_total_minor'])
            && (int) $filters['min_total_minor'] > (int) $filters['max_total_minor']) {
            throw new InvalidArgumentException('Le montant minimum dépasse le montant maximum.');
        }
        if (isset($filters['min_pieces'], $filters['max_pieces'])
            && (float) $filters['min_pieces'] > (float) $filters['max_pieces']) {
            throw new InvalidArgumentException('Le nombre minimum de pièces dépasse le maximum.');
        }

        return $filters;
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains(strtolower($request->header('Accept', '') ?? ''), 'application/json');
    }

    private function parseAmount(string $value): int
    {
        $value = str_replace(',', '.', $value);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Montant de filtre invalide.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function positiveInt(mixed $value, string $message): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < 1) {
            throw new InvalidArgumentException($message);
        }

        return $number;
    }

    private function inputValue(Request $request, string $key, mixed $default = null): mixed
    {
        if (!$request->isJson()) {
            return $request->input($key, $default);
        }

        $input = $request->json();
        return array_key_exists($key, $input) ? $input[$key] : $default;
    }

    private function textInput(mixed $value, int $maxLength, string $message): string
    {
        if (!is_string($value) || strlen($value) > $maxLength) {
            throw new InvalidArgumentException($message);
        }
        return trim($value);
    }

    /** @return array{name: string, phone: string, email: string} */
    private function customerInput(Request $request): array
    {
        return [
            'name' => $this->textInput($this->inputValue($request, 'customer_name', ''), 190, 'Nom client invalide.'),
            'phone' => $this->textInput($this->inputValue($request, 'customer_phone', ''), 80, 'Téléphone invalide.'),
            'email' => $this->textInput($this->inputValue($request, 'customer_email', ''), 190, 'Adresse e-mail invalide.'),
        ];
    }

    /**
     * @return list<array{item_reference: string, label: string, quantity: string, unit: string, unit_amount_minor: int, line_amount_minor: int}>
     */
    private function orderItems(mixed $rawItems, string $currency): array
    {
        if (!$this->serviceState->isEnabled('inventory-service')) {
            throw new InvalidArgumentException('Activez le service Produits et stocks pour relier les articles des commandes au catalogue.');
        }
        if (!is_array($rawItems) || count($rawItems) < 1 || count($rawItems) > 50) {
            throw new InvalidArgumentException('Ajoutez au moins une ligne de commande (50 maximum).');
        }
        $items = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('Ligne de commande invalide.');
            }
            if (trim((string) ($raw['item_reference'] ?? '')) === ''
                && trim((string) ($raw['quantity'] ?? '')) === ''
                && trim((string) ($raw['unit_price'] ?? '')) === '') {
                continue;
            }
            $reference = $this->textInput($raw['item_reference'] ?? null, 10, 'Produit invalide.');
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $reference)) {
                throw new InvalidArgumentException('Choisissez un produit du catalogue pour chaque ligne.');
            }
            $product = $this->connection->selectOne(
                'SELECT p.id, p.name, v.price, v.currency, COALESCE(u.code, ?) AS unit
                 FROM catalog_products p
                 INNER JOIN catalog_variants v ON v.product_id = p.id AND v.is_default = 1
                 LEFT JOIN inventory_items i ON i.variant_id = v.id
                 LEFT JOIN inventory_units u ON u.id = i.reference_unit_id
                 WHERE p.id = ? AND p.status = ? AND v.status = ? AND v.price IS NOT NULL
                 LIMIT 1',
                ['PCS', (int) $reference, 'active', 'active']
            );
            if ($product === null || strtoupper((string) $product['currency']) !== strtoupper($currency)) {
                throw new InvalidArgumentException('Le produit sélectionné est introuvable, inactif ou dans une autre devise.');
            }
            $label = (string) $product['name'];
            $quantity = $this->textInput($raw['quantity'] ?? null, 32, 'Quantité invalide.');
            $unit = (string) $product['unit'];
            $priceInput = trim((string) ($raw['unit_price'] ?? ''));
            $price = $priceInput === ''
                ? LocalCatalogAdapter::toMinorAmount((string) $product['price'])
                : $this->parseAmount($this->textInput($priceInput, 32, 'Prix invalide.'));
            if ($label === '' || $unit === '') {
                throw new InvalidArgumentException('Le produit et son unité sont obligatoires.');
            }
            $line = (new \NM\Services\SalesService\Sales\Domain\Money($price, $currency))->multiplyQuantity($quantity);
            $items[] = [
                'item_reference' => $reference,
                'label' => $label,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_amount_minor' => $price,
                'line_amount_minor' => $line->minorAmount,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{id: string, name: string, unit: string, price_minor: int, currency: string}>
     */
    private function dashboardCatalogProducts(): array
    {
        if (!$this->serviceState->isEnabled('inventory-service')) {
            return [];
        }

        $rows = $this->connection->select(
            'SELECT p.id, p.name, v.price, v.currency, COALESCE(u.code, ?) AS unit
             FROM catalog_products p
             INNER JOIN catalog_variants v ON v.product_id = p.id AND v.is_default = 1
             LEFT JOIN inventory_items i ON i.variant_id = v.id
             LEFT JOIN inventory_units u ON u.id = i.reference_unit_id
             WHERE p.status = ? AND v.status = ? AND v.price IS NOT NULL
               AND ROUND(v.price, 2) = v.price
             ORDER BY p.name ASC, p.id ASC',
            ['PCS', 'active', 'active']
        );

        return array_map(
            static fn(array $row): array => [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'unit' => (string) $row['unit'],
                'price_minor' => LocalCatalogAdapter::toMinorAmount((string) $row['price']),
                'currency' => strtoupper((string) $row['currency']),
            ],
            $rows
        );
    }

    private function activeApplication(int $applicationId): array
    {
        foreach ($this->orderRepository->activeDashboardApplications() as $application) {
            if ($application['id'] === $applicationId) {
                return $application;
            }
        }
        throw new RuntimeException('Application Ventes active introuvable ou module Commandes désactivé.');
    }

    private function dashboardContext(int $applicationId, User $user, string $scope = 'orders:manage'): SalesContext
    {
        $this->activeApplication($applicationId);

        return new SalesContext(
            applicationId: $applicationId,
            tenantId: null,
            scopes: [$scope],
            actorReference: (string) $user->getAuthIdentifier(),
        );
    }

    private function statusLabel(string $status): string
    {
        return [
            SalesOrderState::PENDING => 'En attente',
            SalesOrderState::CONFIRMED => 'Confirmée',
            SalesOrderState::PROCESSING => 'En traitement',
            SalesOrderState::READY => 'Prête',
            SalesOrderState::COMPLETED => 'Terminée',
            SalesOrderState::CANCELLED => 'Annulée',
        ][$status] ?? $status;
    }

    private function requireSalesUser(string ...$permissions): User
    {
        $userId = $this->session->getUserId();
        $user = $userId === null ? null : User::find($userId);
        if ($user === null || !$user->is_active()) {
            throw new GateException();
        }
        if (
            !$user->isSuper() && !array_filter(
                $permissions,
                static fn(string $permission): bool => $user->hasPermission($permission)
            )
        ) {
            throw new GateException();
        }

        return $user;
    }

    private function assertSalesEnabled(): void
    {
        $this->serviceState->assertEnabled();
    }

    private function assertCatalogStatisticsEnabled(): void
    {
        if (!$this->catalogStatisticsEnabled()) {
            throw new NotFoundHttpException();
        }
    }

    private function catalogStatisticsEnabled(): bool
    {
        return $this->serviceState->isEnabled('inventory-service');
    }

    private function storefrontSetupRequired(User $user): bool
    {
        $application = $this->applications->findByCode($user, $this->storefrontCode());
        if ($application === null || $application['status'] !== 'active' || $application['tenant_id'] !== null) {
            return true;
        }

        $context = $this->applications->administrativeContext($user, (int) $application['id']);
        foreach (self::STOREFRONT_MODULES as $module) {
            if (!$this->applications->isModuleEnabled($context, $module)) {
                return true;
            }
        }

        return false;
    }

    private function storefrontCode(): string
    {
        return strtolower(trim((string) app()->config('services.sales-service.storefront_code', 'storefront')));
    }

    /**
     * @return list<array{status: string, label: string}>
     */
    private function availableTransitions(string $status): array
    {
        $labels = [
            SalesOrderState::CONFIRMED => 'Confirmer',
            SalesOrderState::PROCESSING => 'Mettre en traitement',
            SalesOrderState::READY => 'Marquer prête',
            SalesOrderState::COMPLETED => 'Terminer',
            SalesOrderState::CANCELLED => 'Annuler',
        ];
        $transitions = [];
        foreach ($labels as $target => $label) {
            if (SalesOrderState::allows($status, $target)) {
                $transitions[] = ['status' => $target, 'label' => $label];
            }
        }

        return $transitions;
    }
}