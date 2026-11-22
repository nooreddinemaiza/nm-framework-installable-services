<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Catalog;

use Core\Http\SessionInterface;
use Core\Logging\LoggerInterface;
use Throwable;

final class CatalogVisitCounter
{
    private const VISIT_TTL = 86400;

    public function __construct(
        private readonly CatalogService $catalog,
        private readonly SessionInterface $session,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(string $kind, int $entityId): void
    {
        $key = 'catalog_view_' . $kind . '_' . $entityId;

        try {
            if ($this->session->hasTemp($key)) {
                return;
            }

            if ($this->catalog->incrementPublicViews($kind, $entityId) > 0) {
                $this->session->setTemp($key, true, self::VISIT_TTL);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Unable to record a public catalog visit.', [
                'entity_id' => $entityId,
                'kind' => $kind,
                'exception' => $exception,
            ]);
        }
    }
}
