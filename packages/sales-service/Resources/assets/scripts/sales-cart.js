(() => {
    'use strict';

    const root = document.querySelector('[data-sales-cart]');
    if (!root) return;

    const storageKey = `sales.cart.v1:${window.location.host}`;
    const list = root.querySelector('[data-cart-items]');
    const empty = root.querySelector('[data-cart-empty]');
    const status = root.querySelector('[data-cart-status]');
    const total = root.querySelector('[data-cart-total]');
    const totalLabel = root.querySelector('[data-cart-total-label]');
    const pricingError = root.querySelector('[data-cart-pricing-error]');
    const clearButton = root.querySelector('[data-cart-clear]');
    const checkoutLink = root.querySelector('[data-cart-checkout]');
    const dialog = root.querySelector('#sales-cart-panel');
    let items = [];
    let unavailable = false;
    let returnFocus = null;
    let pricingPreview = null;
    let previewRequest = 0;

    const report = (message) => {
        status.textContent = message;
        window.dispatchEvent(new CustomEvent('sales:cart-error', { detail: { message } }));
    };

    const amountToUnits = (value) => {
        const price = String(value).trim();
        if (!/^\d{1,14}(?:\.\d{1,4})?$/.test(price)) {
            throw new Error('Le prix de cet article n’est pas valide.');
        }
        const [whole, fraction = ''] = price.split('.');
        return (BigInt(whole) * 10000n) + BigInt(fraction.padEnd(4, '0'));
    };

    const validItem = (item) => item
        && typeof item.id === 'string'
        && item.id.length > 0
        && item.id.length <= 120
        && typeof item.name === 'string'
        && item.name.length > 0
        && item.name.length <= 240
        && /^[A-Z]{3}$/.test(item.currency)
        && typeof item.unitAmount === 'string'
        && /^\d{1,18}$/.test(item.unitAmount)
        && Number.isInteger(item.quantity)
        && item.quantity >= 1
        && item.quantity <= 99;

    const load = () => {
        try {
            const stored = window.localStorage.getItem(storageKey);
            if (stored === null) return [];
            const parsed = JSON.parse(stored);
            if (!Array.isArray(parsed) || parsed.length > 100 || !parsed.every(validItem)) {
                throw new Error('Le contenu enregistré du panier est invalide.');
            }
            return parsed;
        } catch (error) {
            unavailable = true;
            report(error instanceof DOMException
                ? 'Le stockage local est inaccessible ; le panier ne peut pas être utilisé.'
                : 'Le panier enregistré est invalide. Effacez les données du site pour le réinitialiser.');
            return [];
        }
    };

    const save = () => {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(items));
            return true;
        } catch (error) {
            report(error instanceof DOMException
                ? 'Le navigateur n’a pas pu enregistrer le panier.'
                : 'Une erreur empêche l’enregistrement du panier.');
            return false;
        }
    };

    const formatAmount = (units, currency) => {
        const value = BigInt(units);
        const whole = value / 10000n;
        const fraction = String(value % 10000n).padStart(4, '0').replace(/0+$/, '');
        const grouped = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(whole);
        const decimal = new Intl.NumberFormat('fr-FR')
            .formatToParts(1.1)
            .find((part) => part.type === 'decimal')?.value || ',';
        return `${grouped}${fraction ? decimal + fraction : ''} ${currency}`;
    };

    const formatMinor = (minor, currency) => formatAmount(BigInt(minor) * 100n, currency);
    const discountLabel = (discount) => {
        const target = ({product: 'Produit', category: 'Catégorie', brand: 'Marque'})[discount.target_type] || 'Promotion';
        const value = discount.discount_type === 'percentage'
            ? `${discount.value} %`
            : `${discount.value} ${discount.currency || ''}`.trim();
        return `Réduction ${target} : ${value}`;
    };

    const makeButton = (label, action, className) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.dataset.cartAction = action;
        button.className = className;
        return button;
    };

    const render = () => {
        list.replaceChildren();
        let count = 0;
        const previewItems = new Map(
            (pricingPreview?.items || []).map((item) => [item.item_reference, item])
        );

        for (const item of items) {
            count += item.quantity;
            const pricedItem = previewItems.get(item.id);

            const row = document.createElement('li');
            row.className = 'rounded-md border-2 border-dashed border-[#10302c] bg-white p-3 dark:border-[#3a5f59] dark:bg-[#143632] sm:p-4';

            const heading = document.createElement('div');
            heading.className = 'flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between';
            const name = document.createElement('p');
            name.className = 'break-words font-bold text-[#10302c] dark:text-[#cfe8dc]';
            name.textContent = item.name;
            const remove = makeButton('Supprimer', 'remove', 'inline-flex min-h-11 shrink-0 items-center justify-center rounded-md bg-[#cfe8dc] px-3 text-xs font-bold text-[#10302c] underline underline-offset-2 shadow-[0_3px_0_#10302c] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] dark:bg-[#0c1c1a] dark:text-[#cfe8dc] dark:shadow-[0_3px_0_#3a5f59]');
            remove.dataset.productId = item.id;
            heading.append(name, remove);

            const details = document.createElement('div');
            details.className = 'mt-3 flex flex-wrap items-end justify-between gap-3 text-sm';
            const quantity = document.createElement('div');
            quantity.className = 'inline-flex items-center gap-2';
            const decrease = makeButton('−', 'decrease', 'inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border-2 border-[#10302c] bg-white text-lg font-bold shadow-[0_3px_0_#10302c] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] dark:border-[#3a5f59] dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59]');
            decrease.dataset.productId = item.id;
            decrease.setAttribute('aria-label', `Réduire la quantité de ${item.name}`);
            const quantityLabel = document.createElement('span');
            quantityLabel.className = 'min-w-6 text-center font-bold tabular-nums';
            quantityLabel.textContent = String(item.quantity);
            const increase = makeButton('+', 'increase', 'inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border-2 border-[#10302c] bg-white text-lg font-bold shadow-[0_3px_0_#10302c] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] dark:border-[#3a5f59] dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59]');
            increase.dataset.productId = item.id;
            increase.setAttribute('aria-label', `Augmenter la quantité de ${item.name}`);
            quantity.append(decrease, quantityLabel, increase);

            const amount = document.createElement('span');
            amount.className = 'min-w-0 max-w-[55%] shrink-0 break-words text-right tabular-nums';
            if (pricedItem && Number(pricedItem.saving_minor) > 0) {
                const original = document.createElement('span');
                original.className = 'block break-words text-xs line-through opacity-75';
                original.textContent = formatMinor(pricedItem.original_line_amount_minor, item.currency);
                amount.append(original);

                const saving = document.createElement('span');
                saving.className = 'mt-1 block break-words text-xs font-bold';
                saving.textContent = `−${formatMinor(pricedItem.saving_minor, item.currency)}`;
                amount.append(saving);
            }
            const lineTotal = document.createElement('span');
            lineTotal.className = 'block break-words font-extrabold text-[#10302c] dark:text-[#cfe8dc]';
            lineTotal.textContent = pricedItem
                ? formatMinor(pricedItem.line_amount_minor, item.currency)
                : '—';
            amount.append(lineTotal);
            details.append(quantity, amount);
            row.append(heading, details);
            if (pricedItem && Number(pricedItem.saving_minor) > 0) {
                const promotionDetails = document.createElement('div');
                promotionDetails.className = 'mt-2 break-words text-xs font-bold';
                (pricedItem.applied_discounts || []).forEach((discount) => {
                    const detail = document.createElement('p');
                    detail.textContent = discountLabel(discount);
                    promotionDetails.append(detail);
                });
                row.append(promotionDetails);
            }
            list.append(row);
        }

        document.querySelectorAll('[data-cart-count]').forEach((element) => {
            element.textContent = String(count);
        });
        root.querySelectorAll('[data-cart-count-label]').forEach((element) => {
            element.textContent = `${count} ${count === 1 ? 'article' : 'articles'}`;
        });
        empty.hidden = items.length > 0;
        if (checkoutLink) checkoutLink.hidden = items.length === 0 || !pricingPreview;
        clearButton.disabled = unavailable || items.length === 0;
        const totalSaving = Number(pricingPreview?.saving_minor || 0);
        const savingOutput = root.querySelector('[data-cart-saving]');
        if (savingOutput) {
            savingOutput.hidden = items.length === 0 || totalSaving <= 0;
            savingOutput.textContent = savingOutput.hidden
                ? ''
                : `Économie totale : ${formatMinor(totalSaving, pricingPreview.currency)}`;
        }
        total.textContent = items.length > 0
            ? (pricingPreview ? formatMinor(pricingPreview.subtotal_minor, pricingPreview.currency) : '—')
            : '0 MAD';
        if (totalLabel) totalLabel.textContent = 'Sous-total';
    };

    const refreshPricePreview = async () => {
        const requestId = ++previewRequest;
        pricingPreview = null;
        if (pricingError) {
            pricingError.hidden = true;
            pricingError.textContent = '';
        }
        render();
        if (items.length === 0) return;
        if (!window.api || typeof window.api.post !== 'function') {
            const message = 'La tarification est indisponible pour le moment.';
            if (pricingError) {
                pricingError.textContent = message;
                pricingError.hidden = false;
            }
            report(message);
            return;
        }

        const formData = new FormData();
        formData.set('items_json', JSON.stringify(items.map(({ id, quantity }) => ({ id, quantity }))));
        try {
            const response = await window.api.post('/api/storefront/cart-pricing', formData, {
                toast: { off: true },
            });
            if (requestId !== previewRequest) return;
            if (!response.success || !response.data || !Array.isArray(response.data.items)) {
                throw new Error(response.message || 'Les prix et promotions du panier ne sont pas disponibles.');
            }
            pricingPreview = response.data;
            render();
        } catch (error) {
            if (requestId !== previewRequest) return;
            pricingPreview = null;
            render();
            const message = error instanceof Error
                ? error.message
                : 'Les prix et promotions ne sont pas disponibles.';
            if (pricingError) {
                pricingError.textContent = message;
                pricingError.hidden = false;
            }
            report(message);
        }
    };

    const notify = (message) => {
        render();
        status.textContent = message;
        window.dispatchEvent(new CustomEvent('sales:cart-updated', { detail: { items: [...items] } }));
        refreshPricePreview();
    };

    const setItems = (next, message) => {
        if (unavailable) return;
        const previous = items;
        items = next;
        if (!save()) {
            items = previous;
            render();
            return;
        }
        notify(message);
    };

    const openCart = (trigger) => {
        returnFocus = trigger || document.activeElement;
        root.hidden = false;
        dialog.focus();
        document.addEventListener('keydown', onEscape);
    };

    const closeCart = () => {
        root.hidden = true;
        document.removeEventListener('keydown', onEscape);
        if (returnFocus instanceof HTMLElement) returnFocus.focus();
    };

    function onEscape(event) {
        if (event.key === 'Escape') closeCart();
    }

    document.querySelectorAll('[data-cart-open]').forEach((button) => {
        button.addEventListener('click', () => openCart(button));
    });
    root.querySelectorAll('[data-cart-close]').forEach((button) => {
        button.addEventListener('click', closeCart);
    });

    document.querySelectorAll('[data-sales-product]').forEach((product) => {
        const addButton = product.querySelector('[data-cart-add]');
        addButton?.addEventListener('click', () => {
            if (unavailable) return;
            const id = product.dataset.productId || '';
            const name = product.dataset.productName || '';
            const currency = (product.dataset.productCurrency || '').toUpperCase();
            const quantity = Number(product.querySelector('[data-product-quantity]')?.value);

            if (!Number.isInteger(quantity) || quantity < 1 || quantity > 99) {
                report('Choisissez une quantité comprise entre 1 et 99.');
                product.querySelector('[data-product-quantity]')?.focus();
                return;
            }

            try {
                const unitAmount = amountToUnits(product.dataset.productPrice || '').toString();
                if (!id || id.length > 120 || !name || name.length > 240 || !/^[A-Z]{3}$/.test(currency)) {
                    throw new Error('Les informations de cet article ne sont pas valides.');
                }
                if (items.some((item) => item.currency !== currency)) {
                    throw new Error('Un panier ne peut pas contenir plusieurs devises.');
                }

                const existing = items.find((item) => item.id === id);
                if (existing && existing.quantity + quantity > 99) {
                    throw new Error('La quantité maximale par article est de 99.');
                }
                if (!existing && items.length >= 100) {
                    throw new Error('Le panier ne peut pas contenir plus de 100 articles différents.');
                }

                const next = existing
                    ? items.map((item) => item.id === id
                        ? { ...item, unitAmount, quantity: item.quantity + quantity }
                        : item)
                    : [...items, { id, name, currency, unitAmount, quantity }];
                setItems(next, `${name} ajouté au panier.`);
                product.closest('[data-product-modal]')?.close();
                openCart(document.querySelector('[data-cart-open]'));
            } catch (error) {
                report(error instanceof Error ? error.message : 'Impossible d’ajouter cet article au panier.');
            }
        });
    });

    list.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        const button = event.target.closest('[data-cart-action]');
        if (!button || unavailable) return;
        const item = items.find((entry) => entry.id === button.dataset.productId);
        if (!item) return;

        let next;
        switch (button.dataset.cartAction) {
            case 'remove':
                next = items.filter((entry) => entry.id !== item.id);
                break;
            case 'decrease':
                next = item.quantity === 1
                    ? items.filter((entry) => entry.id !== item.id)
                    : items.map((entry) => entry.id === item.id ? { ...entry, quantity: entry.quantity - 1 } : entry);
                break;
            case 'increase':
                if (item.quantity >= 99) {
                    report('La quantité maximale par article est de 99.');
                    return;
                }
                next = items.map((entry) => entry.id === item.id ? { ...entry, quantity: entry.quantity + 1 } : entry);
                break;
            default:
                return;
        }
        setItems(next, 'Panier mis à jour.');
    });

    clearButton.addEventListener('click', () => {
        if (items.length > 0) setItems([], 'Panier vidé.');
    });

    window.addEventListener('storage', (event) => {
        if (event.key !== storageKey) return;
        items = load();
        refreshPricePreview();
        report('Le panier a été mis à jour dans un autre onglet.');
    });

    window.addEventListener('sales:checkout-completed', () => {
        items = [];
        pricingPreview = null;
        render();
    });

    items = load();
    refreshPricePreview();
})();
