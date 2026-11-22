<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Contracts;

interface FlowStorageInterface
{
    /** @param array<string, mixed> $value */
    public function put(string $key, array $value, int $ttl): void;

    /** Lit ET supprime (usage unique). @return array<string, mixed>|null */
    public function pull(string $key): ?array;

    public function purge(): void;
}
