(() => {
    const root = document.querySelector('[data-storefront-root]');
    if (!root) return;

    const results = root.querySelector('[data-storefront-results-container]');
    const activeFilters = root.querySelector('[data-storefront-active-filters]');
    const status = root.querySelector('[data-storefront-status]');
    const count = root.querySelector('[data-storefront-result-count]');
    const countLabel = root.querySelector('[data-storefront-result-label]');
    const searchForm = root.querySelector('[data-storefront-form="search"]');
    const filtersForm = root.querySelector('[data-storefront-form="filters"]');
    const categorySelect = root.querySelector('[data-storefront-category-field]');
    const brandSelect = root.querySelector('[data-storefront-brand-field]');
    const sortSelect = root.querySelector('[data-storefront-sort-field]');
    const searchFields = Array.from(root.querySelectorAll('[data-storefront-search-field]'));
    const categoryLinks = root.querySelector('[data-storefront-category-links]');
    const categoryNav = root.querySelector('[data-storefront-category-nav]');

    if (!results || !activeFilters || !status || !count || !countLabel || !searchForm || !filtersForm) {
        return;
    }

    let activeRequest = null;
    let requestSequence = 0;
    let searchDebounce = 0;

    const normalizeParams = (source) => {
        const params = new URLSearchParams(source);
        params.delete('per_page');
        if (!(params.get('search') || '').trim()) params.delete('search');
        if (!params.get('category_id') || Number(params.get('category_id')) <= 0) params.delete('category_id');
        if (!params.get('brand_id') || Number(params.get('brand_id')) <= 0) params.delete('brand_id');
        if (!['name_asc', 'name_desc'].includes(params.get('sort'))) params.delete('sort');
        if (!params.get('page') || Number(params.get('page')) <= 1) params.delete('page');
        return params;
    };

    const setHiddenField = (form, name, value) => {
        let field = form.querySelector(`input[type="hidden"][name="${name}"]`);
        if (value) {
            if (!field) {
                field = document.createElement('input');
                field.type = 'hidden';
                field.name = name;
                form.append(field);
            }
            field.value = value;
        } else if (field) {
            field.remove();
        }
    };

    const updateOptions = (select, items, allLabel, selectedValue) => {
        if (!select) return;
        const options = [new Option(allLabel, '')];
        items.forEach((item) => options.push(new Option(item.name, String(item.id))));
        select.replaceChildren(...options);
        select.value = selectedValue ? String(selectedValue) : '';
    };

    const updateCategoryLinks = (items, filters) => {
        if (!categoryLinks) return;
        const className = categoryLinks.dataset.linkClass || '';
        const entries = [{ id: 0, name: 'Tout' }, ...items.slice(0, 8)];
        const fragment = document.createDocumentFragment();
        entries.forEach((entry) => {
            const listItem = document.createElement('li');
            listItem.className = 'shrink-0';
            const link = document.createElement('a');
            link.className = className;
            link.href = buildStorefrontUrl(filters, { category_id: entry.id, page: 1 });
            link.textContent = entry.name;
            if (Number(filters.category_id) === Number(entry.id)) {
                link.setAttribute('aria-current', 'true');
                const selectedText = document.createElement('span');
                selectedText.className = 'sr-only';
                selectedText.textContent = ' (sélectionné)';
                link.append(selectedText);
            }
            listItem.append(link);
            fragment.append(listItem);
        });
        categoryLinks.replaceChildren(fragment);
        if (categoryNav) categoryNav.hidden = items.length === 0;
    };

    const buildStorefrontUrl = (filters, changes = {}) => {
        const params = new URLSearchParams();
        const state = { ...filters, ...changes };
        if (state.search) params.set('search', state.search);
        if (Number(state.category_id) > 0) params.set('category_id', String(state.category_id));
        if (Number(state.brand_id) > 0) params.set('brand_id', String(state.brand_id));
        if (state.sort && state.sort !== 'newest') params.set('sort', state.sort);
        if (Number(state.page) > 1) params.set('page', String(state.page));
        const query = params.toString();
        return `/boutique${query ? `?${query}` : ''}`;
    };

    const syncForms = (filters) => {
        searchFields.forEach((field) => {
            field.value = filters.search || '';
        });
        setHiddenField(searchForm, 'category_id', Number(filters.category_id) > 0 ? String(filters.category_id) : '');
        setHiddenField(searchForm, 'brand_id', Number(filters.brand_id) > 0 ? String(filters.brand_id) : '');
        setHiddenField(searchForm, 'sort', filters.sort !== 'newest' ? filters.sort : '');
        const filterSearch = filtersForm.querySelector('input[name="search"]');
        if (filterSearch) filterSearch.value = filters.search || '';
        updateOptions(categorySelect, filters.categories || [], 'Toutes les catégories', filters.category_id);
        updateOptions(brandSelect, filters.brands || [], 'Toutes les marques', filters.brand_id);
        if (sortSelect) sortSelect.value = filters.sort || 'newest';
    };

    const updateResults = async (source, historyMode = 'push', shouldScroll = false) => {
        const params = normalizeParams(source);
        const requestId = ++requestSequence;
        activeRequest?.abort();
        activeRequest = new AbortController();
        results.setAttribute('aria-busy', 'true');
        status.hidden = false;
        status.textContent = 'Mise à jour des résultats…';

        const endpoint = new URL('/boutique/results', window.location.origin);
        endpoint.search = params.toString();
        try {
            const response = await fetch(endpoint, {
                headers: { Accept: 'application/json' },
                signal: activeRequest.signal,
            });
            if (!response.ok) {
                throw new Error(`La requête a échoué (${response.status}).`);
            }
            const data = await response.json();
            if (!data.success || typeof data.results_html !== 'string' || typeof data.filters_html !== 'string') {
                throw new Error('La réponse des résultats est invalide.');
            }
            if (requestId !== requestSequence) return;

            results.innerHTML = data.results_html;
            activeFilters.innerHTML = data.filters_html;
            const filters = {
                ...data.filters,
                categories: data.categories,
                brands: data.brands,
            };
            syncForms(filters);
            updateCategoryLinks(data.categories, filters);
            count.textContent = String(data.total);
            countLabel.textContent = Number(data.total) === 1 ? 'résultat' : 'résultats';

            const pageUrl = buildStorefrontUrl(filters);
            if (historyMode === 'push') {
                window.history.pushState({}, '', pageUrl);
            } else if (historyMode === 'replace') {
                window.history.replaceState({}, '', pageUrl);
            }

            status.textContent = `${data.total} ${Number(data.total) === 1 ? 'résultat' : 'résultats'}`;
            results.setAttribute('aria-busy', 'false');
            if (shouldScroll) {
                results.scrollIntoView({
                    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                    block: 'start',
                });
            }
        } catch (error) {
            if (error.name === 'AbortError') return;
            console.error('Unable to update public catalog results.', error);
            status.textContent = 'Impossible de mettre à jour les résultats. Vérifiez votre connexion et réessayez.';
            results.setAttribute('aria-busy', 'false');
            status.focus();
        } finally {
            if (requestId === requestSequence) activeRequest = null;
        }
    };

    const submitForm = (form, historyMode = 'push', shouldScroll = false) => {
        if (form === filtersForm) {
            const filterSearch = filtersForm.querySelector('input[name="search"]');
            if (filterSearch) filterSearch.value = searchFields[0]?.value || '';
        } else {
            setHiddenField(searchForm, 'category_id', categorySelect?.value || '');
            setHiddenField(searchForm, 'brand_id', brandSelect?.value || '');
            setHiddenField(searchForm, 'sort', sortSelect?.value !== 'newest' ? sortSelect?.value : '');
        }
        const params = new URLSearchParams(new FormData(form));
        params.set('page', '1');
        updateResults(params, historyMode, shouldScroll);
    };

    [searchForm, filtersForm].forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            window.clearTimeout(searchDebounce);
            submitForm(form, 'push', true);
        });
    });

    searchFields.forEach((field) => {
        field.addEventListener('input', () => {
            window.clearTimeout(searchDebounce);
            searchDebounce = window.setTimeout(() => submitForm(searchForm, 'replace'), 350);
        });
    });

    [categorySelect, brandSelect, sortSelect].forEach((select) => {
        select?.addEventListener('change', () => {
            window.clearTimeout(searchDebounce);
            submitForm(filtersForm, 'push', true);
        });
    });

    root.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || !root.contains(link) || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || url.pathname !== '/boutique') {
            return;
        }
        event.preventDefault();
        window.clearTimeout(searchDebounce);
        updateResults(url.searchParams, 'push', true);
    });

    window.addEventListener('popstate', () => {
        window.clearTimeout(searchDebounce);
        updateResults(new URLSearchParams(window.location.search), 'none', true);
    });
})();
