<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use NM\Services\SocialAuth\Social\Contracts\FlowStorageInterface;
use Core\Http\SessionInterface;

/** Stockage des tentatives dans les variables temporaires (TTL) de la session existante. */
final class SessionFlowStorage implements FlowStorageInterface
{
    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function put(string $key, array $value, int $ttl): void
    {
        $this->session->setTemp($key, $value, $ttl);
    }

    public function pull(string $key): ?array
    {
        $value = $this->session->pullTemp($key);

        return is_array($value) ? $value : null;
    }

    public function purge(): void
    {
        $this->session->cleanTempData();
    }
}
