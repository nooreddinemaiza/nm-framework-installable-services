/**
 * Panneau de conversation flottant (démarrer / continuer une conversation
 * depuis une liste d'utilisateurs ou de groupes, sans quitter la page).
 *
 * Utilisation :
 *   Alpine.store('chatPanel').open User({ id, name, conversation_id? })
 *   Alpine.store('chatPanel').openGroup({ id, name, conversation_id?, can_start? })
 *   Alpine.store('chatPanel').openConversation(id, 'Titre')
 */
document.addEventListener('alpine:init', () => {
    Alpine.store('chatPanel', {
        open: false,
        conversationId: null,
        title: '',
        is_online: false,
        chatImage: '',
        isGroup: false,
        messages: [],
        loading: false,
        starting: false,
        loadingOlder: false,
        hasMore: false,
        beforeId: null,
        finished: false,
        body: '',
        image: null,
        sending: false,
        newBelow: false,
        currentUserId: 0,
        realtimeOn: false,

        _ready: false,
        _token: 0,
        _seen: new Set(),
        _offs: [],

        // Appelé depuis la vue : x-init="$store.chatPanel.setup($el)"
        setup(el) {
            if (this._ready) return;
            this._ready = true;
            this.currentUserId = Number(el.dataset.userId || 0);

            const realtime = Alpine.store('realtime');
            if (realtime?.on) {
                this._offs.push(realtime.on('message', (payload) => this.onRealtime(payload)));
            }
            const status = (event) => { this.realtimeOn = Boolean(event.detail?.connected); };
            window.addEventListener('app:realtime-status', status);
            this._offs.push(() => window.removeEventListener('app:realtime-status', status));
            this.realtimeOn = Boolean(realtime?.connected);

            // Retour sur l'onglet : rattrape les messages manqués si le realtime était coupé
            const visibility = () => {
                if (document.visibilityState === 'visible' && this.open && this.conversationId && !this.realtimeOn) {
                    this.fetchMessages();
                }
            };
            document.addEventListener('visibilitychange', visibility);
            this._offs.push(() => document.removeEventListener('visibilitychange', visibility));
        },

        destroy() {
            this._offs.forEach((off) => off?.());
            this._offs = [];
            this._ready = false;
        },

        // ── Ouverture ──
        async openUser(user) {
            if (!user?.id || this.starting) return;
            if (user.can_message === false) return;
            this.reset(user.name || 'Conversation', false);

            this.chatImage = user.profile_image;
            this.is_online = user.is_online;

            this.open = true;
            if (user.conversation_id) {
                this.attach(Number(user.conversation_id));
                return;
            }
            this.starting = true;
            const token = this._token;
            const result = await this.request('/dashboard/conversations/open', {
                method: 'POST',
                body: { user_id: user.id },
            });
            this.starting = false;
            if (token !== this._token) return;
            if (!result?.success) {
                this.open = false;
                Toaster.warning(this.flatten(result?.errors) || 'Impossible de démarrer la conversation.');
                return;
            }
            user.conversation_id = Number(result.data.id); // mémorisé pour les prochains clics
            this.attach(user.conversation_id);
        },

        async openGroup(group) {
            if (!group?.id || this.starting) return;
            this.reset(group.name || 'Groupe', true);
            this.open = true;
            if (group.conversation_id) {
                this.attach(Number(group.conversation_id));
                return;
            }
            if (group.can_start === false || group.can_start_conversation === false) {
                this.open = false;
                Toaster.warning('Un administrateur du groupe doit démarrer sa conversation.');
                return;
            }
            this.starting = true;
            const token = this._token;
            const result = await this.request('/dashboard/conversations/open', {
                method: 'POST',
                body: { group_id: group.id },
            });
            this.starting = false;
            if (token !== this._token) return;
            if (!result?.success) {
                this.open = false;
                Toaster.warning(this.flatten(result?.errors) || 'Impossible de démarrer la conversation du groupe.');
                return;
            }
            group.conversation_id = Number(result.data.id);
            this.attach(group.conversation_id);
        },

        openConversation(id, title = 'Conversation', isGroup = false) {
            if (!id) return;
            this.reset(title, isGroup);
            this.open = true;
            this.attach(Number(id));
        },

        close() {
            this.open = false;
            this._token++;
            this.conversationId = null;
            this.messages = [];
            this.body = '';
            this.clearImage();
        },

        reset(title, isGroup) {
            this._token++;
            this.conversationId = null;
            this.title = title;
            this.isGroup = isGroup;
            this.messages = [];
            this.hasMore = false;
            this.beforeId = null;
            this.finished = false;
            this.body = '';
            this.newBelow = false;
            this.loading = false;
            this.clearImage();
        },

        attach(conversationId) {
            this.conversationId = conversationId;
            this.fetchMessages(true);
        },

        // ── Messages ──
        async fetchMessages(initial = false) {
            const id = this.conversationId;
            if (!id) return;
            const token = this._token;
            if (initial) this.loading = true;
            const result = await this.request(`/dashboard/conversations/messages?conversation_id=${id}`);
            if (token !== this._token) return;
            this.loading = false;
            if (!result?.success) {
                Toaster.warning(this.flatten(result?.errors) || 'Impossible de charger les messages.');
                if (initial) this.close();
                return;
            }
            const merged = new Map(this.messages.map((m) => [Number(m.id), m]));
            for (const m of result.data || []) merged.set(Number(m.id), m);
            this.messages = [...merged.values()].sort((a, b) => Number(a.id) - Number(b.id));
            if (initial) {
                this.hasMore = Boolean(result.has_more);
                this.beforeId = result.before_id ? Number(result.before_id) : null;
            }
            this.finished = Boolean(result.finished || result.conversation?.finished);
            if (result.conversation?.label) this.title = result.conversation.label;
            this.isGroup = Boolean(result.conversation?.is_group ?? this.isGroup);
            this.markRead();
            this.scrollToBottom();
        },

        async loadOlder() {
            if (!this.conversationId || !this.beforeId || this.loadingOlder || !this.hasMore) return;
            const id = this.conversationId;
            const token = this._token;
            this.loadingOlder = true;
            const list = document.getElementById('chat-panel-messages');
            const oldHeight = list?.scrollHeight || 0;
            const result = await this.request(`/dashboard/conversations/messages?conversation_id=${id}&before_id=${this.beforeId}`);
            if (token === this._token && result?.success) {
                this.messages = [...(result.data || []), ...this.messages];
                this.hasMore = Boolean(result.has_more);
                this.beforeId = result.before_id ? Number(result.before_id) : null;
                Alpine.nextTick(() => { if (list) list.scrollTop = list.scrollHeight - oldHeight; });
            }
            this.loadingOlder = false;
        },

        async markRead() {
            if (!this.conversationId) return;
            const result = await this.request('/dashboard/conversations/read', {
                method: 'POST',
                body: { conversation_id: this.conversationId },
            });
            if (result?.success) Alpine.store('inbox')?.load?.();
        },

        isMine(message) {
            return Number(message.sender_id) === this.currentUserId;
        },

        // ── Temps réel ──
        // Accepte `data` ({conversation_id, sender_id, message}) ou l'enveloppe {type, data}.
        onRealtime(payload) {
            if (!payload) return;
            if (payload.type && payload.type !== 'message' && payload.type !== 'conversation.message') return;
            const data = payload.data ?? payload;
            const message = data?.message;
            if (!data?.conversation_id || !message?.id) return;

            const key = String(message.id);
            if (this._seen.has(key)) return;
            this._seen.add(key);
            if (this._seen.size > 500) this._seen.delete(this._seen.values().next().value);

            if (!this.open || Number(data.conversation_id) !== Number(this.conversationId)) return;

            const own = Number(data.sender_id ?? message.sender_id) === this.currentUserId;
            this.upsert(message, own);
            if (!own && !this.finished) this.markRead(); // panneau ouvert = lu
        },

        upsert(message, force = false) {
            const id = Number(message.id);
            if (this.messages.some((m) => Number(m.id) === id)) return false;
            this.messages = [...this.messages, message].sort((a, b) => Number(a.id) - Number(b.id));
            if (force || this.isNearBottom()) this.scrollToBottom();
            else this.newBelow = true;
            return true;
        },

        isNearBottom() {
            const list = document.getElementById('chat-panel-messages');
            return !list || list.scrollHeight - list.scrollTop - list.clientHeight < 120;
        },
        scrollToBottom() {
            this.newBelow = false;
            Alpine.nextTick(() => {
                const list = document.getElementById('chat-panel-messages');
                if (list) list.scrollTop = list.scrollHeight;
            });
        },
        onScroll() {
            if (this.newBelow && this.isNearBottom()) this.newBelow = false;
        },

        // ── Envoi ──
        chooseImage(event) {
            const file = event.target.files?.[0] || null;
            if (file && (file.size <= 0 || file.size >= 512000 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
                Toaster.warning('Choisissez une image JPEG, PNG ou WebP de moins de 500 Ko.');
                event.target.value = '';
                this.image = null;
                return;
            }
            this.image = file;
        },
        clearImage() {
            this.image = null;
            const input = document.getElementById('chat-panel-image');
            if (input) input.value = '';
        },
        get canSend() {
            return Boolean(this.conversationId) && !this.finished && !this.sending && (this.image || this.body.trim().length > 0);
        },
        async send() {
            if (!this.canSend) return;
            this.sending = true;
            try {
                let body;
                if (this.image) {
                    body = new FormData();
                    body.append('conversation_id', String(this.conversationId));
                    body.append('image', this.image);
                } else {
                    body = { conversation_id: this.conversationId, body: this.body };
                }
                const result = await api.post('/dashboard/conversations/send', body);
                if (!result?.success) {
                    Toaster.warning(this.flatten(result?.errors) || 'Le message n’a pas pu être envoyé.');
                    return;
                }
                this.body = '';
                this.clearImage();
                this.upsert(result.data, true);
                return;
            } catch (error) {
                if (!error.notified) Toaster.warning(error.message || 'Erreur de connexion.');
            } finally {
                this.sending = false;
            }
        },

        // ── Réseau ──
        flatten(errors) {
            return Toaster.format(errors);
        },
        async request(url, options = {}) {
            try {
                const data = await api.request(options.method || 'GET', url, {
                    body: options.body || undefined,
                    signal: options.signal,
                });
                return { ...(data || {}), status: 200 };
            } catch (error) {
                if (error.code === 'SESSION_EXPIRED' && typeof sessionExpired === 'function') sessionExpired();
                if (error.code !== 'ABORTED' && !error.notified) Toaster.warning(error.message || 'Erreur de connexion.');
                return null;
            }
        },
    });
});