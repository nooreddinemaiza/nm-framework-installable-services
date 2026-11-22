<?php

declare(strict_types=1);

namespace NM\Services\CashRegister\Cash;

use App\Models\Activity;
use App\Models\Setting;
use App\Models\User;
use Core\Database\Connection;
use NM\Services\CashRegister\Models\CashMovementRecord;
use NM\Services\CashRegister\Models\CashReceiptRecord;
use NM\Services\CashRegister\Models\CashRegisterRecord;
use NM\Services\CashRegister\Models\CashSessionRecord;
use NM\Services\SalesService\Sales\SalesAuthorizationException;
use NM\Services\SalesService\Sales\SalesCartConflictException;
use NM\Services\SalesService\Sales\SalesConsumerUnavailableException;
use NM\Services\SalesService\Sales\SalesOrderFinancialService;
use NM\Services\SalesService\Sales\SalesPaymentException;
use InvalidArgumentException;
use PDO;

final class CashRegisterService
{
    private const CONFIG_KEY = 'cash.service.configuration';
    private const MAX_AMOUNT_MINOR = 9_000_000_000_000_000;

    public function __construct(
        private readonly CashServiceState $serviceState,
        private readonly Connection $connection,
        private readonly SalesOrderFinancialService $salesPayments,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $defaults = [
            'service_name' => 'Service Caisse',
            'store_name' => 'Commerce',
            'business_reference' => '',
            'currency' => 'MAD',
            'timezone' => 'Africa/Casablanca',
            'planned_register_count' => 1,
            'max_register_count' => 100,
            'sessions_enabled' => true,
            'require_session' => true,
            'require_opening_float' => true,
            'allow_zero_opening_float' => true,
            'require_physical_count' => true,
            'allow_cash_variance' => false,
            'variance_manager_approval' => true,
            'variance_approval_threshold_minor' => 0,
            'allow_other_user_close' => false,
            'require_variance_reason' => true,
            'allow_partial_payments' => false,
            'allow_split_payments' => false,
            'auto_print_receipts' => false,
            'receipt_number_format' => 'REC-{number:6}',
            'operation_number_format' => 'OP-{number:6}',
        ];
        $setting = Setting::where('key', '=', self::CONFIG_KEY)->first();
        if ($setting === null) {
            return $defaults;
        }

        $saved = json_decode((string) $setting->value, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($saved)) {
            throw new CashServiceException('La configuration du Service Caisse est invalide.');
        }

        return array_replace($defaults, $saved);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveConfiguration(User $actor, array $input): array
    {
        $this->authorize($actor, 'cash.registers.update');
        $configuration = [
            'service_name' => $this->requiredText($input['service_name'] ?? null, 'Nom du service', 120),
            'store_name' => $this->requiredText($input['store_name'] ?? null, 'Nom du commerce', 160),
            'business_reference' => $this->optionalText($input['business_reference'] ?? '', 80),
            'currency' => $this->currency($input['currency'] ?? null),
            'timezone' => $this->timezone($input['timezone'] ?? null),
            'planned_register_count' => $this->integer($input['planned_register_count'] ?? null, 1, 1000, 'Nombre de caisses prévu'),
            'max_register_count' => $this->integer($input['max_register_count'] ?? null, 1, 1000, 'Nombre maximal de caisses'),
            'sessions_enabled' => $this->boolean($input['sessions_enabled'] ?? null),
            'require_session' => $this->boolean($input['require_session'] ?? null),
            'require_opening_float' => $this->boolean($input['require_opening_float'] ?? null),
            'allow_zero_opening_float' => $this->boolean($input['allow_zero_opening_float'] ?? null),
            'require_physical_count' => $this->boolean($input['require_physical_count'] ?? null),
            'allow_cash_variance' => $this->boolean($input['allow_cash_variance'] ?? null),
            'variance_manager_approval' => $this->boolean($input['variance_manager_approval'] ?? null),
            'variance_approval_threshold_minor' => self::parseAmount($input['variance_approval_threshold'] ?? '0'),
            'allow_other_user_close' => $this->boolean($input['allow_other_user_close'] ?? null),
            'require_variance_reason' => $this->boolean($input['require_variance_reason'] ?? null),
            'allow_partial_payments' => $this->boolean($input['allow_partial_payments'] ?? null),
            'allow_split_payments' => $this->boolean($input['allow_split_payments'] ?? '0'),
            'auto_print_receipts' => $this->boolean($input['auto_print_receipts'] ?? '0'),
            'receipt_number_format' => $this->numberFormat($input['receipt_number_format'] ?? null),
            'operation_number_format' => $this->numberFormat($input['operation_number_format'] ?? null),
        ];
        if ($configuration['allow_split_payments']) {
            throw new InvalidArgumentException('Les paiements combinés nécessitent un moyen externe confirmé, indisponible actuellement.');
        }
        if ($configuration['auto_print_receipts']) {
            throw new InvalidArgumentException('L’impression automatique n’est pas encore prise en charge par l’interface.');
        }
        if ($configuration['planned_register_count'] > $configuration['max_register_count']) {
            throw new InvalidArgumentException('Le nombre prévu dépasse le maximum autorisé.');
        }

        $json = json_encode($configuration, JSON_THROW_ON_ERROR);
        Setting::query()->transaction(function () use ($actor, $json): void {
            $setting = Setting::where('key', '=', self::CONFIG_KEY)->first();
            if ($setting === null) {
                Setting::create(['key' => self::CONFIG_KEY, 'value' => $json, 'type' => 'json']);
            } else {
                $setting->update(['value' => $json, 'type' => 'json']);
            }
            Activity::record('cash.configuration.updated', $json, (int) $actor->getAuthIdentifier());
        });

        return $configuration;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveRegister(User $actor, array $input): int
    {
        $id = isset($input['id']) && $input['id'] !== '' ? $this->integer($input['id'], 1, PHP_INT_MAX, 'Identifiant') : null;
        $this->authorize($actor, $id === null ? 'cash.registers.create' : 'cash.registers.update');
        if (array_key_exists('allowed_user_ids', $input)) {
            $this->authorize($actor, 'cashiers.manage');
        }

        $code = strtoupper($this->requiredText($input['code'] ?? null, 'Référence de caisse', 40));
        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/D', $code) !== 1) {
            throw new InvalidArgumentException('La référence doit contenir uniquement des lettres, chiffres, points, tirets ou underscores.');
        }
        $existing = CashRegisterRecord::where('code', '=', $code)->first();
        if ($existing !== null && ($id === null || (int) $existing->id !== $id)) {
            throw new InvalidArgumentException('Cette référence de caisse est déjà utilisée.');
        }
        $registerToUpdate = $id === null ? null : CashRegisterRecord::find($id);
        if ($id !== null && $registerToUpdate === null) {
            throw new InvalidArgumentException('La caisse demandée est introuvable.');
        }

        $currency = $this->currency($input['currency'] ?? $this->configuration()['currency']);
        $status = (string) ($input['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive', 'maintenance'], true)) {
            throw new InvalidArgumentException('Le statut de la caisse est invalide.');
        }
        if (array_key_exists('allowed_user_ids', $input)) {
            $allowedUsers = $this->allowedUsers($input['allowed_user_ids']);
        } elseif ($registerToUpdate !== null) {
            $allowedUsers = $this->decodeList((string) $registerToUpdate->allowed_user_ids_json);
        } else {
            $allowedUsers = [(int) $actor->getAuthIdentifier()];
        }
        if ($allowedUsers === []) {
            throw new InvalidArgumentException('Affectez au moins un caissier autorisé à cette caisse.');
        }
        $attributes = [
            'code' => $code,
            'name' => $this->requiredText($input['name'] ?? null, 'Nom de caisse', 120),
            'description' => $this->optionalText($input['description'] ?? '', 4000),
            'location' => $this->optionalText($input['location'] ?? '', 120),
            'register_type' => 'physical',
            'currency' => $currency,
            'suggested_opening_minor' => self::parseAmount($input['suggested_opening'] ?? '0'),
            'status' => $status,
            'allowed_user_ids_json' => json_encode($allowedUsers, JSON_THROW_ON_ERROR),
            'allowed_payment_methods_json' => json_encode(['cash'], JSON_THROW_ON_ERROR),
            'allow_refunds' => $this->boolean($input['allow_refunds'] ?? ($registerToUpdate->allow_refunds ?? '0')),
            'allow_cash_outflows' => $this->boolean($input['allow_cash_outflows'] ?? null),
            'printer_configuration_json' => null,
        ];

        if ($id === null) {
            $config = $this->configuration();
            $attributes['created_by'] = (int) $actor->getAuthIdentifier();
            return CashRegisterRecord::query()->transaction(function () use ($actor, $attributes, $code, $config): int {
                if (CashRegisterRecord::query()->count() >= $config['max_register_count']) {
                    throw new CashServiceException('Le nombre maximal de caisses autorisé est atteint.');
                }
                $register = CashRegisterRecord::create($attributes);
                Activity::record(
                    'cash.register.created',
                    json_encode(['register_id' => (int) $register->id, 'code' => $code], JSON_THROW_ON_ERROR),
                    (int) $actor->getAuthIdentifier()
                );

                return (int) $register->id;
            });
        }

        return CashRegisterRecord::query()->transaction(function () use ($actor, $attributes, $code, $id, $currency, $status): int {
            $register = $this->lockRegister($id);
            if ($register === null) {
                throw new InvalidArgumentException('La caisse demandée est introuvable.');
            }
            if ($register->status !== $status) {
                $this->authorize($actor, 'cash.registers.deactivate');
                if ($status !== 'active'
                    && CashSessionRecord::where('active_register_id', '=', $id)->first() !== null) {
                    throw new CashServiceException('Fermez la session active avant de désactiver ou mettre en maintenance cette caisse.');
                }
            }
            if ($register->currency !== $currency
                && CashSessionRecord::where('active_register_id', '=', $id)->first() !== null) {
                throw new CashServiceException('La devise ne peut pas être modifiée pendant une session active.');
            }
            $activeSession = CashSessionRecord::where('active_register_id', '=', $id)->first();
            if ($activeSession !== null
                && !in_array((int) $activeSession->opened_by, $allowedUsers, true)) {
                throw new CashServiceException('Le caissier responsable doit rester affecté jusqu’à la fermeture de sa session.');
            }
            $register->update($attributes);
            Activity::record(
                'cash.register.updated',
                json_encode(['register_id' => $id, 'code' => $code], JSON_THROW_ON_ERROR),
                (int) $actor->getAuthIdentifier()
            );

            return $id;
        });
    }

    public function setRegisterStatus(User $actor, int $registerId, string $status): void
    {
        $this->authorize($actor, 'cash.registers.deactivate');
        if (!in_array($status, ['active', 'inactive', 'maintenance'], true)) {
            throw new InvalidArgumentException('Le statut de la caisse est invalide.');
        }
        CashRegisterRecord::query()->transaction(function () use ($actor, $registerId, $status): void {
            $register = $this->lockRegister($registerId);
            if ($register === null) {
                throw new InvalidArgumentException('La caisse demandée est introuvable.');
            }
            if ($status !== 'active'
                && CashSessionRecord::where('active_register_id', '=', $registerId)->first() !== null) {
                throw new CashServiceException('Fermez la session active avant de désactiver ou mettre en maintenance cette caisse.');
            }
            $register->update(['status' => $status]);
            Activity::record(
                'cash.register.status_changed',
                json_encode(['register_id' => $registerId, 'status' => $status], JSON_THROW_ON_ERROR),
                (int) $actor->getAuthIdentifier()
            );
        });
    }

    public function openSession(User $actor, int $registerId, mixed $openingFloat): int
    {
        $this->authorize($actor, 'cash.sessions.open');
        $config = $this->configuration();
        if (!$config['sessions_enabled']) {
            throw new CashServiceException('La gestion des sessions est désactivée.');
        }
        $register = CashRegisterRecord::find($registerId);
        if ($register === null || $register->status !== 'active') {
            throw new CashServiceException('La caisse est indisponible.');
        }
        $this->assertRegisterUser($register, $actor);
        if ($config['require_opening_float'] && ($openingFloat === '' || $openingFloat === null)) {
            throw new InvalidArgumentException('Le fond de caisse à l’ouverture est obligatoire.');
        }
        $amount = $openingFloat === '' || $openingFloat === null ? 0 : self::parseAmount($openingFloat);
        if (!$config['allow_zero_opening_float'] && $amount === 0) {
            throw new InvalidArgumentException('Le fond de caisse ne peut pas être égal à zéro.');
        }
        if ($amount > 0 && !$config['require_opening_float'] && $openingFloat === '') {
            throw new InvalidArgumentException('Le montant du fond de caisse est invalide.');
        }

        return CashSessionRecord::query()->transaction(function () use (
            $actor,
            $registerId,
            $amount
        ): int {
            $currentRegister = $this->lockRegister($registerId);
            if ($currentRegister === null || $currentRegister->status !== 'active') {
                throw new CashServiceException('La caisse est indisponible.');
            }
            $this->assertRegisterUser($currentRegister, $actor);
            if (CashSessionRecord::where('active_register_id', '=', $registerId)->first() !== null) {
                throw new CashServiceException('Une session est déjà ouverte sur cette caisse.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $session = CashSessionRecord::create([
                'register_id' => $registerId,
                'active_register_id' => $registerId,
                'register_code_snapshot' => (string) $currentRegister->code,
                'register_name_snapshot' => (string) $currentRegister->name,
                'currency' => (string) $currentRegister->currency,
                'opened_by' => (int) $actor->getAuthIdentifier(),
                'closed_by' => null,
                'variance_approved_by' => null,
                'status' => 'open',
                'opening_float_minor' => $amount,
                'expected_cash_minor' => null,
                'counted_cash_minor' => null,
                'variance_minor' => null,
                'variance_reason' => null,
                'opened_at' => $now,
                'closed_at' => null,
            ]);
            Activity::record(
                'cash.session.opened',
                json_encode(['session_id' => (int) $session->id, 'register_id' => $registerId], JSON_THROW_ON_ERROR),
                (int) $actor->getAuthIdentifier()
            );

            return (int) $session->id;
        });
    }

    public function recordMovement(
        User $actor,
        int $sessionId,
        string $type,
        mixed $amountInput,
        mixed $reasonInput,
        mixed $idempotencyKey
    ): int {
        $permission = $type === 'entry' ? 'cash.movements.entry' : 'cash.movements.outflow';
        $this->authorize($actor, $permission);
        if (!in_array($type, ['entry', 'outflow'], true)) {
            throw new InvalidArgumentException('Le type de mouvement est invalide.');
        }
        $session = CashSessionRecord::find($sessionId);
        if ($session === null || $session->status !== 'open') {
            throw new CashServiceException('La session est inexistante ou déjà fermée.');
        }
        $register = CashRegisterRecord::find((int) $session->register_id);
        if ($register === null) {
            throw new CashServiceException('La caisse de cette session est introuvable.');
        }
        $this->assertRegisterUser($register, $actor);
        if ($type === 'outflow' && !$register->allow_cash_outflows) {
            throw new CashServiceException('Les sorties de fonds sont désactivées pour cette caisse.');
        }
        $amount = self::parseAmount($amountInput);
        if ($amount < 1) {
            throw new InvalidArgumentException('Le montant du mouvement doit être supérieur à zéro.');
        }
        $reason = $this->requiredText($reasonInput, 'Motif', 1000);
        if (!is_string($idempotencyKey) || preg_match('/^[A-Za-z0-9._:-]{16,190}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('La clé de soumission du mouvement est invalide.');
        }

        return CashMovementRecord::query()->transaction(function () use (
            $actor,
            $sessionId,
            $type,
            $amount,
            $reason,
            $idempotencyKey
        ): int {
            $currentSession = $this->lockSession($sessionId);
            if ($currentSession === null || $currentSession->status !== 'open') {
                throw new CashServiceException('La session a été clôturée entre-temps.');
            }
            $currentRegister = CashRegisterRecord::find((int) $currentSession->register_id);
            if ($currentRegister === null) {
                throw new CashServiceException('La caisse de cette session est introuvable.');
            }
            $this->assertRegisterUser($currentRegister, $actor);
            if ($type === 'outflow' && !$currentRegister->allow_cash_outflows) {
                throw new CashServiceException('Les sorties de fonds sont désactivées pour cette caisse.');
            }
            $existing = CashMovementRecord::where('idempotency_key', '=', $idempotencyKey)->first();
            if ($existing !== null) {
                if ((int) $existing->session_id !== $sessionId
                    || $existing->movement_type !== $type
                    || (int) $existing->amount_minor !== $amount
                    || $existing->reason !== $reason
                    || (int) $existing->actor_user_id !== (int) $actor->getAuthIdentifier()) {
                    throw new CashServiceException('La clé de soumission a déjà été utilisée pour un autre mouvement.');
                }

                return (int) $existing->id;
            }
            if ($type === 'outflow' && $amount > $this->expectedCash($currentSession)) {
                throw new CashServiceException('La sortie dépasse le montant théorique disponible dans la caisse.');
            }

            $movement = CashMovementRecord::create([
                'session_id' => $sessionId,
                'movement_type' => $type,
                'amount_minor' => $amount,
                'reason' => $reason,
                'actor_user_id' => (int) $actor->getAuthIdentifier(),
                'idempotency_key' => $idempotencyKey,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            Activity::record(
                'cash.movement.' . $type,
                json_encode(['movement_id' => (int) $movement->id, 'session_id' => $sessionId, 'amount_minor' => $amount], JSON_THROW_ON_ERROR),
                (int) $actor->getAuthIdentifier()
            );

            return (int) $movement->id;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function cashierQuote(User $actor, int $sessionId, string $orderReference): array
    {
        $this->authorize($actor, 'cash.payments.accept');
        [$session, $register] = $this->openPaymentSession($actor, $sessionId);
        $quote = $this->callSalesPayment(
            fn(): array => $this->salesPayments->cashierQuote($actor, strtoupper(trim($orderReference)))
        );
        if ($quote['currency'] !== $session->currency || $register->currency !== $session->currency) {
            throw new CashServiceException('La devise de la commande ne correspond pas à celle de la session.');
        }

        return $quote;
    }

    public function recordSalePayment(
        User $actor,
        int $sessionId,
        string $orderReference,
        mixed $amountInput,
        mixed $cashReceivedInput,
        mixed $idempotencyKey,
    ): int {
        $this->authorize($actor, 'cash.payments.accept');
        $orderReference = strtoupper(trim($orderReference));
        $amount = self::parseAmount($amountInput);
        $cashReceived = self::parseAmount($cashReceivedInput);
        $this->validateIdempotencyKey($idempotencyKey);
        if ($amount < 1 || $cashReceived < $amount) {
            throw new InvalidArgumentException('Le montant reçu doit couvrir le montant encaissé.');
        }

        return CashMovementRecord::query()->transaction(function () use (
            $actor,
            $sessionId,
            $orderReference,
            $amount,
            $cashReceived,
            $idempotencyKey
        ): int {
            if (!$this->configuration()['sessions_enabled']) {
                throw new CashServiceException('Les encaissements nécessitent une session de caisse active.');
            }
            $session = $this->lockSession($sessionId);
            if ($session === null || $session->status !== 'open') {
                throw new CashServiceException('La session a été clôturée entre-temps.');
            }
            $register = CashRegisterRecord::find((int) $session->register_id);
            if ($register === null || $register->status !== 'active') {
                throw new CashServiceException('La caisse est indisponible.');
            }
            $this->assertRegisterUser($register, $actor);
            if (!in_array('cash', $this->decodeStringList((string) $register->allowed_payment_methods_json), true)) {
                throw new CashServiceException('Le paiement en espèces n’est pas autorisé sur cette caisse.');
            }

            $existing = CashMovementRecord::where('idempotency_key', '=', $idempotencyKey)->first();
            if ($existing !== null) {
                $receipt = $this->receiptForPayment((string) $existing->sales_payment_id);
                if ((int) $existing->session_id !== $sessionId
                    || $existing->movement_type !== 'sale_payment'
                    || (int) $existing->amount_minor !== $amount
                    || $receipt->order_reference !== $orderReference
                    || (int) $existing->actor_user_id !== (int) $actor->getAuthIdentifier()) {
                    throw new CashServiceException('La clé de paiement a déjà été utilisée pour une autre opération.');
                }
                if ((int) $receipt->cash_received_minor !== $cashReceived) {
                    throw new CashServiceException('La clé de paiement a déjà été utilisée avec un autre montant reçu.');
                }

                return (int) $receipt->id;
            }

            $quote = $this->callSalesPayment(
                fn(): array => $this->salesPayments->cashierQuote($actor, $orderReference)
            );
            if ($quote['currency'] !== $session->currency) {
                throw new CashServiceException('La devise de la commande ne correspond pas à celle de la session.');
            }
            if ($amount > $quote['balance_due_minor']) {
                throw new CashServiceException('Le montant dépasse le solde restant de la commande.');
            }
            if (!$this->configuration()['allow_partial_payments']
                && $amount !== $quote['balance_due_minor']) {
                throw new CashServiceException('Le règlement doit couvrir le solde total de la commande.');
            }
            $expected = $this->expectedCash($session);
            if ($amount > self::MAX_AMOUNT_MINOR - $expected) {
                throw new CashServiceException('Le solde théorique dépasse la capacité de calcul autorisée.');
            }

            $payment = $this->callSalesPayment(fn(): array => $this->salesPayments->recordCashPayment(
                $actor,
                $orderReference,
                $amount,
                (string) $idempotencyKey,
                (bool) $this->configuration()['allow_partial_payments'],
            ));
            $reason = 'Encaissement ' . $orderReference;
            CashMovementRecord::create([
                'session_id' => $sessionId,
                'movement_type' => 'sale_payment',
                'amount_minor' => $amount,
                'reason' => $reason,
                'actor_user_id' => (int) $actor->getAuthIdentifier(),
                'idempotency_key' => $idempotencyKey,
                'sales_order_id' => $payment['order_id'],
                'sales_payment_id' => $payment['payment_id'],
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $receipt = $this->createReceipt(
                $actor,
                $session,
                $register,
                'payment',
                $payment,
                $amount,
                $cashReceived,
                $cashReceived - $amount,
                null,
            );
            Activity::record(
                'cash.payment.recorded',
                json_encode([
                    'receipt_id' => (int) $receipt->id,
                    'session_id' => $sessionId,
                    'order_reference' => $orderReference,
                    'amount_minor' => $amount,
                ], JSON_THROW_ON_ERROR),
                (int) $actor->getAuthIdentifier()
            );

            return (int) $receipt->id;
        });
    }

    public function refundSalePayment(
        User $actor,
        int $sessionId,
        string $originalReceiptNumber,
        mixed $amountInput,
        mixed $reasonInput,
        mixed $idempotencyKey,
    ): int {
        $this->authorize($actor, 'cash.refunds.create');
        $amount = self::parseAmount($amountInput);
        $reason = $this->requiredText($reasonInput, 'Motif du remboursement', 500);
        $this->validateIdempotencyKey($idempotencyKey);
        if ($amount < 1) {
            throw new InvalidArgumentException('Le montant du remboursement doit être supérieur à zéro.');
        }

        return CashMovementRecord::query()->transaction(function () use (
            $actor,
            $sessionId,
            $originalReceiptNumber,
            $amount,
            $reason,
            $idempotencyKey
        ): int {
            if (!$this->configuration()['sessions_enabled']) {
                throw new CashServiceException('Les remboursements nécessitent une session de caisse active.');
            }
            $session = $this->lockSession($sessionId);
            if ($session === null || $session->status !== 'open') {
                throw new CashServiceException('La session a été clôturée entre-temps.');
            }
            $register = CashRegisterRecord::find((int) $session->register_id);
            if ($register === null || $register->status !== 'active') {
                throw new CashServiceException('La caisse est indisponible.');
            }
            $this->assertRegisterUser($register, $actor);
            if (!$register->allow_refunds) {
                throw new CashServiceException('Les remboursements sont désactivés pour cette caisse.');
            }

            $originalReceipt = CashReceiptRecord::where('receipt_number', '=', trim($originalReceiptNumber))->first();
            if ($originalReceipt === null || $originalReceipt->receipt_type !== 'payment'
                || $originalReceipt->payment_method !== 'cash') {
                throw new CashServiceException('Le reçu d’origine est introuvable ou ne correspond pas à un paiement espèces.');
            }
            if ($originalReceipt->currency !== $session->currency) {
                throw new CashServiceException('Le remboursement doit être effectué dans la devise du paiement d’origine.');
            }

            $existing = CashMovementRecord::where('idempotency_key', '=', $idempotencyKey)->first();
            if ($existing !== null) {
                if ((int) $existing->session_id !== $sessionId
                    || $existing->movement_type !== 'sale_refund'
                    || (int) $existing->amount_minor !== $amount
                    || $existing->reason !== $reason
                    || (int) $existing->actor_user_id !== (int) $actor->getAuthIdentifier()) {
                    throw new CashServiceException('La clé de remboursement a déjà été utilisée pour une autre opération.');
                }
                $refundReceipt = $this->receiptForPayment((string) $existing->sales_payment_id);
                if ((string) $refundReceipt->related_sales_payment_id !== (string) $originalReceipt->sales_payment_id) {
                    throw new CashServiceException('La clé de remboursement a déjà été utilisée pour un autre paiement.');
                }

                return (int) $refundReceipt->id;
            }

            if ($amount > $this->expectedCash($session)) {
                throw new CashServiceException('Le remboursement dépasse le montant théorique disponible dans la caisse.');
            }
            $result = $this->callSalesPayment(fn(): array => $this->salesPayments->refundCashPayment(
                $actor,
                (int) $originalReceipt->sales_payment_id,
                $amount,
                (string) $idempotencyKey,
                $reason,
            ));
            CashMovementRecord::create([
                'session_id' => $sessionId,
                'movement_type' => 'sale_refund',
                'amount_minor' => $amount,
                'reason' => $reason,
                'actor_user_id' => (int) $actor->getAuthIdentifier(),
                'idempotency_key' => $idempotencyKey,
                'sales_order_id' => $result['order_id'],
                'sales_payment_id' => $result['payment_id'],
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $receipt = $this->createReceipt(
                $actor,
                $session,
                $register,
                'refund',
                $result,
                $amount,
                null,
                0,
                $reason,
            );
            Activity::record(
                'cash.payment.refunded',
                json_encode([
                    'receipt_id' => (int) $receipt->id,
                    'session_id' => $sessionId,
                    'order_reference' => $result['reference'],
                    'amount_minor' => $amount,
                ], JSON_THROW_ON_ERROR),
                (int) $actor->getAuthIdentifier()
            );

            return (int) $receipt->id;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function receipt(User $actor, int $receiptId): array
    {
        if (!$actor->is_active()) {
            throw new CashServiceException('Vous ne disposez pas de la permission nécessaire.');
        }
        $this->serviceState->assertReady();
        $receipt = CashReceiptRecord::find($receiptId);
        if ($receipt === null) {
            throw new CashServiceException('Le reçu demandé est introuvable.');
        }
        $canViewAll = $actor->isSuper()
            || $actor->hasPermission('cash.payments.view')
            || $actor->hasPermission('cash.reports.view');
        $canViewOwn = (int) $receipt->actor_user_id === (int) $actor->getAuthIdentifier()
            && ($actor->hasPermission('cash.payments.accept') || $actor->hasPermission('cash.refunds.create'));
        if (!$canViewAll && !$canViewOwn) {
            throw new CashServiceException('Vous n’êtes pas autorisé à consulter ce reçu.');
        }
        $register = CashRegisterRecord::find((int) $receipt->register_id);
        if ($register === null) {
            throw new CashServiceException('La caisse associée au reçu est introuvable.');
        }
        if (!$canViewAll
            && !in_array((int) $actor->getAuthIdentifier(), $this->decodeList((string) $register->allowed_user_ids_json), true)) {
            throw new CashServiceException('Vous n’êtes pas autorisé à consulter ce reçu.');
        }

        return $receipt->toArray();
    }

    public function closeSession(
        User $actor,
        int $sessionId,
        mixed $countedAmountInput,
        mixed $varianceReasonInput
    ): void {
        $this->authorize($actor, 'cash.sessions.close');
        $session = CashSessionRecord::find($sessionId);
        if ($session === null || $session->status !== 'open') {
            throw new CashServiceException('La session est inexistante ou déjà fermée.');
        }
        $config = $this->configuration();
        if (!$config['allow_other_user_close'] && (int) $session->opened_by !== (int) $actor->getAuthIdentifier()) {
            throw new CashServiceException('Seul le caissier ayant ouvert la session peut la fermer.');
        }
        $register = CashRegisterRecord::find((int) $session->register_id);
        if ($register === null) {
            throw new CashServiceException('La caisse de cette session est introuvable.');
        }
        $this->assertRegisterUser($register, $actor);

        $counted = $countedAmountInput === '' || $countedAmountInput === null
            ? null
            : self::parseAmount($countedAmountInput);
        if ($config['require_physical_count'] && $counted === null) {
            throw new InvalidArgumentException('Le comptage physique à la fermeture est obligatoire.');
        }

        CashSessionRecord::query()->transaction(function () use (
            $actor,
            $session,
            $counted,
            $varianceReasonInput,
            $config
        ): void {
            $lockedSession = $this->lockSession((int) $session->id);
            if ($lockedSession === null || $lockedSession->status !== 'open') {
                throw new CashServiceException('La session a été clôturée entre-temps. Actualisez la page.');
            }
            if (!$config['allow_other_user_close']
                && (int) $lockedSession->opened_by !== (int) $actor->getAuthIdentifier()) {
                throw new CashServiceException('Seul le caissier ayant ouvert la session peut la fermer.');
            }
            $expected = $this->expectedCash($lockedSession);
            $variance = $counted === null ? null : $counted - $expected;
            $varianceReason = $variance === null || $variance === 0
                ? null
                : $this->optionalText($varianceReasonInput, 1000);
            if ($variance !== null && $variance !== 0) {
                if (!$config['allow_cash_variance']) {
                    throw new CashServiceException('La configuration interdit la fermeture avec un écart de caisse.');
                }
                if ($config['require_variance_reason'] && $varianceReason === '') {
                    throw new InvalidArgumentException('Une justification est obligatoire en cas d’écart.');
                }
            }
            $requiresApproval = $variance !== null
                && $variance !== 0
                && $config['variance_manager_approval']
                && abs($variance) >= $config['variance_approval_threshold_minor'];
            $approvedBy = null;
            if ($requiresApproval) {
                $this->authorize($actor, 'cash.sessions.variance.validate');
                if ((int) $lockedSession->opened_by === (int) $actor->getAuthIdentifier()) {
                    throw new CashServiceException('Un responsable différent du caissier doit valider cet écart.');
                }
                $approvedBy = (int) $actor->getAuthIdentifier();
            }
            $now = gmdate('Y-m-d H:i:s');
            $updated = CashSessionRecord::where('id', '=', (int) $lockedSession->id)
                ->where('status', '=', 'open')
                ->update([
                    'active_register_id' => null,
                    'status' => 'closed',
                    'closed_by' => (int) $actor->getAuthIdentifier(),
                    'variance_approved_by' => $approvedBy,
                    'expected_cash_minor' => $expected,
                    'counted_cash_minor' => $counted,
                    'variance_minor' => $variance,
                    'variance_reason' => $varianceReason,
                    'closed_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new CashServiceException('La session a été clôturée entre-temps. Actualisez la page.');
            }
            Activity::record(
                'cash.session.closed',
                json_encode(['session_id' => (int) $lockedSession->id, 'expected_minor' => $expected, 'variance_minor' => $variance], JSON_THROW_ON_ERROR),
                (int) $actor->getAuthIdentifier()
            );
        });
    }

    /**
     * @return array{registers: list<array<string, mixed>>, sessions: list<array<string, mixed>>, configuration: array<string, mixed>, users: list<array{id: int, name: string, username: string}>}
     */
    public function dashboardData(User $actor): array
    {
        $registers = [];
        $canViewAll = $actor->isSuper()
            || $actor->hasPermission('cash.registers.view')
            || $actor->hasPermission('cash.reports.view');
        $registerModels = CashRegisterRecord::query()->orderBy('name')->get();
        if (!$canViewAll) {
            $registerModels = array_values(array_filter(
                $registerModels,
                fn(CashRegisterRecord $register): bool => in_array(
                    (int) $actor->getAuthIdentifier(),
                    $this->decodeList((string) $register->allowed_user_ids_json),
                    true
                )
            ));
        }
        $registerById = [];
        foreach ($registerModels as $register) {
            $registerById[(int) $register->id] = $register;
        }
        $activeSessions = [];
        foreach (CashSessionRecord::where('status', '=', 'open')->get() as $activeSession) {
            $activeSessions[(int) $activeSession->register_id] = $activeSession->toArray();
        }
        foreach ($registerModels as $register) {
            $data = $register->toArray();
            $data['allowed_user_ids'] = $this->decodeList((string) $register->allowed_user_ids_json);
            $data['active_session'] = $activeSessions[(int) $register->id] ?? null;
            $registers[] = $data;
        }
        $sessions = [];
        $sessionQuery = CashSessionRecord::query()->orderByDesc('opened_at');
        if (!$canViewAll) {
            $registerIds = array_keys($registerById);
            $sessionModels = [];
            foreach (array_chunk($registerIds, 500) as $registerIdChunk) {
                array_push(
                    $sessionModels,
                    ...CashSessionRecord::query()
                        ->whereIn('register_id', $registerIdChunk)
                        ->orderByDesc('opened_at')
                        ->limit(100)
                        ->get()
                );
            }
            usort($sessionModels, static fn(CashSessionRecord $left, CashSessionRecord $right): int =>
                [$right->opened_at, (int) $right->id] <=> [$left->opened_at, (int) $left->id]
            );
            $sessionModels = array_slice($sessionModels, 0, 100);
        } else {
            $sessionModels = $sessionQuery->limit(100)->get();
        }
        $sessionIds = array_map(static fn(CashSessionRecord $session): int => (int) $session->id, $sessionModels);
        $movementsBySession = [];
        $receiptIdsByPayment = [];
        if ($sessionIds !== []) {
            foreach (CashMovementRecord::query()->whereIn('session_id', $sessionIds)->orderBy('created_at')->get() as $movement) {
                $movementsBySession[(int) $movement->session_id][] = $movement->toArray();
            }
            $paymentIds = [];
            foreach ($movementsBySession as $sessionMovements) {
                foreach ($sessionMovements as $movement) {
                    if (!empty($movement['sales_payment_id'])) {
                        $paymentIds[] = (string) $movement['sales_payment_id'];
                    }
                }
            }
            if ($paymentIds !== []) {
                foreach (CashReceiptRecord::query()->whereIn('sales_payment_id', array_values(array_unique($paymentIds)))->get() as $receipt) {
                    $receiptIdsByPayment[(string) $receipt->sales_payment_id] = (int) $receipt->id;
                }
            }
            foreach ($movementsBySession as &$sessionMovements) {
                foreach ($sessionMovements as &$movement) {
                    $paymentId = (string) ($movement['sales_payment_id'] ?? '');
                    $movement['receipt_id'] = $receiptIdsByPayment[$paymentId] ?? null;
                }
                unset($movement);
            }
            unset($sessionMovements);
        }
        foreach ($sessionModels as $session) {
            $data = $session->toArray();
            $data['register'] = $session->register_name_snapshot;
            $data['register_code'] = $session->register_code_snapshot;
            $data['movements'] = $movementsBySession[(int) $session->id] ?? [];
            $sessions[] = $data;
        }

        $users = [];
        if ($actor->isSuper() || $actor->hasPermission('cashiers.manage')) {
            foreach (User::where('is_active', '=', 1)->orderBy('name')->get() as $user) {
                $users[] = [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'username' => (string) $user->username,
                ];
            }
        }

        return [
            'registers' => $registers,
            'sessions' => $sessions,
            'configuration' => $this->configuration(),
            'users' => $users,
        ];
    }

    public static function formatAmount(int $minor): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);

        return ($negative ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function parseAmount(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('Le montant est invalide.');
        }
        $value = trim((string) $value);
        if (preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\\.([0-9]{1,2}))?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Saisissez un montant positif avec au plus deux décimales.');
        }
        $parts = explode('.', $value, 2);
        $minor = ((int) $parts[0] * 100) + (isset($parts[1]) ? (int) str_pad($parts[1], 2, '0') : 0);
        if ($minor > self::MAX_AMOUNT_MINOR) {
            throw new InvalidArgumentException('Le montant dépasse la limite autorisée.');
        }

        return $minor;
    }

    private function authorize(User $actor, string $permission): void
    {
        if (!$actor->is_active() || (!$actor->isSuper() && !$actor->hasPermission($permission))) {
            throw new CashServiceException('Vous ne disposez pas de la permission nécessaire.');
        }
        $this->serviceState->assertReady();
    }

    private function assertRegisterUser(CashRegisterRecord $register, User $actor): void
    {
        $allowed = $this->decodeList((string) $register->allowed_user_ids_json);
        if (!in_array((int) $actor->getAuthIdentifier(), $allowed, true)) {
            throw new CashServiceException('Vous n’êtes pas affecté à cette caisse.');
        }
    }

    /**
     * @return list<int>
     */
    private function allowedUsers(mixed $values): array
    {
        if (!is_array($values)) {
            throw new InvalidArgumentException('La liste des caissiers autorisés est invalide.');
        }
        $ids = [];
        foreach ($values as $value) {
            $id = $this->integer($value, 1, PHP_INT_MAX, 'Identifiant de caissier');
            $user = User::find($id);
            if ($user === null || !$user->is_active()) {
                throw new InvalidArgumentException('Un caissier sélectionné est introuvable ou inactif.');
            }
            $ids[$id] = $id;
        }
        if ($ids === []) {
            throw new InvalidArgumentException('Sélectionnez au moins un caissier autorisé.');
        }

        return array_values($ids);
    }

    /**
     * @return list<int>
     */
    private function decodeList(string $json): array
    {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new CashServiceException('Une liste de configuration de caisse est invalide.');
        }

        return array_values(array_map('intval', $decoded));
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(string $json): array
    {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new CashServiceException('La liste des moyens de paiement autorisés est invalide.');
        }
        foreach ($decoded as $value) {
            if (!is_string($value)) {
                throw new CashServiceException('La liste des moyens de paiement autorisés est invalide.');
            }
        }

        return array_values($decoded);
    }

    /**
     * @return array{0: CashSessionRecord, 1: CashRegisterRecord}
     */
    private function openPaymentSession(User $actor, int $sessionId): array
    {
        if (!$this->configuration()['sessions_enabled']) {
            throw new CashServiceException('Les encaissements nécessitent une session de caisse active.');
        }
        $session = CashSessionRecord::find($sessionId);
        if ($session === null || $session->status !== 'open') {
            throw new CashServiceException('La session est inexistante ou déjà fermée.');
        }
        $register = CashRegisterRecord::find((int) $session->register_id);
        if ($register === null || $register->status !== 'active') {
            throw new CashServiceException('La caisse est indisponible.');
        }
        $this->assertRegisterUser($register, $actor);

        return [$session, $register];
    }

    private function validateIdempotencyKey(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._:-]{16,190}$/D', $value) !== 1) {
            throw new InvalidArgumentException('La clé de soumission est invalide.');
        }
    }

    /**
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function callSalesPayment(callable $operation): array
    {
        try {
            return $operation();
        } catch (InvalidArgumentException | SalesPaymentException | SalesCartConflictException
            | SalesAuthorizationException | SalesConsumerUnavailableException $exception) {
            throw new CashServiceException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function createReceipt(
        User $actor,
        CashSessionRecord $session,
        CashRegisterRecord $register,
        string $type,
        array $payment,
        int $amount,
        ?int $cashReceived,
        int $change,
        ?string $reason,
    ): CashReceiptRecord {
        $receipt = CashReceiptRecord::create([
            'receipt_number' => 'TMP-' . bin2hex(random_bytes(16)),
            'receipt_type' => $type,
            'session_id' => (int) $session->id,
            'register_id' => (int) $register->id,
            'sales_order_id' => (string) $payment['order_id'],
            'sales_payment_id' => (string) $payment['payment_id'],
            'related_sales_payment_id' => isset($payment['original_payment_id'])
                ? (string) $payment['original_payment_id']
                : null,
            'order_reference' => (string) $payment['reference'],
            'store_name_snapshot' => (string) $this->configuration()['store_name'],
            'business_reference_snapshot' => $this->configuration()['business_reference'] ?: null,
            'register_code_snapshot' => (string) $session->register_code_snapshot,
            'register_name_snapshot' => (string) $session->register_name_snapshot,
            'amount_minor' => $amount,
            'cash_received_minor' => $cashReceived,
            'change_minor' => $change,
            'currency' => (string) $session->currency,
            'payment_method' => 'cash',
            'reason' => $reason,
            'actor_user_id' => (int) $actor->getAuthIdentifier(),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $number = $this->formatSequence((string) $this->configuration()['receipt_number_format'], (int) $receipt->id);
        $receipt->update(['receipt_number' => $number]);

        return $receipt;
    }

    private function formatSequence(string $format, int $number): string
    {
        return preg_replace_callback(
            '/\{number(?::([1-9][0-9]?))?\}/',
            static fn(array $match): string => str_pad((string) $number, isset($match[1]) ? (int) $match[1] : 1, '0', STR_PAD_LEFT),
            $format,
        ) ?? throw new CashServiceException('Le format de numérotation des reçus est invalide.');
    }

    private function receiptForPayment(string $paymentId): CashReceiptRecord
    {
        $receipt = CashReceiptRecord::where('sales_payment_id', '=', $paymentId)->first();

        return $receipt ?? throw new CashServiceException('Le reçu lié au paiement est introuvable.');
    }

    private function expectedCash(CashSessionRecord $session): int
    {
        $expected = (int) $session->opening_float_minor;
        foreach (CashMovementRecord::where('session_id', '=', (int) $session->id)->get() as $movement) {
            $delta = (int) $movement->amount_minor;
            if (in_array($movement->movement_type, ['entry', 'sale_payment'], true)) {
                if ($delta > self::MAX_AMOUNT_MINOR - $expected) {
                    throw new CashServiceException('Le solde théorique dépasse la capacité de calcul autorisée.');
                }
                $expected += $delta;
            } else {
                if ($delta > $expected) {
                    throw new CashServiceException('Le journal des mouvements produit un solde théorique négatif.');
                }
                $expected -= $delta;
            }
        }

        return $expected;
    }

    private function lockSession(int $sessionId): ?CashSessionRecord
    {
        $query = CashSessionRecord::where('id', '=', $sessionId);
        if ($this->connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function lockRegister(int $registerId): ?CashRegisterRecord
    {
        $query = CashRegisterRecord::where('id', '=', $registerId);
        if ($this->connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function currency(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('La devise est invalide.');
        }
        $currency = strtoupper(trim($value));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('La devise doit être un code ISO à trois lettres.');
        }

        return $currency;
    }

    private function timezone(mixed $value): string
    {
        if (!is_string($value) || !in_array(trim($value), \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Le fuseau horaire est invalide.');
        }

        return trim($value);
    }

    private function numberFormat(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 80
            || preg_match('/^[A-Za-z0-9._{}:-]+$/D', $value) !== 1
            || preg_match('/\{number(?::[1-9][0-9]?)?\}/', $value) !== 1) {
            throw new InvalidArgumentException('Le format doit contenir {number} ou {number:N}.');
        }

        return $value;
    }

    private function boolean(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }

        throw new InvalidArgumentException('Un paramètre booléen est invalide.');
    }

    private function integer(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new InvalidArgumentException($label . ' invalide.');
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException($label . ' hors limites.');
        }

        return $integer;
    }

    private function requiredText(mixed $value, string $label, int $maximum): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' invalide.');
        }
        $value = trim($value);
        if ($value === '' || preg_match('/^.{1,' . $maximum . '}$/us', $value) !== 1) {
            throw new InvalidArgumentException($label . ' requis ou trop long.');
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maximum): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Un texte de configuration est invalide.');
        }
        $value = trim($value);
        if (preg_match('/^.{0,' . $maximum . '}$/us', $value) !== 1) {
            throw new InvalidArgumentException('Un texte de configuration dépasse la longueur autorisée.');
        }

        return $value;
    }
}
