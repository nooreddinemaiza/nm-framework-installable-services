<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales;

use InvalidArgumentException;

final class SalesContext
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly int $applicationId,
        public readonly ?int $tenantId = null,
        public readonly array $scopes = [],
        public readonly ?string $actorReference = null,
    ) {
        if ($applicationId < 1 || ($tenantId !== null && $tenantId < 1)) {
            throw new InvalidArgumentException('Sales context identifiers must be positive.');
        }
    }

    public function allows(string $scope): bool
    {
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }

    public function requireScope(string $scope): void
    {
        if (!$this->allows($scope)) {
            throw new SalesAuthorizationException("Missing sales scope: {$scope}");
        }
    }
}
