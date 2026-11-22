<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Providers;

use NM\Services\SocialAuth\Social\Exceptions\ProviderException;

/**
 * Validation des revendications d'un id_token OpenID Connect REÇU DIRECTEMENT
 * du endpoint de jeton en TLS (OIDC Core §3.1.3.7, point 6 : la validation du
 * serveur TLS remplace alors celle de la signature). Vérifie : alg != none,
 * émetteur, audience (+ azp), expiration, iat, nonce (temps constant), sub.
 *
 * Ne JAMAIS l'utiliser sur un jeton reçu du navigateur.
 */
final class IdTokenValidator
{
    private const LEEWAY = 60;

    /**
     * @param callable(string, array<string, mixed>): bool $issuerMatcher
     * @return array<string, mixed> revendications validées
     *
     * @throws ProviderException
     */
    public static function validate(
        string $jwt,
        string $audience,
        callable $issuerMatcher,
        ?string $nonce,
        ?int $now = null,
    ): array {
        $now ??= time();
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new ProviderException('id_token mal formé');
        }

        $header = self::decode($parts[0]);
        $claims = self::decode($parts[1]);

        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || $alg === '' || strtolower($alg) === 'none') {
            throw new ProviderException('id_token : algorithme refusé');
        }

        $iss = $claims['iss'] ?? null;
        if (!is_string($iss) || !$issuerMatcher($iss, $claims)) {
            throw new ProviderException('id_token : émetteur invalide');
        }

        $aud = $claims['aud'] ?? null;
        $auds = is_array($aud) ? $aud : [$aud];
        if (!in_array($audience, $auds, true) || (count($auds) > 1 && ($claims['azp'] ?? null) !== $audience)) {
            throw new ProviderException('id_token : audience invalide');
        }

        $exp = $claims['exp'] ?? null;
        if (!is_numeric($exp) || (int) $exp < $now - self::LEEWAY) {
            throw new ProviderException('id_token expiré');
        }
        if (isset($claims['iat']) && (!is_numeric($claims['iat']) || (int) $claims['iat'] > $now + self::LEEWAY)) {
            throw new ProviderException('id_token : iat invalide');
        }

        if ($nonce !== null) {
            $n = $claims['nonce'] ?? null;
            if (!is_string($n) || !hash_equals($nonce, $n)) {
                throw new ProviderException('id_token : nonce invalide');
            }
        }

        if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '') {
            throw new ProviderException('id_token : sub manquant');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    private static function decode(string $segment): array
    {
        $raw = base64_decode(strtr($segment, '-_', '+/'), true);
        $data = $raw === false ? null : json_decode($raw, true, 16);
        if (!is_array($data)) {
            throw new ProviderException('id_token mal formé');
        }

        return $data;
    }
}
