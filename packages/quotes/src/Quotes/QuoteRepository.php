<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Quotes;

use NM\Services\Quotes\Models\QuoteHistoryRecord;
use NM\Services\Quotes\Models\QuoteItemRecord;
use NM\Services\Quotes\Models\QuoteRecord;
use Core\Database\Model;
use RuntimeException;

final class QuoteRepository
{
    public function find(int $id): ?QuoteRecord
    {
        return QuoteRecord::query()->where('id', '=', $id)->first();
    }

    public function findByReference(string $reference): ?QuoteRecord
    {
        return QuoteRecord::query()->where('reference', '=', $reference)->first();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): QuoteRecord
    {
        $record = QuoteRecord::create($attributes);
        if (!$record instanceof QuoteRecord) {
            throw new RuntimeException('Impossible de créer le devis.');
        }
        return $record;
    }

    public function update(QuoteRecord $quote, array $attributes): QuoteRecord
    {
        $quote->update($attributes);
        return $quote;
    }

    public function delete(QuoteRecord $quote): void
    {
        $quote->delete();
    }

    /** @return list<QuoteItemRecord> */
    public function items(int $quoteId): array
    {
        return QuoteItemRecord::query()
            ->where('quote_id', '=', $quoteId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** @param array<string, mixed> $attributes */
    public function replaceItems(int $quoteId, array $items): void
    {
        QuoteItemRecord::query()->where('quote_id', '=', $quoteId)->delete();
        $position = 0;
        foreach ($items as $item) {
            $item['quote_id'] = $quoteId;
            $item['position'] = $position++;
            QuoteItemRecord::create($item);
        }
    }

    public function history(int $quoteId): array
    {
        return QuoteHistoryRecord::query()
            ->where('quote_id', '=', $quoteId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();
    }

    /** @param array<string, mixed>|null $oldValues @param array<string, mixed>|null $newValues */
    public function addHistory(
        int $quoteId,
        ?int $userId,
        string $action,
        string $description,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        QuoteHistoryRecord::create([
            'quote_id' => $quoteId,
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE),
            'new_values' => $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
