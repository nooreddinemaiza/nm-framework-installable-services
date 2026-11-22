document.addEventListener('alpine:init', () => {
    const BASE = '/dashboard/conversations/admin';
    const controllers = {};
    const lastAt = {};
    const MIN_INTERVAL = 400;

    // Même contrat que _fetchJson de realtimeOrganisations : CSRF auto, abort par clé, throttle optionnel.
    async function call(key, url, { method = 'POST', body = null, throttle = false } = {}) {
        controllers[key]?.abort();
        if (throttle) {
            const wait = MIN_INTERVAL - (Date.now() - (lastAt[key] || 0));
            if (wait > 0) await new Promise((resolve) => setTimeout(resolve, wait));
        }
        const controller = new AbortController();
        controllers[key] = controller;
        lastAt[key] = Date.now();
        try {
            const result = await api.request(method, url, { body: body || undefined, signal: controller.signal });
            if (result && result.success === false) {
                const error = result.errors ?? result.error;
                if (error) Toaster.warning(error);
            }
            return result;
        } catch (error) {
            if (error.code === 'ABORTED') return null;
            if (error.code === 'SESSION_EXPIRED' && typeof sessionExpired === 'function') sessionExpired();
            if (error.notified) return null;
            if (error.status === 403) { Toaster.warning('Action non autorisée.'); return null; }
            if (error.data?.errors || error.data?.error) {
                const e = error.data.errors ?? error.data.error;
                Toaster.warning(e);
                return error.data;
            }
            Toaster.warning('Erreur de connexion. Veuillez réessayer.');
            return null;
        } finally {
            if (controllers[key] === controller) controllers[key] = null;
        }
    }

    const confirmDialog = (message, title, confirmText = 'Confirmer') =>
        Toaster.confirm(message, { type: 'danger', title, confirmText, cancelText: 'Annuler' });
    const ACTION_FLAG = { preserve: 'can_preserve', terminate: 'can_suspend', delete: 'can_delete' };
    const rowAllows = (row, action) => Boolean(row?.[ACTION_FLAG[action]]);
    const table = (id) => Alpine.store('tables').get(id);
    const refilter = (id) => {
        const t = table(id);
        if (!t) return;
        t.page = 1;
        return t.reload();
    };

    // ─────────────────────────────────────────────────────────────────────
    // Conversations (modérateur + super-admin)
    // ─────────────────────────────────────────────────────────────────────
    Alpine.store('conversationAdmin', {
        loading: false,
        list: [],
        totalItems: 0,
        filters: { search: '', status: 'all', kind: 'all', dateFrom: '', dateTo: '' },

        async query(ctx) {
            this.loading = true;
            const result = await call('conv-list', `${BASE}/list`, {
                throttle: true,
                body: {
                    search: this.filters.search,
                    status: this.filters.status,
                    kind: this.filters.kind,
                    date_from: this.filters.dateFrom,
                    date_to: this.filters.dateTo,
                    sort_by: ctx?.sort?.key,
                    sort_dir: ctx?.sort?.dir,
                    page: ctx?.page,
                    per_page: ctx?.pageSize,
                },
            });
            this.loading = false;
            if (!result) return;
            if (!result.success) {
                this.list = [];
                this.totalItems = 0;
                return;
            }
            this.list = result.data;
            this.totalItems = result.meta.total;
        },
        reload() { return table('conversations-table')?.reload(); },
        refilter() { return refilter('conversations-table'); },
        setStatus(value) { this.filters.status = value; this.refilter(); },
        setKind(value) { this.filters.kind = value; this.refilter(); },
        resetFilters() {
            this.filters = { search: '', status: 'all', kind: 'all', dateFrom: '', dateTo: '' };
            this.refilter();
        },

        statusLabel(row) {
            if (row.preserved) return 'Préservée';
            return row.status === 'active' ? 'Active' : 'Terminée';
        },
        statusClass(row) {
            if (row.preserved) return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
            return row.status === 'active'
                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300'
                : 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200';
        },

        async act(row, action) {
            if (!rowAllows(row, action)) {
                Toaster.warning('Vous n’avez pas la permission d’effectuer cette action.');
                return;
            }
            const prompts = {
                terminate: row.preserved
                    ? 'Terminer cette conversation ? Les messages préservés resteront en lecture seule.'
                    : 'Terminer cette conversation ? Les messages et médias seront supprimés.',
                delete: 'Supprimer définitivement cette conversation, ses messages et ses médias ? Action irréversible.',
            };
            if (prompts[action] && !(await confirmDialog(prompts[action], action === 'delete' ? 'Suppression définitive' : 'Terminer la conversation'))) return;

            const result = await call('conv-action', '/dashboard/conversations/admin-action', {
                body: { conversation_id: Number(row.id), action },
            });
            if (!result?.success) return;
            Toaster.success({
                preserve: 'Conversation préservée.',
                terminate: 'Conversation terminée.',
                delete: 'Conversation supprimée définitivement.',
            }[action] || 'Action effectuée.');
            await this.reload();
            Alpine.store('messageAdmin').refreshAll();
        },

        async bulk(action, ids) {
            if (!ids.length) return;

            // Lignes de la page courante connues du store : on retire celles non autorisées.
            const known = new Map(this.list.map((row) => [Number(row.id), row]));
            const allowed = ids.filter((id) => {
                const row = known.get(Number(id));
                return !row || rowAllows(row, action);   // id hors page : le back tranchera
            });
            if (!allowed.length) {
                Toaster.warning('Aucune conversation sélectionnée ne permet cette action.');
                return;
            }

            const label = { preserve: 'préserver', terminate: 'terminer', delete: 'supprimer définitivement' }[action];
            const skipped = ids.length - allowed.length;
            const ok = await confirmDialog(
                `Voulez-vous ${label} ${allowed.length} conversation(s) ?`
                + (skipped > 0 ? ` ${skipped} ne sont pas autorisées et seront ignorées.` : ' Les conversations non éligibles seront ignorées.'),
                'Action sur la sélection'
            );
            if (!ok) return;
            const result = await call('conv-bulk', `${BASE}/bulk-action`, { body: { ids: allowed, action } });
            if (!result?.success) return;
            Toaster.success(result.message || 'Action effectuée.');
            await this.reload();
            Alpine.store('messageAdmin').refreshAll();
        },
    });

    // ─────────────────────────────────────────────────────────────────────
    // Messages (super-admin uniquement)
    // ─────────────────────────────────────────────────────────────────────
    Alpine.store('messageAdmin', {
        enabled: false,
        loading: false,
        list: [],
        totalItems: 0,
        viewing: null,
        stats: null,
        filters: { search: '', type: 'all', kind: 'all', dateFrom: '', dateTo: '', conversationId: '' },

        get hasFilters() {
            const f = this.filters;
            return Boolean(f.search || f.type !== 'all' || f.kind !== 'all' || f.dateFrom || f.dateTo || f.conversationId);
        },
        get statCards() {
            const s = this.stats;
            if (!s) return [];
            return [
                { label: 'Conversations', value: s.conversations_total },
                { label: 'Actives', value: s.conversations_active },
                { label: 'Préservées', value: s.conversations_preserved },
                { label: 'Messages', value: s.messages_total },
                { label: 'Images', value: s.messages_images },
                { label: 'Aujourd’hui', value: s.messages_today },
            ];
        },

        _filterPayload() {
            const f = this.filters;
            return {
                search: f.search,
                type: f.type,
                kind: f.kind,
                date_from: f.dateFrom,
                date_to: f.dateTo,
                conversation_id: f.conversationId || null,
            };
        },

        async query(ctx) {
            this.loading = true;
            const result = await call('msg-list', `${BASE}/messages/list`, {
                throttle: true,
                body: {
                    ...this._filterPayload(),
                    sort_by: ctx?.sort?.key,
                    sort_dir: ctx?.sort?.dir,
                    page: ctx?.page,
                    per_page: ctx?.pageSize,
                },
            });
            this.loading = false;
            if (!result) return;
            if (!result.success) {
                this.list = [];
                this.totalItems = 0;
                return;
            }
            this.list = result.data;
            this.totalItems = result.meta.total;
        },
        reload() { return table('messages-table')?.reload(); },
        refilter() { return refilter('messages-table'); },
        setType(value) { this.filters.type = value; this.refilter(); },
        setKind(value) { this.filters.kind = value; this.refilter(); },
        clearConversation() { this.filters.conversationId = ''; this.refilter(); },
        resetFilters() {
            this.filters = { search: '', type: 'all', kind: 'all', dateFrom: '', dateTo: '', conversationId: '' };
            this.refilter();
        },
        // Depuis le tableau des conversations : bascule sur l'onglet Messages, filtré.
        filterByConversation(id) {
            this.filters.conversationId = String(id);
            window.dispatchEvent(new CustomEvent('admin-tab', { detail: 'messages' }));
            Alpine.nextTick(() => this.refilter());
        },
        view(row) { this.viewing = row; },

        async loadStats() {
            if (!this.enabled) return;
            const result = await call('msg-stats', `${BASE}/stats`);
            if (result?.success) this.stats = result.data;
        },
        async refreshAll() {
            if (!this.enabled) return;
            await Promise.all([this.reload(), this.loadStats()]);
        },

        async _afterDelete(result) {
            Toaster.success(result.message || 'Suppression effectuée.');
            this.viewing = null;
            await this.refreshAll();
        },

        async removeOne(row) {
            const ok = await confirmDialog(
                `Supprimer définitivement le message #${row.id} de ${row.sender_name} ?${row.type === 'image' ? ' Le fichier image sera effacé.' : ''}`,
                'Supprimer le message'
            );
            if (!ok) return;
            const result = await call('msg-remove', `${BASE}/messages/remove`, { method: 'DELETE', body: { id: row.id } });
            if (result?.success) await this._afterDelete(result);
        },

        async removeSelected(ids) {
            if (!ids.length) return;
            const ok = await confirmDialog(`Supprimer définitivement ${ids.length} message(s) sélectionné(s) ?`, 'Supprimer la sélection');
            if (!ok) return;
            const result = await call('msg-remove-sel', `${BASE}/messages/remove-selection`, { method: 'DELETE', body: { ids } });
            if (result?.success) await this._afterDelete(result);
        },

        async removeAll() {
            const total = this.totalItems;
            if (!total) {
                Toaster.info('Aucun message à supprimer.');
                return;
            }
            const scope = this.hasFilters ? 'correspondant aux filtres actuels' : 'de TOUTE la plateforme';
            const ok = await confirmDialog(
                `Supprimer définitivement les ${total} message(s) ${scope} ? Cette action est irréversible.`,
                'Supprimer tous les messages',
                'Tout supprimer'
            );
            if (!ok) return;
            const result = await call('msg-remove-all', `${BASE}/messages/remove-all`, {
                method: 'DELETE',
                body: { ...this._filterPayload(), expected_total: total },
            });
            if (result?.success) await this._afterDelete(result);
        },
    });
});