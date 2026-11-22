<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\OnlinePayments;

use NM\Services\OnlinePayments\Models\OnlinePaymentAttemptRecord;
use NM\Services\OnlinePayments\Models\OnlinePaymentConfigurationRecord;
use NM\Services\OnlinePayments\Models\OnlinePaymentProviderRecord;
use App\Models\User;
use NM\Services\OnlinePayments\OnlinePayments\Contracts\PaymentProviderPort;
use NM\Services\OnlinePayments\OnlinePayments\Domain\HostedPayment;
use NM\Services\OnlinePayments\OnlinePayments\Domain\PaymentCheckoutRequest;
use NM\Services\OnlinePayments\OnlinePayments\Domain\PaymentRefundResult;
use NM\Services\OnlinePayments\OnlinePayments\Domain\VerifiedPaymentNotification;
use NM\Services\SalesService\Sales\SalesConsumerUnavailableException;
use NM\Services\SalesService\Sales\SalesApplicationRegistry;
use NM\Services\SalesService\Sales\SalesOrderFinancialService;
use NM\Services\SalesService\Sales\SalesPaymentException;
use Core\Database\Connection;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class OnlinePaymentService
{
    public function __construct(
        private readonly OnlinePaymentServiceState $serviceState,
        private readonly Connection $connection,
        private readonly PaymentProviderRegistry $providers,
        private readonly PaymentCredentialCipher $credentialCipher,
        private readonly SalesOrderFinancialService $salesPayments,
        private readonly SalesApplicationRegistry $applications,
        private readonly string $applicationCode,
        private readonly string $applicationUrl,
    ) {
    }

    private function assertApplicationUrl(): void
    {
        $parts = parse_url($this->applicationUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (filter_var($this->applicationUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || ($scheme !== 'https' && !($scheme === 'http' && $localHost))
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('APP_URL doit être une URL HTTPS valide avant d’activer un fournisseur de paiement.');
        }
    }

    /**
     * @return array{
     *   configuration: array{merchant_country: string, currency: string, allow_partial_payments: bool},
     *   available_providers: list<array{code: string, name: string}>,
     *   configured_providers: list<array{id: int, code: string, name: string, status: string, adapter_available: bool}>,
     *   attempts: list<array<string, mixed>>,
     *   statuses: array<string, int>,
     *   encryption_key_configured: bool
     * }
     */
    public function dashboardData(User $actor): array
    {
        $this->authorize($actor, 'online_payments.view');
        $this->assertReady();
        $available = $this->providers->available();
        $applicationId = $this->storefrontApplicationId();
        $configured = [];
        foreach (OnlinePaymentProviderRecord::query()->orderBy('name')->get() as $provider) {
            $code = (string) $provider->code;
            $configured[] = [
                'id' => (int) $provider->id,
                'code' => $code,
                'name' => (string) $provider->name,
                'status' => (string) $provider->status,
                'adapter_available' => $this->providers->get($code) !== null,
            ];
        }

        $statuses = [];
        foreach (['creating', 'awaiting', 'unknown', 'reconciliation_pending', 'confirmed', 'failed', 'cancelled', 'manual_review'] as $status) {
            $statuses[$status] = OnlinePaymentAttemptRecord::query()
                ->where('application_id', '=', $applicationId)
                ->where('status', '=', $status)
                ->count();
        }

        $attempts = [];
        foreach (OnlinePaymentAttemptRecord::query()
            ->where('application_id', '=', $applicationId)
            ->select(['id', 'order_reference', 'amount_minor', 'currency', 'status', 'created_at'])
            ->orderBy('created_at', 'DESC')
            ->limit(20)
            ->get() as $attempt) {
            $attempts[] = $attempt->toArray();
        }

        return [
            'configuration' => $this->configuration(),
            'available_providers' => $available,
            'configured_providers' => $configured,
            'attempts' => $attempts,
            'statuses' => $statuses,
            'encryption_key_configured' => $this->credentialCipher->isConfigured(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{merchant_country: string, currency: string, allow_partial_payments: bool}
     */
    public function saveConfiguration(User $actor, array $input): array
    {
        $this->authorize($actor, 'online_payments.settings.manage');
        $this->assertReady();
        $country = strtoupper(trim((string) ($input['merchant_country'] ?? '')));
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw new InvalidArgumentException('Le pays du compte marchand doit être un code ISO à deux lettres.');
        }
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('La devise doit être un code ISO à trois lettres.');
        }
        $allowPartial = $this->boolean($input['allow_partial_payments'] ?? null);
        $values = [
            'merchant_country' => $country,
            'currency' => $currency,
            'allow_partial_payments' => $allowPartial,
        ];

        $this->connection->transaction(function () use ($values): void {
            $configuration = OnlinePaymentConfigurationRecord::find(1);
            if ($configuration === null) {
                OnlinePaymentConfigurationRecord::create(['id' => 1] + $values);
            } else {
                $configuration->update($values);
            }
        });

        return $values;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveProvider(User $actor, array $input): void
    {
        $this->authorize($actor, 'online_payments.settings.manage');
        $this->assertReady();
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        $adapter = $this->providers->get($code);
        if ($adapter === null) {
            throw new InvalidArgumentException('Ce fournisseur ne dispose d’aucun adaptateur installé.');
        }
        $enabled = $this->boolean($input['enabled'] ?? null);
        $rawCredentials = $input['credentials'] ?? [];
        if (!is_array($rawCredentials)) {
            throw new InvalidArgumentException('Les paramètres du fournisseur sont invalides.');
        }
        $credentials = [];
        foreach ($rawCredentials as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,79}$/D', $key) !== 1
                || !is_string($value) || strlen($value) > 4096 || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidArgumentException('Un paramètre du fournisseur est invalide.');
            }
            if ($value !== '') {
                $credentials[$key] = $value;
            }
        }
        if ($enabled && $credentials === []) {
            $existing = OnlinePaymentProviderRecord::where('code', '=', $code)->first();
            if ($existing !== null && $existing->credentials_ciphertext !== null) {
                $credentials = $this->credentialCipher->decrypt((string) $existing->credentials_ciphertext);
            }
            if ($credentials === []) {
                throw new InvalidArgumentException('Renseignez les identifiants avant d’activer ce fournisseur.');
            }
        }
        $ciphertext = $credentials === []
            ? null
            : $this->credentialCipher->encrypt($credentials);
        $provider = OnlinePaymentProviderRecord::where('code', '=', $code)->first();

        $this->connection->transaction(function () use ($provider, $code, $adapter, $enabled, $ciphertext, $credentials): void {
            if ($enabled && $provider !== null && $provider->status === 'enabled'
                && $provider->credentials_ciphertext !== null
                && $credentials !== $this->credentialCipher->decrypt((string) $provider->credentials_ciphertext)) {
                $this->assertProviderHasNoPendingOperations((int) $provider->id);
            }
            if ($enabled) {
                foreach (OnlinePaymentProviderRecord::where('status', '=', 'enabled')->get() as $activeProvider) {
                    if ($provider === null || (int) $activeProvider->id !== (int) $provider->id) {
                        $this->assertProviderHasNoPendingOperations((int) $activeProvider->id);
                    }
                }
            } elseif ($provider !== null && $provider->status === 'enabled') {
                $this->assertProviderHasNoPendingOperations((int) $provider->id);
            }
            if ($enabled) {
                OnlinePaymentProviderRecord::query()
                    ->where('status', '=', 'enabled')
                    ->update(['status' => 'disabled']);
            }
            $values = [
                'code' => $code,
                'name' => $adapter->name(),
                'status' => $enabled ? 'enabled' : 'disabled',
                'credentials_ciphertext' => $ciphertext ?? $provider?->credentials_ciphertext,
            ];
            if ($provider === null) {
                OnlinePaymentProviderRecord::create($values);
            } else {
                $provider->update($values);
            }
        });
    }

    private function assertProviderHasNoPendingOperations(int $providerId): void
    {
        $pendingAttempt = OnlinePaymentAttemptRecord::query()
            ->where('provider_id', '=', $providerId)
            ->whereIn('status', ['creating', 'awaiting', 'unknown', 'reconciliation_pending', 'manual_review'])
            ->first();
        if ($pendingAttempt !== null) {
            throw new RuntimeException('Rapprochez toutes les tentatives en attente avant de désactiver ou remplacer ce fournisseur.');
        }

        $attemptIds = array_map(
            static fn(OnlinePaymentAttemptRecord $attempt): int => (int) $attempt->id,
            OnlinePaymentAttemptRecord::query()
                ->where('provider_id', '=', $providerId)
                ->select(['id'])
                ->get()
        );
        if ($attemptIds !== []
            && \NM\Services\OnlinePayments\Models\OnlinePaymentRefundRecord::query()
                ->whereIn('attempt_id', $attemptIds)
                ->where('status', '=', 'pending')
                ->first() !== null) {
            throw new RuntimeException('Rapprochez tous les remboursements en attente avant de désactiver ou remplacer ce fournisseur.');
        }
    }

    /**
     * Starts a hosted checkout. The caller must only supply an order reference
     * already authorized by the Sales storefront's customer session.
     *
     * @return array{attempt_id: int, redirect_url: string, replayed: bool}
     */
    public function startCheckout(string $orderReference, string $idempotencyKey, ?int $requestedAmountMinor = null): array
    {
        $this->assertReady();
        $this->validateIdempotencyKey($idempotencyKey);
        $quote = $this->salesPayments->onlinePaymentQuote($orderReference);
        $configuration = $this->configuration();
        if ($quote['currency'] !== $configuration['currency']) {
            throw new RuntimeException('La devise de la commande ne correspond pas à la configuration du service de paiement.');
        }
        if ($quote['balance_due_minor'] < 1) {
            throw new InvalidArgumentException('Cette commande ne présente aucun solde à régler.');
        }
        $amountMinor = $requestedAmountMinor ?? $quote['balance_due_minor'];
        if ($amountMinor < 1 || $amountMinor > $quote['balance_due_minor']) {
            throw new InvalidArgumentException('Le montant demandé dépasse le solde restant de la commande.');
        }
        if (!$configuration['allow_partial_payments'] && $amountMinor !== $quote['balance_due_minor']) {
            throw new InvalidArgumentException('Le règlement doit couvrir le solde total de la commande.');
        }

        $providerRecord = OnlinePaymentProviderRecord::where('status', '=', 'enabled')->first();
        if ($providerRecord === null) {
            throw new RuntimeException('Aucun fournisseur de paiement en ligne n’est activé.');
        }
        $this->assertApplicationUrl();
        $providerCode = (string) $providerRecord->code;
        $provider = $this->providers->get($providerCode)
            ?? throw new RuntimeException('L’adaptateur du fournisseur activé n’est pas installé.');
        if ($providerRecord->credentials_ciphertext === null) {
            throw new RuntimeException('Les identifiants du fournisseur de paiement ne sont pas configurés.');
        }
        $credentials = $this->credentialCipher->decrypt((string) $providerRecord->credentials_ciphertext);

        $existing = OnlinePaymentAttemptRecord::query()
            ->where('application_id', '=', $quote['application_id'])
            ->where('idempotency_key', '=', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            if ((int) $existing->order_id !== (int) $quote['order_id']
                || (int) $existing->amount_minor !== $amountMinor
                || $existing->currency !== $quote['currency']
                || (int) $existing->provider_id !== (int) $providerRecord->id) {
                throw new InvalidArgumentException('La clé de paiement a déjà servi pour une autre commande.');
            }
            if ($existing->status === 'awaiting' && $existing->redirect_url !== null) {
                return [
                    'attempt_id' => (int) $existing->id,
                    'redirect_url' => (string) $existing->redirect_url,
                    'replayed' => true,
                ];
            }
            if (!in_array($existing->status, ['creating', 'unknown'], true)) {
                return [
                    'attempt_id' => (int) $existing->id,
                    'redirect_url' => $this->applicationUrl . '/online-payment/return?attempt=' . rawurlencode((string) $existing->id),
                    'replayed' => true,
                ];
            }
            return $this->requestHostedCheckout(
                $existing,
                $provider,
                $credentials,
                $providerCode,
                $orderReference,
                $amountMinor,
                $quote['currency'],
                $idempotencyKey,
                true,
            );
        }

        $attempt = $this->connection->transaction(function () use ($quote, $providerRecord, $orderReference, $idempotencyKey, $amountMinor): OnlinePaymentAttemptRecord {
            $attempt = OnlinePaymentAttemptRecord::create([
                'application_id' => $quote['application_id'],
                'order_id' => (int) $quote['order_id'],
                'provider_id' => (int) $providerRecord->id,
                'sales_payment_id' => null,
                'order_reference' => $orderReference,
                'provider_reference' => null,
                'idempotency_key' => $idempotencyKey,
                'amount_minor' => $amountMinor,
                'currency' => $quote['currency'],
                'status' => 'creating',
                'redirect_url' => null,
                'expires_at' => null,
                'confirmed_at' => null,
            ]);
            $this->recordStatus($attempt, null, 'creating', 'checkout_requested');

            return $attempt;
        });

        return $this->requestHostedCheckout(
            $attempt,
            $provider,
            $credentials,
            $providerCode,
            $orderReference,
            $amountMinor,
            $quote['currency'],
            $idempotencyKey,
            false,
        );
    }

    /**
     * Verify a provider notification before recording it. Raw payloads and
     * credentials are deliberately not persisted or logged.
     *
     * @param array<string, string> $headers
     * @return array{accepted: bool, replayed: bool, status: string}
     */
    public function handleWebhook(string $providerCode, array $headers, string $rawBody): array
    {
        $this->assertReady();
        if ($rawBody === '' || strlen($rawBody) > 65536) {
            throw new InvalidArgumentException('La notification du fournisseur est vide ou dépasse la taille autorisée.');
        }
        $providerRecord = OnlinePaymentProviderRecord::where('code', '=', $providerCode)
            ->where('status', '=', 'enabled')
            ->first();
        if ($providerRecord === null) {
            throw new InvalidArgumentException('Le fournisseur de paiement est indisponible.');
        }
        $provider = $this->providers->get($providerCode)
            ?? throw new InvalidArgumentException('L’adaptateur du fournisseur de paiement est indisponible.');
        if ($providerRecord->credentials_ciphertext === null) {
            throw new RuntimeException('Les identifiants du fournisseur ne sont pas configurés.');
        }
        $credentials = $this->credentialCipher->decrypt((string) $providerRecord->credentials_ciphertext);
        $notification = $provider->verifyNotification($headers, $rawBody, $credentials);
        $this->validateNotification($notification);
        $payloadHash = hash('sha256', $rawBody);
        $providerId = (int) $providerRecord->id;

        $existingEvent = \NM\Services\OnlinePayments\Models\OnlinePaymentEventRecord::query()
            ->where('provider_id', '=', $providerId)
            ->where('external_event_id', '=', $notification->eventId)
            ->first();
        if ($existingEvent !== null) {
            if (!hash_equals((string) $existingEvent->payload_sha256, $payloadHash)) {
                throw new InvalidArgumentException('L’identifiant de notification a déjà été reçu avec un contenu différent.');
            }
            $attempt = $existingEvent->attempt_id === null
                ? null
                : OnlinePaymentAttemptRecord::find((int) $existingEvent->attempt_id);
            if ($attempt !== null && $attempt->status === 'reconciliation_pending') {
                $this->reconcileAttempt($attempt);
            }
            if ($notification->type === 'refund') {
                $this->processVerifiedRefund($notification, $providerCode, $providerId);
            }

            return [
                'accepted' => true,
                'replayed' => true,
                'status' => (string) $existingEvent->status,
            ];
        }

        $refund = $notification->type === 'refund'
            ? \NM\Services\OnlinePayments\Models\OnlinePaymentRefundRecord::find((int) $notification->merchantReference)
            : null;
        if ($notification->type === 'refund' && $refund === null) {
            throw new RuntimeException('Le remboursement indiqué par le fournisseur est introuvable.');
        }
        $attempt = $refund === null
            ? OnlinePaymentAttemptRecord::query()
                ->where('provider_id', '=', $providerId)
                ->where('provider_reference', '=', $notification->providerReference)
                ->first()
            : OnlinePaymentAttemptRecord::find((int) $refund->attempt_id);
        if ($refund === null && $attempt === null && $notification->type === 'payment'
            && ctype_digit($notification->merchantReference)) {
            $candidate = OnlinePaymentAttemptRecord::find((int) $notification->merchantReference);
            if ($candidate !== null
                && (int) $candidate->provider_id === $providerId
                && $candidate->provider_reference === null) {
                $attempt = $candidate;
                if ($notification->providerReference !== '') {
                    $attempt->update(['provider_reference' => $notification->providerReference]);
                }
            }
        }
        if ($attempt === null || (int) $attempt->provider_id !== $providerId) {
            throw new RuntimeException(
                'La tentative de paiement n’est pas encore rapprochable. Le fournisseur doit réessayer la notification.'
            );
        }
        $event = \NM\Services\OnlinePayments\Models\OnlinePaymentEventRecord::create([
            'provider_id' => $providerId,
            'attempt_id' => (int) $attempt->id,
            'external_event_id' => $notification->eventId,
            'payload_sha256' => $payloadHash,
            'status' => 'received',
            'result_code' => null,
            'received_at' => gmdate('Y-m-d H:i:s'),
            'processed_at' => null,
        ]);

        if ($refund !== null) {
            $this->processVerifiedRefund($notification, $providerCode, $providerId);
            $this->finishEvent($event, 'processed', 'refund_notification_processed');
            return ['accepted' => true, 'replayed' => false, 'status' => 'refund_' . $notification->status];
        }
        if (in_array($attempt->status, ['confirmed', 'failed', 'cancelled'], true)) {
            $this->finishEvent($event, 'processed', 'attempt_already_terminal');
            return ['accepted' => true, 'replayed' => false, 'status' => (string) $attempt->status];
        }
        if ($notification->status === 'pending') {
            $this->finishEvent($event, 'processed', 'provider_pending');
            return ['accepted' => true, 'replayed' => false, 'status' => 'pending'];
        }
        if ($notification->status === 'failed' || $notification->status === 'cancelled') {
            $this->transition($attempt, $notification->status, 'provider_' . $notification->status);
            $this->finishEvent($event, 'processed', 'provider_' . $notification->status);
            return ['accepted' => true, 'replayed' => false, 'status' => $notification->status];
        }
        if ($notification->amountMinor !== (int) $attempt->amount_minor
            || $notification->currency !== $attempt->currency) {
            $this->transition($attempt, 'manual_review', 'provider_amount_or_currency_mismatch');
            $this->finishEvent($event, 'manual_review', 'amount_or_currency_mismatch');
            return ['accepted' => true, 'replayed' => false, 'status' => 'manual_review'];
        }
        if ($notification->providerReference === '') {
            $this->transition($attempt, 'manual_review', 'provider_reference_missing');
            $this->finishEvent($event, 'manual_review', 'provider_reference_missing');
            return ['accepted' => true, 'replayed' => false, 'status' => 'manual_review'];
        }

        $this->transition($attempt, 'reconciliation_pending', 'provider_payment_verified');
        $event->update(['status' => 'reconciliation_pending', 'result_code' => null]);
        $this->reconcileAttempt($attempt);

        return [
            'accepted' => true,
            'replayed' => false,
            'status' => (string) $attempt->status,
        ];
    }

    public function retryReconciliation(User $actor, int $limit = 50): int
    {
        $this->authorize($actor, 'online_payments.reconciliation.manage');
        $this->assertReady();
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('La limite de rapprochement doit être comprise entre 1 et 500.');
        }
        $attempts = OnlinePaymentAttemptRecord::query()
            ->where('application_id', '=', $this->storefrontApplicationId())
            ->where('status', '=', 'reconciliation_pending')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        foreach ($attempts as $attempt) {
            $this->reconcileAttempt($attempt);
        }

        return count($attempts);
    }

    /**
     * @return array{refund_id: int, status: string, replayed: bool}
     */
    public function refund(
        User $actor,
        int $attemptId,
        int $amountMinor,
        string $reason,
        string $idempotencyKey,
    ): array {
        $this->authorize($actor, 'online_payments.refunds.create');
        $this->assertReady();
        $this->validateIdempotencyKey($idempotencyKey);
        $reason = trim($reason);
        if ($attemptId < 1 || $amountMinor < 1 || $reason === '' || strlen($reason) > 500) {
            throw new InvalidArgumentException('Les informations du remboursement sont invalides.');
        }
        $applicationId = $this->storefrontApplicationId();
        $existing = \NM\Services\OnlinePayments\Models\OnlinePaymentRefundRecord::where('idempotency_key', '=', $idempotencyKey)->first();
        if ($existing !== null) {
            if ((int) $existing->attempt_id !== $attemptId
                || (int) $existing->amount_minor !== $amountMinor
                || $existing->reason !== $reason) {
                throw new InvalidArgumentException('La clé de remboursement a déjà servi pour une autre opération.');
            }
            if ($existing->status !== 'pending') {
                return ['refund_id' => (int) $existing->id, 'status' => (string) $existing->status, 'replayed' => true];
            }
        }

        $reservation = $this->connection->transaction(function () use (
            $applicationId,
            $attemptId,
            $amountMinor,
            $reason,
            $idempotencyKey,
            $actor,
            $existing
        ): array {
            $attemptQuery = OnlinePaymentAttemptRecord::query()
                ->where('id', '=', $attemptId)
                ->where('application_id', '=', $applicationId);
            if ($this->connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $attemptQuery->lockForUpdate();
            }
            $attempt = $attemptQuery->first();
            if ($attempt === null || $attempt->status !== 'confirmed' || $attempt->sales_payment_id === null) {
                throw new InvalidArgumentException('Le paiement confirmé demandé est introuvable.');
            }
            if ($existing !== null) {
                return ['refund' => $existing, 'attempt' => $attempt, 'replayed' => true];
            }
            $reservedAmount = (int) \NM\Services\OnlinePayments\Models\OnlinePaymentRefundRecord::query()
                ->where('attempt_id', '=', $attemptId)
                ->whereIn('status', ['pending', 'succeeded'])
                ->sum('amount_minor');
            if ($amountMinor > (int) $attempt->amount_minor - $reservedAmount) {
                throw new InvalidArgumentException('Le montant dépasse le solde remboursable du paiement.');
            }
            $refund = \NM\Services\OnlinePayments\Models\OnlinePaymentRefundRecord::create([
                'attempt_id' => $attemptId,
                'actor_user_id' => (int) $actor->getAuthIdentifier(),
                'external_reference' => null,
                'idempotency_key' => $idempotencyKey,
                'amount_minor' => $amountMinor,
                'currency' => (string) $attempt->currency,
                'status' => 'pending',
                'reason' => $reason,
            ]);

            return ['refund' => $refund, 'attempt' => $attempt, 'replayed' => false];
        });

        $attempt = $reservation['attempt'];
        $refund = $reservation['refund'];
        $providerRecord = OnlinePaymentProviderRecord::find((int) $attempt->provider_id)
            ?? throw new RuntimeException('Le fournisseur du paiement d’origine est introuvable.');
        $provider = $this->providers->get((string) $providerRecord->code)
            ?? throw new RuntimeException('L’adaptateur du fournisseur du paiement d’origine est indisponible.');
        if ($providerRecord->credentials_ciphertext === null) {
            throw new RuntimeException('Les identifiants du fournisseur de paiement ne sont pas configurés.');
        }

        try {
            $result = $provider->refund(
                (string) $attempt->provider_reference,
                $amountMinor,
                (string) $attempt->currency,
                $idempotencyKey,
                $this->credentialCipher->decrypt((string) $providerRecord->credentials_ciphertext),
            );
            $this->validateRefundResult($result);
            $currentRefund = \NM\Services\OnlinePayments\Models\OnlinePaymentRefundRecord::find((int) $refund->id)
                ?? throw new RuntimeException('La demande de remboursement n’est plus disponible.');
            if ($currentRefund->status !== 'succeeded') {
                if ($currentRefund->external_reference !== null
                    && $result->providerRefundReference !== ''
                    && $currentRefund->external_reference !== $result->providerRefundReference) {
                    throw new RuntimeException('La référence du remboursement fournisseur est incohérente.');
                }
                $currentRefund->update([
                    'external_reference' => $result->providerRefundReference !== ''
                        ? $result->providerRefundReference
                        : $currentRefund->external_reference,
                    'status' => $result->status === 'succeeded' ? 'pending' : $result->status,
                ]);
                if ($result->status === 'succeeded') {
                    $this->recordSalesRefund($currentRefund, $attempt, (string) $providerRecord->code);
                }
            }
            $refund = $currentRefund;
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Online refund result uncertain; provider=%s refund=%s error=%s',
                (string) $providerRecord->code,
                (string) $refund->id,
                get_class($exception)
            ));
            throw new RuntimeException(
                'Le résultat du remboursement est incertain. La réservation est conservée pour rapprochement.',
                0,
                $exception
            );
        }

        return [
            'refund_id' => (int) $refund->id,
            'status' => (string) $refund->status,
            'replayed' => $reservation['replayed'],
        ];
    }

    /**
     * @return array{merchant_country: string, currency: string, allow_partial_payments: bool}
     */
    public function configuration(): array
    {
        $configuration = OnlinePaymentConfigurationRecord::find(1);
        if ($configuration === null) {
            return [
                'merchant_country' => 'MA',
                'currency' => 'MAD',
                'allow_partial_payments' => false,
            ];
        }
        return [
            'merchant_country' => (string) $configuration->merchant_country,
            'currency' => (string) $configuration->currency,
            'allow_partial_payments' => (bool) $configuration->allow_partial_payments,
        ];
    }

    public function checkoutAvailable(): bool
    {
        if (!$this->serviceState->isEnabled('sales-service')
            || !$this->serviceState->isEnabled('online-payments')) {
            return false;
        }
        $provider = OnlinePaymentProviderRecord::where('status', '=', 'enabled')->first();

        return $provider !== null
            && $this->providers->get((string) $provider->code) !== null
            && $provider->credentials_ciphertext !== null
            && $this->credentialCipher->isConfigured();
    }

    /**
     * @return array{application_id: int, order_id: string, reference: string, status: string, total_minor: int, paid_minor: int, refunded_minor: int, balance_due_minor: int, currency: string, financial_status: string}
     */
    public function quoteOrder(string $orderReference): array
    {
        $this->assertReady();
        $quote = $this->salesPayments->onlinePaymentQuote($orderReference);
        if ($quote['currency'] !== $this->configuration()['currency']) {
            throw new RuntimeException('La devise de la commande ne correspond pas à la configuration du service de paiement.');
        }

        return $quote;
    }

    public function attemptOrderId(int $attemptId): ?string
    {
        $this->assertReady();
        if ($attemptId < 1) {
            return null;
        }
        $attempt = OnlinePaymentAttemptRecord::query()
            ->where('id', '=', $attemptId)
            ->where('application_id', '=', $this->storefrontApplicationId())
            ->first();

        return $attempt === null ? null : (string) $attempt->order_id;
    }

    private function assertReady(): void
    {
        $this->serviceState->assertReady();
    }

    private function storefrontApplicationId(): int
    {
        return $this->applications
            ->publicConsumerContext($this->applicationCode, ['orders:read'])
            ->applicationId;
    }

    private function authorize(User $actor, string $permission): void
    {
        if (!$actor->is_active()) {
            throw new RuntimeException('Le compte utilisateur est inactif.');
        }
        if (!$actor->isSuper() && !$actor->hasPermission($permission)) {
            throw new RuntimeException('Vous ne disposez pas des droits nécessaires pour cette opération.');
        }
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === '1' || $value === 1) {
            return true;
        }
        if ($value === '0' || $value === 0) {
            return false;
        }

        throw new InvalidArgumentException('Une option booléenne du service est invalide.');
    }

    /**
     * @param array<string, string> $credentials
     * @return array{attempt_id: int, redirect_url: string, replayed: bool}
     */
    private function requestHostedCheckout(
        OnlinePaymentAttemptRecord $attempt,
        PaymentProviderPort $provider,
        array $credentials,
        string $providerCode,
        string $orderReference,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        bool $replayed,
    ): array {
        try {
            $hostedPayment = $provider->createCheckout(
                new PaymentCheckoutRequest(
                    $orderReference,
                    $amountMinor,
                    $currency,
                    $idempotencyKey,
                    (string) $attempt->id,
                    $this->applicationUrl . '/online-payment/return?attempt=' . rawurlencode((string) $attempt->id),
                    $this->applicationUrl . '/online-payment/return?attempt=' . rawurlencode((string) $attempt->id),
                    $this->applicationUrl . '/online-payment/webhook/' . rawurlencode($providerCode),
                ),
                $credentials
            );
            $this->validateHostedPayment($hostedPayment);
        } catch (Throwable $exception) {
            $current = OnlinePaymentAttemptRecord::find((int) $attempt->id);
            if ($current !== null && $current->status === 'creating') {
                $this->transition($current, 'unknown', 'provider_result_uncertain');
            }
            error_log(sprintf(
                'Online payment checkout result uncertain; provider=%s attempt=%s error=%s',
                $providerCode,
                (string) $attempt->id,
                get_class($exception)
            ));

            throw new RuntimeException(
                'Le résultat de la création du paiement est incertain. La tentative est conservée pour rapprochement.',
                0,
                $exception
            );
        }

        $current = OnlinePaymentAttemptRecord::find((int) $attempt->id)
            ?? throw new RuntimeException('La tentative de paiement n’est plus disponible.');
        if ($current->provider_reference !== null
            && $current->provider_reference !== $hostedPayment->providerReference) {
            if (in_array($current->status, ['creating', 'awaiting', 'unknown'], true)) {
                $this->transition($current, 'manual_review', 'provider_reference_conflict');
            }
            throw new RuntimeException('La référence de paiement du fournisseur est incohérente.');
        }
        $current->update([
            'provider_reference' => $hostedPayment->providerReference,
            'redirect_url' => $hostedPayment->redirectUrl,
            'expires_at' => $hostedPayment->expiresAt?->format('Y-m-d H:i:s'),
        ]);
        if (in_array($current->status, ['creating', 'unknown'], true)) {
            $this->transition($current, 'awaiting', 'hosted_checkout_created');
        }
        $redirectUrl = in_array($current->status, ['confirmed', 'failed', 'cancelled', 'manual_review', 'reconciliation_pending'], true)
            ? $this->applicationUrl . '/online-payment/return?attempt=' . rawurlencode((string) $current->id)
            : $hostedPayment->redirectUrl;

        return [
            'attempt_id' => (int) $current->id,
            'redirect_url' => $redirectUrl,
            'replayed' => $replayed,
        ];
    }

    private function validateIdempotencyKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{16,190}$/D', $key) !== 1) {
            throw new InvalidArgumentException('La clé d’idempotence du paiement est invalide.');
        }
    }

    private function validateHostedPayment(HostedPayment $payment): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,180}$/D', $payment->providerReference) !== 1) {
            throw new InvalidArgumentException('Le fournisseur a retourné une référence de paiement invalide.');
        }
        $parts = parse_url($payment->redirectUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (filter_var($payment->redirectUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || ($scheme !== 'https' && !($scheme === 'http' && $localHost))
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Le fournisseur a retourné une URL de paiement non sécurisée.');
        }
    }

    private function validateNotification(VerifiedPaymentNotification $notification): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,190}$/D', $notification->eventId) !== 1
            || ($notification->providerReference !== ''
                && preg_match('/^[A-Za-z0-9._:-]{1,180}$/D', $notification->providerReference) !== 1)
            || preg_match('/^[1-9][0-9]{0,18}$/D', $notification->merchantReference) !== 1
            || !in_array($notification->status, ['pending', 'succeeded', 'failed', 'cancelled'], true)
            || !in_array($notification->type, ['payment', 'refund'], true)
            || $notification->amountMinor < 0
            || preg_match('/^[A-Z]{3}$/D', $notification->currency) !== 1) {
            throw new InvalidArgumentException('La notification vérifiée du fournisseur est invalide.');
        }
    }

    private function validateRefundResult(PaymentRefundResult $result): void
    {
        if (!in_array($result->status, ['pending', 'succeeded', 'failed'], true)
            || ($result->status === 'succeeded'
                && preg_match('/^[A-Za-z0-9._:-]{1,180}$/D', $result->providerRefundReference) !== 1)
            || ($result->providerRefundReference !== ''
                && preg_match('/^[A-Za-z0-9._:-]{1,180}$/D', $result->providerRefundReference) !== 1)) {
            throw new InvalidArgumentException('Le fournisseur a retourné un résultat de remboursement invalide.');
        }
    }

    private function processVerifiedRefund(
        VerifiedPaymentNotification $notification,
        string $providerCode,
        int $providerId,
    ): void {
        $refund = \NM\Services\OnlinePayments\Models\OnlinePaymentRefundRecord::find((int) $notification->merchantReference);
        if ($refund === null) {
            throw new RuntimeException('Le remboursement indiqué par le fournisseur est introuvable.');
        }
        $attempt = OnlinePaymentAttemptRecord::find((int) $refund->attempt_id);
        if ($attempt === null || (int) $attempt->provider_id !== $providerId
            || (int) $attempt->application_id !== $this->storefrontApplicationId()) {
            throw new RuntimeException('Le remboursement ne correspond pas au fournisseur ou à la boutique configurée.');
        }
        if ((int) $refund->amount_minor !== $notification->amountMinor
            || $refund->currency !== $notification->currency) {
            throw new InvalidArgumentException('Le montant du remboursement vérifié ne correspond pas à la demande.');
        }
        if (in_array($notification->status, ['failed', 'cancelled'], true)) {
            if ($refund->status !== 'succeeded') {
                $refund->update(['status' => 'failed']);
            }
            return;
        }
        if ($notification->status !== 'succeeded') {
            return;
        }
        if ($notification->providerReference === '') {
            throw new InvalidArgumentException('La confirmation du remboursement ne contient aucune référence externe.');
        }
        if ($refund->external_reference !== null
            && $refund->external_reference !== $notification->providerReference) {
            throw new InvalidArgumentException('La référence du remboursement ne correspond pas au résultat enregistré.');
        }
        $refund->update(['external_reference' => $notification->providerReference]);
        $this->recordSalesRefund($refund, $attempt, $providerCode);
    }

    private function recordSalesRefund(
        \NM\Services\OnlinePayments\Models\OnlinePaymentRefundRecord $refund,
        OnlinePaymentAttemptRecord $attempt,
        string $providerCode,
    ): void {
        if ($refund->external_reference === null || $attempt->sales_payment_id === null) {
            throw new RuntimeException('Le remboursement ne possède pas de références financières complètes.');
        }
        $this->salesPayments->recordVerifiedOnlineRefund(
            $providerCode,
            (int) $attempt->sales_payment_id,
            (string) $refund->external_reference,
            (int) $refund->amount_minor,
            (string) $refund->currency,
            'online-refund-id:' . (int) $refund->id,
            (string) $refund->reason,
        );
        $refund->update(['status' => 'succeeded']);
    }

    private function transition(OnlinePaymentAttemptRecord $attempt, string $status, string $reason): void
    {
        $fromStatus = (string) $attempt->status;
        if ($fromStatus === $status) {
            return;
        }
        $allowedTransitions = [
            'creating' => ['awaiting', 'unknown', 'reconciliation_pending', 'failed', 'cancelled', 'manual_review'],
            'awaiting' => ['reconciliation_pending', 'failed', 'cancelled', 'manual_review'],
            'unknown' => ['awaiting', 'reconciliation_pending', 'failed', 'cancelled', 'manual_review'],
            'reconciliation_pending' => ['confirmed', 'manual_review'],
            'confirmed' => [],
            'failed' => [],
            'cancelled' => [],
            'manual_review' => ['reconciliation_pending', 'confirmed'],
        ];
        if (!in_array($status, $allowedTransitions[$fromStatus] ?? [], true)) {
            throw new RuntimeException("Transition de paiement interdite [{$fromStatus} -> {$status}].");
        }
        $this->connection->transaction(function () use ($attempt, $fromStatus, $status, $reason): void {
            $this->recordStatus($attempt, $fromStatus, $status, $reason);
            $attempt->update(['status' => $status]);
        });
    }

    private function recordStatus(
        OnlinePaymentAttemptRecord $attempt,
        ?string $fromStatus,
        string $toStatus,
        string $reason,
    ): void {
        \NM\Services\OnlinePayments\Models\OnlinePaymentHistoryRecord::create([
            'attempt_id' => (int) $attempt->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason_code' => $reason,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function finishEvent(\NM\Services\OnlinePayments\Models\OnlinePaymentEventRecord $event, string $status, string $code): void
    {
        $event->update([
            'status' => $status,
            'result_code' => $code,
            'processed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function reconcileAttempt(OnlinePaymentAttemptRecord $attempt): void
    {
        $provider = OnlinePaymentProviderRecord::find((int) $attempt->provider_id);
        if ($provider === null || $attempt->provider_reference === null) {
            $this->transition($attempt, 'manual_review', 'provider_reference_missing');
            return;
        }
        try {
            $payment = $this->salesPayments->recordVerifiedOnlinePayment(
                (string) $provider->code,
                (string) $attempt->order_reference,
                (string) $attempt->provider_reference,
                (int) $attempt->amount_minor,
                (string) $attempt->currency,
                'online-attempt:' . (int) $attempt->id,
                $this->configuration()['allow_partial_payments'],
            );
            $this->connection->transaction(function () use ($attempt, $payment): void {
                $attempt->update([
                    'sales_payment_id' => (int) $payment['payment_id'],
                    'confirmed_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->transition($attempt, 'confirmed', 'sales_payment_reconciled');
                foreach (\NM\Services\OnlinePayments\Models\OnlinePaymentEventRecord::query()
                    ->where('attempt_id', '=', (int) $attempt->id)
                    ->where('status', '=', 'reconciliation_pending')
                    ->get() as $event) {
                    $this->finishEvent($event, 'processed', 'sales_payment_reconciled');
                }
            });
        } catch (SalesConsumerUnavailableException | PDOException $exception) {
            error_log(sprintf(
                'Online payment reconciliation deferred; attempt=%s error=%s',
                (string) $attempt->id,
                get_class($exception)
            ));
        } catch (SalesPaymentException | InvalidArgumentException $exception) {
            $this->transition($attempt, 'manual_review', 'sales_reconciliation_rejected');
            foreach (\NM\Services\OnlinePayments\Models\OnlinePaymentEventRecord::query()
                ->where('attempt_id', '=', (int) $attempt->id)
                ->where('status', '=', 'reconciliation_pending')
                ->get() as $event) {
                $this->finishEvent($event, 'manual_review', 'sales_reconciliation_rejected');
            }
            error_log(sprintf(
                'Online payment requires manual reconciliation; attempt=%s error=%s',
                (string) $attempt->id,
                get_class($exception)
            ));
        }
    }
}
