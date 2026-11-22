function parseCatalogSeoTags(value) {
    if (Array.isArray(value)) {
        return [...new Set(value.filter((tag) => typeof tag === 'string').flatMap((tag) => tag.split(/[,;\n]+/)).map((tag) => tag.trim()).filter(Boolean))];
    }
    if (typeof value !== 'string' || value.trim() === '') return [];
    try {
        const decoded = JSON.parse(value);
        if (Array.isArray(decoded)) return parseCatalogSeoTags(decoded);
    } catch (error) {
        // Plain text tags are stored as a delimited string.
    }
    return [...new Set(value.split(/[,;\n]+/).map((tag) => tag.trim()).filter(Boolean))];
}

function catalogSeoTagsString(value) {
    return parseCatalogSeoTags(value).join(', ');
}

document.addEventListener('alpine:init', () => {
    Alpine.store('catalog', {
        products: [],
        discounts: {},
        discountRequests: {},
        inventoryStocks: [],
        inventoryLocations: [],
        inventoryLots: [],
        defaultMovementQuantity: '1',
        inventoryLoading: false,
        inventoryLoadError: '',
        inventoryLoadRequest: null,
        discountModalData: null,
        categories: [],
        brands: [],
        categoryRows: [],
        categoryTotal: 0,
        categoryLoading: false,
        brandRows: [],
        brandTotal: 0,
        brandLoading: false,
        filters: {},
        editingProduct: false,
        saving: false,
        referenceSaving: false,
        mediaSaving: false,
        mediaLoading: false,
        mediaError: '',
        mediaManagerOpen: false,
        selectedMediaProduct: null,
        page: 1,
        pages: 1,
        total: 0,
        perPage: 5,
        loading: false,
        media: [],
        stockUnits: [],
        categoryCreationToken: '',
        productCreationToken: '',
        canArchive: false,
        canViewDiscounts: false,
        canViewStock: false,
        canReceiveStock: false,
        canIssueStock: false,
        init() {
            document.addEventListener('inventory:discount-mutation', () => {
                this.discounts = {};
                this.discountRequests = {};
                this.loadDiscountsForProducts();
            });
        },
        initialize(data = {}) {
            this.filters = {
                search: '',
                status: 'all',
                kind: 'all',
                category_id: 'all',
                brand_id: 'all',
                ...data.filters
            };
            this.filters.search = String(this.filters.search || '');
            this.filters.status = this.filters.status || 'all';
            this.filters.kind = this.filters.kind || 'all';
            this.filters.category_id = this.filters.category_id || 'all';
            this.filters.brand_id = this.filters.brand_id || 'all';
            this.products = data.products || [];
            this.discounts = {};
            this.discountRequests = {};
            this.categories = data.categories || [];
            this.brands = data.brands || [];
            this.categoryRows = data.categoryRows || [];
            this.categoryTotal = Number(data.categoryTotal || 0);
            this.brandRows = data.brandRows || [];
            this.brandTotal = Number(data.brandTotal || 0);
            this.page = Number(data.page || 1);
            this.pages = Number(data.pages || 1);
            this.total = Number(data.total || this.products.length);
            this.perPage = Number(data.perPage || 5);
            this.media = data.media || [];
            this.stockUnits = data.stockUnits || [];
            this.categoryCreationToken = data.categoryCreationToken || '';
            this.productCreationToken = data.productCreationToken || '';
            this.canArchive = Boolean(data.canArchive);
            this.canViewDiscounts = Boolean(data.canViewDiscounts);
            this.canViewStock = Boolean(data.canViewStock);
            this.canReceiveStock = Boolean(data.canReceiveStock);
            this.canIssueStock = Boolean(data.canIssueStock);
            this.loadDiscountsForProducts();
            void this.loadInventoryStockData();
            if (data.editProduct) {
                const exists = this.products.some((item) => Number(item.id) === Number(data.editProduct.id));
                if (!exists) this.products.unshift(data.editProduct);
                this.openProduct(data.editProduct);
            }
        },
        async loadDiscount(item) {
            if (!this.canViewDiscounts) return;
            const variantId = String(item?.variant_id || '');
            if (!variantId || item.kind === 'service' || item.status !== 'active' || item.price === null) return;
            if (this.discounts[variantId] || this.discountRequests[variantId]) return;

            const request = (async () => {
                try {
                    const response = await api.post('/dashboard/inventory/discounts/calculate', {
                        variant_id: variantId
                    }, {toast: false});
                    if (!response.success || !response.data) {
                        throw new Error(response.message || 'Impossible de charger la réduction applicable.');
                    }
                    this.discounts = {...this.discounts, [variantId]: response.data};
                } catch (error) {
                    this.discounts = {
                        ...this.discounts,
                        [variantId]: {error: error.message || 'Impossible de charger la réduction applicable.'}
                    };
                } finally {
                    delete this.discountRequests[variantId];
                }
            })();
            this.discountRequests[variantId] = request;
            await request;
        },
        loadDiscountsForProducts() {
            if (!this.canViewDiscounts) return;
            this.products.forEach((item) => { void this.loadDiscount(item); });
        },
        discountSummary(item) {
            const result = this.discounts[String(item?.variant_id || '')];
            if (!result) {
                if (!item?.variant_id || item.kind === 'service' || item.price === null) return '—';
                return this.discountRequests[String(item.variant_id)] ? 'Calcul en cours…' : 'Chargement…';
            }
            if (result.error) return result.error;
            if (!result.applied_discounts?.length) return 'Aucune réduction';

            return `${result.final_price} ${result.currency}`;
        },
        hasDiscount(item) {
            const result = this.discounts[String(item?.variant_id || '')];
            return Boolean(result?.applied_discounts?.length) && !result.error;
        },
        openDiscountPrice(item) {
            const result = this.discounts[String(item?.variant_id || '')];
            if (!result || result.error || !result.applied_discounts?.length) return;
            this.discountModalData = {...result, productName: item.name};
        },
        closeDiscountPrice() {
            this.discountModalData = null;
        },
        stockQuantity(item) {
            const stock = this.inventoryStocks.find((row) => Number(row.variant_id) === Number(item?.variant_id));
            if (!stock) return this.inventoryLoading ? 'Chargement…' : '—';
            return `${stock.physical_qty} ${stock.unit_code}`;
        },
        async loadInventoryStockData() {
            if (!this.canViewStock && !this.canReceiveStock && !this.canIssueStock) return;
            if (this.inventoryLoadRequest) return this.inventoryLoadRequest;

            this.inventoryLoading = true;
            this.inventoryLoadRequest = (async () => {
                try {
                    const response = await api.get('/dashboard/inventory/data', {toast: false});
                    const data = response.data;
                    if (!response.success || !Array.isArray(data?.productStocks)) {
                        throw new Error(response.message || 'Impossible de charger les quantités de stock.');
                    }
                    this.inventoryLoadError = '';
                    this.inventoryStocks = data.productStocks;
                    this.inventoryLocations = Array.isArray(data.locations) ? data.locations : [];
                    this.inventoryLots = Array.isArray(data.lots) ? data.lots : [];
                    this.defaultMovementQuantity = String(data.defaultMovementQuantity || '1');
                } catch (error) {
                    this.inventoryLoadError = error.message || 'Impossible de charger les quantités de stock.';
                    Toaster.warning(this.inventoryLoadError);
                } finally {
                    this.inventoryLoading = false;
                    this.inventoryLoadRequest = null;
                }
            })();
            return this.inventoryLoadRequest;
        },
        async manageStock(item) {
            try {
                await this.loadInventoryStockData();
                if (this.inventoryLoadError) return;
                const stock = this.inventoryStocks.find((row) => Number(row.variant_id) === Number(item.variant_id));
                if (!stock) throw new Error('Cet article n’est pas encore configuré dans l’inventaire.');
                if (this.inventoryLocations.length === 0) throw new Error('Créez un entrepôt et un emplacement avant de modifier le stock.');
                const lots = this.inventoryLots.filter((lot) => Number(lot.item_id) === Number(stock.item_id));
                if (lots.length === 0) throw new Error('Aucun lot disponible pour cet article. Configurez un lot dans l’inventaire.');

                const movementOptions = [];
                if (this.canReceiveStock) movementOptions.push({value: 'receipt', label: 'Ajouter au stock'});
                if (this.canIssueStock) movementOptions.push({value: 'issue', label: 'Retirer du stock'});
                const values = {
                    movement_type: movementOptions[0].value,
                    location_id: String(this.inventoryLocations[0].id),
                    lot_id: String(lots[0].id),
                    quantity: this.defaultMovementQuantity,
                    reference: '',
                    reason: ''
                };
                await Form.open({
                    variant: 'edit',
                    title: `Modifier le stock — ${item.name}`,
                    confirmText: 'Enregistrer',
                    cancelText: 'Annuler',
                    size: 'lg',
                    values,
                    fields: [
                        {name: 'movement_type', label: 'Opération', type: 'select', required: true, options: movementOptions},
                        {name: 'location_id', label: 'Entrepôt / emplacement', type: 'select', required: true, options: this.inventoryLocations.map((location) => ({
                            value: String(location.id),
                            label: `${location.warehouse_name} / ${location.code} — ${location.name}`
                        }))},
                        {name: 'lot_id', label: 'Lot', type: 'select', required: true, options: lots.map((lot) => ({
                            value: String(lot.id),
                            label: lot.lot_code
                        }))},
                        {name: 'quantity', label: `Quantité (${stock.unit_code})`, type: 'number', required: true, min: 0.000001, step: 0.000001},
                        {name: 'reference', label: 'Référence', type: 'text', maxLength: 190},
                        {name: 'reason', label: 'Motif', type: 'text', maxLength: 500}
                    ],
                    onSubmit: async (formValues, {close}) => {
                        const response = await api.post('/dashboard/inventory/movement', {
                            ...formValues,
                            item_id: stock.item_id,
                            unit_id: stock.unit_id
                        }, {toast: false});
                        if (!response.success) throw new Error(response.message || 'Impossible de modifier le stock.');
                        Toaster.success(response.message || 'Stock mis à jour.');
                        close();
                        await this.loadInventoryStockData();
                    }
                });
            } catch (error) {
                Toaster.warning(error.message || 'Impossible de modifier le stock.');
            }
        },
        noTextMatches(selector) {
            return this.filters.search.trim() !== ''
                && !Array.from(document.querySelectorAll(selector)).some((row) => this.matchesText(row));
        },
        matchesText(element) {
            const query = this.filters.search.trim().toLocaleLowerCase();
            return query === '' || (element.dataset.search || element.textContent).toLocaleLowerCase().includes(query);
        },
        matchesCategory(category) {
            const parent = this.categories.find((item) => Number(item.id) === Number(category.parent_id));
            const text = `${category.name} ${parent?.name || ''}`.toLocaleLowerCase();
            const query = this.filters.search.trim().toLocaleLowerCase();
            return query === '' || text.includes(query);
        },
        matchesBrand(brand) {
            const query = this.filters.search.trim().toLocaleLowerCase();
            return query === '' || brand.name.toLocaleLowerCase().includes(query);
        },
        matchesProduct(product) {
            const query = this.filters.search.trim().toLocaleLowerCase();
            const searchText = `${product.name || ''} ${product.slug || ''} ${product.sku || ''}`.toLocaleLowerCase();
            return (query === '' || searchText.includes(query))
                && (this.filters.status === 'all' || product.status === this.filters.status)
                && (this.filters.kind === 'all' || product.kind === this.filters.kind)
                && (this.filters.category_id === 'all' || String(product.category_id || '') === String(this.filters.category_id))
                && (this.filters.brand_id === 'all' || String(product.brand_id || '') === String(this.filters.brand_id));
        },
        async queryCategories(ctx = {}) {
            this.categoryLoading = true;
            try {
                const result = await api.get('/dashboard/catalog/categories/search', {
                    params: {
                        search: this.filters.search || '',
                        page: ctx.page ?? 1,
                        per_page: ctx.pageSize ?? 10
                    },
                    toast: false,
                    signal: ctx.signal
                });
                const data = result.data;
                if (!result.success || !data || !Array.isArray(data.items)) {
                    throw new Error(result.message || 'Impossible de charger les catégories.');
                }
                this.categoryRows = data.items;
                this.categoryTotal = Number(data.total || 0);
            } finally {
                this.categoryLoading = false;
            }
        },
        async queryBrands(ctx = {}) {
            this.brandLoading = true;
            try {
                const result = await api.get('/dashboard/catalog/brands/search', {
                    params: {
                        search: this.filters.search || '',
                        page: ctx.page ?? 1,
                        per_page: ctx.pageSize ?? 10
                    },
                    toast: false,
                    signal: ctx.signal
                });
                const data = result.data;
                if (!result.success || !data || !Array.isArray(data.items)) {
                    throw new Error(result.message || 'Impossible de charger les marques.');
                }
                this.brandRows = data.items;
                this.brandTotal = Number(data.total || 0);
            } finally {
                this.brandLoading = false;
            }
        },
        async applyReferenceSearch(resource) {
            const tableId = resource === 'categories' ? 'catalog-categories-table' : 'catalog-brands-table';
            const table = Alpine.store('tables')?.get(tableId);
            if (table) {
                table.page = 1;
                await table.reload();
            } else if (resource === 'categories') {
                await this.queryCategories();
            } else {
                await this.queryBrands();
            }
        },
        openNewProduct() {
            this.editingProduct = false;
            this.openProductForm(this.emptyProduct());
        },
        openProduct(product) {
            this.editingProduct = true;
            this.openProductForm({
                id: Number(product.id),
                name: product.name || '',
                slug: product.slug || '',
                sku: product.sku || '',
                category_id: product.category_id || '',
                brand_id: product.brand_id || '',
                price: product.price || '',
                cost_price: product.cost_price || '',
                currency: product.currency || 'MAD',
                kind: product.kind || 'goods',
                status: product.status || 'draft',
                is_trending: Number(product.is_trending) === 1,
                description: product.description || '',
                seo_tags: parseCatalogSeoTags(product.seo_tags),
                manufacturer: product.manufacturer || ''
            });
        },
        async openProductForm(values) {
            const fields = [
                {name: 'name', label: 'Nom du produit', type: 'text', required: true, maxLength: 190},
                {name: 'slug', label: 'Slug', type: 'slug', from: 'name', regenerateOnSourceChange: true, required: true, maxLength: 220},
                {name: 'sku', label: 'Référence', type: 'text', maxLength: 100, hint: 'Générée automatiquement si vide'},
                {name: 'category_id', label: 'Catégorie', type: 'select', options: [
                    {value: '', label: 'Aucune catégorie'},
                    ...this.categories.map((category) => ({value: String(category.id), label: category.name}))
                ]},
                {name: 'brand_id', label: 'Marque', type: 'select', options: [
                    {value: '', label: 'Aucune marque'},
                    ...this.brands.map((brand) => ({value: String(brand.id), label: brand.name}))
                ]},
                {name: 'price', label: 'Prix de vente indicatif', type: 'number', min: 0, step: 0.0001},
                {name: 'cost_price', label: 'Coût d’achat (base du profit estimé)', type: 'number', min: 0, step: 0.0001},
                {name: 'currency', label: 'Devise', type: 'text', required: true, minLength: 3, maxLength: 3, format: 'upper'},
                {name: 'kind', label: 'Type', type: 'select', required: true, options: [
                    {value: 'goods', label: 'Produit'},
                    {value: 'service', label: 'Service'}
                ]},
                ...(!this.editingProduct ? [{
                    name: 'stock_unit_id',
                    label: 'Unité de stock',
                    type: 'select',
                    options: [
                        {value: '', label: 'Par défaut — PCS (Pièce)'},
                        ...this.stockUnits.map((unit) => ({value: String(unit.id), label: `${unit.code} — ${unit.name}`}))
                    ],
                    showIf: {field: 'kind', eq: 'goods'},
                    clearOnHide: true
                }] : []),
                {name: 'status', label: 'Statut', type: 'select', required: true, options: [
                    {value: 'draft', label: 'En préparation'},
                    {value: 'active', label: 'Actif'},
                    ...(this.editingProduct && this.canArchive ? [{value: 'archived', label: 'Retiré du catalogue'}] : [])
                ]},
                {name: 'is_trending', label: 'Mettre en tendance', type: 'checkbox'},
                {
                    name: 'description', label: 'Description', type: 'richtext', maxLength: 1000000,
                    maxImageSize: 500000, minHeight: 260, fullWidth: true
                },
                {name: 'seo_tags', label: 'Tags SEO (séparés par des virgules)', type: 'tags', rows: 2, maxLength: 1000, fullWidth: true},
                {name: 'manufacturer', label: 'Fabricant', type: 'text', maxLength: 190, fullWidth: true}
            ];
            await Form.open({
                variant: this.editingProduct ? 'edit' : 'new',
                title: this.editingProduct ? 'Modifier le produit' : 'Ajouter un produit',
                confirmText: 'Enregistrer',
                cancelText: 'Annuler',
                size: '2xl',
                values,
                fields,
                onSubmit: async (formValues, {close}) => {
                    await this.saveProduct({...formValues, id: values.id});
                    close();
                }
            });
        },
        emptyProduct() {
            return {
                id: null, name: '', slug: '', sku: '', category_id: '', brand_id: '',
                price: '', currency: 'MAD', kind: 'goods', status: 'active', stock_unit_id: '',
                description: '', manufacturer: '', cost_price: '', seo_tags: '', is_trending: false
            };
        },
        async saveProduct(formValues) {
            if (this.saving) return;
            this.saving = true;
            const payload = {...formValues, seo_tags: catalogSeoTagsString(formValues.seo_tags)};
            if (this.editingProduct) {
                payload.product_id = payload.id;
            } else {
                payload.creation_token = this.productCreationToken;
            }
            delete payload.id;
            const url = this.editingProduct ? '/dashboard/catalog/update' : '/dashboard/catalog/create';
            try {
                const result = await api.post(url, payload, {toast: false});
                const saved = result.data?.product;
                if (!result.success || !saved) throw new Error(result.message || 'Impossible d’enregistrer le produit.');
                const index = this.products.findIndex((product) => Number(product.id) === Number(saved.id));
                if (index < 0) {
                    if (this.matchesProduct(saved)) {
                        this.products.unshift(saved);
                        this.total++;
                    }
                }
                else this.products.splice(index, 1, saved);
                if (!this.editingProduct) {
                    this.productCreationToken = Array.from(
                        crypto.getRandomValues(new Uint8Array(32)),
                        (byte) => byte.toString(16).padStart(2, '0')
                    ).join('');
                }
                await this.refreshProductList(this.editingProduct ? this.page : 1);
                Toaster.success(result.message || 'Produit enregistré.');
            } catch (error) {
                throw error;
            } finally {
                this.saving = false;
            }
        },
        async loadProducts(page = 1, perPage = this.perPage, signal) {
            this.loading = true;
            try {
            const result = await api.get('/dashboard/catalog/search', {
                params: {...this.filters, page, per_page: perPage},
                toast: false,
                signal
            });
            const data = result.data;
            if (!result.success || !data || !Array.isArray(data.items)) {
                throw new Error(result.message || 'Impossible de charger les produits.');
            }
            this.products = data.items;
            this.discounts = {};
            this.discountRequests = {};
            this.loadDiscountsForProducts();
            await this.loadInventoryStockData();
            this.page = Number(data.page || 1);
            this.pages = Number(data.pages || 1);
            this.total = Number(data.total || 0);
            this.perPage = Number(data.per_page || perPage);
            } finally {
                this.loading = false;
            }
        },
        async query(ctx = {}) {
            await this.loadProducts(ctx.page || 1, ctx.pageSize || this.perPage, ctx.signal);
        },
        async refreshProductList(page = this.page) {
            try {
                const table = Alpine.store('tables')?.get('catalog-products');
                if (table) {
                    table.page = page;
                    await table.reload();
                } else {
                    await this.loadProducts(page, this.perPage);
                }
            } catch (error) {
                Toaster.warning(`Modification enregistrée, mais la liste des produits n’a pas pu être actualisée : ${error.message}`);
            }
        },
        async applyFilters() {
            try {
                const table = Alpine.store('tables')?.get('catalog-products');
                if (table) {
                    table.page = 1;
                    await table.reload();
                } else {
                    await this.loadProducts(1, this.perPage);
                }
            } catch (error) {
                Toaster.warning(error.message || 'Impossible d’appliquer les filtres.');
            }
        },
        async setPage(page) {
            try {
                const table = Alpine.store('tables')?.get('catalog-products');
                if (table) {
                    table.page = page;
                    await table.reload();
                } else {
                    await this.loadProducts(page, this.perPage);
                }
            } catch (error) {
                Toaster.warning(error.message || 'Impossible de charger cette page.');
            }
        },
        async clearFilters() {
            this.filters = {search: '', status: 'all', kind: 'all', category_id: 'all', brand_id: 'all'};
            await this.applyFilters();
        },
        async toggleTrending(kind, item) {
            const isTrending = Number(item.is_trending) !== 1;
            try {
                const result = await api.post('/dashboard/catalog/trending', {
                    kind,
                    entity_id: item.id,
                    is_trending: isTrending
                }, {toast: false});
                if (!result.success || typeof result.data?.is_trending !== 'boolean') {
                    throw new Error(result.message || 'Impossible de modifier la tendance.');
                }
                item.is_trending = result.data.is_trending ? 1 : 0;
                if (kind === 'goods' || kind === 'service') {
                    const product = this.products.find((row) => Number(row.id) === Number(item.id));
                    if (product) product.is_trending = item.is_trending;
                    await this.refreshProductList(this.page);
                } else {
                    const collection = kind === 'category' ? this.categories : this.brands;
                    const reference = collection.find((row) => Number(row.id) === Number(item.id));
                    if (reference) reference.is_trending = item.is_trending;
                    await this.refreshReferenceTable(kind === 'category' ? 'categories' : 'brands');
                }
                Toaster.success(result.message || 'Tendance mise à jour.');
            } catch (error) {
                Toaster.warning(error.message || 'Impossible de modifier la tendance.');
            }
        },
        async setProductStatus(product, status) {
            const message = status === 'archived'
                ? 'Retirer ce produit du catalogue ? Il sera conservé et pourra être restauré.'
                : 'Restaurer ce produit dans le catalogue ?';
            const confirmed = await Form.open({
                variant: status === 'archived' ? 'delete' : 'add',
                title: status === 'archived' ? 'Retirer du catalogue' : 'Restaurer le produit',
                message,
                confirmText: status === 'archived' ? 'Retirer' : 'Restaurer',
                cancelText: 'Annuler'
            });
            if (confirmed !== true) return;
            try {
                const result = await api.post('/dashboard/catalog/status', {product_id: product.id, status}, {toast: false});
                const updated = result.data?.product;
                if (!result.success || !updated) throw new Error(result.message || 'Impossible de modifier le statut.');
                const index = this.products.findIndex((item) => Number(item.id) === Number(updated.id));
                if (index >= 0) this.products.splice(index, 1, updated);
                await this.refreshProductList(this.page);
                Toaster.success(result.message || 'Statut mis à jour.');
            } catch (error) {
                Toaster.warning(error.message || 'Impossible de modifier le statut.');
            }
        },
        async confirmDelete(title, message, action) {
            await Form.open({
                variant: 'delete',
                title,
                message,
                confirmText: 'Supprimer',
                cancelText: 'Annuler',
                persistent: true,
                onSubmit: async (_, {close}) => {
                    await action();
                    close();
                }
            });
        },
        async deleteProduct(product) {
            await this.confirmDelete(
                'Supprimer le produit',
                `Supprimer définitivement « ${product.name} » ?`,
                async () => {
                    const result = await api.post('/dashboard/catalog/products/delete', {product_id: product.id}, {toast: false});
                    if (!result.success) throw new Error(result.message || 'Impossible de supprimer le produit.');
                    this.products = this.products.filter((item) => Number(item.id) !== Number(product.id));
                    this.total = Math.max(0, this.total - 1);
                    await this.refreshProductList(this.page);
                    Toaster.success(result.message || 'Produit supprimé.');
                }
            );
        },
        async refreshReferenceTable(resource) {
            const tableId = resource === 'categories' ? 'catalog-categories-table' : 'catalog-brands-table';
            const table = Alpine.store('tables')?.get(tableId);
            if (table) {
                await table.reload();
            } else if (resource === 'categories') {
                await this.queryCategories();
            } else {
                await this.queryBrands();
            }
        },
        async openCategory(category = null) {
            const values = category
                ? {id: Number(category.id), name: category.name, slug: category.slug || Form.slugify(category.name), parent_id: category.parent_id || '', seo_tags: parseCatalogSeoTags(category.seo_tags), is_trending: Number(category.is_trending) === 1}
                : {id: null, name: '', slug: '', parent_id: '', seo_tags: '', is_trending: false};
            await Form.open({
                variant: category ? 'edit' : 'new',
                title: category ? 'Modifier la catégorie' : 'Ajouter une catégorie',
                confirmText: 'Enregistrer',
                cancelText: 'Annuler',
                values,
                fields: [
                    {name: 'name', label: 'Nom', type: 'text', required: true, maxLength: 190},
                    {name: 'slug', label: 'Slug', type: 'slug', from: 'name', regenerateOnSourceChange: true, required: true, maxLength: 220},
                    {name: 'seo_tags', label: 'Tags SEO (séparés par des virgules)', type: 'tags', rows: 2, maxLength: 1000},
                    {name: 'is_trending', label: 'Mettre en tendance', type: 'checkbox'},
                    {name: 'parent_id', label: 'Catégorie parente', type: 'select', options: [
                        {value: '', label: 'Aucune — catégorie principale'},
                        ...this.categories
                            .filter((item) => Number(item.id) !== Number(values.id))
                            .map((item) => ({value: String(item.id), label: item.name}))
                    ]}
                ],
                onSubmit: async (formValues, {close}) => {
                    await this.saveCategory({...formValues, id: values.id});
                    close();
                }
            });
        },
        async saveCategory(formValues) {
            if (this.referenceSaving) return;
            this.referenceSaving = true;
            const editing = formValues.id !== null;
            const payload = {
                name: formValues.name.trim(),
                slug: formValues.slug,
                seo_tags: catalogSeoTagsString(formValues.seo_tags),
                is_trending: formValues.is_trending,
                parent_id: formValues.parent_id || ''
            };
            if (editing) payload.category_id = formValues.id;
            else payload.category_creation_token = this.categoryCreationToken;
            try {
                const result = await api.post(
                    editing ? '/dashboard/catalog/category/update' : '/dashboard/catalog/category',
                    payload,
                    {toast: false}
                );
                const saved = result.data?.category;
                if (!result.success || !saved) throw new Error(result.message || 'Impossible d’enregistrer la catégorie.');
                const index = this.categories.findIndex((item) => Number(item.id) === Number(saved.id));
                if (index < 0) this.categories.push(saved);
                else this.categories.splice(index, 1, saved);
                await this.refreshReferenceTable('categories');
                this.categoryCreationToken = Array.from(
                    crypto.getRandomValues(new Uint8Array(32)),
                    (byte) => byte.toString(16).padStart(2, '0')
                ).join('');
                Toaster.success(result.message || 'Catégorie enregistrée.');
            } catch (error) {
                throw error;
            } finally {
                this.referenceSaving = false;
            }
        },
        async deleteCategory(category) {
            await this.confirmDelete(
                'Supprimer la catégorie',
                `Supprimer la catégorie « ${category.name} » ?`,
                async () => {
                    const result = await api.post('/dashboard/catalog/category/delete', {category_id: category.id}, {toast: false});
                    if (!result.success) throw new Error(result.message || 'Impossible de supprimer la catégorie.');
                    this.categories = this.categories.filter((item) => Number(item.id) !== Number(category.id));
                    await this.refreshReferenceTable('categories');
                    Toaster.success(result.message || 'Catégorie supprimée.');
                }
            );
        },
        async openBrand(brand = null) {
            const values = brand
                ? {id: Number(brand.id), name: brand.name, slug: brand.slug || Form.slugify(brand.name), seo_tags: parseCatalogSeoTags(brand.seo_tags), is_trending: Number(brand.is_trending) === 1}
                : {id: null, name: '', slug: '', seo_tags: '', is_trending: false};
            await Form.open({
                variant: brand ? 'edit' : 'new',
                title: brand ? 'Modifier la marque' : 'Ajouter une marque',
                confirmText: 'Enregistrer',
                cancelText: 'Annuler',
                values,
                fields: [
                    {name: 'name', label: 'Nom de la marque', type: 'text', required: true, maxLength: 190},
                    {name: 'slug', label: 'Slug', type: 'slug', from: 'name', regenerateOnSourceChange: true, required: true, maxLength: 220},
                    {name: 'seo_tags', label: 'Tags SEO (séparés par des virgules)', type: 'tags', rows: 2, maxLength: 1000},
                    {name: 'is_trending', label: 'Mettre en tendance', type: 'checkbox'}
                ],
                onSubmit: async (formValues, {close}) => {
                    await this.saveBrand({...formValues, id: values.id});
                    close();
                }
            });
        },
        async saveBrand(formValues) {
            if (this.referenceSaving) return;
            this.referenceSaving = true;
            const editing = formValues.id !== null;
            const payload = {
                name: formValues.name.trim(),
                slug: formValues.slug,
                seo_tags: catalogSeoTagsString(formValues.seo_tags),
                is_trending: formValues.is_trending
            };
            if (editing) payload.brand_id = formValues.id;
            try {
                const result = await api.post(
                    editing ? '/dashboard/catalog/brand/update' : '/dashboard/catalog/brand',
                    payload,
                    {toast: false}
                );
                const saved = result.data?.brand;
                if (!result.success || !saved) throw new Error(result.message || 'Impossible d’enregistrer la marque.');
                const index = this.brands.findIndex((item) => Number(item.id) === Number(saved.id));
                if (index < 0) this.brands.push(saved);
                else this.brands.splice(index, 1, saved);
                await this.refreshReferenceTable('brands');
                Toaster.success(result.message || 'Marque enregistrée.');
            } catch (error) {
                throw error;
            } finally {
                this.referenceSaving = false;
            }
        },
        async deleteBrand(brand) {
            await this.confirmDelete(
                'Supprimer la marque',
                `Supprimer la marque « ${brand.name} » ?`,
                async () => {
                    const result = await api.post('/dashboard/catalog/brand/delete', {brand_id: brand.id}, {toast: false});
                    if (!result.success) throw new Error(result.message || 'Impossible de supprimer la marque.');
                    this.brands = this.brands.filter((item) => Number(item.id) !== Number(brand.id));
                    await this.refreshReferenceTable('brands');
                    Toaster.success(result.message || 'Marque supprimée.');
                }
            );
        },
        async openMediaManager(product) {
            this.selectedMediaProduct = product;
            this.media = [];
            this.mediaError = '';
            this.mediaManagerOpen = true;
            await this.loadMediaManager();
        },
        closeMediaManager() {
            this.mediaManagerOpen = false;
            this.selectedMediaProduct = null;
            this.mediaError = '';
        },
        async loadMediaManager() {
            if (!this.selectedMediaProduct) return;
            this.mediaLoading = true;
            this.mediaError = '';
            try {
                await this.refreshMedia(Number(this.selectedMediaProduct.id));
            } catch (error) {
                this.mediaError = error.message || 'Impossible de charger les médias du produit.';
            } finally {
                this.mediaLoading = false;
            }
        },
        async openMediaUpload(productId) {
            await Form.open({
                variant: 'new',
                title: 'Téléverser un fichier',
                confirmText: 'Ajouter le fichier',
                cancelText: 'Annuler',
                values: {caption: ''},
                fields: [
                    {name: 'media_file', label: 'Fichier', type: 'file', required: true, accept: 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm', maxSize: 10 * 1024 * 1024},
                    {name: 'caption', label: 'Légende descriptive (facultatif)', type: 'text', maxLength: 500}
                ],
                onSubmit: async (values, {close}) => {
                    const payload = new FormData();
                    payload.append('product_id', String(productId));
                    payload.append('media_file', values.media_file);
                    payload.append('caption', values.caption || '');
                    await this.submitMedia('/dashboard/catalog/media/upload', payload, productId);
                    close();
                }
            });
        },
        async openMediaLink(productId) {
            await Form.open({
                variant: 'new',
                title: 'Ajouter un lien externe',
                confirmText: 'Ajouter le lien',
                cancelText: 'Annuler',
                values: {media_type: 'image', external_url: '', caption: ''},
                fields: [
                    {name: 'media_type', label: 'Type', type: 'select', required: true, options: [
                        {value: 'image', label: 'Image'},
                        {value: 'video', label: 'Vidéo'}
                    ]},
                    {name: 'external_url', label: 'URL HTTPS', type: 'url', required: true, placeholder: 'https://…'},
                    {name: 'caption', label: 'Légende descriptive (facultatif)', type: 'text', maxLength: 500}
                ],
                onSubmit: async (values, {close}) => {
                    const payload = new FormData();
                    payload.append('product_id', String(productId));
                    payload.append('media_type', values.media_type);
                    payload.append('external_url', values.external_url);
                    payload.append('caption', values.caption || '');
                    await this.submitMedia('/dashboard/catalog/media/link', payload, productId);
                    close();
                }
            });
        },
        async submitMedia(endpoint, payload, productId) {
            if (this.mediaSaving) return;
            this.mediaSaving = true;
            try {
                const result = await api.post(endpoint, payload, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible d’enregistrer le média.');
                await this.refreshMedia(productId);
                Toaster.success(result.message || 'Média enregistré.');
            } finally {
                this.mediaSaving = false;
            }
        },
        async deleteMedia(mediaId, productId) {
            await Form.open({
                variant: 'delete',
                title: 'Supprimer ce média ?',
                message: 'Il disparaîtra de la page publique du produit.',
                confirmText: 'Supprimer',
                cancelText: 'Annuler',
                onSubmit: async (_, {close}) => {
                    const result = await api.post('/dashboard/catalog/media/delete', {
                        product_id: productId,
                        media_id: mediaId
                    }, {toast: false});
                    if (!result.success) throw new Error(result.message || 'Impossible de supprimer le média.');
                    await this.refreshMedia(productId);
                    Toaster.success(result.message || 'Média supprimé.');
                    close();
                }
            });
        },
        async refreshMedia(productId) {
            const result = await api.get('/dashboard/catalog/media', {
                params: {product_id: productId},
                toast: false
            });
            if (!result.success || !Array.isArray(result.data?.media)) {
                throw new Error(result.message || 'Impossible d’actualiser les médias.');
            }
            this.media = result.data.media;
        }
    });
});
