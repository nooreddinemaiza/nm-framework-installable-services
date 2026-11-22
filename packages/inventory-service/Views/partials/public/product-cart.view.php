<?php
$cartProductPrice = (string) ($discountFinalPrice ?? $product['price'] ?? '');
$cartProductCurrency = strtoupper((string) ($product['currency'] ?? app()->env('APP_CURRENCY', 'MAD')));
$cartProductId = (string) ($product['id'] ?? $product['slug'] ?? '');
$cartProductName = (string) ($product['name'] ?? '');
$cartQuantityId = 'sales-product-quantity-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $cartProductId);
?>
<?php if (!empty($cart_enabled) && !empty($hasPrice) && !$isService): ?>
    <section
        data-sales-product
        data-product-id="<?= htmlspecialchars($cartProductId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
        data-product-name="<?= htmlspecialchars($cartProductName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
        data-product-price="<?= htmlspecialchars($cartProductPrice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
        data-product-currency="<?= htmlspecialchars($cartProductCurrency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
        class="mt-5 rounded-md border-2 border-dashed border-[#10302c] p-4 text-[#10302c] dark:border-[#cfe8dc]/60 dark:text-[#cfe8dc]"
    >
        <label for="<?= htmlspecialchars($cartQuantityId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="block text-sm font-extrabold">Quantité</label>
        <div class="mt-2 flex flex-wrap items-end gap-3">
            <input
                id="<?= htmlspecialchars($cartQuantityId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-product-quantity
                type="number"
                min="1"
                max="99"
                step="1"
                value="1"
                inputmode="numeric"
                class="min-h-11 w-24 rounded-md border-2 border-[#10302c] bg-white px-3 text-base font-bold tabular-nums text-[#10302c] focus:border-[#ff7a1a] focus:outline-none focus:ring-4 focus:ring-[#ff7a1a]/40 dark:border-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]"
            >
            <button
                type="button"
                data-cart-add
                class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-md bg-[#ff7a1a] px-4 text-sm font-extrabold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 focus:outline-none focus-visible:ring-4 focus-visible:ring-[#ff7a1a]/40 dark:shadow-[0_4px_0_#3a5f59]"
            >
                Ajouter au panier
            </button>
        </div>
        <noscript><p class="mt-2 text-sm font-bold underline">Activez JavaScript pour ajouter cet article au panier.</p></noscript>
    </section>
<?php endif; ?>