<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\Controllers;

use App\Helpers\NavbarFactory;
use App\Models\User;
use NM\Services\OnlinePayments\OnlinePayments\OnlinePaymentService;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Security\CsrfTokenManager;
use Core\View\ViewFactory;
use InvalidArgumentException;
use RuntimeException;

final class OnlinePaymentController extends Controller
{
    public function __construct(
        private readonly OnlinePaymentService $payments,
        private readonly ViewFactory $factory,
        private readonly SessionInterface $session,
    ) {
    }

    public function view(): Response
    {
        $user = $this->currentUser();
        $this->authorize($user, 'online_payments.view');
        $data = $this->payments->dashboardData($user);

        return new Response($this->factory->renderWithLayout('app', 'dashboard', [
            'title' => 'Paiement en ligne',
            'csrf_token' => (new CsrfTokenManager($this->session, self::TOKEN_NAME))->generateToken(),
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user, 'online-payments'),
            'admin_permissions' => $user->effectivePermissions('string'),
            'content_name' => 'online-payments.dashboard',
            'activeNavTab' => 'online-payments',
            'showHome' => true,
            'breadcrumbs' => [
                ['label' => 'Ventes', 'url' => '/dashboard/sales'],
                ['label' => 'Paiement en ligne'],
            ],
            'online_payments' => $data,
            'online_payment_message' => $this->session->getFlashMessages('online_payment_message')[0] ?? '',
            'online_payment_variant' => $this->session->getFlashMessages('online_payment_variant')[0] ?? 'success',
        ]));
    }

    public function saveConfiguration(Request $request): Response
    {
        $user = $this->currentUser();
        $this->register_csrf($this->session);
        $input = $request->request->all();
        try {
            $validated = $this->validatePost(
                data: $input,
                only: ['merchant_country', 'currency', 'allow_partial_payments', self::TOKEN_NAME],
                rules: [
                    'merchant_country' => 'required|string|max:2|no_sql|no_html',
                    'currency' => 'required|string|max:3|no_sql|no_html',
                    'allow_partial_payments' => 'required|in:0,1',
                    self::TOKEN_NAME => 'required',
                ],
            )->toArray();
            $this->payments->saveConfiguration($user, $validated);
            $this->flash('La configuration générale a été enregistrée.', 'success');
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $this->flash($exception->getMessage(), 'danger');
        }

        return Response::redirect('/dashboard/online-payments');
    }

    public function saveProvider(Request $request): Response
    {
        $user = $this->currentUser();
        $this->register_csrf($this->session);
        $input = $request->request->all();
        try {
            $validated = $this->validatePost(
                data: $input,
                only: ['code', 'enabled', 'credentials_json', self::TOKEN_NAME],
                rules: [
                    'code' => 'required|string|max:60|no_sql|no_html',
                    'enabled' => 'required|in:0,1',
                    'credentials_json' => 'nullable|string|max:16384',
                    self::TOKEN_NAME => 'required',
                ],
            )->toArray();
            $credentialsJson = trim((string) ($validated['credentials_json'] ?? ''));
            $credentials = json_decode($credentialsJson === '' ? '{}' : $credentialsJson, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($credentials)) {
                throw new InvalidArgumentException('Les paramètres du fournisseur doivent être un objet JSON.');
            }
            $this->payments->saveProvider($user, [
                'code' => $validated['code'],
                'enabled' => $validated['enabled'],
                'credentials' => $credentials,
            ]);
            $this->flash('Le fournisseur de paiement a été enregistré.', 'success');
        } catch (InvalidArgumentException | RuntimeException | \JsonException $exception) {
            $this->flash($exception->getMessage(), 'danger');
        }

        return Response::redirect('/dashboard/online-payments');
    }

    public function webhook(Request $request, string $providerCode): Response
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (isset($values[0])) {
                $headers[$name] = $values[0];
            }
        }

        try {
            $this->payments->handleWebhook($providerCode, $headers, $request->getContent());

            return new Response('', 204);
        } catch (InvalidArgumentException $exception) {
            return new Response('Notification de paiement invalide.', 400);
        } catch (RuntimeException $exception) {
            error_log('Online payment webhook deferred: ' . get_class($exception));

            return new Response('Notification temporairement non traitée. Le fournisseur doit réessayer.', 503);
        }
    }

    public function returnFromProvider(Request $request): Response
    {
        $attemptId = $request->query->getInt('attempt');
        $orderId = $this->payments->attemptOrderId($attemptId);
        if ($orderId === null) {
            throw new NotFoundHttpException();
        }

        return Response::redirect('/checkout/confirmation/' . rawurlencode($orderId));
    }

    public function retryReconciliation(Request $request): Response
    {
        $user = $this->currentUser();
        $this->register_csrf($this->session);
        $input = $request->request->all();
        try {
            $this->validatePost(
                data: $input,
                only: [self::TOKEN_NAME],
                rules: [self::TOKEN_NAME => 'required'],
            );
            $count = $this->payments->retryReconciliation($user);
            $this->flash("Le rapprochement a traité {$count} tentative(s) en attente.", 'success');
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $this->flash($exception->getMessage(), 'danger');
        }

        return Response::redirect('/dashboard/online-payments');
    }

    private function currentUser(): User
    {
        $userId = $this->session->getUserId();
        $user = $userId === null ? null : User::find($userId);
        if (!$user instanceof User || !$user->is_active()) {
            throw new NotFoundHttpException();
        }

        return $user;
    }

    private function authorize(User $user, string $permission): void
    {
        if (!$user->isSuper() && !$user->hasPermission($permission)) {
            throw new NotFoundHttpException();
        }
    }

    private function flash(string $message, string $variant): void
    {
        $this->session->addFlash('online_payment_message', $message);
        $this->session->addFlash('online_payment_variant', $variant);
    }
}
