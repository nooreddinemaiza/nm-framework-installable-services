(() => {
    'use strict';

    const form = document.querySelector('[data-order-lookup-form]');
    const result = document.querySelector('[data-order-lookup-result]');
    if (!(form instanceof HTMLFormElement) || !(result instanceof HTMLElement)) return;

    const showMessage = (message, isError = false) => {
        const paragraph = document.createElement('p');
        paragraph.className = isError
            ? 'rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900'
            : 'rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900';
        paragraph.textContent = message;
        result.replaceChildren(paragraph);
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = form.querySelector('button[type="submit"]');
        if (submit instanceof HTMLButtonElement) submit.disabled = true;
        result.replaceChildren();

        try {
            const response = await window.api.post('/api/storefront/order-status', new FormData(form), {
                toast: { off: true },
            });
            const card = document.createElement('section');
            card.className = 'rounded-xl border border-slate-200 p-4 dark:border-slate-700';
            const heading = document.createElement('h2');
            heading.className = 'font-semibold text-slate-950 dark:text-white';
            heading.textContent = `Commande ${response.data.reference}`;
            const status = document.createElement('p');
            status.className = 'mt-2 text-sm text-slate-700 dark:text-slate-200';
            status.textContent = `État : ${response.data.status}`;
            const total = document.createElement('p');
            total.className = 'mt-1 text-sm text-slate-700 dark:text-slate-200';
            total.textContent = `Total : ${response.data.total}`;
            card.append(heading, status, total);
            if (response.data.saving && response.data.items.some((item) => Number(item.saving_minor) > 0)) {
                const saving = document.createElement('p');
                saving.className = 'mt-1 text-sm font-semibold text-emerald-700 dark:text-emerald-300';
                saving.textContent = `Économie totale : ${response.data.saving}`;
                card.append(saving);
            }
            const items = document.createElement('ul');
            items.className = 'mt-3 space-y-1 text-sm text-slate-600 dark:text-slate-300';
            response.data.items.forEach((item) => {
                const line = document.createElement('li');
                line.className = 'flex items-start justify-between gap-4 border-t border-slate-100 py-2 dark:border-slate-800';
                const description = document.createElement('span');
                description.textContent = `${item.label} × ${item.quantity}`;
                if (Number(item.saving_minor) > 0) {
                    const discount = document.createElement('span');
                    discount.className = 'mt-1 block text-xs font-medium text-emerald-700 dark:text-emerald-300';
                    discount.textContent = `Réduction appliquée : −${item.saving}`;
                    description.append(discount);
                    (item.applied_discounts || []).forEach((applied) => {
                        const detail = document.createElement('span');
                        detail.className = 'mt-1 block text-xs text-slate-500 dark:text-slate-400';
                        const target = ({product: 'Produit', category: 'Catégorie', brand: 'Marque'})[applied.target_type] || 'Promotion';
                        const value = applied.discount_type === 'percentage'
                            ? `${applied.value} %`
                            : `${applied.value} ${applied.currency || item.currency}`.trim();
                        detail.textContent = `Réduction ${target} : ${value}`;
                        description.append(detail);
                    });
                }
                const amount = document.createElement('span');
                amount.className = 'shrink-0 text-right tabular-nums';
                if (Number(item.saving_minor) > 0) {
                    const original = document.createElement('span');
                    original.className = 'block text-xs text-slate-500 line-through dark:text-slate-400';
                    original.textContent = item.original_line_total;
                    amount.append(original);
                }
                const final = document.createElement('span');
                final.className = 'block font-semibold text-slate-900 dark:text-white';
                final.textContent = item.line_total;
                amount.append(final);
                line.append(description, amount);
                items.append(line);
            });
            card.append(items);
            result.replaceChildren(card);
        } catch (error) {
            showMessage(error instanceof Error ? error.message : 'La consultation a échoué.', true);
        } finally {
            if (submit instanceof HTMLButtonElement) submit.disabled = false;
        }
    });
})();
