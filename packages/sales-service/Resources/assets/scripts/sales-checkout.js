(() => {
    'use strict';

    const storageKey = `sales.cart.v1:${window.location.host}`;
    const complete = document.querySelector('[data-sales-checkout-complete]');
    if (complete) {
        try {
            window.localStorage.removeItem(storageKey);
            window.dispatchEvent(new CustomEvent('sales:checkout-completed'));
        } catch (error) {
            console.error('Unable to clear the completed storefront cart.', error);
        }
        return;
    }

    const form = document.querySelector('[data-storefront-checkout-form]');
    if (!form) return;

    const itemsList = form.querySelector('[data-checkout-items]');
    const emptyMessage = form.querySelector('[data-checkout-empty]');
    const totalOutput = form.querySelector('[data-checkout-total]');
    const savingOutput = form.querySelector('[data-checkout-saving]');
    const payload = form.querySelector('[name="items_json"]');
    const submit = form.querySelector('[data-checkout-submit]');
    let items = [];

    const showError = (message) => {
        return;
        const error = document.createElement('p');
        error.setAttribute('role', 'alert');
        error.className = 'mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200';
        error.textContent = message;
        form.prepend(error);
    };

    const formatAmount = (units, currency) => {
        const amount = BigInt(units);
        const whole = amount / 10000n;
        const fraction = String(amount % 10000n).padStart(4, '0').replace(/0+$/, '');
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

    try {
        const stored = window.localStorage.getItem(storageKey);
        const parsed = stored === null ? [] : JSON.parse(stored);
        if (!Array.isArray(parsed) || parsed.length > 100 || !parsed.every((item) =>
            item && typeof item.id === 'string' && item.id !== ''
            && typeof item.name === 'string' && item.name !== ''
            && typeof item.currency === 'string' && /^[A-Z]{3}$/.test(item.currency)
            && typeof item.unitAmount === 'string' && /^\d{1,18}$/.test(item.unitAmount)
            && Number.isInteger(item.quantity) && item.quantity >= 1 && item.quantity <= 99
        )) {
            throw new Error('Le panier enregistré est invalide.');
        }
        items = parsed;
    } catch (error) {
        showError(error instanceof Error ? error.message : 'Le panier enregistré ne peut pas être lu.');
    }

    const renderPreview = (preview) => {
        itemsList.replaceChildren();
        for (const item of preview.items) {
            const row = document.createElement('li');
            row.className = 'flex min-w-0 items-start justify-between gap-3 py-3 text-sm sm:gap-4';
            const description = document.createElement('span');
            description.className = 'min-w-0 break-words text-[#10302c] dark:text-[#cfe8dc]';
            description.textContent = `${item.label} × ${item.quantity}`;
            if (Number(item.saving_minor) > 0) {
                const saving = document.createElement('span');
                saving.className = 'mt-1 block break-words text-xs font-bold';
                saving.textContent = `Réduction appliquée : −${formatMinor(item.saving_minor, item.currency)}`;
                description.append(saving);
                (item.applied_discounts || []).forEach((discount) => {
                    const detail = document.createElement('span');
                    detail.className = 'mt-1 block break-words text-xs';
                    detail.textContent = discountLabel(discount);
                    description.append(detail);
                });
            }
            const price = document.createElement('span');
            price.className = 'max-w-[45%] shrink-0 break-words text-right tabular-nums';
            if (Number(item.saving_minor) > 0) {
                const original = document.createElement('span');
                original.className = 'block break-words text-xs line-through opacity-75';
                original.textContent = formatMinor(item.original_line_amount_minor, item.currency);
                price.append(original);
            }
            const final = document.createElement('span');
            final.className = 'block break-words font-extrabold text-[#10302c] dark:text-[#cfe8dc]';
            final.textContent = formatMinor(item.line_amount_minor, item.currency);
            price.append(final);
            row.append(description, price);
            itemsList.append(row);
        }
        emptyMessage.hidden = preview.items.length > 0;
        totalOutput.textContent = formatMinor(preview.subtotal_minor, preview.currency);
        savingOutput.hidden = Number(preview.saving_minor) <= 0;
        if (!savingOutput.hidden) {
            savingOutput.textContent = `Économie totale : ${formatMinor(preview.saving_minor, preview.currency)}`;
        }
    };

    const loadPricePreview = async () => {
        if (items.length === 0) {
            itemsList.replaceChildren();
            emptyMessage.hidden = false;
            totalOutput.textContent = '—';
            savingOutput.hidden = true;
            submit.disabled = true;
            return;
        }

        submit.disabled = true;
        totalOutput.textContent = 'Calcul des prix…';
        const previewForm = new FormData();
        previewForm.set('items_json', JSON.stringify(items.map((item) => ({
            id: item.id,
            quantity: item.quantity,
        }))));
        try {
            const response = await window.api.post('/api/storefront/cart-pricing', previewForm, {
                toast: { off: true },
            });
            if (!response.success || !response.data || !Array.isArray(response.data.items)) {
                throw new Error(response.message || 'Les prix du panier n’ont pas pu être calculés.');
            }
            renderPreview(response.data);
            submit.disabled = false;
        } catch (error) {
            totalOutput.textContent = '—';
            savingOutput.hidden = true;
            showError(error instanceof Error ? error.message : 'Les prix du panier n’ont pas pu être calculés.');
        }
    };
    emptyMessage.hidden = items.length > 0;
    loadPricePreview();

    const deliveryDetails = form.querySelector('[data-delivery-details]');
    const deliveryFields = ['delivery_street', 'delivery_city', 'delivery_postal_code']
        .map((name) => form.elements.namedItem(name));
    const updateFulfillment = () => {
        const delivery = form.elements.namedItem('fulfillment_mode').value === 'delivery';
        deliveryDetails.hidden = !delivery;
        deliveryFields.forEach((field) => { field.required = delivery; });
    };
    form.querySelectorAll('[name="fulfillment_mode"]').forEach((input) => {
        input.addEventListener('change', updateFulfillment);
    });
    updateFulfillment();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (items.length === 0) {
            showError('Ajoutez au moins un article avant de confirmer la commande.');
            return;
        }
        payload.value = JSON.stringify(items.map((item) => ({
            id: item.id,
            quantity: item.quantity,
        })));
        submit.disabled = true;
        submit.textContent = 'Enregistrement de la commande…';
        form.querySelectorAll('[role="alert"]').forEach((alert) => alert.remove());
        try {
            const response = await window.api.post('/checkout/order', new FormData(form), {
                toast: { off: true },
            });
            window.localStorage.removeItem(storageKey);
            window.dispatchEvent(new CustomEvent('sales:checkout-completed'));

            const section = document.createElement('section');
            section.className = 'rounded-md border-2 border-dashed border-[#10302c] bg-white p-4 shadow-[0_6px_0_#10302c] [font-family:Anybody,ui-sans-serif,system-ui,sans-serif] dark:border-[#3a5f59] dark:bg-[#143632] dark:shadow-[0_6px_0_#3a5f59] sm:p-8';
            const heading = document.createElement('h1');
            heading.className = 'break-words text-2xl font-extrabold text-[#10302c] [font-stretch:80%] dark:text-[#cfe8dc] sm:text-3xl';
            heading.textContent = 'Commande enregistrée';
            const reference = document.createElement('p');
            reference.className = 'mt-3 break-words text-[#10302c] dark:text-[#cfe8dc]';
            reference.textContent = `Votre référence de commande : ${response.data.reference}`;
            const instructions = document.createElement('p');
            instructions.className = 'mt-2 text-sm';
            instructions.textContent = 'Conservez cette référence pour consulter l’état de votre commande.';
            const link = document.createElement('a');
            link.href = '/suivi-commande';
            link.className = 'mt-6 inline-flex min-h-11 items-center rounded-md bg-[#ff7a1a] px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] dark:shadow-[0_4px_0_#3a5f59]';
            link.textContent = 'Suivre ma commande';
            section.append(heading, reference, instructions, link);
            form.closest('main')?.replaceChildren(section);
        } catch (error) {
            showError(error instanceof Error ? error.message : 'La commande n’a pas pu être enregistrée.');
            submit.disabled = false;
            submit.textContent = 'Confirmer la commande';
        }
    });
})();
