<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Quotes;

use NM\Services\InventoryService\Models\CatalogProduct;
use NM\Services\InventoryService\Models\CatalogVariant;
use NM\Services\Quotes\Models\QuoteRecord;
use NM\Services\Quotes\Quotes\Domain\QuoteState;
use InvalidArgumentException;
use RuntimeException;

final class QuoteService
{
    public function __construct(
        private readonly QuoteRepository $repository,
        private readonly QuoteConfigurationStore $configuration,
    ) {
    }

    /** @param array<string, mixed> $data @param list<array<string, mixed>> $items */
    public function create(array $data, array $items, ?int $userId = null): QuoteRecord
    {
        $config = $this->configuration->values();
        if (!$config['public_requests_enabled'] && $userId === null) {
            throw new RuntimeException('Les demandes de devis publiques sont désactivées.');
        }
        if ($userId === null && !$config['allow_anonymous']) {
            throw new RuntimeException('Les demandes de devis anonymes ne sont pas autorisées.');
        }
        $source = trim((string) ($data['source'] ?? ''));
        if ($userId === null && $source !== '') {
            $sourceSetting = match ($source) {
                'product' => 'product_requests_enabled',
                'category' => 'category_requests_enabled',
                'brand' => 'brand_requests_enabled',
                default => null,
            };
            if ($sourceSetting !== null && !$config[$sourceSetting]) {
                throw new RuntimeException('Les demandes de devis depuis cette page sont désactivées.');
            }
        }
        if (count($items) < 1) {
            throw new InvalidArgumentException('Chaque devis doit contenir au moins une ligne.');
        }
        if (count($items) > (int) $config['max_lines']) {
            throw new InvalidArgumentException('Le nombre maximal de lignes du devis est dépassé.');
        }

        $customerName = trim((string) ($data['customer_name'] ?? ''));
        $customerEmail = trim((string) ($data['customer_email'] ?? ''));
        $customerPhone = trim((string) ($data['customer_phone'] ?? ''));
        if ($customerName === '' || strlen($customerName) > 190) {
            throw new InvalidArgumentException('Le nom du demandeur est obligatoire.');
        }
        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || strlen($customerEmail) > 190) {
            throw new InvalidArgumentException('L’adresse e-mail du demandeur est invalide.');
        }
        if ($config['require_phone'] && $customerPhone === '') {
            throw new InvalidArgumentException('Le numéro de téléphone est obligatoire.');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? $config['default_currency'])));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Devise invalide.');
        }

        $normalizedItems = [];
        foreach ($items as $item) {
            $normalizedItems[] = $this->normalizeItem($item, (int) $config['max_quantity_per_line']);
        }
        $totals = $this->calculateTotals($normalizedItems);
        $validityDays = (int) $config['default_validity_days'];

        $quote = $this->repository->create([
            'reference' => $this->reference(),
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
            'customer_company' => trim((string) ($data['customer_company'] ?? '')) ?: null,
            'customer_address' => trim((string) ($data['customer_address'] ?? '')) ?: null,
            'status' => QuoteState::PENDING,
            'currency' => $currency,
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'total' => $totals['total'],
            'customer_note' => trim((string) ($data['customer_note'] ?? '')) ?: null,
            'internal_note' => trim((string) ($data['internal_note'] ?? '')) ?: null,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $validityDays * 86400),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $quoteId = (int) $quote->getAttribute('id');
        $this->repository->replaceItems($quoteId, $normalizedItems);
        $this->repository->addHistory(
            $quoteId,
            $userId,
            'created',
            'Devis créé.',
            null,
            ['status' => QuoteState::PENDING, 'reference' => $quote->getAttribute('reference')]
        );
        return $quote;
    }

    public function transition(QuoteRecord $quote, string $status, ?int $userId = null): QuoteRecord
    {
        $from = (string) $quote->getAttribute('status');
        if (!QuoteState::allows($from, $status)) {
            throw new InvalidArgumentException("Transition du devis interdite : {$from} -> {$status}.");
        }
        if ($status === QuoteState::ACCEPTED && $this->isExpired($quote)) {
            throw new InvalidArgumentException('Un devis expiré ne peut pas être accepté sans renouvellement.');
        }
        $updated = $this->repository->update($quote, ['status' => $status, 'updated_by' => $userId]);
        $this->repository->addHistory(
            (int) $quote->getAttribute('id'),
            $userId,
            'status_changed',
            "Statut modifié de {$from} vers {$status}.",
            ['status' => $from],
            ['status' => $status]
        );
        return $updated;
    }

    /** @param array<string, mixed> $data @param list<array<string, mixed>>|null $items */
    public function update(QuoteRecord $quote, array $data, ?array $items = null, ?int $userId = null): QuoteRecord
    {
        $config = $this->configuration->values();
        $attributes = [];
        foreach ([
            'customer_name', 'customer_email', 'customer_phone', 'customer_company',
            'customer_address', 'customer_note', 'internal_note', 'currency',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = trim((string) $data[$field]) ?: null;
            }
        }
        if (isset($attributes['customer_name']) && $attributes['customer_name'] === null) {
            throw new InvalidArgumentException('Le nom du demandeur est obligatoire.');
        }
        if (isset($attributes['customer_email']) && (!filter_var((string) $attributes['customer_email'], FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('L’adresse e-mail du demandeur est invalide.');
        }
        if (isset($attributes['currency'])) {
            $attributes['currency'] = strtoupper((string) $attributes['currency']);
            if (!preg_match('/^[A-Z]{3}$/D', (string) $attributes['currency'])) {
                throw new InvalidArgumentException('Devise invalide.');
            }
        }
        if (array_key_exists('expires_at', $data)) {
            $expires = trim((string) $data['expires_at']);
            $attributes['expires_at'] = $expires === '' ? null : gmdate('Y-m-d H:i:s', strtotime($expires));
        }
        $attributes['updated_by'] = $userId;

        if ($items !== null) {
            if ($items === []) {
                throw new InvalidArgumentException('Chaque devis doit contenir au moins une ligne.');
            }
            if (count($items) > (int) $config['max_lines']) {
                throw new InvalidArgumentException('Le nombre maximal de lignes du devis est dépassé.');
            }
            $normalizedItems = [];
            foreach ($items as $item) {
                $normalizedItems[] = $this->normalizeItem($item, (int) $config['max_quantity_per_line']);
            }
            $totals = $this->calculateTotals($normalizedItems);
            $attributes = array_merge($attributes, $totals);
            $this->repository->replaceItems((int) $quote->getAttribute('id'), $normalizedItems);
        }

        $old = $quote->toArray();
        $updated = $this->repository->update($quote, $attributes);
        $this->repository->addHistory(
            (int) $quote->getAttribute('id'),
            $userId,
            'updated',
            'Informations ou lignes du devis modifiées.',
            $old,
            $updated->toArray()
        );
        return $updated;
    }

    public function convert(QuoteRecord $quote, ?int $userId = null): QuoteRecord
    {
        if ((string) $quote->getAttribute('status') !== QuoteState::ACCEPTED) {
            throw new InvalidArgumentException('Seul un devis accepté peut être converti.');
        }
        if ($quote->getAttribute('converted_order_id') !== null) {
            throw new InvalidArgumentException('Ce devis a déjà été converti.');
        }
        throw new RuntimeException('La conversion vers les ventes doit être confirmée par une application utilisant SalesPlatform.');
    }

    /** @return list<array<string, mixed>> */
    public function searchCatalog(?string $term, ?int $categoryId, ?int $brandId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $query = CatalogProduct::query()->where('status', '=', 'active');
        $term = trim((string) $term);
        if ($term !== '') {
            $query->where('name', 'LIKE', '%' . $term . '%');
        }
        if ($categoryId !== null) {
            $query->where('category_id', '=', $categoryId);
        }
        if ($brandId !== null) {
            $query->where('brand_id', '=', $brandId);
        }
        $products = $query->select('id', 'name', 'description', 'category_id', 'brand_id')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $results = [];
        foreach ($products as $product) {
            $variant = CatalogVariant::query()
                ->where('product_id', '=', (int) $product->getAttribute('id'))
                ->where('status', '=', 'active')
                ->orderBy('is_default', 'DESC')
                ->orderBy('id')
                ->first();
            $results[] = [
                'id' => (int) $product->getAttribute('id'),
                'name' => (string) $product->getAttribute('name'),
                'description' => (string) ($product->getAttribute('description') ?? ''),
                'reference' => $variant instanceof CatalogVariant ? (string) $variant->getAttribute('sku') : null,
                'unit_price' => $variant instanceof CatalogVariant ? (string) $variant->getAttribute('price') : '0.00',
            ];
        }
        return $results;
    }

    /** @param array<string, mixed> $item */
    private function normalizeItem(array $item, int $maxQuantity): array
    {
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($quantity === false || $quantity <= 0 || $quantity > $maxQuantity) {
            throw new InvalidArgumentException('Quantité de ligne invalide.');
        }

        $productId = isset($item['item_id']) ? (int) $item['item_id'] : 0;
        $name = trim((string) ($item['item_name'] ?? $item['name'] ?? ''));
        $description = trim((string) ($item['description'] ?? '')) ?: null;
        $itemReference = trim((string) ($item['item_reference'] ?? '')) ?: null;
        $price = filter_var($item['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($productId > 0) {
            $product = CatalogProduct::query()
                ->where('id', '=', $productId)
                ->where('status', '=', 'active')
                ->first();
            if (!$product instanceof CatalogProduct) {
                throw new InvalidArgumentException('Un produit ou service sélectionné n’est plus disponible.');
            }
            $variant = CatalogVariant::query()
                ->where('product_id', '=', $productId)
                ->where('status', '=', 'active')
                ->orderBy('is_default', 'DESC')
                ->orderBy('id')
                ->first();
            $name = trim((string) $product->getAttribute('name'));
            $description = trim((string) ($product->getAttribute('description') ?? '')) ?: $description;
            $itemReference = $variant instanceof CatalogVariant
                ? (string) $variant->getAttribute('sku')
                : ((trim((string) ($product->getAttribute('sku') ?? '')) !== '') 
                    ? trim((string) $product->getAttribute('sku'))
                    : null);
            $price = $variant instanceof CatalogVariant
                ? filter_var($variant->getAttribute('price'), FILTER_VALIDATE_FLOAT)
                : 0.0;
        }
        if ($name === '' || strlen($name) > 190) {
            throw new InvalidArgumentException('Le nom d’une ligne est invalide.');
        }
        if ($price === false || $price < 0) {
            throw new InvalidArgumentException('Prix unitaire invalide.');
        }

        $discountType = (string) ($item['discount_type'] ?? 'none');
        if (!in_array($discountType, ['none', 'fixed', 'percent'], true)) {
            throw new InvalidArgumentException('Type de remise invalide.');
        }
        $discountValue = (float) ($item['discount_value'] ?? 0);
        if ($discountValue < 0) {
            throw new InvalidArgumentException('Remise invalide.');
        }
        $taxRate = (float) ($item['tax_rate'] ?? 0);
        if ($taxRate < 0 || $taxRate > 100) {
            throw new InvalidArgumentException('Taux de taxe invalide.');
        }

        $normalizedQuantity = number_format($quantity, 6, '.', '');
        $unitPrice = number_format($price, 2, '.', '');
        $lineSubtotal = $this->roundMoney((float) $quantity * (float) $unitPrice);
        $discount = $discountType === 'percent'
            ? $this->roundMoney($lineSubtotal * $discountValue / 100)
            : ($discountType === 'fixed' ? min($lineSubtotal, $discountValue) : 0.0);
        $taxAmount = $this->roundMoney(($lineSubtotal - $discount) * $taxRate / 100);

        return [
            'item_type' => (string) ($item['item_type'] ?? 'product'),
            'item_id' => $productId > 0 ? $productId : (isset($item['item_id']) ? (int) $item['item_id'] : null),
            'item_reference' => $itemReference,
            'item_name' => $name,
            'description' => $description,
            'quantity' => $normalizedQuantity,
            'unit_price' => $unitPrice,
            'discount_type' => $discountType,
            'discount_value' => number_format($discountValue, 6, '.', ''),
            'tax_rate' => number_format($taxRate, 4, '.', ''),
            'tax_amount' => number_format($taxAmount, 2, '.', ''),
            'line_subtotal' => number_format($lineSubtotal, 2, '.', ''),
            'line_total' => number_format($lineSubtotal - $discount + $taxAmount, 2, '.', ''),
        ];
    }

    /** @param list<array<string, mixed>> $items @return array<string, string> */
    private function calculateTotals(array $items): array
    {
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) $item['line_subtotal'];
            $discount += (float) $item['line_subtotal']
                - ((float) $item['line_total'] - (float) $item['tax_amount']);
            $tax += (float) $item['tax_amount'];
        }
        $total = $subtotal - $discount + $tax;
        return [
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'discount_total' => number_format($discount, 2, '.', ''),
            'tax_total' => number_format($tax, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
        ];
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }

    private function isExpired(QuoteRecord $quote): bool
    {
        $expires = $quote->getAttribute('expires_at');
        return is_string($expires) && strtotime($expires . ' UTC') !== false && strtotime($expires . ' UTC') < time();
    }

    private function reference(): string
    {
        do {
            $reference = 'DEV-' . strtoupper(bin2hex(random_bytes(8)));
        } while ($this->repository->findByReference($reference) !== null);
        return $reference;
    }
}
