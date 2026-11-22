<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Controllers;

use NM\Services\SalesService\Sales\Domain\SalesOrder;
use NM\Services\SalesService\Sales\SalesApplicationRegistry;
use NM\Services\SalesService\Sales\SalesCartConflictException;
use NM\Services\SalesService\Sales\SalesContext;
use NM\Services\SalesService\Sales\SalesPlatform;
use NM\Services\SalesService\Sales\SalesConsumerUnavailableException;
use NM\Services\SalesService\Sales\Contracts\CatalogPort;
use NM\Services\SalesService\Sales\SalesServiceState;
use Core\Exceptions\Http\GateException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Http\RedirectResponse;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Security\CsrfTokenManager;
use Core\View\ViewFactory;
use InvalidArgumentException;
use JsonException;
use PDOException;
use RuntimeException;

final class StorefrontCheckoutController
{
    private const SESSION_TOKEN = 'sales.storefront.checkout_token';
    private const SESSION_DRAFT = 'sales.storefront.checkout_draft';
    private const SESSION_LAST_SUBMISSION = 'sales.storefront.last_submission';
    private const SESSION_ORDER_IDS = 'sales.storefront.order_ids';
    private const CONTEXT_SCOPES = ['carts:read', 'carts:write', 'checkout:write', 'orders:read'];

    public function __construct(
        private readonly ViewFactory $factory,
        private readonly SessionInterface $session,
        private readonly SalesApplicationRegistry $applications,
        private readonly SalesPlatform $sales,
        private readonly CatalogPort $catalog,
        private readonly \NM\Services\SalesService\Sales\SalesOrderRepository $orderRepository,
        private readonly SalesServiceState $serviceState,
        private readonly string $applicationCode,
        private readonly string $currency,
    ) {
    }

    public function checkout(): Response
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_TOKEN, $token);
        try {
            $this->assertReady();
        } catch (SalesConsumerUnavailableException) {
            return $this->checkoutResponse(
                $token,
                'La boutique en ligne n’est pas encore configurée. Un administrateur doit la configurer dans le tableau de bord Ventes.',
                503
            );
        }

        return $this->checkoutResponse($token);
    }

    public function pricingPreview(Request $request): Response
    {
        try {
            $this->assertReady();
            $lines = $this->validatedCartItems($request);
            $context = $this->storefrontContext();
            $previewLines = [];
            $subtotal = new \NM\Services\SalesService\Sales\Domain\Money(0, $this->currency);
            $originalSubtotal = new \NM\Services\SalesService\Sales\Domain\Money(0, $this->currency);
            foreach ($lines as $line) {
                $item = $this->catalog->findItem($context, $line['id'])
                    ?? throw new InvalidArgumentException('Un article du panier n’est plus disponible.');
                if ($item->unitPrice->currency !== $this->currency) {
                    throw new InvalidArgumentException('La devise d’un article du panier ne correspond plus à la boutique.');
                }
                $originalUnitPrice = $item->originalUnitPrice ?? $item->unitPrice;
                $quantity = (string) $line['quantity'];
                $lineAmount = $item->unitPrice->multiplyQuantity($quantity);
                $originalLineAmount = $originalUnitPrice->multiplyQuantity($quantity);
                $subtotal = $subtotal->add($lineAmount);
                $originalSubtotal = $originalSubtotal->add($originalLineAmount);
                $previewLines[] = [
                    'item_reference' => $line['id'],
                    'label' => $item->label,
                    'quantity' => $quantity,
                    'currency' => $this->currency,
                    'unit_amount_minor' => $item->unitPrice->minorAmount,
                    'original_unit_amount_minor' => $originalUnitPrice->minorAmount,
                    'line_amount_minor' => $lineAmount->minorAmount,
                    'original_line_amount_minor' => $originalLineAmount->minorAmount,
                    'saving_minor' => $originalLineAmount->minorAmount - $lineAmount->minorAmount,
                    'applied_discounts' => $item->discount?->appliedDiscounts ?? [],
                ];
            }

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'currency' => $this->currency,
                    'subtotal_minor' => $subtotal->minorAmount,
                    'original_subtotal_minor' => $originalSubtotal->minorAmount,
                    'saving_minor' => $originalSubtotal->minorAmount - $subtotal->minorAmount,
                    'items' => $previewLines,
                ],
            ]);
        } catch (SalesConsumerUnavailableException $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 503);
        } catch (InvalidArgumentException | JsonException $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 422);
        } catch (PDOException $exception) {
            error_log('Storefront price preview failed: ' . $exception->getMessage());
            return $this->jsonResponse([
                'error' => 'Le service de tarification est temporairement indisponible.',
            ], 503);
        } catch (RuntimeException $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 422);
        }
    }

    public function placeOrder(Request $request): Response
    {
        $token = $request->input('checkout_token');
        try {
            $this->assertReady();
        } catch (SalesConsumerUnavailableException) {
            $responseToken = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)
                ? $token
                : bin2hex(random_bytes(32));
            if ($responseToken !== $token) {
                $this->session->set(self::SESSION_TOKEN, $responseToken);
            }
            return $this->checkoutFailure(
                $request,
                $responseToken,
                'La boutique en ligne n’est pas encore configurée. Un administrateur doit la configurer dans le tableau de bord Ventes.',
                503
            );
        }
        if (
            !is_string($token)
            || !preg_match('/^[a-f0-9]{64}$/D', $token)
            || !hash_equals((string) $this->session->get(self::SESSION_TOKEN, ''), $token)
        ) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_TOKEN, $token);
            return $this->checkoutFailure($request, $token, 'Le formulaire a expiré. Vérifiez le panier puis confirmez à nouveau.', 409);
        }

        try {
            [$lines, $orderDetails] = $this->validatedSubmission($request);
        } catch (InvalidArgumentException | JsonException $exception) {
            return $this->checkoutFailure($request, $token, $exception->getMessage(), 422);
        }

        $requestHash = hash('sha256', json_encode(
            ['lines' => $lines, 'details' => $orderDetails],
            JSON_THROW_ON_ERROR
        ));
        $lastSubmission = $this->session->get(self::SESSION_LAST_SUBMISSION, []);
        if (
            is_array($lastSubmission)
            && ($lastSubmission['token'] ?? null) === $token
            && ($lastSubmission['request_hash'] ?? null) === $requestHash
            && is_string($lastSubmission['order_id'] ?? null)
        ) {
            if ($this->wantsJson($request) && is_string($lastSubmission['public_reference'] ?? null)) {
                return $this->jsonResponse([
                    'success' => true,
                    'data' => ['order_id' => $lastSubmission['order_id'], 'reference' => $lastSubmission['public_reference']],
                ]);
            }
            return $this->confirmationRedirect($lastSubmission['order_id']);
        }

        $draft = $this->session->get(self::SESSION_DRAFT, []);
        if (is_array($draft) && ($draft['token'] ?? null) === $token) {
            if (($draft['request_hash'] ?? null) !== $requestHash) {
                return $this->checkoutFailure($request, $token, 'Le panier ou les coordonnées ont changé. Réessayez sans recharger la page.', 409);
            }
        } else {
            $draft = [
                'token' => $token,
                'request_hash' => $requestHash,
                'cart_id' => null,
            ];
        }

        $context = $this->storefrontContext();
        $cartId = $draft['cart_id'] ?? null;
        if (!is_string($cartId)) {
            $cart = $this->sales->carts()->create($context);
            $cartId = $cart->id;
            $draft['cart_id'] = $cartId;
            $this->session->set(self::SESSION_DRAFT, $draft);
        }

        try {
            if (($draft['order_ready'] ?? false) === true && is_int($draft['cart_version'] ?? null)) {
                $cartVersion = $draft['cart_version'];
            } else {
                $cart = $this->sales->carts()->get($context, $cartId);
                $existingLines = $cart->lines;
                if (count($existingLines) > count($lines)) {
                    throw new SalesCartConflictException('Le panier de commande ne correspond plus aux articles transmis.');
                }
                foreach ($existingLines as $index => $line) {
                    if (
                        $line->itemReference !== $lines[$index]['id']
                        || $line->quantity !== (string) $lines[$index]['quantity']
                    ) {
                        throw new SalesCartConflictException('Le panier de commande ne correspond plus aux articles transmis.');
                    }
                }

                foreach (array_slice($lines, count($existingLines)) as $offset => $line) {
                    $index = count($existingLines) + $offset;
                    $cart = $this->sales->carts()->addItem(
                        $context,
                        $cartId,
                        $line['id'],
                        (string) $line['quantity'],
                        [],
                        $cart->version,
                        hash('sha256', 'storefront-line:' . $cartId . ':' . $index),
                    );
                }
                $cartVersion = $cart->version;
                $draft['order_ready'] = true;
                $draft['cart_version'] = $cartVersion;
                $this->session->set(self::SESSION_DRAFT, $draft);
            }

            $order = $this->sales->checkout()->checkout(
                $context,
                $cartId,
                $cartVersion,
                hash('sha256', 'storefront-checkout:' . $token),
                $orderDetails,
            );
        } catch (SalesCartConflictException | InvalidArgumentException $exception) {
            return $this->checkoutFailure($request, $token, $exception->getMessage(), 409);
        } catch (PDOException $exception) {
            error_log('Storefront order persistence failed: ' . $exception->getMessage());
            return $this->checkoutFailure(
                $request,
                $token,
                'Le service de commande est temporairement indisponible. Réessayez dans quelques instants.',
                503
            );
        } catch (RuntimeException $exception) {
            return $this->checkoutFailure($request, $token, $exception->getMessage(), 422);
        }
        $this->rememberOrder($order);
        $this->session->set(self::SESSION_LAST_SUBMISSION, [
            'token' => $token,
            'request_hash' => $requestHash,
            'order_id' => $order->id,
            'public_reference' => $order->publicReference,
        ]);
        $this->session->delete(self::SESSION_DRAFT);

        if ($this->wantsJson($request)) {
            return $this->jsonResponse([
                'success' => true,
                'data' => ['order_id' => $order->id, 'reference' => $order->publicReference],
            ]);
        }
        return $this->confirmationRedirect($order->id);
    }

    public function orderStatusPage(): Response
    {
        return new Response($this->factory->render('sales.order-lookup', [
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
        ]));
    }

    public function lookupOrder(Request $request): Response
    {
        $reference = strtoupper(trim((string) $request->input('reference', '')));
        $email = trim((string) $request->input('email', ''));
        $phone = trim((string) $request->input('phone', ''));
        if (
            !preg_match('/^CMD-[A-F0-9]{16}$/D', $reference)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($phone) < 6
            || strlen($phone) > 40
        ) {
            return $this->jsonResponse(['error' => 'Vérifiez la référence, l’adresse e-mail et le téléphone.'], 422);
        }

        $order = $this->orderRepository->findPublicOrder(
            $this->applicationCode,
            hash('sha256', $reference),
            $email,
            $phone,
        );
        if ($order === null) {
            return $this->jsonResponse(['error' => 'Aucune commande ne correspond aux informations fournies.'], 404);
        }

        $labels = [
            'pending' => 'En attente',
            'confirmed' => 'Confirmée',
            'processing' => 'En préparation',
            'ready' => 'Prête',
            'completed' => 'Terminée',
            'cancelled' => 'Annulée',
        ];
        $checkout = is_array($order->details['checkout'] ?? null) ? $order->details['checkout'] : [];
        $discounts = is_array($checkout['discounts'] ?? null) ? $checkout['discounts'] : [];
        $orderItems = [];
        $totalSaving = new \NM\Services\SalesService\Sales\Domain\Money(0, $order->total->currency);
        foreach ($order->lines as $index => $line) {
            $discount = is_array($discounts[$index] ?? null) ? $discounts[$index] : [];
            $saving = max(0, (int) ($discount['saving_minor'] ?? 0));
            $totalSaving = $totalSaving->add(new \NM\Services\SalesService\Sales\Domain\Money($saving, $line['currency']));
            $orderItems[] = [
                'label' => $line['label'],
                'quantity' => $line['quantity'],
                'currency' => $line['currency'],
                'line_amount_minor' => $line['line_amount_minor'],
                'original_line_amount_minor' => (int) ($discount['original_line_amount_minor'] ?? $line['line_amount_minor']),
                'saving_minor' => $saving,
                'line_total' => (new \NM\Services\SalesService\Sales\Domain\Money((int) $line['line_amount_minor'], $line['currency']))->format(),
                'original_line_total' => (new \NM\Services\SalesService\Sales\Domain\Money(
                    (int) ($discount['original_line_amount_minor'] ?? $line['line_amount_minor']),
                    $line['currency']
                ))->format(),
                'saving' => (new \NM\Services\SalesService\Sales\Domain\Money($saving, $line['currency']))->format(),
                'applied_discounts' => is_array($discount['applied_discounts'] ?? null)
                    ? $discount['applied_discounts']
                    : [],
            ];
        }
        return $this->jsonResponse([
            'success' => true,
            'data' => [
                'reference' => $reference,
                'status' => $labels[$order->status] ?? $order->status,
                'total' => $order->total->format(),
                'saving' => $totalSaving->format(),
                'items' => $orderItems,
            ],
        ]);
    }

    public function confirmation(string $orderId): Response
    {
        $orderIds = $this->session->get(self::SESSION_ORDER_IDS, []);
        if (
            !preg_match('/^[1-9][0-9]{0,9}$/D', $orderId)
            || !is_array($orderIds)
            || !in_array($orderId, $orderIds, true)
        ) {
            throw new NotFoundHttpException();
        }
        try {
            $this->assertReady();
        } catch (SalesConsumerUnavailableException) {
            return new Response(
                'La boutique en ligne est temporairement indisponible. Veuillez réessayer plus tard.',
                503
            );
        }
        $order = $this->sales->orders()->get($this->storefrontContext(), $orderId);
        $lastSubmission = $this->session->get(self::SESSION_LAST_SUBMISSION, []);
        $publicReference = is_array($lastSubmission)
            && ($lastSubmission['order_id'] ?? null) === $orderId
            && is_string($lastSubmission['public_reference'] ?? null)
            ? $lastSubmission['public_reference']
            : null;

        $confirmationData = [
            'order' => $order,
            'public_reference' => $publicReference,
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
        ];
        $confirmationData['sales_confirmation_data'] = $confirmationData;

        return new Response($this->factory->render('sales.order-confirmation', $confirmationData));
    }

    private function assertReady(): void
    {
        if (
            !$this->serviceState->isEnabled('sales-service')
            || !$this->serviceState->isEnabled('inventory-service')
        ) {
            throw new GateException('La boutique en ligne n’est pas encore configurée. Un administrateur doit la configurer dans le tableau de bord Ventes.');
        }
        if (!preg_match('/^[A-Z]{3}$/D', $this->currency)) {
            throw new RuntimeException('The application currency configuration is invalid.');
        }
        $context = $this->storefrontContext();
        $application = $this->applications->forContext($context);
        if ($application['currency'] !== $this->currency) {
            throw new RuntimeException('The configured storefront currency differs from APP_CURRENCY. Run the Sales storefront setup again.');
        }
        foreach (['cart', 'catalog', 'availability', 'pricing', 'orders', 'checkout'] as $module) {
            if (!$this->applications->isModuleEnabled($context, $module)) {
                throw new RuntimeException("Sales storefront module [{$module}] is not enabled.");
            }
        }
    }

    private function storefrontContext(): SalesContext
    {
        return $this->applications->publicConsumerContext($this->applicationCode, self::CONTEXT_SCOPES);
    }

    private function checkoutResponse(string $token, string $error = '', int $status = 200): Response
    {
        return new Response($this->factory->render('sales.checkout', [
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
            'checkout_token' => $token,
            'checkout_error' => $error,
            'currency' => $this->currency,
        ]), $status);
    }

    private function checkoutFailure(Request $request, string $token, string $error, int $status): Response
    {
        return $this->wantsJson($request)
            ? $this->jsonResponse(['error' => $error], $status)
            : $this->checkoutResponse($token, $error, $status);
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains(strtolower($request->header('Accept', '') ?? ''), 'application/json');
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /**
     * @return array{0: list<array{id: string, quantity: int}>, 1: array<string, mixed>}
     */
    private function validatedSubmission(Request $request): array
    {
        $lines = $this->validatedCartItems($request);

        $name = $this->textInput($request, 'customer_name', 190, 'Le nom est obligatoire.');
        $email = $this->textInput($request, 'customer_email', 190, 'L’adresse e-mail est obligatoire.');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Saisissez une adresse e-mail valide.');
        }
        $phone = $this->textInput($request, 'customer_phone', 40, 'Le numéro de téléphone est obligatoire.');
        $mode = $request->input('fulfillment_mode');
        if (!in_array($mode, ['pickup', 'delivery'], true)) {
            throw new InvalidArgumentException('Choisissez le retrait ou une demande de livraison.');
        }

        $checkout = [
            'customer' => ['name' => $name, 'email' => $email, 'phone' => $phone],
            'fulfillment' => ['mode' => $mode],
            'payment_status' => 'not_configured',
        ];
        if ($mode === 'delivery') {
            $checkout['fulfillment']['address'] = [
                'street' => $this->textInput($request, 'delivery_street', 190, 'L’adresse de livraison est obligatoire.'),
                'city' => $this->textInput($request, 'delivery_city', 100, 'La ville est obligatoire.'),
                'postal_code' => $this->textInput($request, 'delivery_postal_code', 20, 'Le code postal est obligatoire.'),
            ];
        }

        return [$lines, $checkout];
    }

    /**
     * @return list<array{id: string, quantity: int}>
     */
    private function validatedCartItems(Request $request): array
    {
        $itemsJson = $request->input('items_json');
        if (!is_string($itemsJson) || strlen($itemsJson) > 16_384) {
            throw new InvalidArgumentException('Le panier est vide ou trop volumineux.');
        }
        $submittedItems = json_decode($itemsJson, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($submittedItems) || !array_is_list($submittedItems) || $submittedItems === [] || count($submittedItems) > 100) {
            throw new InvalidArgumentException('Le panier ne contient aucun article valide.');
        }

        $quantities = [];
        foreach ($submittedItems as $item) {
            if (
                !is_array($item)
                || !is_string($item['id'] ?? null)
                || !preg_match('/^[1-9][0-9]{0,9}$/D', $item['id'])
                || filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT) === false
            ) {
                throw new InvalidArgumentException('Un article du panier est invalide.');
            }
            $quantity = (int) $item['quantity'];
            if ($quantity < 1 || $quantity > 99) {
                throw new InvalidArgumentException('Chaque quantité doit être comprise entre 1 et 99.');
            }
            $quantities[$item['id']] = ($quantities[$item['id']] ?? 0) + $quantity;
            if ($quantities[$item['id']] > 99) {
                throw new InvalidArgumentException('La quantité maximale par article est de 99.');
            }
        }
        ksort($quantities, SORT_STRING);

        $lines = [];
        foreach ($quantities as $id => $quantity) {
            $lines[] = ['id' => (string) $id, 'quantity' => $quantity];
        }

        return $lines;
    }

    private function textInput(Request $request, string $key, int $maxLength, string $error): string
    {
        $value = $request->input($key);
        if (!is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function rememberOrder(SalesOrder $order): void
    {
        $orderIds = $this->session->get(self::SESSION_ORDER_IDS, []);
        if (!is_array($orderIds)) {
            $orderIds = [];
        }
        $orderIds = array_values(array_filter($orderIds, 'is_string'));
        if (!in_array($order->id, $orderIds, true)) {
            $orderIds[] = $order->id;
        }
        $this->session->set(self::SESSION_ORDER_IDS, array_slice($orderIds, -10));
    }

    private function confirmationRedirect(string $orderId): RedirectResponse
    {
        return new RedirectResponse('/checkout/confirmation/' . rawurlencode($orderId), 303);
    }
}
