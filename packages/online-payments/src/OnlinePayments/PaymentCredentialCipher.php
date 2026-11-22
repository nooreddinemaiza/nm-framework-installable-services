<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\OnlinePayments;

use Core\Security\Encrypter;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class PaymentCredentialCipher
{
    private ?Encrypter $encrypter;

    public function __construct(?string $key)
    {
        $rawKey = self::decodeKey($key);
        $this->encrypter = $rawKey === null ? null : new Encrypter($rawKey);
    }

    public function isConfigured(): bool
    {
        return $this->encrypter !== null;
    }

    /**
     * @param array<string, string> $credentials
     */
    public function encrypt(array $credentials): string
    {
        $encrypter = $this->encrypter
            ?? throw new RuntimeException('Configurez ONLINE_PAYMENTS_ENCRYPTION_KEY avant d’enregistrer des secrets.');

        return $encrypter->encrypt(json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    public function decrypt(string $payload): array
    {
        $encrypter = $this->encrypter
            ?? throw new RuntimeException('Configurez ONLINE_PAYMENTS_ENCRYPTION_KEY avant d’utiliser les secrets.');

        try {
            $credentials = json_decode($encrypter->decrypt($payload), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('La configuration chiffrée du fournisseur est invalide.', 0, $exception);
        }
        if (!is_array($credentials)) {
            throw new RuntimeException('La configuration chiffrée du fournisseur est invalide.');
        }
        foreach ($credentials as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new RuntimeException('La configuration chiffrée du fournisseur est invalide.');
            }
        }

        return $credentials;
    }

    private static function decodeKey(?string $key): ?string
    {
        if ($key === null || trim($key) === '') {
            return null;
        }
        $key = trim($key);
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            $decoded = hex2bin($key);
        } else {
            $decoded = base64_decode($key, true);
            if ($decoded === false && strlen($key) === 32) {
                $decoded = $key;
            }
        }
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            throw new InvalidArgumentException('ONLINE_PAYMENTS_ENCRYPTION_KEY doit encoder exactement 32 octets.');
        }

        return $decoded;
    }
}
