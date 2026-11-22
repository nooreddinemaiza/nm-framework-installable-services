<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Controllers;

use App\Models\User;
use NM\Services\Quotes\Models\QuoteRecord;
use NM\Services\Quotes\Quotes\QuotePdfService;
use NM\Services\Quotes\Quotes\QuoteRepository;
use NM\Services\Quotes\Quotes\QuoteService;
use NM\Services\Quotes\Quotes\QuoteServiceState;
use Core\Exceptions\Http\GateException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\View\ViewFactory;
use App\Helpers\NavbarFactory;
use InvalidArgumentException;
use RuntimeException;

final class QuoteController extends Controller
{
    public function __construct(
        private readonly SessionInterface $session,
        private readonly QuoteService $quotes,
        private readonly QuoteRepository $repository,
        private readonly QuotePdfService $pdf,
        private readonly QuoteServiceState $serviceState,
        private readonly ?ViewFactory $factory = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->requireQuoteUser('quotes.view', 'quotes.create');
        $this->assertEnabled();
        if (!$this->factory instanceof ViewFactory) {
            throw new \RuntimeException('La fabrique de vues n’est pas disponible.');
        }
        $this->register_csrf($this->session, 'token');
        return new Response($this->factory->renderWithLayout('app', 'dashboard', [
            'title' => 'Devis',
            'csrf_token' => (new \Core\Security\CsrfTokenManager($this->session, self::TOKEN_NAME))->generateToken(),
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user),
            'admin_permissions' => $user->effectivePermissions('string'),
            'content_name' => 'dashboard.quotes',
            'activeNavTab' => 'quotes',
            'showHome' => true,
            'breadcrumbs' => [['label' => 'Ventes'], ['label' => 'Devis']],
        ]));
    }

    public function submit(Request $request): Response
    {
        $this->assertEnabled();
        $this->register_csrf($this->session);
        $data = $request->isJson() ? $request->json() : $request->request->all();
        if (!is_array($data)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Payload invalide.'], 422);
        }
        $token = $data[self::TOKEN_NAME] ?? null;
        if (is_string($token) && !$this->csrfManager->validateToken($token)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Jeton CSRF invalide.'], 419);
        }
        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Au moins une ligne est requise.'], 422);
        }

        try {
            $userId = $this->session->getUserId();
            $quote = $this->quotes->create($data, array_values($items), $userId);
            return $this->jsonResponse([
                'success' => true,
                'reference' => $quote->getAttribute('reference'),
                'quote' => [
                    'id' => (int) $quote->getAttribute('id'),
                    'reference' => (string) $quote->getAttribute('reference'),
                    'status' => (string) $quote->getAttribute('status'),
                    'subtotal' => (string) $quote->getAttribute('subtotal'),
                    'discount_total' => (string) $quote->getAttribute('discount_total'),
                    'tax_total' => (string) $quote->getAttribute('tax_total'),
                    'total' => (string) $quote->getAttribute('total'),
                    'currency' => (string) $quote->getAttribute('currency'),
                    'created_at' => (string) $quote->getAttribute('created_at'),
                ],
            ], 201);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function search(Request $request): Response
    {
        $this->assertEnabled();
        $term = $request->query->get('term');
        $category = $request->query->getInt('category_id');
        $brand = $request->query->getInt('brand_id');
        $limit = $request->query->getInt('limit', 20);
        try {
            $results = $this->quotes->searchCatalog(
                is_string($term) ? $term : null,
                $category > 0 ? $category : null,
                $brand > 0 ? $brand : null,
                $limit
            );
            return $this->jsonResponse(['success' => true, 'data' => $results]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'error' => $exception->getMessage()], 422);
        }
    }

    public function data(Request $request): Response
    {
        $this->requireQuoteUser('quotes.view', 'quotes.create');
        $this->assertEnabled();
        $status = $request->query->getString('status', 'all');
        $term = trim($request->query->getString('term'));
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 20)));

        $query = QuoteRecord::query();
        if ($status !== '' && $status !== 'all') {
            $query->where('status', '=', $status);
        }
        if ($term !== '') {
            $query->where(function ($builder) use ($term): void {
                $builder->where('reference', 'LIKE', '%' . $term . '%')
                    ->orWhere('customer_name', 'LIKE', '%' . $term . '%')
                    ->orWhere('customer_email', 'LIKE', '%' . $term . '%');
            });
        }
        $total = $query->count();
        $quotes = $query->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return $this->jsonResponse([
            'success' => true,
            'data' => array_map(static fn(QuoteRecord $quote): array => $quote->toArray(), $quotes),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function updateStatus(Request $request): Response
    {
        $this->requireQuoteUser('quotes.manage_status');
        $this->assertEnabled();
        $data = $request->isJson() ? $request->json() : $request->request->all();
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $status = is_string($data['status'] ?? null) ? (string) $data['status'] : '';
        $quote = $id === false ? null : $this->repository->find((int) $id);
        if (!$quote instanceof QuoteRecord) {
            throw new NotFoundHttpException();
        }
        $userId = $this->session->getUserId();
        try {
            $updated = $this->quotes->transition($quote, $status, $userId);
            return $this->jsonResponse(['success' => true, 'data' => $updated->toArray()]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'error' => $exception->getMessage()], 422);
        }
    }

    public function pdf(Request $request): Response
    {
        $this->requireQuoteUser('quotes.view', 'quotes.export_pdf');
        $this->assertEnabled();
        $id = $request->query->getInt('id');
        $quote = $this->repository->find($id);
        if (!$quote instanceof QuoteRecord) {
            throw new NotFoundHttpException();
        }
        $document = $this->pdf->render($quote);
        return new Response($document, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="devis-' . $quote->getAttribute('reference') . '.pdf"',
        ]);
    }

    public function csv(Request $request): Response
    {
        $this->requireQuoteUser('quotes.view', 'quotes.export_csv');
        $this->assertEnabled();
        $id = $request->query->getInt('id');
        $quote = $id > 0 ? $this->repository->find($id) : null;
        $quotes = $quote instanceof QuoteRecord
            ? [$quote]
            : QuoteRecord::query()->orderBy('created_at', 'DESC')->limit(5000)->get();

        $lines = [[
            'Référence', 'Nom', 'E-mail', 'Téléphone', 'Statut', 'Création',
            'Sous-total', 'Remises', 'Taxes', 'Total', 'Devise',
        ]];
        foreach ($quotes as $row) {
            $values = [
                $row->getAttribute('reference'), $row->getAttribute('customer_name'),
                $row->getAttribute('customer_email'), $row->getAttribute('customer_phone'),
                $row->getAttribute('status'), $row->getAttribute('created_at'),
                $row->getAttribute('subtotal'), $row->getAttribute('discount_total'),
                $row->getAttribute('tax_total'), $row->getAttribute('total'),
                $row->getAttribute('currency'),
            ];
            $lines[] = array_map(static fn(mixed $value): string => self::csvValue($value), $values);
        }
        $csv = "\xEF\xBB\xBF";
        foreach ($lines as $line) {
            $csv .= implode(';', $line) . "\r\n";
        }
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="devis.csv"',
        ]);
    }

    private function requireQuoteUser(string ...$permissions): User
    {
        $userId = $this->session->getUserId();
        $user = $userId === null ? null : User::find($userId);
        if ($user === null || !$user->is_active()) {
            throw new GateException();
        }
        if (!$user->isSuper() && !array_filter(
            $permissions,
            static fn(string $permission): bool => $user->hasPermission($permission)
        )) {
            throw new GateException();
        }
        return $user;
    }

    private function assertEnabled(): void
    {
        $this->serviceState->assertEnabled();
    }

    private static function csvValue(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $text = "'" . $text;
        }
        return '"' . str_replace('"', '""', $text) . '"';
    }
}
