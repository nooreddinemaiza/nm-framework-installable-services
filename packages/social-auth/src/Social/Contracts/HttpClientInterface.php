<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Contracts;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string>|null $form Corps application/x-www-form-urlencoded
     * @return array{status: int, body: string}
     *
     * @throws \NM\Services\SocialAuth\Social\Exceptions\ProviderException Échec de transport.
     */
    public function request(string $method, string $url, array $headers = [], ?array $form = null): array;
}
