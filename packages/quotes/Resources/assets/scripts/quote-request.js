(() => {
    'use strict';

    document.querySelectorAll('[data-quote-request-root]').forEach((root) => {
        const dialog = root.querySelector('[data-quote-dialog]');
        const form = root.querySelector('[data-quote-form]');
        const search = root.querySelector('[data-quote-search]');
        const results = root.querySelector('[data-quote-results]');
        const status = root.querySelector('[data-quote-status]');
        if (!dialog || !form || !results || !status) return;

        const source = root.dataset.quoteSource || '';
        const initialCategory = Number(root.dataset.quoteCategory || 0) || null;
        const initialBrand = Number(root.dataset.quoteBrand || 0) || null;
        const initialItems = root.dataset.quoteItem
            ? [{ item_id: Number(root.dataset.quoteItem), quantity: 1 }]
            : [];
        let selected = [...initialItems];
        let debounce = 0;
        let activeRequest = null;

        const open = root.querySelector('[data-quote-open]');
        open?.addEventListener('click', () => dialog.showModal());

        dialog.addEventListener('close', () => {
            window.clearTimeout(debounce);
            activeRequest?.abort();
        });

        const render = () => {
            results.replaceChildren();
            selected.forEach((item, index) => {
                const row = document.createElement('div');
                row.className = 'flex items-center gap-3 rounded-md border px-3 py-2';
                const name = document.createElement('span');
                name.className = 'min-w-0 flex-1';
                name.textContent = item.name || 'Produit sélectionné';
                const quantity = document.createElement('input');
                quantity.type = 'number';
                quantity.min = '0.000001';
                quantity.step = '0.000001';
                quantity.value = String(item.quantity || 1);
                quantity.className = 'w-28 rounded-md border px-2 py-1';
                quantity.addEventListener('change', () => {
                    selected[index].quantity = quantity.value;
                });
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'rounded-md border px-2 py-1';
                remove.textContent = 'Retirer';
                remove.addEventListener('click', () => {
                    selected.splice(index, 1);
                    render();
                });
                row.append(name, quantity, remove);
                results.append(row);
            });
        };

        const searchCatalog = async () => {
            activeRequest?.abort();
            activeRequest = new AbortController();
            const term = (search?.value || '').trim();
            if (!term && source !== 'category' && source !== 'brand') {
                results.replaceChildren();
                status.textContent = '';
                return;
            }
            const params = new URLSearchParams({ limit: '20' });
            if (term) params.set('term', term);
            if (initialCategory) params.set('category_id', String(initialCategory));
            if (initialBrand) params.set('brand_id', String(initialBrand));
            status.textContent = 'Recherche…';
            try {
                const response = await fetch('/api/quotes/catalog-search?' + params, {
                    headers: { Accept: 'application/json' },
                    signal: activeRequest.signal,
                });
                if (!response.ok) throw new Error('Recherche indisponible.');
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Recherche indisponible.');
                results.replaceChildren();
                (data.data || []).forEach((item) => {
                    const row = document.createElement('button');
                    row.type = 'button';
                    row.className = 'block w-full rounded-md border px-3 py-2 text-left hover:bg-slate-50';
                    row.textContent = item.name + (item.reference ? ' — ' + item.reference : '');
                    row.addEventListener('click', () => {
                        if (!selected.some((entry) => entry.item_id === item.id)) {
                            selected.push({
                                item_id: item.id,
                                item_name: item.name,
                                item_reference: item.reference,
                                quantity: 1,
                                name: item.name,
                            });
                        }
                        render();
                    });
                    results.append(row);
                });
                status.textContent = (data.data || []).length ? '' : 'Aucun résultat.';
            } catch (error) {
                if (error.name !== 'AbortError') status.textContent = 'Impossible de rechercher les articles.';
            }
        };

        search?.addEventListener('input', () => {
            window.clearTimeout(debounce);
            debounce = window.setTimeout(searchCatalog, 350);
        });
        if (source === 'category' || source === 'brand') searchCatalog();
        render();

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (selected.length === 0) {
                status.textContent = 'Sélectionnez au moins un article.';
                return;
            }
            const submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            try {
                const items = selected.map((item) => ({
                    item_id: item.item_id,
                    quantity: item.quantity,
                }));
                const formData = new FormData(form);
                const payload = Object.fromEntries(formData.entries());
                payload.items = items;
                const response = await window.api.post('/api/quotes/submit', payload, { toast: false });
                form.hidden = true;
                status.textContent = 'Demande enregistrée. Référence : ' + response.reference;
            } catch (error) {
                status.textContent = error?.message || 'Impossible d’enregistrer la demande.';
            } finally {
                submit.disabled = false;
            }
        });
    });
})();
