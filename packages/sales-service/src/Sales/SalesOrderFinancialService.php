<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use NM\Services\SalesService\Models\SalesPaymentRecord;
use App\Models\User;
use NM\Services\SalesService\Sales\Domain\SalesOrder;
use NM\Services\SalesService\Sales\Domain\SalesOrderState;
use Core\Database\Connection;
use InvalidArgumentException;

final class SalesOrderFinancialService
{
    private const MAX_AMOUNT_MINOR = 9_000_000_000_000_000;

    public function __construct(
        private readonly Connection $connection,
        private readonly SalesApplicationRegistry $applications,
        private readonly SalesOrderRepository $orders,
        private readonly SalesCartRepository $carts,
        private readonly string $applicationCode,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $applicationCode) !== 1) {
            throw new InvalidArgumentException('Le code de l’application Ventes est invalide.');
        }
    }

    /**
     * @return array{order_id: string, reference: string, status: string, total_minor: int, paid_minor: int, refunded_minor: int, balance_due_minor: int, currency: string, financial_status: string}
     */
    public function cashierQuote(User $actor, string $reference): array
    {
        $context = $this->cashierContext($actor, 'orders:payments:read', 'cash.payments.accept');
        $order = $this->findCashierOrder($context, $reference);
        $this->assertPayableStatus($order);

        return $this->summary($context, $order);
    }

    /**
     * @return array{payment_id: string, order_id: string, reference: string, amount_minor: int, currency: string, balance_due_minor: int, financial_status: string, replayed: bool}
     */
    public function recordCashPayment(
        User $actor,
        string $reference,
        int $amountMinor,
        string $idempotencyKey,
        bool $allowPartial,
    ): array {
        $context = $this->cashierContext($actor, 'orders:payments:write', 'cash.payments.accept');
        $this->validateAmountAndKey($amountMinor, $idempotencyKey);

        return $this->connection->transaction(function () use (
            $actor,
            $context,
            $reference,
            $amountMinor,
            $idempotencyKey,
            $allowPartial
        ): array {
            $order = $this->findCashierOrder($context, $reference);
            $existing = $this->idempotentRecord($context, $idempotencyKey);
            if ($existing !== null) {
                if ($existing->operation_type !== 'payment'
                    || (int) $existing->order_id !== (int) $order->id
                    || (int) $existing->amount_minor !== $amountMinor
                    || $existing->method !== 'cash'
                    || $existing->source_module !== 'cash') {
                    throw new SalesPaymentException('La clé de paiement a déjà servi pour une autre opération.');
                }

                $summary = $this->summary($context, $order);
                return [
                    'payment_id' => (string) $existing->id,
                    'order_id' => $order->id,
                    'reference' => (string) $order->publicReference,
                    'amount_minor' => $amountMinor,
                    'currency' => $order->total->currency,
                    'balance_due_minor' => $summary['balance_due_minor'],
                    'financial_status' => $summary['financial_status'],
                    'replayed' => true,
                ];
            }

            $this->assertPayableStatus($order);
            $summary = $this->summary($context, $order);
            if ($amountMinor > $summary['balance_due_minor']) {
                throw new SalesPaymentException('Le montant dépasse le solde restant de la commande.');
            }
            if (!$allowPartial && $amountMinor !== $summary['balance_due_minor']) {
                throw new SalesPaymentException('Le règlement doit couvrir le solde total de la commande.');
            }

            $this->orders->bumpFinancialVersion($context, $order);
            $payment = SalesPaymentRecord::create([
                'application_id' => $context->applicationId,
                'tenant_id' => $context->tenantId,
                'order_id' => (int) $order->id,
                'operation_type' => 'payment',
                'method' => 'cash',
                'amount_minor' => $amountMinor,
                'currency' => $order->total->currency,
                'related_payment_id' => null,
                'source_module' => 'cash',
                'idempotency_key' => $idempotencyKey,
                'reference' => null,
                'reason' => null,
                'actor_ref' => $context->actorReference,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->carts->appendOutboxEvent($context, 'PaymentRecorded', (string) $payment->id, [
                'order_id' => $order->id,
                'payment_id' => (string) $payment->id,
                'amount_minor' => $amountMinor,
                'currency' => $order->total->currency,
                'method' => 'cash',
            ], 'payment');

            $newSummary = $this->summary($context, $order);
            return [
                'payment_id' => (string) $payment->id,
                'order_id' => $order->id,
                'reference' => (string) $order->publicReference,
                'amount_minor' => $amountMinor,
                'currency' => $order->total->currency,
                'balance_due_minor' => $newSummary['balance_due_minor'],
                'financial_status' => $newSummary['financial_status'],
                'replayed' => false,
            ];
        });
    }

    /**
     * @return array{payment_id: string, original_payment_id: string, order_id: string, reference: string, amount_minor: int, currency: string, balance_due_minor: int, financial_status: string, replayed: bool}
     */
    public function refundCashPayment(
        User $actor,
        int $originalPaymentId,
        int $amountMinor,
        string $idempotencyKey,
        string $reason,
    ): array {
        $context = $this->cashierContext($actor, 'orders:payments:write', 'cash.refunds.create');
        $this->validateAmountAndKey($amountMinor, $idempotencyKey);
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw new InvalidArgumentException('La justification du remboursement est obligatoire (500 caractères maximum).');
        }
        if ($originalPaymentId < 1) {
            throw new InvalidArgumentException('La référence du paiement d’origine est invalide.');
        }

        return $this->connection->transaction(function () use (
            $context,
            $originalPaymentId,
            $amountMinor,
            $idempotencyKey,
            $reason
        ): array {
            $existing = $this->idempotentRecord($context, $idempotencyKey);
            if ($existing !== null) {
                if ($existing->operation_type !== 'refund'
                    || (int) $existing->related_payment_id !== $originalPaymentId
                    || (int) $existing->amount_minor !== $amountMinor
                    || $existing->reason !== $reason
                    || $existing->source_module !== 'cash') {
                    throw new SalesPaymentException('La clé de remboursement a déjà servi pour une autre opération.');
                }

                $original = $this->findOriginalPayment($context, $originalPaymentId);
                $order = $this->orders->find($context, (string) $original->order_id)
                    ?? throw new SalesPaymentException('La commande du paiement est introuvable.');
                $summary = $this->summary($context, $order);

                return [
                    'payment_id' => (string) $existing->id,
                    'original_payment_id' => (string) $originalPaymentId,
                    'order_id' => $order->id,
                    'reference' => (string) $order->publicReference,
                    'amount_minor' => $amountMinor,
                    'currency' => $order->total->currency,
                    'balance_due_minor' => $summary['balance_due_minor'],
                    'financial_status' => $summary['financial_status'],
                    'replayed' => true,
                ];
            }

            $original = $this->findOriginalPayment($context, $originalPaymentId);
            if ($original->method !== 'cash' || $original->source_module !== 'cash') {
                throw new SalesPaymentException('Seuls les encaissements espèces peuvent être remboursés par le Service Caisse.');
            }
            $alreadyRefunded = (int) $this->paymentQuery($context)
                ->where('related_payment_id', '=', $originalPaymentId)
                ->where('operation_type', '=', 'refund')
                ->sum('amount_minor');
            if ($amountMinor > (int) $original->amount_minor - $alreadyRefunded) {
                throw new SalesPaymentException('Le montant dépasse le solde remboursable du paiement.');
            }

            $order = $this->orders->find($context, (string) $original->order_id)
                ?? throw new SalesPaymentException('La commande du paiement est introuvable.');
            $this->orders->bumpFinancialVersion($context, $order);
            $refund = SalesPaymentRecord::create([
                'application_id' => $context->applicationId,
                'tenant_id' => $context->tenantId,
                'order_id' => (int) $order->id,
                'operation_type' => 'refund',
                'method' => 'cash',
                'amount_minor' => $amountMinor,
                'currency' => $order->total->currency,
                'related_payment_id' => $originalPaymentId,
                'source_module' => 'cash',
                'idempotency_key' => $idempotencyKey,
                'reference' => null,
                'reason' => $reason,
                'actor_ref' => $context->actorReference,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->carts->appendOutboxEvent($context, 'PaymentRefunded', (string) $refund->id, [
                'order_id' => $order->id,
                'payment_id' => (string) $refund->id,
                'original_payment_id' => (string) $originalPaymentId,
                'amount_minor' => $amountMinor,
                'currency' => $order->total->currency,
            ], 'payment');

            $summary = $this->summary($context, $order);
            return [
                'payment_id' => (string) $refund->id,
                'original_payment_id' => (string) $originalPaymentId,
                'order_id' => $order->id,
                'reference' => (string) $order->publicReference,
                'amount_minor' => $amountMinor,
                'currency' => $order->total->currency,
                'balance_due_minor' => $summary['balance_due_minor'],
                'financial_status' => $summary['financial_status'],
                'replayed' => false,
            ];
        });
    }

    /**
     * @return array{order_id: string, reference: string, status: string, total_minor: int, paid_minor: int, refunded_minor: int, balance_due_minor: int, currency: string, financial_status: string}
     */
    public function paymentSummaryForOrder(SalesContext $context, SalesOrder $order): array
    {
        $this->applications->assertActiveContext($context);
        $this->assertOrderContext($context, $order);

        return $this->summary($context, $order);
    }

    public function hasPaymentHistory(SalesContext $context, SalesOrder $order): bool
    {
        $this->applications->assertActiveContext($context);
        $this->assertOrderContext($context, $order);

        return $this->paymentQuery($context)
            ->where('order_id', '=', (int) $order->id)
            ->count() > 0;
    }

    /**
     * @return array{application_id: int, order_id: string, reference: string, status: string, total_minor: int, paid_minor: int, refunded_minor: int, balance_due_minor: int, currency: string, financial_status: string}
     */
    public function onlinePaymentQuote(string $reference): array
    {
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{0,19}$/D', $reference) !== 1) {
            throw new InvalidArgumentException('La référence de commande est invalide.');
        }

        $context = $this->onlinePaymentContext();
        $order = $this->orders->findByPublicReference($context, $reference)
            ?? throw new SalesPaymentException('La commande est introuvable pour la boutique configurée.');
        $this->applications->assertActiveContext($context);
        $this->assertOrderContext($context, $order);

        return [
            ...$this->summary($context, $order),
            'application_id' => $context->applicationId,
        ];
    }

    /**
     * Called only after OnlinePaymentService has verified the provider notification.
     *
     * @return array{payment_id: string, balance_due_minor: int, financial_status: string, replayed: bool}
     */
    public function recordVerifiedOnlinePayment(
        string $providerCode,
        string $orderReference,
        string $providerReference,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        bool $allowPartial,
    ): array {
        $this->validateProviderCode($providerCode);
        $this->validateReference($orderReference, 'La référence de commande est invalide.');
        $this->validateExternalReference($providerReference);
        $this->validateAmountAndKey($amountMinor, $idempotencyKey);
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('La devise du paiement est invalide.');
        }

        $context = $this->onlinePaymentContext();
        return $this->connection->transaction(function () use (
            $context,
            $providerCode,
            $orderReference,
            $providerReference,
            $amountMinor,
            $currency,
            $idempotencyKey,
            $allowPartial,
        ): array {
            $order = $this->orders->findByPublicReference($context, $orderReference)
                ?? throw new SalesPaymentException('La commande est introuvable pour la boutique configurée.');
            $this->assertOrderContext($context, $order);
            $existing = $this->idempotentRecord($context, $idempotencyKey);
            if ($existing !== null) {
                if ($existing->operation_type !== 'payment'
                    || (int) $existing->order_id !== (int) $order->id
                    || (int) $existing->amount_minor !== $amountMinor
                    || $existing->currency !== $currency
                    || $existing->method !== $providerCode
                    || $existing->source_module !== 'online_payments'
                    || $existing->reference !== $providerReference) {
                    throw new SalesPaymentException('La clé de paiement a déjà servi pour une autre opération.');
                }

                $summary = $this->summary($context, $order);
                return [
                    'payment_id' => (string) $existing->id,
                    'balance_due_minor' => $summary['balance_due_minor'],
                    'financial_status' => $summary['financial_status'],
                    'replayed' => true,
                ];
            }

            $this->assertPayableStatus($order);
            if ($order->total->currency !== $currency) {
                throw new SalesPaymentException('La devise du paiement ne correspond pas à celle de la commande.');
            }
            $summary = $this->summary($context, $order);
            if ($amountMinor > $summary['balance_due_minor']) {
                throw new SalesPaymentException('Le montant dépasse le solde restant de la commande.');
            }
            if (!$allowPartial && $amountMinor !== $summary['balance_due_minor']) {
                throw new SalesPaymentException('Le règlement doit couvrir le solde total de la commande.');
            }

            $this->orders->bumpFinancialVersion($context, $order);
            $payment = SalesPaymentRecord::create([
                'application_id' => $context->applicationId,
                'tenant_id' => $context->tenantId,
                'order_id' => (int) $order->id,
                'operation_type' => 'payment',
                'method' => $providerCode,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'related_payment_id' => null,
                'source_module' => 'online_payments',
                'idempotency_key' => $idempotencyKey,
                'reference' => $providerReference,
                'reason' => null,
                'actor_ref' => null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->carts->appendOutboxEvent($context, 'PaymentRecorded', (string) $payment->id, [
                'order_id' => $order->id,
                'payment_id' => (string) $payment->id,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'method' => $providerCode,
            ], 'payment');

            $newSummary = $this->summary($context, $order);
            return [
                'payment_id' => (string) $payment->id,
                'balance_due_minor' => $newSummary['balance_due_minor'],
                'financial_status' => $newSummary['financial_status'],
                'replayed' => false,
            ];
        });
    }

    public function recordVerifiedOnlineRefund(
        string $providerCode,
        int $originalPaymentId,
        string $externalReference,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        string $reason,
    ): void {
        $this->validateProviderCode($providerCode);
        $this->validateExternalReference($externalReference);
        $this->validateAmountAndKey($amountMinor, $idempotencyKey);
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw new InvalidArgumentException('La justification du remboursement est obligatoire (500 caractères maximum).');
        }
        if ($originalPaymentId < 1 || !preg_match('/^[A-Z]{3}$/D', strtoupper(trim($currency)))) {
            throw new InvalidArgumentException('Les références financières du remboursement sont invalides.');
        }
        $currency = strtoupper(trim($currency));
        $context = $this->onlinePaymentContext();

        $this->connection->transaction(function () use (
            $context,
            $providerCode,
            $originalPaymentId,
            $externalReference,
            $amountMinor,
            $currency,
            $idempotencyKey,
            $reason,
        ): void {
            $existing = $this->idempotentRecord($context, $idempotencyKey);
            if ($existing !== null) {
                if ($existing->operation_type !== 'refund'
                    || (int) $existing->related_payment_id !== $originalPaymentId
                    || (int) $existing->amount_minor !== $amountMinor
                    || $existing->currency !== $currency
                    || $existing->method !== $providerCode
                    || $existing->source_module !== 'online_payments'
                    || $existing->reference !== $externalReference
                    || $existing->reason !== $reason) {
                    throw new SalesPaymentException('La clé de remboursement a déjà servi pour une autre opération.');
                }

                return;
            }

            $original = $this->findOriginalPayment($context, $originalPaymentId);
            if ($original->method !== $providerCode
                || $original->source_module !== 'online_payments'
                || $original->currency !== $currency) {
                throw new SalesPaymentException('Le paiement d’origine ne correspond pas au remboursement vérifié.');
            }
            $alreadyRefunded = (int) $this->paymentQuery($context)
                ->where('related_payment_id', '=', $originalPaymentId)
                ->where('operation_type', '=', 'refund')
                ->sum('amount_minor');
            if ($amountMinor > (int) $original->amount_minor - $alreadyRefunded) {
                throw new SalesPaymentException('Le montant dépasse le solde remboursable du paiement.');
            }

            $order = $this->orders->find($context, (string) $original->order_id)
                ?? throw new SalesPaymentException('La commande du paiement est introuvable.');
            $this->assertOrderContext($context, $order);
            $this->orders->bumpFinancialVersion($context, $order);
            $refund = SalesPaymentRecord::create([
                'application_id' => $context->applicationId,
                'tenant_id' => $context->tenantId,
                'order_id' => (int) $order->id,
                'operation_type' => 'refund',
                'method' => $providerCode,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'related_payment_id' => $originalPaymentId,
                'source_module' => 'online_payments',
                'idempotency_key' => $idempotencyKey,
                'reference' => $externalReference,
                'reason' => $reason,
                'actor_ref' => null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->carts->appendOutboxEvent($context, 'PaymentRefunded', (string) $refund->id, [
                'order_id' => $order->id,
                'payment_id' => (string) $refund->id,
                'original_payment_id' => (string) $originalPaymentId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
            ], 'payment');
        });
    }

    private function onlinePaymentContext(): SalesContext
    {
        $context = $this->applications->publicConsumerContext(
            $this->applicationCode,
            ['orders:read', 'orders:payments:read', 'orders:payments:write'],
        );
        if (!$this->applications->isModuleEnabled($context, 'orders')) {
            throw new SalesPaymentException('Le module de commandes Ventes n’est pas activé.');
        }

        return $context;
    }

    private function validateProviderCode(string $providerCode): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,59}$/D', $providerCode) !== 1) {
            throw new InvalidArgumentException('Le fournisseur de paiement est invalide.');
        }
    }

    private function validateReference(string $reference, string $message): void
    {
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{0,19}$/D', $reference) !== 1) {
            throw new InvalidArgumentException($message);
        }
    }

    private function validateExternalReference(string $reference): void
    {
        if ($reference === '' || strlen($reference) > 190 || preg_match('/[\r\n\0]/', $reference) === 1) {
            throw new InvalidArgumentException('La référence fournisseur du paiement est invalide.');
        }
    }

    private function cashierContext(User $actor, string $requiredScope, string $requiredPermission): SalesContext
    {
        if (!$actor->is_active()) {
            throw new SalesPaymentException('Le compte utilisateur est inactif.');
        }
        if (!$actor->isSuper() && !$actor->hasPermission($requiredPermission)) {
            throw new SalesAuthorizationException('Missing permission for cash payment operation.');
        }
        $publicContext = $this->applications->publicConsumerContext(
            $this->applicationCode,
            ['orders:read', 'orders:payments:read', 'orders:payments:write'],
        );
        $context = new SalesContext(
            $publicContext->applicationId,
            $publicContext->tenantId,
            $publicContext->scopes,
            'user:' . (int) $actor->getAuthIdentifier(),
        );
        $context->requireScope($requiredScope);
        if (!$this->applications->isModuleEnabled($context, 'orders')) {
            throw new SalesPaymentException('Le module de commandes Ventes n’est pas activé.');
        }

        return $context;
    }

    private function findCashierOrder(SalesContext $context, string $reference): SalesOrder
    {
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{0,19}$/D', $reference) !== 1) {
            throw new InvalidArgumentException('La référence de commande est invalide.');
        }

        return $this->orders->findByPublicReference($context, $reference)
            ?? throw new SalesPaymentException('La commande est introuvable pour la boutique configurée.');
    }

    private function assertPayableStatus(SalesOrder $order): void
    {
        if (in_array($order->status, [SalesOrderState::CANCELLED, SalesOrderState::COMPLETED], true)) {
            throw new SalesPaymentException('Cette commande ne peut plus recevoir de paiement.');
        }
        if ($order->total->minorAmount < 1) {
            throw new SalesPaymentException('Une commande dont le total est nul ne peut pas être encaissée.');
        }
    }

    /**
     * @return array{order_id: string, reference: string, status: string, total_minor: int, paid_minor: int, refunded_minor: int, balance_due_minor: int, currency: string, financial_status: string}
     */
    private function summary(SalesContext $context, SalesOrder $order): array
    {
        $scope = $this->paymentQuery($context)->where('order_id', '=', (int) $order->id);
        $paid = (int) (clone $scope)->where('operation_type', '=', 'payment')->sum('amount_minor');
        $refunded = (int) (clone $scope)->where('operation_type', '=', 'refund')->sum('amount_minor');
        $netPaid = max(0, $paid - $refunded);
        $due = max(0, $order->total->minorAmount - $netPaid);
        if ($paid === 0) {
            $financialStatus = 'unpaid';
        } elseif ($netPaid === 0) {
            $financialStatus = 'refunded';
        } elseif ($refunded > 0) {
            $financialStatus = 'partially_refunded';
        } elseif ($netPaid >= $order->total->minorAmount) {
            $financialStatus = 'paid';
        } else {
            $financialStatus = 'partially_paid';
        }

        return [
            'order_id' => $order->id,
            'reference' => (string) $order->publicReference,
            'status' => $order->status,
            'total_minor' => $order->total->minorAmount,
            'paid_minor' => $paid,
            'refunded_minor' => $refunded,
            'balance_due_minor' => $due,
            'currency' => $order->total->currency,
            'financial_status' => $financialStatus,
        ];
    }

    private function findOriginalPayment(SalesContext $context, int $paymentId): SalesPaymentRecord
    {
        $payment = $this->paymentQuery($context)
            ->where('id', '=', $paymentId)
            ->where('operation_type', '=', 'payment')
            ->first();

        return $payment ?? throw new SalesPaymentException('Le paiement d’origine est introuvable.');
    }

    private function idempotentRecord(SalesContext $context, string $key): ?SalesPaymentRecord
    {
        return $this->paymentQuery($context)
            ->where('idempotency_key', '=', $key)
            ->first();
    }

    private function paymentQuery(SalesContext $context): \Core\Database\QueryBuilder
    {
        $query = SalesPaymentRecord::query()->where('application_id', '=', $context->applicationId);
        if ($context->tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', '=', $context->tenantId);
        }

        return $query;
    }

    private function assertOrderContext(SalesContext $context, SalesOrder $order): void
    {
        if ($order->applicationId !== $context->applicationId || $order->tenantId !== $context->tenantId) {
            throw new SalesAuthorizationException('Sales order context is invalid.');
        }
    }

    private function validateAmountAndKey(int $amountMinor, string $key): void
    {
        if ($amountMinor < 1 || $amountMinor > self::MAX_AMOUNT_MINOR) {
            throw new InvalidArgumentException('Le montant doit être positif et inférieur à la limite autorisée.');
        }
        if (preg_match('/^[A-Za-z0-9._:-]{16,190}$/D', $key) !== 1) {
            throw new InvalidArgumentException('La clé d’idempotence est invalide.');
        }
    }
}
