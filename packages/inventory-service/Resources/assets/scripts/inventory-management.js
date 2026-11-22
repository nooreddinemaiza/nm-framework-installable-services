const extendInventoryStore = () => {
    const store = Alpine.store('inventory');
    const methods = {
        searches: {
            stock: '',
            products: ''
        },
        balances: [],
        productStocks: [],
        initialize(data = {}) {
            this.balances = data.balances || [];
            this.productStocks = data.productStocks || [];
        },
        matches(type, row) {
            const query = this.searches[type].trim().toLocaleLowerCase();
            return query === '' || row.textContent.toLocaleLowerCase().includes(query);
        },
        showEmpty(type) {
            const rowSelector = type === 'stock' ? '[data-stock-row]' : '[data-product-row]';
            const rows = [...document.querySelectorAll(rowSelector)];
            return rows.length > 0 && this.searches[type].trim() !== ''
                && !rows.some((row) => this.matches(type, row));
        },
        async mutate(url, payload) {
            const result = await api.post(url, payload, {toast: false});
            if (!result.success) {
                throw new Error(result.message || 'L’opération a échoué.');
            }
            return result;
        },
        async submit(url, payload, options = {}) {
            if (options.confirm) {
                const confirmed = await Form.open({
                    variant: 'delete',
                    title: options.confirmTitle || 'Confirmer l’action',
                    message: options.confirm,
                    confirmText: options.confirmText || 'Confirmer',
                    cancelText: 'Annuler'
                });
                if (confirmed !== true) return false;
            }

            const result = await this.mutate(url, payload);
            try {
                await this.refreshTables();
            } catch (error) {
                Toaster.warning(`Opération enregistrée, mais l’affichage du stock n’a pas pu être actualisé : ${error.message}`);
            }
            const identifiers = result.data
                ? Object.entries(result.data)
                    .filter(([key]) => key.endsWith('_id'))
                    .map(([key, value]) => `${key.replaceAll('_', ' ')} : ${value}`)
                    .join(' · ')
                : '';
            Toaster.success([result.message || 'Opération enregistrée.', identifiers].filter(Boolean).join(' — '));
            return result;
        },
        async refreshTables() {
            const result = await api.get('/dashboard/inventory/data', {toast: false});
            const data = result.data;
            if (!result.success || !Array.isArray(data?.balances) || !Array.isArray(data?.productStocks)) {
                throw new Error(result.message || 'Impossible de recharger les soldes de stock.');
            }
            this.balances = data.balances;
            this.productStocks = data.productStocks;
        }
    };

    if (store) {
        Object.assign(store, methods);
    } else {
        Alpine.store('inventory', methods);
    }
};

document.addEventListener('alpine:init', extendInventoryStore, {once: true});
if (window.Alpine) extendInventoryStore();
