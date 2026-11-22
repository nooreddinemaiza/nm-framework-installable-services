document.addEventListener('alpine:init', () => {
    Alpine.store('conversationApp', {
        csrfToken: '',
        conversations: [],
        users: [],
        groups: [],
        messages: [],
        hasMoreMessages: false,
        beforeMessageId: null,
        loadingOlder: false,
        selected: null,
        search: '',
        conversationSearch: '',
        groupId: '',
        subject: '',
        body: '',
        image: null,
        unread: 0,
        hasUnreadSnapshot: false,
        loading: false,
        sending: false,
        finished: false,
        isModerator: false,
        canStart: false,
        canParticipate: false,
        isSuperAdmin: false,
        currentUserId: 0,
        adminRows: [],
        policyRoles: [],
        policyOverrides: [],
        uploadingDefaultMedia: false,
        defaultMediaVersion: Date.now(),
        _initialized: false,
        _fallbackPoll: null,
        _realtimeConnected: false,
        _seenRealtimeMessages: new Set(),
        newBelow: false,          // true = un message est arrivé hors de vue (bouton "Nouveau message")
        _offRealtime: null,
        _listeners: [],
        _unreadTimer: null,
        _lastRefreshAt: 0,
        _watchTimer: null,
        groupPolicy: {
            message_limit: 500,
            lifetime_minutes: 43200,
            image_streak_limit: 3,
            media_retention_minutes: 1440,
            fallback: { message_limit: 100, lifetime_minutes: 10080, image_streak_limit: 3 },
        },
        newPolicy: { sr_id: '', rr_id: '', message_limit: 100, lifetime_minutes: 10080, image_streak_limit: 3 },
        get filteredConversations() {
            const query = this.conversationSearch.trim().toLocaleLowerCase();
            if (!query) return this.conversations;
            return this.conversations.filter((conversation) => [
                conversation.label,
                ...(conversation.participant_names || []),
                conversation.latest_message,
            ].some((value) => String(value || '').toLocaleLowerCase().includes(query)));
        },
        get selectedGroup() {
            return this.groups.find((group) => Number(group.id) === Number(this.groupId)) || null;
        },
        initialize(root) {
            if (this._initialized) return;
            this._initialized = true;
            this.csrfToken = root.dataset.csrf || '';
            this.isModerator = root.dataset.moderator === '1';
            this.isSuperAdmin = root.dataset.superAdmin === '1';
            this.currentUserId = Number(root.dataset.userId || 0);
            if (root.dataset.poll === '0') return;

            this.canStart = root.dataset.canStart === '1';
            this.canParticipate = root.dataset.canParticipate === '1';
            if (root.dataset.poll === '0') return;
            if (!this.canParticipate) return;   // pas de realtime, ni de polling, ni de liste

            this.bindRealtime();
            this.load().then(() => {
                const requested = Number(new URLSearchParams(window.location.search).get('open') || 0);
                if (requested > 0 && this.isSuperAdmin) this.open(requested);
            });
            this.refreshUnread();
            if (this.canStart) this.loadGroups();
        },


        bindRealtime() {
            const realtime = Alpine.store('realtime');

            // Source principale : le même store `realtime` que `inbox` et `notifications`.
            // Le backend publie { type: 'message', data: { conversation_id, sender_id, message } }.
            if (realtime?.on) {
                this._offRealtime = realtime.on('message', (payload) => this.handleRealtimeEvent(payload));
            } else {
                console.warn('[conversations] realtime store indisponible, repli sur le polling');
            }

            // Compat : événements window (dédupliqués par id de message dans handleRealtimeEvent)
            this.listen(window, 'app:realtime', (event) => this.handleRealtimeEvent(event.detail));
            this.listen(window, 'app:realtime-status', (event) => {
                this.setRealtimeStatus(Boolean(event.detail?.connected));
            });

            // Retour sur l'onglet : rattrape ce qui a pu être manqué
            this.listen(document, 'visibilitychange', () => {
                if (document.visibilityState === 'visible' && Date.now() - this._lastRefreshAt > 15000) {
                    this.refreshInbox();
                }
            });

            this._realtimeConnected = this.readRealtimeStatus(realtime);
            this.setRealtimeStatus(this._realtimeConnected);
        },

        readRealtimeStatus(realtime) {
            return Boolean(
                realtime?.connected ?? realtime?.isConnected ?? realtime?._connected ?? realtime?._wsConnected ?? false
            );
        },

        listen(target, type, handler) {
            target.addEventListener(type, handler);
            this._listeners.push(() => target.removeEventListener(type, handler));
        },

        destroy() {
            this._offRealtime?.();
            this._listeners.forEach((off) => off());
            this._listeners = [];
            clearInterval(this._fallbackPoll);
            clearTimeout(this._unreadTimer);
            this._fallbackPoll = null;
            this._initialized = false;
            this.stopWatch();
        },

        // Accepte l'enveloppe complète ({type, data}) OU directement `data`.
        unwrapMessageEvent(payload) {
            if (!payload) return null;
            if (payload.type && payload.type !== 'message' && payload.type !== 'conversation.message') return null;
            const data = payload.data ?? payload;
            return data?.conversation_id && data?.message?.id ? data : null;
        },

        syncInbox() {
            Alpine.store('inbox')?.load?.();
        },

        scheduleUnreadRefresh() {
            clearTimeout(this._unreadTimer);
            this._unreadTimer = setTimeout(() => this.refreshUnread(), 600);
        },

        async markSelectedRead(conversationId) {
            await this.request('/dashboard/conversations/read', {
                method: 'POST',
                body: { conversation_id: conversationId },
            });
            this.syncInbox();            // le store inbox (global) se met à jour
            this.scheduleUnreadRefresh();
        },

        notifyIncoming(message) {
            const sender = String(message.sender_name || '').trim();
            Toaster.info(sender ? `Nouveau message de ${sender}.` : 'Nouveau message reçu.');
        },

        isNearBottom() {
            const list = document.getElementById('conversation-messages');
            if (!list) return true;
            return list.scrollHeight - list.scrollTop - list.clientHeight < 120;
        },

        scrollToBottom() {
            this.newBelow = false;
            Alpine.nextTick(() => {
                const list = document.getElementById('conversation-messages');
                if (list) list.scrollTop = list.scrollHeight;
            });
        },

        // À brancher sur la liste : @scroll.passive="$store.conversationApp.onMessagesScroll()"
        onMessagesScroll() {
            if (this.newBelow && this.isNearBottom()) this.newBelow = false;
        },
        initializePolicies(root) {
            this.csrfToken = root.dataset.csrf || '';
            this.policyRoles = JSON.parse(root.dataset.roles || '[]');
            this.policyOverrides = JSON.parse(root.dataset.policies || '[]');
            this.groupPolicy = JSON.parse(root.dataset.groupPolicy || '{}');
            if (this.policyRoles.length) {
                this.newPolicy.sr_id = String(this.policyRoles[0].id);
                this.newPolicy.rr_id = String(this.policyRoles[0].id);
            }
        },
        async load() {
            const result = await this.request('/dashboard/conversations/list');
            if (!result?.success) return;
            const rows = result.data || [];
            const selectedId = Number(this.selected?.id || 0);
            const selectedRow = rows.find((row) => Number(row.id) === selectedId);
            if (selectedId && !selectedRow) {
                const access = await this.request(
                    `/dashboard/conversations/messages?conversation_id=${selectedId}`
                );
                if (access?.status === 404 || access?.status === 410) {
                    this.clearSelected();
                    Toaster.info('Cette conversation a été supprimée ou vous n’y avez plus accès.');
                }
            } else if (selectedRow) {
                const wasFinished = this.finished;
                this.selected = { ...selectedRow, current_user_id: this.currentUserId };
                this.finished = !selectedRow.active;
                if (!wasFinished && this.finished) {
                    this.messages = selectedRow.preserved ? this.messages : [];
                    Toaster.info('Cette conversation a été terminée par un participant.');
                }
            }
            this.conversations = rows;
        },
        async refreshInbox() {
            this._lastRefreshAt = Date.now();
            await this.load();
            const selectedId = Number(this.selected?.id || 0);
            if (selectedId && !this.finished) await this.refreshSelected(selectedId);
            await this.refreshUnread();
        },
        setRealtimeStatus(connected) {
            const reconnected = connected && !this._realtimeConnected;
            this._realtimeConnected = connected;
            if (connected) {
                clearInterval(this._fallbackPoll);
                this._fallbackPoll = null;
                if (reconnected) this.refreshInbox();
                return;
            }
            if (this._fallbackPoll === null) {
                this._fallbackPoll = window.setInterval(() => this.refreshInbox(), 30000);
            }
        },
        handleRealtimeEvent(payload) {
            const data = this.unwrapMessageEvent(payload);
            if (!data) return;

            const conversationId = Number(data.conversation_id);
            const message = data.message;

            // Dédoublonnage (store realtime + événement window, reconnexions…)
            const messageId = String(message.id);
            if (this._seenRealtimeMessages.has(messageId)) return;
            this._seenRealtimeMessages.add(messageId);
            if (this._seenRealtimeMessages.size > 500) {
                this._seenRealtimeMessages.delete(this._seenRealtimeMessages.values().next().value);
            }

            // Sur un canal de groupe partagé, l'expéditeur reçoit aussi son propre message.
            const isOwn = Number(data.sender_id ?? message.sender_id) === this.currentUserId;
            const isSelected = Number(this.selected?.id || 0) === conversationId;

            if (isSelected) {
                message.can_preserve = message.type === 'image'
                    && !message.media_removed
                    && Boolean(this.selected?.can_preserve);
                // Déjà présent (ajouté par send()) => rien d'autre à faire
                if (!this.upsertMessage(message, isOwn)) return;
            }

            // Liste de gauche : aperçu + compteur, et la conversation remonte en tête
            const preview = message.type === 'image' ? 'Image' : String(message.body || '').slice(0, 100);
            const updated = this.conversations.map((conversation) => {
                if (Number(conversation.id) !== conversationId) return conversation;
                const unread = isSelected ? 0 : (isOwn ? Number(conversation.unread || 0) : Number(conversation.unread || 0) + 1);
                return { ...conversation, latest_message: preview, unread };
            });
            const index = updated.findIndex((conversation) => Number(conversation.id) === conversationId);
            if (index === -1) {
                this.load(); // nouvelle conversation (ex. premier message reçu)
            } else if (index > 0) {
                updated.unshift(updated.splice(index, 1)[0]);
            }
            this.conversations = updated;

            if (isOwn) return; // pas de non-lu ni de toast pour ses propres messages

            if (isSelected && !this.finished) {
                // Conversation ouverte : lu immédiatement (le compteur global ne bouge pas)
                this.markSelectedRead(conversationId);
                return;
            }

            this.unread += 1;
            this.notifyIncoming(message);
        },
        // `forceScroll` : toujours descendre (ses propres messages, envoi). Sinon on ne
        // descend que si l'utilisateur est déjà en bas ; sinon on propose "Nouveau message".
        upsertMessage(message, forceScroll = false) {
            const messageId = Number(message.id);
            if (this.messages.some((item) => Number(item.id) === messageId)) return false;
            this.messages = [...this.messages, message]
                .sort((left, right) => Number(left.id) - Number(right.id));

            if (forceScroll || this.isNearBottom()) {
                this.scrollToBottom();
            } else {
                this.newBelow = true;
            }
            return true;
        },
        async refreshUnread() {
            const result = await this.request('/dashboard/conversations/unread-count');
            if (result?.success) {
                const previous = this.unread;
                const current = Number(result.count || 0);
                if (this.hasUnreadSnapshot && current > previous) {
                    Toaster.info(`Vous avez ${current - previous} nouveau(x) message(s).`);
                }
                this.unread = current;
                this.hasUnreadSnapshot = true;
            }
        },
        async loadGroups() {
            const result = await this.request('/dashboard/conversations/groups');
            if (result?.success) this.groups = result.data || [];
        },
        async findUsers() {
            if (!this.canStart) return;
            const query = this.search.trim();
            if (query.length < 2) {
                this.users = [];
                return;
            }
            const result = await this.request(`/dashboard/conversations/users?q=${encodeURIComponent(query)}`);
            if (result?.success) this.users = result.data || [];
        },
        async startDirect(user) {
            this.search = '';
            this.users = [];
            if (user.conversation_id) {
                await this.load();
                await this.open(Number(user.conversation_id));
                return;
            }
            const result = await this.request('/dashboard/conversations/open', {
                method: 'POST',
                body: { user_id: user.id },
            });
            if (!result?.success) return this.showError(result?.errors || 'Impossible de démarrer la conversation.');
            await this.load();
            await this.open(Number(result.data.id));
        },
        async startGroup() {
            if (!this.groupId) return;
            const group = this.selectedGroup;
            if (!group) return;
            if (group.conversation_id) {
                await this.load();
                await this.open(Number(group.conversation_id));
                return;
            }
            if (!group.can_start) {
                this.showError('Un administrateur du groupe doit démarrer sa conversation.');
                return;
            }
            const result = await this.request('/dashboard/conversations/open', {
                method: 'POST',
                body: { group_id: Number(this.groupId), subject: this.subject },
            });
            if (!result?.success) return this.showError(result?.errors || 'Impossible de démarrer la conversation du groupe.');
            this.groupId = '';
            this.subject = '';
            await Promise.all([this.load(), this.loadGroups()]);
            await this.open(Number(result.data.id));
        },
        async open(id) {
            if (!this.canParticipate) return;
            this.newBelow = false;
            const row = this.conversations.find((conversation) => Number(conversation.id) === Number(id));
            if (Number(this.selected?.id || 0) !== Number(id)) {
                this.body = '';
                this.image = null;
                this.messages = [];
                const imageInput = document.getElementById('conversation-image');
                if (imageInput) imageInput.value = '';
            }
            this.selected = {
                ...(row || {}),
                id,
                current_user_id: this.currentUserId,
            };
            this.conversationSearch = '';
            this.loading = true;
            const result = await this.request(`/dashboard/conversations/messages?conversation_id=${Number(id)}`);
            this.loading = false;
            if (!result?.success) {
                this.showError(result?.errors || 'Impossible de charger les messages.');
                return;
            }
            const loadedMessages = new Map((result.data || []).map((message) => [Number(message.id), message]));
            for (const message of this.messages) loadedMessages.set(Number(message.id), message);
            this.messages = [...loadedMessages.values()].sort((left, right) => Number(left.id) - Number(right.id));
            this.hasMoreMessages = Boolean(result.has_more);
            this.beforeMessageId = result.before_id ? Number(result.before_id) : null;
            this.finished = Boolean(result.finished || result.conversation?.finished);
            const info = result.conversation || {};
            this.selected = {
                ...this.selected,
                label: info.label || this.selected.label,
                is_member: info.is_member,
                can_preserve: info.can_preserve,
                can_suspend: info.can_suspend,
                can_delete: info.can_delete,
                preserved: Boolean(info.preserved),
                active: !this.finished,
                is_group: Boolean(info.is_group),
            };
            await this.request('/dashboard/conversations/read', {
                method: 'POST',
                body: { conversation_id: Number(id) },
            });
            await Promise.all([this.refreshUnread(), this.load()]);
            this.syncInbox();
            Alpine.nextTick(() => {
                const list = document.getElementById('conversation-messages');
                if (list) list.scrollTop = list.scrollHeight;
            });
            this.startWatch();
        },
        startWatch() {
            this.stopWatch();
            if (this.selected?.is_member !== false) return;
            this._watchTimer = setInterval(() => {
                if (document.hidden || !this.selected || this.finished) return;
                this.refreshSelected(this.selected.id);
            }, 10000);
        },
        stopWatch() {
            clearInterval(this._watchTimer);
            this._watchTimer = null;
        },
        async loadOlder() {
            if (!this.selected || !this.beforeMessageId || this.loadingOlder || !this.hasMoreMessages) return;
            this.loadingOlder = true;
            const list = document.getElementById('conversation-messages');
            const oldHeight = list?.scrollHeight || 0;
            const result = await this.request(
                `/dashboard/conversations/messages?conversation_id=${Number(this.selected.id)}&before_id=${this.beforeMessageId}`
            );
            if (result?.success) {
                this.messages = [...(result.data || []), ...this.messages];
                this.hasMoreMessages = Boolean(result.has_more);
                this.beforeMessageId = result.before_id ? Number(result.before_id) : null;
                Alpine.nextTick(() => {
                    if (list) list.scrollTop = list.scrollHeight - oldHeight;
                });
            }
            this.loadingOlder = false;
        },
        async refreshSelected(id) {
            const result = await this.request(`/dashboard/conversations/messages?conversation_id=${Number(id)}`);
            if (Number(this.selected?.id) !== Number(id)) return;
            if (!result?.success) {
                if (result?.status === 404 || result?.status === 410) {
                    this.clearSelected();
                    await this.load();
                }
                return;
            }
            const messages = new Map(this.messages.map((message) => [Number(message.id), message]));
            for (const message of result.data || []) messages.set(Number(message.id), message);
            this.finished = Boolean(result.finished || result.conversation?.finished);
            if (this.finished && !result.conversation?.preserved) {
                this.messages = [];
            } else {
                this.messages = [...messages.values()].sort((left, right) => Number(left.id) - Number(right.id));
            }
            if (this.selected) {
                this.selected = {
                    ...this.selected,
                    active: !this.finished,
                    preserved: Boolean(result.conversation?.preserved || this.selected.preserved),
                };
            }
            await this.request('/dashboard/conversations/read', {
                method: 'POST',
                body: { conversation_id: Number(id) },
            });
            this.syncInbox();
        },
        closeModal() {
            this.clearSelected();
        },
        clearSelected() {
            this.selected = null;
            this.messages = [];
            this.hasMoreMessages = false;
            this.beforeMessageId = null;
            this.finished = false;
            this.loading = false;
            this.body = '';
            this.image = null;
            this.newBelow = false;
            this.stopWatch();
        },
        chooseImage(event) {
            const file = event.target.files?.[0] || null;
            if (file && (file.size <= 0 || file.size >= 512000 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
                this.showError('Choisissez une image JPEG, PNG ou WebP de moins de 500 Ko.');
                event.target.value = '';
                this.image = null;
                return;
            }
            this.image = file;
        },
        async send() {
            if (!this.canParticipate || !this.selected || this.finished || this.sending
                || (!this.image && !this.body.trim())) return;
            this.sending = true;
            try {
                let payload;
                if (this.image) {
                    payload = new FormData();
                    payload.append('conversation_id', String(this.selected.id));
                    payload.append('image', this.image);
                } else {
                    payload = { conversation_id: Number(this.selected.id), body: this.body };
                }

                const result = await api.post('/dashboard/conversations/send', payload);
                if (!result?.success) {
                    this.showError(Toaster.format(result?.errors) || 'Le message n’a pas pu être envoyé.');
                    return;
                }
                this.body = '';
                this.image = null;
                const input = document.getElementById('conversation-image');
                if (input) input.value = '';
                this.upsertMessage(result.data, true);
                await this.load();
            } catch (error) {
                if (error.code === 'SESSION_EXPIRED') return; // déjà géré par api-client
                if (!error.notified) this.showError(error.message || 'Erreur de connexion.');
            } finally {
                this.sending = false;
            }
        },
        async finish(action) {
            if (!this.selected) return;
            const allowed = {
                preserve: this.selected.can_preserve,
                terminate: this.selected.can_suspend,
                delete: this.selected.can_delete,
            }[action];
            if (!allowed) return;

            const prompts = {
                preserve: {
                    message: 'Préserver les messages et médias de cette conversation ? La conversation restera ouverte.',
                    type: 'info',
                    title: 'Préserver l’historique',
                },
                terminate: {
                    message: this.selected.preserved
                        ? 'Terminer la conversation ? Les messages préservés resteront accessibles en lecture seule.'
                        : 'Terminer la conversation ? Les messages et médias seront supprimés; les participants verront qu’elle est terminée.',
                    type: 'warning',
                    title: 'Terminer la conversation',
                },
                delete: {
                    message: 'Supprimer définitivement cette conversation et ses éventuelles données préservées ? Cette action est irréversible.',
                    type: 'warning',
                    title: 'Supprimer définitivement',
                },
            };
            const prompt = prompts[action];
            if (!prompt) return;
            const confirmed = await Toaster.confirm(
                prompt.message,
                { type: prompt.type, title: prompt.title, confirmText: action === 'delete' ? 'Supprimer' : 'Confirmer', cancelText: 'Annuler' }
            );
            if (!confirmed) return;
            const result = await this.request('/dashboard/conversations/finish', {
                method: 'POST',
                body: { conversation_id: Number(this.selected.id), action },
            });
            if (!result?.success) return this.showError(result?.errors || 'Impossible de terminer la conversation.');
            if (action === 'delete') {
                this.clearSelected();
                await this.load();
                Toaster.success('Conversation supprimée définitivement.');
                return;
            }
            if (action === 'preserve') {
                this.selected.preserved = true;
                Toaster.success('Conversation préservée.');
            } else {
                this.finished = true;
                this.messages = result.preserved ? this.messages : [];
                this.body = '';
                this.image = null;
                const imageInput = document.getElementById('conversation-image');
                if (imageInput) imageInput.value = '';
                this.selected.active = false;
                this.selected.status = 'finished';
                this.selected.preserved = Boolean(result.preserved);
                Toaster.success('Conversation terminée.');
            }
            await this.load();
        },
        async preserveMedia(message) {
            const result = await this.request('/dashboard/conversations/preserve-media', {
                method: 'POST',
                body: { message_id: Number(message.id) },
            });
            if (!result?.success) return this.showError(result?.errors || 'Impossible de préserver cette image.');
            message.media_preserved = true;
            Toaster.success('Image préservée.');
        },
        addPolicyOverride() {
            const starter = Number(this.newPolicy.sr_id);
            const recipient = Number(this.newPolicy.rr_id);
            if (!starter || !recipient) return this.showError('Choisissez les deux rôles.');
            const duplicate = this.policyOverrides.some((rule) =>
                Number(rule.sr_id) === starter && Number(rule.rr_id) === recipient
            );
            if (duplicate) return this.showError('Une règle existe déjà pour cette paire de rôles.');
            this.policyOverrides.push({
                ...this.newPolicy,
                sr_id: starter,
                rr_id: recipient,
                message_limit: Number(this.newPolicy.message_limit),
                lifetime_minutes: Number(this.newPolicy.lifetime_minutes),
                image_streak_limit: Number(this.newPolicy.image_streak_limit),
            });
        },
        removePolicyOverride(index) {
            this.policyOverrides.splice(index, 1);
        },
        async savePolicies() {
            const result = await this.request('/dashboard/conversations/policies', {
                method: 'POST',
                body: {
                    policies: this.policyOverrides,
                    group_policy: this.groupPolicy,
                },
            });
            if (!result?.success) return this.showError(result?.errors || 'Impossible d’enregistrer les paramètres.');
            Toaster.success(result.message || 'Paramètres enregistrés.');
        },
        async uploadDefaultMedia(file) {
            if (!file) return;
            const formData = new FormData();
            formData.append('image', file);
            this.uploadingDefaultMedia = true;
            try {
                const result = await api.post('/dashboard/conversations/policies/default-media', formData);
                if (!result?.success) {
                    this.showError(Toaster.format(result?.errors) || 'Impossible de mettre à jour l’image de remplacement.');
                    return;
                }
                this.defaultMediaVersion = Date.now();
                Toaster.success(result.message || 'Image de remplacement mise à jour.');
            } catch (error) {
                if (error.code !== 'SESSION_EXPIRED' && !error.notified) {
                    this.showError(error.message || 'Erreur de connexion.');
                }
            } finally {
                this.uploadingDefaultMedia = false;
            }
        },
        async request(url, options = {}) {
            try {
                const data = await api.request(options.method || 'GET', url, {
                    body: options.body || undefined,
                });
                return { ...(data || {}), success: data?.success !== false, status: 200 };
            } catch (error) {
                if (error.code === 'SESSION_EXPIRED' && typeof sessionExpired === 'function') sessionExpired();
                if (error.notified) return null;
                if (error.status === 403) {
                    return { success: false, status: 403, errors: error.data?.errors || 'Action non autorisée.' };
                }
                if (error.code !== 'ABORTED' && !error.notified) this.showError(error.message || 'Erreur de connexion.');
                return null;
            }
        },
        showError(message) {
            Toaster.warning(message || 'Une erreur est survenue.');
        },
    });
});
