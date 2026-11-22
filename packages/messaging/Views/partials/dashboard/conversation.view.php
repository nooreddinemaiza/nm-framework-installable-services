@cdn('scripts','/dashboard/service-assets/messaging/chat-panel.js')
<div x-data x-init="$store.chatPanel.setup($el)" data-user-id="<?= (int) ($currentUserId ?? $user['id'] ?? 0) ?>"
    @keydown.escape.window="$store.chatPanel.open && $store.chatPanel.close()">
    <div x-show="$store.chatPanel.open" x-cloak role="dialog" aria-label="Conversation"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-end="opacity-0 translate-y-2"
        class="fixed z-50 bottom-0 right-0 sm:bottom-4 sm:right-4 w-full sm:w-[380px] h-[100dvh] sm:h-[520px] sm:max-h-[85vh] flex flex-col rounded-none sm:rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">

        <!-- En-tête -->
        <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-slate-100 dark:border-slate-800">
            <div class="min-w-0 flex items-center gap-2">
                <x-avatar name="$store.chatPanel.title" status="$store.chatPanel.is_online" src="$store.chatPanel.chatImage" :js="true" />
                <h2 class="text-[13.5px] font-semibold truncate" x-text="$store.chatPanel.title"></h2>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <a x-show="$store.chatPanel.conversationId" x-cloak
                    :href="'/dashboard/conversations?open=' + $store.chatPanel.conversationId"
                    class="text-[12px] text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 px-2 py-1">
                    Agrandir
                </a>
                <button type="button" @click="$store.chatPanel.close()" aria-label="Fermer"
                    class="cursor-pointer w-7 h-7 grid place-items-center rounded-full text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Messages -->
        <div class="relative flex-1 min-h-0">
            <div id="chat-panel-messages" @scroll.passive="$store.chatPanel.onScroll()"
                class="h-full overflow-y-auto px-4 py-3 space-y-2 scrollbar-thin">

                <div x-show="$store.chatPanel.loading || $store.chatPanel.starting" x-cloak
                    class="text-center text-[12.5px] text-slate-400 py-6">Chargement…</div>

                <div x-show="$store.chatPanel.hasMore" x-cloak class="text-center">
                    <button type="button" @click="$store.chatPanel.loadOlder()"
                        :disabled="$store.chatPanel.loadingOlder"
                        class="cursor-pointer text-[12px] font-medium text-indigo-600 dark:text-indigo-400 hover:underline disabled:opacity-50">
                        <span x-show="!$store.chatPanel.loadingOlder">Messages précédents</span>
                        <span x-show="$store.chatPanel.loadingOlder" x-cloak>…</span>
                    </button>
                </div>
                <p x-show="!$store.chatPanel.realtimeOn"
                    x-transition.opacity
                    class="mx-4 mb-3 flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-inset ring-amber-200 sm:mx-5 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                    <i class="fa-solid fa-plug-circle-xmark"></i>
                    Temps réel indisponible, actualisation toutes les 30 s.
                </p>
                <template
                    x-if="!$store.chatPanel.loading && !$store.chatPanel.starting && $store.chatPanel.conversationId && $store.chatPanel.messages.length === 0">
                    <p class="text-center text-[12.5px] text-slate-400 py-6">Aucun message. Écrivez le premier !</p>
                </template>

                <template x-for="m in $store.chatPanel.messages" :key="m.id">
                    <div class="flex" :class="$store.chatPanel.isMine(m) ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[80%] rounded-2xl px-3 py-1.5 text-[13px] leading-snug"
                            :class="$store.chatPanel.isMine(m)
                                ? 'bg-indigo-600 text-white rounded-br-sm'
                                : 'bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-100 rounded-bl-sm'">
                            <p x-show="$store.chatPanel.isGroup && !$store.chatPanel.isMine(m)" x-cloak
                                class="text-[11px] font-semibold opacity-70 mb-0.5" x-text="m.sender_name"></p>
                            <template x-if="m.type === 'image' && m.media_url && !m.media_removed">
                                <img :src="m.media_url" alt="Image" class="rounded-lg max-h-48 mb-0.5" loading="lazy">
                            </template>
                            <template x-if="m.type === 'image' && m.media_removed">
                                <p class="italic opacity-70">Image supprimée</p>
                            </template>
                            <p x-show="m.type !== 'image'" class="whitespace-pre-wrap break-words" x-text="m.body"></p>
                            <p class="text-[10.5px] mt-0.5 opacity-60 text-right" x-text="m.created_at"></p>
                        </div>
                    </div>
                </template>
            </div>

            <button type="button" x-show="$store.chatPanel.newBelow" x-cloak @click="$store.chatPanel.scrollToBottom()"
                class="cursor-pointer absolute bottom-2 left-1/2 -translate-x-1/2 text-[12px] font-medium px-3 py-1 rounded-full bg-indigo-600 text-white shadow">
                Nouveau message ↓
            </button>
        </div>

        <!-- Saisie -->
        <div class="border-t border-slate-100 dark:border-slate-800 p-3">
            <p x-show="$store.chatPanel.finished" x-cloak class="text-center text-[12.5px] text-slate-500">
                Cette conversation est terminée.
            </p>
            <form x-show="!$store.chatPanel.finished" @submit.prevent="$store.chatPanel.send()" class="space-y-2">
                <div x-show="$store.chatPanel.image" x-cloak
                    class="flex items-center justify-between text-[12px] rounded-md bg-slate-50 dark:bg-slate-800 px-2.5 py-1.5">
                    <span class="truncate" x-text="$store.chatPanel.image?.name"></span>
                    <button type="button" @click="$store.chatPanel.clearImage()"
                        class="cursor-pointer text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 ml-2">Retirer</button>
                </div>
                <div class="flex items-end gap-2">
                    <label class="cursor-pointer shrink-0 w-8 h-8 grid place-items-center rounded-md text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                        title="Joindre une image">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <circle cx="8.5" cy="8.5" r="1.5" />
                            <path d="m21 15-5-5L5 21" />
                        </svg>
                        <input id="chat-panel-image" type="file" accept="image/jpeg,image/png,image/webp" class="hidden"
                            @change="$store.chatPanel.chooseImage($event)">
                    </label>
                    <textarea x-model="$store.chatPanel.body" rows="1" maxlength="4000" placeholder="Votre message…"
                        :disabled="$store.chatPanel.sending || !$store.chatPanel.conversationId || !!$store.chatPanel.image"
                        @keydown.enter.prevent="!$event.shiftKey && $store.chatPanel.send()"
                        class="flex-1 resize-none max-h-24 rounded-md border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-[13px] leading-snug placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 disabled:opacity-60"></textarea>
                    <button type="submit" :disabled="!$store.chatPanel.canSend"
                        class="cursor-pointer shrink-0 h-8 px-3 rounded-md bg-indigo-600 hover:bg-indigo-700 text-white text-[12px] font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                        <span x-show="!$store.chatPanel.sending">Envoyer</span>
                        <span x-show="$store.chatPanel.sending" x-cloak>…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>