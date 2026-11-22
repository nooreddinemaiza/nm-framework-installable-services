document.addEventListener('alpine:init', () => {
    const emptyItem = () => ({item_reference: '', label: '', quantity: '1', unit: '', unit_price: ''});
    const emptyFilters = () => ({
        search: '', status: 'all', date_from: '', date_to: '',
        min_total: '', max_total: '', min_pieces: '', max_pieces: ''
    });

    const registerOrderItemsField = (products) => {
        if (!window.Form || window.Form.types().includes('sales-order-items')) return;

        window.Form.register('sales-order-items', {
            isEmpty: (items) => !Array.isArray(items) || !items.some((item) =>
                String(item.item_reference || '').trim() && String(item.quantity || '').trim()
                && String(item.unit_price || '').trim()),
            validate: (items) => {
                if (!Array.isArray(items)) return 'Ajoutez au moins un article.';
                const active = items.filter((item) =>
                    String(item.item_reference || '').trim() || String(item.quantity || '').trim()
                    || String(item.unit_price || '').trim());
                if (!active.length) return 'Ajoutez au moins un article.';
                if (active.some((item) => !String(item.label || '').trim()
                    || !String(item.item_reference || '').trim()
                    || !String(item.quantity || '').trim() || !String(item.unit || '').trim()
                    || !String(item.unit_price || '').trim())) {
                    return 'Choisissez un produit et complétez la quantité et le prix de chaque article.';
                }
                return undefined;
            },
            render: ({id, field, changed}) => {
                let items = Array.isArray(field.value) ? field.value.map((item) => ({...item})) : [];
                const wrapper = document.createElement('div');
                wrapper.className = 'space-y-2';
                const rows = document.createElement('div');
                rows.className = 'space-y-2';
                const add = document.createElement('button');
                add.type = 'button';
                add.className = 'rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium dark:border-gray-600';
                add.textContent = 'Ajouter un article';

                const render = () => {
                    rows.replaceChildren();
                    items.forEach((item, index) => {
                        const fieldset = document.createElement('fieldset');
                        fieldset.className = 'rounded-xl border border-gray-200 p-3 dark:border-gray-700';
                        const legend = document.createElement('legend');
                        legend.className = 'px-1 text-xs font-semibold';
                        legend.textContent = `Article ${index + 1}`;
                        fieldset.appendChild(legend);

                        const product = document.createElement('select');
                        product.required = true;
                        product.setAttribute('aria-label', `Produit de l’article ${index + 1}`);
                        product.className = 'mb-2 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900';
                        const placeholder = document.createElement('option');
                        placeholder.value = '';
                        placeholder.textContent = 'Choisir un produit du catalogue';
                        product.appendChild(placeholder);
                        products.forEach((catalogProduct) => {
                            const option = document.createElement('option');
                            option.value = String(catalogProduct.id);
                            option.textContent = `${catalogProduct.name} — ${(Number(catalogProduct.price_minor) / 100).toFixed(2)} ${catalogProduct.currency}`;
                            product.appendChild(option);
                        });
                        product.value = String(item.item_reference || '');
                        product.disabled = products.length === 0;
                        if (!index) product.id = id;
                        product.addEventListener('change', () => {
                            const selected = products.find((entry) => String(entry.id) === product.value);
                            items[index].item_reference = selected ? String(selected.id) : '';
                            items[index].label = selected ? selected.name : '';
                            items[index].unit = selected ? selected.unit : '';
                            items[index].unit_price = selected
                                ? (Number(selected.price_minor) / 100).toFixed(2)
                                : '';
                            render();
                            changed();
                        });
                        fieldset.appendChild(product);
                        if (products.length === 0) {
                            const notice = document.createElement('p');
                            notice.className = 'mb-2 text-sm text-rose-700 dark:text-rose-300';
                            notice.textContent = 'Aucun produit actif et tarifé du catalogue n’est disponible.';
                            fieldset.appendChild(notice);
                        }

                        const inputs = document.createElement('div');
                        inputs.className = 'grid grid-cols-3 gap-2';
                        [
                            ['quantity', 'text', 'Quantité'],
                            ['unit', 'text', 'Unité'],
                            ['unit_price', 'number', 'Prix unitaire'],
                        ].forEach(([key, type, placeholder]) => {
                            const input = document.createElement('input');
                            input.type = type;
                            if (key === 'unit') input.readOnly = true;
                            if (key === 'unit_price') {
                                input.min = '0';
                                input.step = '0.01';
                            }
                            input.required = true;
                            input.placeholder = placeholder;
                            input.value = item[key] || '';
                            input.setAttribute('aria-label', `${placeholder} de l’article ${index + 1}`);
                            input.className = 'min-w-0 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-900';
                            input.addEventListener('input', () => { items[index][key] = input.value; changed(); });
                            inputs.appendChild(input);
                        });
                        fieldset.appendChild(inputs);

                        if (items.length > 1) {
                            const remove = document.createElement('button');
                            remove.type = 'button';
                            remove.className = 'mt-2 text-xs text-rose-700 dark:text-rose-300';
                            remove.textContent = 'Retirer cet article';
                            remove.addEventListener('click', () => {
                                items.splice(index, 1);
                                render();
                                changed();
                            });
                            fieldset.appendChild(remove);
                        }
                        rows.appendChild(fieldset);
                    });
                    add.disabled = items.length >= 50;
                    add.classList.toggle('opacity-50', add.disabled);
                };

                add.addEventListener('click', () => {
                    if (items.length >= 50) return;
                    items.push(emptyItem());
                    render();
                    changed();
                });
                wrapper.append(rows, add);
                if (!items.length) items.push(emptyItem());
                render();

                return {
                    el: wrapper,
                    input: rows.querySelector('input') || add,
                    getValue: () => items.map((item) => ({...item})),
                    setValue: (value) => {
                        items = Array.isArray(value) ? value.map((item) => ({...item})) : [];
                        if (!items.length) items.push(emptyItem());
                        render();
                    },
                };
            },
        });
    };

    Alpine.store('sales', {
        orders: [],
        applications: [],
        statistics: {orders: 0, pieces: 0, revenue_by_currency: {}, average_by_currency: {}, statuses: {}},
        filters: emptyFilters(),
        selected: [],
        page: 1,
        pages: 1,
        total: 0,
        perPage: 10,
        saving: false,
        loading: false,
        canManage: false,
        canSetup: false,
        setupRequired: false,
        mailEnabled: false,
        products: [],
        viewOrder: null,
        statusLabels: {
            pending: 'En attente',
            confirmed: 'Confirmée',
            processing: 'En traitement',
            ready: 'Prête',
            completed: 'Terminée',
            cancelled: 'Annulée'
        },
        statusClasses: {
            pending: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
            confirmed: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20',
            processing: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-400/20',
            ready: 'bg-teal-50 text-teal-700 ring-teal-600/20 dark:bg-teal-500/10 dark:text-teal-300 dark:ring-teal-400/20',
            completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
            cancelled: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/20'
        },

        initialize(data = {}) {
            this.filters = {...emptyFilters(), ...(data.filters || {})};
            Object.keys(this.filters).forEach((key) => {
                if (this.filters[key] === null || this.filters[key] === undefined) this.filters[key] = '';
            });
            this.filters.status = this.filters.status || 'all';
            this.applyResult({
                items: data.orders || [],
                page: data.page,
                pages: data.pages,
                total: data.total,
                perPage: data.perPage,
                statistics: data.statistics
            });
            this.applications = data.applications || [];
            this.products = data.products || [];
            this.canManage = Boolean(data.canManage);
            this.canSetup = Boolean(data.canSetup);
            this.setupRequired = Boolean(data.setupRequired);
            this.mailEnabled = Boolean(data.mailEnabled);
            this.selected = [];
        },

        /* ---------- Formatage ---------- */
        money(minor, currency) {
            const amount = Number(minor || 0) / 100;
            try {
                return new Intl.NumberFormat('fr-FR', {style: 'currency', currency: currency || 'MAD'}).format(amount);
            } catch (error) {
                return `${amount.toFixed(2).replace('.', ',')} ${currency || ''}`.trim();
            }
        },
        number(value) {
            return new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 2}).format(Number(value || 0));
        },
        statusLabel(status) {
            return this.statusLabels[status] || status;
        },
        statusClass(status) {
            return this.statusClasses[status] || 'bg-slate-50 text-slate-700 ring-slate-500/20';
        },
        itemsCount(order) {
            return this.number((order.items || []).reduce((sum, item) => sum + Number(item.quantity || 0), 0));
        },
        customer(order) {
            const details = order.checkout_details || {};
            return details.customer && typeof details.customer === 'object' ? details.customer : {};
        },
        fulfillment(order) {
            const details = order.checkout_details || {};
            return details.fulfillment && typeof details.fulfillment === 'object' ? details.fulfillment : {};
        },
        fulfillmentLabel(order) {
            const fulfillment = this.fulfillment(order);
            if (fulfillment.mode !== 'delivery') return 'Retrait à confirmer';
            const address = fulfillment.address || {};
            return 'Livraison — ' + [address.street, address.city, address.postal_code].filter(Boolean).join(', ');
        },
        orderNote(order) {
            return String((order.checkout_details || {}).note || '');
        },
        revenueEntries() {
            return Object.entries(this.statistics.revenue_by_currency || {});
        },
        averageEntries() {
            return Object.entries(this.statistics.average_by_currency || {});
        },
        statusEntries() {
            return Object.entries(this.statistics.statuses || {});
        },

        /* ---------- Chargement ---------- */
        applyResult(data) {
            this.orders = Array.isArray(data.items) ? data.items : [];
            this.page = Number(data.page || 1);
            this.pages = Number(data.pages || 1);
            this.total = Number(data.total ?? this.orders.length);
            this.perPage = Number(data.perPage || this.perPage);
            if (data.statistics) {
                this.statistics = {
                    orders: 0, pieces: 0, revenue_by_currency: {}, average_by_currency: {}, statuses: {},
                    ...data.statistics
                };
            }
            const ids = this.orders.map((order) => Number(order.id));
            this.selected = this.selected.filter((id) => ids.includes(Number(id)));
        },
        queryParams(page, perPage = this.perPage) {
            const params = {page, per_page: perPage};
            Object.entries(this.filters).forEach(([key, value]) => {
                const text = String(value ?? '').trim();
                if (text !== '' && !(key === 'status' && text === 'all')) params[key] = text;
            });
            return params;
        },
        async loadOrders(page = 1, perPage = this.perPage, signal) {
            const params = this.queryParams(page, perPage);
            this.loading = true;
            try {
                const result = await api.get('/dashboard/sales/orders/search', {params, toast: false, signal});
                const data = result.data;
                if (!result.success || !data || !Array.isArray(data.items)) {
                    throw new Error(result.message || 'Impossible de charger les commandes.');
                }
                this.applyResult(data);
                this.perPage = Number(data.per_page || perPage);
                const query = new URLSearchParams(params);
                query.delete('page');
                history.replaceState(null, '', `${location.pathname}${query.toString() ? `?${query}` : ''}`);
            } finally {
                this.loading = false;
            }
        },
        async query(ctx = {}) {
            await this.loadOrders(ctx.page || 1, ctx.pageSize || this.perPage, ctx.signal);
        },
        async refreshOrderList(page = this.page) {
            try {
                const table = Alpine.store('tables')?.get('sales-orders');
                if (table) {
                    table.page = page;
                    await table.reload();
                } else {
                    await this.loadOrders(page, this.perPage);
                }
            } catch (error) {
                Toaster.warning(`Opération enregistrée, mais la liste des commandes n’a pas pu être actualisée : ${error.message}`);
            }
        },
        async applyFilters() {
            try {
                const table = Alpine.store('tables')?.get('sales-orders');
                if (table) {
                    table.page = 1;
                    await table.reload();
                } else {
                    await this.loadOrders(1, this.perPage);
                }
            } catch (error) {
                Toaster.warning(error.message || 'Impossible d’appliquer les filtres.');
            }
        },
        async setPage(page) {
            try {
                const table = Alpine.store('tables')?.get('sales-orders');
                if (table) {
                    table.page = page;
                    await table.reload();
                } else {
                    await this.loadOrders(page, this.perPage);
                }
            } catch (error) {
                Toaster.warning(error.message || 'Impossible de charger cette page.');
            }
        },
        async clearFilters() {
            this.filters = emptyFilters();
            await this.applyFilters();
        },

        /* ---------- Sélection ---------- */
        isSelected(id) {
            return this.selected.includes(Number(id));
        },
        toggleSelection(id) {
            const value = Number(id);
            this.selected = this.isSelected(value)
                ? this.selected.filter((item) => item !== value)
                : [...this.selected, value];
        },
        allSelected() {
            return this.orders.length > 0 && this.orders.every((order) => this.isSelected(order.id));
        },
        toggleAll() {
            this.selected = this.allSelected() ? [] : this.orders.map((order) => Number(order.id));
        },

        /* ---------- Création / modification ---------- */
        async openNewOrder() {
            registerOrderItemsField(this.products);
            if (!this.applications.length) {
                Toaster.warning('Aucune application Ventes active ne permet de créer une commande.');
                return;
            }
            await Form.open({
                variant: 'new',
                title: 'Ajouter une commande',
                confirmText: 'Créer la commande',
                cancelText: 'Annuler',
                size: '3xl',
                values: {
                    application_id: String(this.applications[0].id),
                    customer_name: '', customer_phone: '', customer_email: '', note: '',
                    items: [emptyItem()]
                },
                fields: [
                    {
                        name: 'application_id', label: 'Application', type: 'select', required: true,
                        options: this.applications.map((application) => ({
                            value: String(application.id),
                            label: `${application.name} (${application.currency})`
                        }))
                    },
                    ...this.customerFields(),
                    {name: 'items', label: 'Articles', type: 'sales-order-items', required: true, fullWidth: true}
                ],
                onSubmit: async (values, {close}) => {
                    await this.saveOrder('/dashboard/sales/orders/create', values, 1);
                    close();
                }
            });
        },
        customerFields() {
            return [
                {name: 'customer_name', label: 'Nom du client', type: 'text', maxLength: 190},
                {name: 'customer_phone', label: 'Téléphone', type: 'tel', maxLength: 80},
                {name: 'customer_email', label: 'E-mail', type: 'email', maxLength: 190},
                {name: 'note', label: 'Remarque', type: 'textarea', rows: 3, maxLength: 1000, fullWidth: true}
            ];
        },
        async openOrder(order) {
            registerOrderItemsField(this.products);
            const customer = this.customer(order);
            const editItems = Boolean(order.dashboard_manual);
            const values = {
                customer_name: String(customer.name || ''),
                customer_phone: String(customer.phone || ''),
                customer_email: String(customer.email || ''),
                note: this.orderNote(order),
                items: (order.items || []).map((item) => ({
                    item_reference: String(item.item_reference || ''),
                    label: String(item.label_snapshot || ''),
                    quantity: String(item.quantity ?? ''),
                    unit: String(item.unit || ''),
                    unit_price: (Number(item.unit_amount_minor || 0) / 100).toFixed(2)
                }))
            };
            const fields = this.customerFields();
            if (editItems) {
                fields.push({name: 'items', label: 'Articles', type: 'sales-order-items', required: true, fullWidth: true});
            }
            await Form.open({
                variant: 'edit',
                title: `Modifier la commande #${order.id}`,
                confirmText: 'Enregistrer',
                cancelText: 'Annuler',
                size: '3xl',
                values,
                fields,
                onSubmit: async (formValues, {close}) => {
                    const payload = {
                        application_id: Number(order.application_id),
                        order_id: Number(order.id),
                        version: Number(order.version),
                        customer_name: formValues.customer_name,
                        customer_phone: formValues.customer_phone,
                        customer_email: formValues.customer_email,
                        note: formValues.note
                    };
                    if (editItems) {
                        payload.edit_items = '1';
                        payload.items = formValues.items;
                    }
                    await this.saveOrder('/dashboard/sales/orders/update', payload, this.page);
                    close();
                }
            });
        },
        async saveOrder(url, payload, page) {
            if (this.saving) return;
            this.saving = true;
            try {
                const result = await api.post(url, payload, {toast: false});
                if (!result.success) throw new Error(result.message || 'L’opération a échoué.');
                let message = result.message || 'Commande enregistrée.';
                if (result.data && typeof result.data.public_reference === 'string') {
                    message += ` Référence à transmettre au client : ${result.data.public_reference}`;
                }
                await this.refreshOrderList(page);
                Toaster.success(message);
            } finally {
                this.saving = false;
            }
        },

        /* ---------- Statut ---------- */
        async setOrderStatus(order, status) {
            if (!status || status === order.status || this.saving) return;
            this.saving = true;
            try {
                const result = await api.post('/dashboard/sales/orders/transition', {
                    application_id: Number(order.application_id),
                    order_id: Number(order.id),
                    version: Number(order.version),
                    status
                }, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible de modifier le statut.');
                await this.refreshOrderList(this.page);
                Toaster.success(result.message || 'Statut mis à jour.');
            } catch (error) {
                Toaster.warning(error.message || 'Impossible de modifier le statut.');
                await this.refreshOrderList(this.page);
            } finally {
                this.saving = false;
            }
        },

        /* ---------- Suppression ---------- */
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
        async deleteOrders(ids) {
            const list = ids.map((id) => Number(id));
            if (!list.length) {
                Toaster.warning('Sélectionnez au moins une commande à supprimer.');
                return;
            }
            await this.confirmDelete(
                'Confirmer la suppression',
                list.length > 1
                    ? `Supprimer définitivement ces ${list.length} commandes ?`
                    : 'Supprimer définitivement cette commande ?',
                async () => {
                    const result = await api.post('/dashboard/sales/orders/delete', {order_ids: list}, {toast: false});
                    if (!result.success) throw new Error(result.message || 'La suppression a échoué.');
                    this.selected = this.selected.filter((id) => !list.includes(id));
                    await this.refreshOrderList(this.page);
                    Toaster.success(result.message || 'Commande(s) supprimée(s).');
                }
            );
        },
        deleteOrder(order) {
            return this.deleteOrders([order.id]);
        },
        deleteSelected() {
            return this.deleteOrders(this.selected);
        },

        /* ---------- Détail ---------- */
        openView(order) {
            this.viewOrder = order;
        },
        closeView() {
            this.viewOrder = null;
        },

        /* ---------- E-mail ---------- */
        async openEmail(order) {
            const customer = this.customer(order);
            const yesNo = [{value: '1', label: 'Oui'}, {value: '0', label: 'Non'}];
            await Form.open({
                variant: 'new',
                title: `Envoyer un e-mail — commande #${order.id}`,
                confirmText: 'Envoyer',
                cancelText: 'Annuler',
                size: '2xl',
                values: {
                    email: String(customer.email || ''),
                    subject: '',
                    include_status: '1',
                    attach_pdf: '0',
                    message: ''
                },
                fields: [
                    {name: 'email', label: 'Destinataire', type: 'email', required: true, maxLength: 190},
                    {name: 'subject', label: 'Objet', type: 'text', maxLength: 190, placeholder: `Commande #${order.id}`},
                    {name: 'include_status', label: 'Inclure l’état actuel de la commande', type: 'select', options: yesNo},
                    {name: 'attach_pdf', label: 'Joindre le PDF de la commande', type: 'select', options: yesNo},
                    {name: 'message', label: 'Message', type: 'textarea', rows: 4, maxLength: 5000, fullWidth: true},
                    {name: 'attachment', label: 'Pièce jointe', type: 'file', accept: '.pdf,.jpg,.jpeg,.png,.txt', fullWidth: true}
                ],
                onSubmit: async (values, {close}) => {
                    const payload = new FormData();
                    payload.append('application_id', String(order.application_id));
                    payload.append('order_id', String(order.id));
                    payload.append('email', values.email);
                    payload.append('subject', values.subject || '');
                    payload.append('message', values.message || '');
                    if (values.include_status === '1') payload.append('include_status', '1');
                    if (values.attach_pdf === '1') payload.append('attach_pdf', '1');
                    if (values.attachment) payload.append('attachment', values.attachment);
                    const result = await api.post('/dashboard/sales/orders/email', payload, {toast: false});
                    if (!result.success) throw new Error(result.message || 'L’e-mail n’a pas pu être envoyé.');
                    Toaster.success(result.message || 'E-mail envoyé.');
                    close();
                }
            });
        },

        /* ---------- PDF ---------- */
        async downloadPdf(order) {
            try {
                const response = await api.get('/dashboard/sales/orders/pdf', {
                    params: {application_id: order.application_id, order_id: order.id},
                    responseType: 'blob',
                    fullResponse: true,
                    toast: false
                });
                const disposition = response.headers.get('Content-Disposition') || '';
                const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] || `commande-${order.id}.pdf`;
                const blobUrl = URL.createObjectURL(response.data);
                const link = document.createElement('a');
                link.href = blobUrl;
                link.download = filename;
                link.click();
                setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
                Toaster.success('Le PDF de la commande a été téléchargé.');
            } catch (error) {
                Toaster.warning(error.message || 'Le PDF ne peut pas être téléchargé.');
            }
        },

        /* ---------- Boutique ---------- */
        async setupStorefront() {
            if (this.saving) return;
            this.saving = true;
            try {
                const result = await api.post('/dashboard/sales/storefront/setup', {}, {toast: false});
                if (!result.success) throw new Error(result.message || 'La configuration a échoué.');
                this.setupRequired = false;
                Toaster.success(result.message || 'Boutique configurée.');
            } catch (error) {
                Toaster.warning(error.message || 'La configuration a échoué.');
            } finally {
                this.saving = false;
            }
        }
    });
});