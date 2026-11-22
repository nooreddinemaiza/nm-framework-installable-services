<?php

declare(strict_types=1);

namespace NM\Services\CashRegister\Controllers;

use App\Helpers\NavbarFactory;
use App\Models\User;
use NM\Services\CashRegister\Cash\CashRegisterService;
use NM\Services\CashRegister\Cash\CashServiceException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Security\CsrfTokenManager;
use Core\View\ViewFactory;
use InvalidArgumentException;

final class CashRegisterController extends Controller
{
    public function __construct(
        private readonly CashRegisterService $cash,
        private readonly ViewFactory $factory,
        private readonly SessionInterface $session,
    ) {
    }

    public function view(Request $request): Response
    {
        $user = $this->currentUser();
        $this->authorizeAny($user, [
            'cash.registers.view',
            'cash.sessions.open',
            'cash.sessions.close',
            'cash.payments.accept',
            'cash.payments.view',
            'cash.refunds.create',
            'cash.reports.view',
        ]);
        $csrf = new CsrfTokenManager($this->session, self::TOKEN_NAME);
        $data = $this->cash->dashboardData($user);
        $permissions = $user->effectivePermissions('string');
        $sessionId = max(0, $request->query->getInt('session_id'));
        $orderReference = strtoupper(trim($request->query->getString('order_reference')));
        $quote = null;
        $quoteError = '';
        if ($sessionId > 0 && $orderReference !== '' && $this->can($user, 'cash.payments.accept')) {
            try {
                $quote = $this->cash->cashierQuote($user, $sessionId, $orderReference);
            } catch (CashServiceException | InvalidArgumentException $exception) {
                $quoteError = $exception->getMessage();
            }
        }

        return new Response($this->factory->renderWithLayout('app', 'dashboard', [
            'title' => 'Service Caisse',
            'csrf_token' => $csrf->generateToken(),
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user, 'cash-registers'),
            'admin_permissions' => $permissions,
            'content_name' => 'dashboard.cash-registers',
            'activeNavTab' => 'cash-registers',
            'showHome' => true,
            'breadcrumbs' => [['label' => 'Ventes', 'url' => '/dashboard/sales'], ['label' => 'Caisses']],
            'cash_data' => $data,
            'cash_message' => $this->session->getFlashMessages('cash_message')[0] ?? '',
            'cash_variant' => $this->session->getFlashMessages('cash_variant')[0] ?? 'success',
            'cash_selected_session_id' => $sessionId,
            'cash_order_reference' => $orderReference,
            'cash_order_quote' => $quote,
            'cash_quote_error' => $quoteError,
            'cash_can_configure' => $this->can($user, 'cash.registers.update'),
            'cash_can_create' => $this->can($user, 'cash.registers.create'),
            'cash_can_assign' => $this->can($user, 'cashiers.manage'),
            'cash_can_open' => $this->can($user, 'cash.sessions.open'),
            'cash_can_close' => $this->can($user, 'cash.sessions.close'),
            'cash_can_move' => $this->can($user, 'cash.movements.entry')
                || $this->can($user, 'cash.movements.outflow'),
            'cash_can_entry' => $this->can($user, 'cash.movements.entry'),
            'cash_can_outflow' => $this->can($user, 'cash.movements.outflow'),
            'cash_can_manage_status' => $this->can($user, 'cash.registers.deactivate'),
            'cash_can_validate_variance' => $this->can($user, 'cash.sessions.variance.validate'),
            'cash_can_report' => $this->can($user, 'cash.reports.view'),
            'cash_can_pay' => $this->can($user, 'cash.payments.accept'),
            'cash_can_refund' => $this->can($user, 'cash.refunds.create'),
            'cash_can_view_receipt' => $this->can($user, 'cash.payments.view'),
        ]));
    }

    public function receipt(Request $request): Response
    {
        $user = $this->currentUser();
        $receiptId = $request->query->getInt('id');
        if ($receiptId < 1) {
            throw new NotFoundHttpException();
        }
        $receipt = $this->cash->receipt($user, $receiptId);

        return new Response($this->factory->renderWithLayout('app', 'dashboard', [
            'title' => 'Reçu ' . $receipt['receipt_number'],
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user, 'cash-registers'),
            'admin_permissions' => $user->effectivePermissions('string'),
            'content_name' => 'dashboard.cash-receipt',
            'activeNavTab' => 'cash-registers',
            'showHome' => true,
            'breadcrumbs' => [['label' => 'Caisses', 'url' => '/dashboard/cash'], ['label' => 'Reçu']],
            'cash_receipt' => $receipt,
            'cash_configuration' => $this->cash->configuration(),
            'cash_timezone' => $this->cash->configuration()['timezone'],
        ]));
    }

    public function saveConfiguration(Request $request): Response
    {
        return $this->perform($request, function (User $user, array $input): void {
            $this->cash->saveConfiguration($user, $input);
        }, 'La configuration générale a été enregistrée.');
    }

    public function saveRegister(Request $request): Response
    {
        return $this->perform($request, function (User $user, array $input): void {
            $this->cash->saveRegister($user, $input);
        }, 'La caisse a été enregistrée.');
    }

    public function setRegisterStatus(Request $request): Response
    {
        return $this->perform($request, function (User $user, array $input): void {
            $this->cash->setRegisterStatus(
                $user,
                (int) ($input['register_id'] ?? 0),
                (string) ($input['status'] ?? '')
            );
        }, 'Le statut de la caisse a été modifié.');
    }

    public function openSession(Request $request): Response
    {
        return $this->perform($request, function (User $user, array $input): void {
            $this->cash->openSession(
                $user,
                (int) ($input['register_id'] ?? 0),
                $input['opening_float'] ?? ''
            );
        }, 'La session de caisse est ouverte.');
    }

    public function recordMovement(Request $request): Response
    {
        return $this->perform($request, function (User $user, array $input): void {
            $this->cash->recordMovement(
                $user,
                (int) ($input['session_id'] ?? 0),
                (string) ($input['movement_type'] ?? ''),
                $input['amount'] ?? '',
                $input['reason'] ?? '',
                $input['idempotency_key'] ?? null
            );
        }, 'Le mouvement de fonds a été enregistré.');
    }

    public function recordSalePayment(Request $request): Response
    {
        $user = $this->currentUser();
        $this->register_csrf($this->session);
        $input = $request->request->all();
        try {
            $validated = $this->validatePost(
                data: $input,
                only: array_keys($input),
                rules: [self::TOKEN_NAME => 'required'],
            )->toArray();
            $receiptId = $this->cash->recordSalePayment(
                $user,
                (int) ($validated['session_id'] ?? 0),
                (string) ($validated['order_reference'] ?? ''),
                $validated['amount'] ?? '',
                $validated['cash_received'] ?? '',
                $validated['idempotency_key'] ?? null,
            );
            $this->flash('Le paiement a été enregistré et le reçu est disponible.', 'success');

            return Response::redirect('/dashboard/cash/receipt?id=' . $receiptId);
        } catch (InvalidArgumentException | CashServiceException $exception) {
            $this->flash($exception->getMessage(), 'danger');

            return Response::redirect('/dashboard/cash?session_id='
                . max(0, (int) ($input['session_id'] ?? 0))
                . '&order_reference=' . rawurlencode((string) ($input['order_reference'] ?? '')));
        }
    }

    public function refundSalePayment(Request $request): Response
    {
        $user = $this->currentUser();
        $this->register_csrf($this->session);
        $input = $request->request->all();
        try {
            $validated = $this->validatePost(
                data: $input,
                only: array_keys($input),
                rules: [self::TOKEN_NAME => 'required'],
            )->toArray();
            $receiptId = $this->cash->refundSalePayment(
                $user,
                (int) ($validated['session_id'] ?? 0),
                (string) ($validated['original_receipt_number'] ?? ''),
                $validated['amount'] ?? '',
                $validated['reason'] ?? '',
                $validated['idempotency_key'] ?? null,
            );
            $this->flash('Le remboursement est enregistré avec sa pièce de traçabilité.', 'success');

            return Response::redirect('/dashboard/cash/receipt?id=' . $receiptId);
        } catch (InvalidArgumentException | CashServiceException $exception) {
            $this->flash($exception->getMessage(), 'danger');

            return Response::redirect('/dashboard/cash?session_id='
                . max(0, (int) ($input['session_id'] ?? 0)));
        }
    }

    public function closeSession(Request $request): Response
    {
        return $this->perform($request, function (User $user, array $input): void {
            $this->cash->closeSession(
                $user,
                (int) ($input['session_id'] ?? 0),
                $input['counted_amount'] ?? '',
                $input['variance_reason'] ?? ''
            );
        }, 'La session de caisse a été clôturée.');
    }

    /**
     * @param callable(User, array<string, mixed>): void $action
     */
    private function perform(Request $request, callable $action, string $success): Response
    {
        $user = $this->currentUser();
        $this->register_csrf($this->session);
        try {
            $input = $request->request->all();
            $validated = $this->validatePost(
                data: $input,
                only: array_keys($input),
                rules: [self::TOKEN_NAME => 'required'],
            );
            $action($user, $validated->toArray());
            $this->flash($success, 'success');
        } catch (InvalidArgumentException | CashServiceException $exception) {
            $this->flash($exception->getMessage(), 'danger');
        }

        return Response::redirect('/dashboard/cash');
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

    /**
     * @param list<string> $permissions
     */
    private function authorizeAny(User $user, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission)) {
                return;
            }
        }

        throw new NotFoundHttpException();
    }

    private function can(User $user, string $permission): bool
    {
        return $user->isSuper() || $user->hasPermission($permission);
    }

    private function flash(string $message, string $variant): void
    {
        $this->session->addFlash('cash_message', $message);
        $this->session->addFlash('cash_variant', $variant);
    }
}
