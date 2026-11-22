@cdn('scripts','/dashboard/service-assets/messaging/conversations.js')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section x-cloak x-data="{ compose: false, tab: 'direct', destroy() { $store.conversationApp.destroy() } }"
    x-init="$store.conversationApp.initialize($el)" data-csrf="<?= $escape($csrf_token) ?>"
    data-moderator="<?= $isModerator ? '1' : '0' ?>" data-super-admin="<?= $isSuperAdmin ? '1' : '0' ?>"
    data-user-id="<?= (int) $currentUserId ?>" data-can-start="<?= $canStart ? '1' : '0' ?>"
    data-can-participate="<?= $canParticipate ? '1' : '0' ?>" class="mx-auto max-w-7xl px-2 pb-6 sm:px-4">

    <div
        class="flex h-[calc(100dvh-9rem)] min-h-[34rem] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">

        <!-- ═════════════ Colonne de gauche : boîte de réception ═════════════ -->
        <aside
            class="flex w-full min-w-0 flex-col border-slate-200 lg:w-[22rem] lg:shrink-0 lg:border-r xl:w-96 dark:border-slate-800">

            <!-- En-tête -->
            <header class="flex items-center justify-between gap-3 px-4 pb-3 pt-4 sm:px-5">
                <div class="flex min-w-0 items-center gap-2.5">
                    <h1 class="truncate text-lg font-bold tracking-tight text-slate-900 dark:text-white">Messages</h1>
                    <span x-show="$store.conversationApp.unread > 0"
                        class="inline-flex min-w-[1.5rem] items-center justify-center rounded-full bg-indigo-600 px-2 py-0.5 text-xs font-semibold text-white dark:bg-indigo-500"
                        x-text="$store.conversationApp.unread"
                        :title="$store.conversationApp.unread + ' nouveau(x)'"></span>
                </div>
                <button type="button" x-show="$store.conversationApp.canStart" @click="compose = !compose"
                    :aria-expanded="compose.toString()"
                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/30 active:scale-[.98] dark:bg-indigo-500 dark:hover:bg-indigo-400">
                    <i class="fa-solid text-[11px] transition" :class="compose ? 'fa-xmark' : 'fa-pen-to-square'"></i>
                    <span x-text="compose ? 'Fermer' : 'Nouvelle'"></span>
                </button>
            </header>

            <!-- Mode dégradé -->
            <p x-show="$store.conversationApp.canParticipate && !$store.conversationApp._realtimeConnected"
                x-transition.opacity
                class="mx-4 mb-3 flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-inset ring-amber-200 sm:mx-5 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                <i class="fa-solid fa-plug-circle-xmark"></i>
                Temps réel indisponible, actualisation toutes les 30 s.
            </p>
            <p x-show="!$store.conversationApp.canParticipate"
                class="mx-4 mb-3 flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-700 sm:mx-5 dark:bg-slate-800 dark:text-slate-300">
                <i class="fa-solid fa-lock"></i>
                Vous n'avez pas l'autorisation de participer aux conversations.
            </p>
            <!-- Panneau « Nouvelle conversation » -->
            <div x-show="compose && $store.conversationApp.canStart" x-transition.opacity.duration.150ms
                class="mx-4 mb-3 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/80 sm:mx-5 dark:border-slate-700 dark:bg-slate-800/60">
                <div class="grid grid-cols-2 gap-1 border-b border-slate-200 p-1.5 dark:border-slate-700"
                    role="tablist">
                    <button type="button" role="tab" @click="tab = 'direct'"
                        :aria-selected="(tab === 'direct').toString()"
                        class="flex items-center justify-center gap-2 rounded-lg px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                        :class="tab === 'direct' ? 'bg-white text-indigo-700 shadow-sm dark:bg-slate-700 dark:text-indigo-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'">
                        <i class="fa-regular fa-user"></i> Personne
                    </button>
                    <button type="button" role="tab" @click="tab = 'group'"
                        :aria-selected="(tab === 'group').toString()"
                        class="flex items-center justify-center gap-2 rounded-lg px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                        :class="tab === 'group' ? 'bg-white text-indigo-700 shadow-sm dark:bg-slate-700 dark:text-indigo-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'">
                        <i class="fa-solid fa-users"></i> Groupe
                    </button>
                </div>

                <!-- Message privé -->
                <div x-show="tab === 'direct'" class="space-y-2 p-3">
                    <label class="relative block">
                        <span class="sr-only">Trouver une personne</span>
                        <i
                            class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 dark:text-slate-500"></i>
                        <input type="search" x-model="$store.conversationApp.search"
                            @input.debounce.300ms="$store.conversationApp.findUsers()"
                            placeholder="Nom ou identifiant (2 caractères min.)"
                            class="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-500/15 dark:border-slate-600 dark:bg-slate-900/60 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-indigo-400 dark:focus:ring-indigo-400/20">
                    </label>
                    <div class="max-h-44 space-y-0.5 overflow-y-auto">
                        <template x-for="person in $store.conversationApp.users" :key="person.id">
                            <button type="button" @click="$store.conversationApp.startDirect(person); compose = false"
                                class="flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-slate-700/70">
                                <span
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-sm font-semibold uppercase text-white"
                                    x-text="(person.name || '?').charAt(0)"></span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100"
                                        x-text="person.name"></span>
                                    <span class="block truncate text-xs text-slate-500 dark:text-slate-400"
                                        x-text="'@' + person.username"></span>
                                </span>
                            </button>
                        </template>
                        <p x-show="$store.conversationApp.search.trim().length < 2"
                            class="px-1 py-2 text-xs text-slate-500 dark:text-slate-400">Saisissez un nom pour lancer
                            une conversation privée.</p>
                    </div>
                </div>

                <!-- Groupe -->
                <div x-show="tab === 'group'" class="space-y-2.5 p-3">
                    <label class="block">
                        <span class="sr-only">Groupe</span>
                        <select x-model="$store.conversationApp.groupId"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 transition focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-500/15 dark:border-slate-600 dark:bg-slate-900/60 dark:text-slate-100 dark:focus:border-indigo-400 dark:focus:ring-indigo-400/20">
                            <option value="">Choisir un groupe</option>
                            <template x-for="group in $store.conversationApp.groups" :key="group.id">
                                <option :value="String(group.id)"
                                    x-text="group.name + (group.conversation_id ? ' — conversation ouverte' : (group.can_start ? ' — démarrer' : ' — en attente d’un administrateur'))">
                                </option>
                            </template>
                        </select>
                    </label>
                    <input
                        x-show="$store.conversationApp.groupId && !$store.conversationApp.selectedGroup?.conversation_id"
                        type="text" maxlength="120" x-model="$store.conversationApp.subject"
                        placeholder="Sujet (facultatif)"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-500/15 dark:border-slate-600 dark:bg-slate-900/60 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-indigo-400 dark:focus:ring-indigo-400/20">
                    <button type="button"
                        x-show="$store.conversationApp.selectedGroup && ($store.conversationApp.selectedGroup.conversation_id || $store.conversationApp.selectedGroup.can_start)"
                        @click="$store.conversationApp.startGroup(); compose = false"
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/30 active:scale-[.99] dark:bg-indigo-500 dark:hover:bg-indigo-400">
                        <i class="fa-solid fa-users text-xs"></i>
                        <span
                            x-text="$store.conversationApp.selectedGroup?.conversation_id ? 'Continuer la conversation' : 'Démarrer la conversation'"></span>
                    </button>
                    <p x-show="$store.conversationApp.selectedGroup && !$store.conversationApp.selectedGroup.conversation_id && !$store.conversationApp.selectedGroup.can_start"
                        class="flex items-start gap-2 rounded-xl bg-white p-3 text-xs text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-900/60 dark:text-slate-300 dark:ring-slate-700">
                        <i class="fa-solid fa-circle-info mt-0.5 text-slate-400"></i>
                        Un administrateur du groupe doit démarrer sa conversation.
                    </p>
                </div>
            </div>

            <!-- Recherche -->
            <div class="px-4 pb-3 sm:px-5">
                <label class="relative block">
                    <span class="sr-only">Rechercher une conversation</span>
                    <i
                        class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 dark:text-slate-500"></i>
                    <input type="search" x-model="$store.conversationApp.conversationSearch"
                        placeholder="Rechercher une conversation"
                        class="w-full rounded-xl border border-transparent bg-slate-100 py-2.5 pl-9 pr-3 text-sm text-slate-900 placeholder:text-slate-500 transition focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/15 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-indigo-400 dark:focus:bg-slate-800 dark:focus:ring-indigo-400/20">
                </label>
            </div>

            <!-- Liste -->
            <nav class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-2 sm:px-3" aria-label="Conversations">
                <template x-for="conversation in $store.conversationApp.filteredConversations" :key="conversation.id">
                    <button type="button" title="Ouvrir la conversation"
                        @click="$store.conversationApp.open(conversation.id)"
                        class="group relative flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                        :class="Number($store.conversationApp.selected?.id) === Number(conversation.id)
                            ? 'bg-indigo-50 dark:bg-indigo-500/10'
                            : 'hover:bg-slate-50 dark:hover:bg-slate-800/70'">
                        <span
                            class="absolute inset-y-3 left-0 w-1 rounded-r-full bg-indigo-600 transition dark:bg-indigo-400"
                            :class="Number($store.conversationApp.selected?.id) === Number(conversation.id) ? 'opacity-100' : 'opacity-0'"></span>
                        <span
                            class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-base font-semibold uppercase text-white"
                            :class="!conversation.active ? 'opacity-60 grayscale' : ''">
                            <span x-text="(conversation.label || '?').charAt(0)"></span>
                            <span x-show="conversation.is_group"
                                class="absolute -bottom-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full border-2 border-white bg-slate-700 text-[8px] text-white dark:border-slate-900 dark:bg-slate-600">
                                <i class="fa-solid fa-users"></i>
                            </span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm text-slate-900 dark:text-slate-100"
                                    :class="conversation.unread > 0 ? 'font-bold' : 'font-semibold'"
                                    x-text="conversation.label"></span>
                                <span x-show="conversation.unread > 0"
                                    class="min-w-[1.35rem] shrink-0 rounded-full bg-indigo-600 px-1.5 py-0.5 text-center text-[11px] font-semibold leading-none text-white dark:bg-indigo-500"
                                    x-text="conversation.unread"></span>
                            </span>
                            <span class="mt-0.5 block truncate text-sm"
                                :class="conversation.unread > 0 ? 'font-medium text-slate-700 dark:text-slate-200' : 'text-slate-500 dark:text-slate-400'"
                                x-text="conversation.latest_message || 'Démarrez la conversation'"></span>
                            <span x-show="conversation.preserved || !conversation.active"
                                class="mt-1.5 flex flex-wrap gap-1.5">
                                <span x-show="conversation.preserved"
                                    class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                                    <i class="fa-solid fa-bookmark"></i> Préservée
                                </span>
                                <span x-show="!conversation.active"
                                    class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700">
                                    <i class="fa-solid fa-lock"></i> Terminée
                                </span>
                            </span>
                        </span>
                    </button>
                </template>

                <div x-show="!$store.conversationApp.filteredConversations.length"
                    class="flex flex-col items-center gap-3 px-6 py-14 text-center">
                    <span
                        class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                        <i class="fa-regular fa-comments text-xl"></i>
                    </span>
                    <p class="text-sm text-slate-500 dark:text-slate-400"
                        x-text="$store.conversationApp.conversationSearch ? 'Aucune conversation ne correspond à la recherche.' : 'Aucune conversation pour le moment.'">
                    </p>
                </div>
            </nav>

            <?php if ($canManageConversations): ?>
                <footer class="border-t border-slate-200 p-3 dark:border-slate-800">
                    <a href="/dashboard/conversations/admin"
                        class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-violet-50 hover:text-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 dark:text-slate-300 dark:hover:bg-violet-500/10 dark:hover:text-violet-300">
                        <i class="fa-solid fa-shield-halved w-4 text-center"></i> Gestion des conversations
                    </a>
                </footer>
            <?php endif; ?>
        </aside>

        <!-- ═════════════ Colonne de droite : conversation ═════════════ -->
        <main class="min-w-0 flex-1">

            <!-- État vide (bureau uniquement) -->
            <div x-show="!$store.conversationApp.selected"
                class="hidden h-full flex-col items-center justify-center gap-4 bg-slate-50 px-6 text-center lg:flex dark:bg-slate-950/40">
                <span
                    class="flex h-20 w-20 items-center justify-center rounded-3xl bg-white text-3xl text-indigo-500 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:text-indigo-400 dark:ring-slate-700">
                    <i class="fa-regular fa-paper-plane"></i>
                </span>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">Sélectionnez une conversation
                    </h2>
                    <p class="mt-1 max-w-xs text-sm text-slate-500 dark:text-slate-400">Choisissez un échange dans la
                        liste ou démarrez-en un nouveau avec une personne ou un groupe.</p>
                </div>
            </div>

            <!-- Conversation ouverte : plein écran sur mobile, panneau sur bureau -->
            <section x-show="$store.conversationApp.selected"
                @keydown.escape.window="$store.conversationApp.closeModal()"
                class="fixed inset-0 z-[70] flex flex-col bg-white lg:static lg:z-auto lg:h-full dark:bg-slate-900"
                role="region" aria-label="Conversation">

                <!-- En-tête de conversation -->
                <header
                    class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white/90 px-3 py-3 backdrop-blur sm:px-5 dark:border-slate-800 dark:bg-slate-900/90">
                    <div class="flex min-w-0 items-center gap-3">
                        <button type="button" @click="$store.conversationApp.closeModal()"
                            aria-label="Retour aux conversations"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-600 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 lg:hidden dark:text-slate-300 dark:hover:bg-slate-800">
                            <i class="fa-solid fa-arrow-left"></i>
                        </button>
                        <span
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-sm font-semibold uppercase text-white"
                            x-text="($store.conversationApp.selected?.label || '?').charAt(0)"></span>
                        <div class="min-w-0">
                            <h2 class="truncate text-sm font-semibold text-slate-900 sm:text-base dark:text-white"
                                x-text="$store.conversationApp.selected?.label || 'Conversation'"></h2>
                            <p class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                                <span
                                    x-text="$store.conversationApp.selected?.is_group ? 'Conversation de groupe' : 'Conversation privée'"></span>
                                <span x-show="$store.conversationApp.finished"
                                    class="inline-flex items-center gap-1 font-semibold text-amber-700 dark:text-amber-300">
                                    <i class="fa-solid fa-lock text-[10px]"></i> Terminée — lecture seule
                                </span>
                                <span x-show="$store.conversationApp.selected?.preserved"
                                    class="inline-flex items-center gap-1 font-semibold text-amber-700 dark:text-amber-300">
                                    <i class="fa-solid fa-bookmark text-[10px]"></i> Historique préservé
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1.5">
                        <button type="button"
                            x-show="$store.conversationApp.selected?.can_preserve && !$store.conversationApp.selected?.preserved"
                            @click="$store.conversationApp.finish('preserve')" title="Préserver l’historique"
                            class="inline-flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200 transition hover:bg-amber-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 dark:text-amber-300 dark:ring-amber-500/30 dark:hover:bg-amber-500/10">
                            <i class="fa-regular fa-bookmark"></i><span class="hidden sm:inline">Préserver</span>
                        </button>
                        <button type="button"
                            x-show="$store.conversationApp.selected?.can_suspend && !$store.conversationApp.finished"
                            @click="$store.conversationApp.finish('terminate')" title="Terminer la conversation"
                            class="inline-flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-200 transition hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 dark:text-rose-300 dark:ring-rose-500/30 dark:hover:bg-rose-500/10">
                            <i class="fa-solid fa-flag-checkered"></i><span class="hidden sm:inline">Terminer</span>
                        </button>
                        <button type="button"
                            x-show="$store.conversationApp.selected?.can_delete && $store.conversationApp.finished"
                            @click="$store.conversationApp.finish('delete')" title="Supprimer définitivement"
                            class="inline-flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-200 transition hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 dark:text-rose-300 dark:ring-rose-500/30 dark:hover:bg-rose-500/10">
                            <i class="fa-regular fa-trash-can"></i><span class="hidden sm:inline">Supprimer</span>
                        </button>
                        <button type="button" @click="$store.conversationApp.closeModal()"
                            aria-label="Fermer la conversation" title="Fermer (Échap)"
                            class="hidden h-9 w-9 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 lg:flex dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </header>

                <!-- Messages -->
                <div id="conversation-messages" @scroll.passive="$store.conversationApp.onMessagesScroll()" role="log"
                    aria-live="polite"
                    class="relative min-h-0 flex-1 space-y-2 overflow-y-auto bg-slate-50 px-3 py-4 sm:px-6 dark:bg-slate-950/40">

                    <button type="button" x-show="$store.conversationApp.hasMoreMessages"
                        :disabled="$store.conversationApp.loadingOlder" @click="$store.conversationApp.loadOlder()"
                        class="mx-auto mb-2 flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-xs font-medium text-slate-600 shadow-sm transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700">
                        <i class="fa-solid fa-clock-rotate-left text-[10px]"></i>
                        <span
                            x-text="$store.conversationApp.loadingOlder ? 'Chargement…' : 'Charger les messages précédents'"></span>
                    </button>

                    <p x-show="$store.conversationApp.loading"
                        class="flex items-center justify-center gap-2 py-6 text-sm text-slate-500 dark:text-slate-400">
                        <i class="fa-solid fa-circle-notch fa-spin"></i> Chargement…
                    </p>

                    <p x-show="$store.conversationApp.selected?.is_member === false"
                        class="flex items-center gap-2 border-b border-violet-200 bg-violet-50 px-4 py-2 text-xs text-violet-800 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300">
                        <i class="fa-solid fa-shield-halved"></i>
                        Vous consultez cette conversation en tant que super-administrateur. Vous n'en êtes pas membre.
                    </p>
                    <p x-show="!$store.conversationApp.loading && !$store.conversationApp.messages.length && !$store.conversationApp.finished"
                        class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                        Aucun message pour le moment. Écrivez le premier !
                    </p>

                    <template x-for="message in $store.conversationApp.messages" :key="message.id">
                        <div x-data="{ mine: Number(message.sender_id) === Number($store.conversationApp.selected?.current_user_id) }"
                            class="flex" :class="mine ? 'justify-end' : 'justify-start'">
                            <article class="max-w-[85%] px-3.5 py-2 shadow-sm sm:max-w-[70%]"
                                :class="mine
                                    ? 'rounded-2xl rounded-br-md bg-indigo-600 text-white dark:bg-indigo-500'
                                    : 'rounded-2xl rounded-bl-md bg-white text-slate-900 ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700'">
                                <p x-show="!mine"
                                    class="mb-0.5 text-xs font-semibold text-indigo-600 dark:text-indigo-300"
                                    x-text="message.sender_name"></p>

                                <p x-show="message.type === 'text'"
                                    class="whitespace-pre-wrap break-words text-sm leading-relaxed"
                                    x-text="message.body"></p>

                                <template x-if="message.type === 'image' && message.media_url">
                                    <div>
                                        <img :src="message.media_url"
                                            :alt="message.media_removed ? 'Image de conversation supprimée' : 'Image temporaire de conversation'"
                                            loading="lazy"
                                            class="mt-1 max-h-56 min-h-32 max-w-full rounded-xl object-contain">
                                        <button type="button" x-show="message.can_preserve && !message.media_preserved"
                                            @click="$store.conversationApp.preserveMedia(message)"
                                            class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-200 transition hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30 dark:hover:bg-amber-500/20">
                                            <i class="fa-regular fa-bookmark"></i> Préserver l’image
                                        </button>
                                        <span x-show="message.media_preserved"
                                            class="mt-2 flex items-center gap-1 text-xs opacity-75">
                                            <i class="fa-solid fa-bookmark"></i> Image préservée
                                        </span>
                                    </div>
                                </template>
                                <p x-show="message.type === 'image' && message.media_removed"
                                    class="text-sm italic opacity-75">
                                    Cette image temporaire a expiré ou a été supprimée.
                                </p>

                                <time class="mt-1 block text-right text-[10px] opacity-60"
                                    x-text="message.created_at"></time>
                            </article>
                        </div>
                    </template>

                    <p x-show="$store.conversationApp.finished"
                        class="mx-auto flex max-w-md items-center justify-center gap-2 rounded-xl bg-white p-3 text-center text-sm text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700">
                        <i class="fa-solid fa-lock text-xs"></i>
                        Cette conversation est terminée. Les participants ne peuvent plus envoyer de messages.
                    </p>

                    <!-- Bouton « Nouveau message » -->
                    <div x-show="$store.conversationApp.newBelow" x-transition.opacity
                        class="pointer-events-none sticky bottom-2 flex justify-center">
                        <button type="button" @click="$store.conversationApp.scrollToBottom()"
                            class="pointer-events-auto inline-flex items-center gap-2 rounded-full bg-indigo-600 px-4 py-1.5 text-xs font-semibold text-white shadow-lg transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/30 dark:bg-indigo-500 dark:hover:bg-indigo-400">
                            <i class="fa-solid fa-arrow-down text-[10px]"></i> Nouveau message
                        </button>
                    </div>
                </div>

                <!-- Zone de saisie -->
                <div x-show="!$store.conversationApp.finished && $store.conversationApp.canParticipate"
                    class="border-t border-slate-200 bg-white p-3 sm:p-4 dark:border-slate-800 dark:bg-slate-900">
                    <form @submit.prevent="$store.conversationApp.send()" class="flex items-end gap-2">
                        <label
                            class="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xl text-slate-500 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-100 hover:text-indigo-600 focus-within:ring-2 focus-within:ring-indigo-500 dark:text-slate-400 dark:ring-slate-700 dark:hover:bg-slate-800 dark:hover:text-indigo-300"
                            :class="$store.conversationApp.finished ? 'pointer-events-none opacity-50' : ''"
                            title="Joindre une image">
                            <i class="fa-regular fa-image"></i>
                            <input id="conversation-image" type="file" accept="image/jpeg,image/png,image/webp"
                                class="sr-only" :disabled="$store.conversationApp.finished"
                                @change="$store.conversationApp.chooseImage($event)">
                        </label>

                        <div class="min-w-0 flex-1">
                            <textarea rows="1" maxlength="4000" x-model="$store.conversationApp.body"
                                @keydown.enter.exact.prevent="$store.conversationApp.send()"
                                :disabled="$store.conversationApp.finished || $store.conversationApp.image"
                                placeholder="Écrire un message…" aria-label="Message"
                                class="block max-h-32 min-h-[2.75rem] w-full resize-none rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/15 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-indigo-400 dark:focus:bg-slate-800 dark:focus:ring-indigo-400/20"></textarea>
                            <span x-show="$store.conversationApp.image"
                                class="mt-1.5 inline-flex max-w-full items-center gap-2 rounded-full bg-indigo-50 py-0.5 pl-2.5 pr-1 text-xs text-indigo-700 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-500/30">
                                <i class="fa-regular fa-image"></i>
                                <span class="truncate" x-text="$store.conversationApp.image?.name"></span>
                                <button type="button" aria-label="Retirer l’image"
                                    @click="$store.conversationApp.image = null; document.getElementById('conversation-image').value = ''"
                                    class="flex h-5 w-5 items-center justify-center rounded-full transition hover:bg-indigo-100 dark:hover:bg-indigo-500/20">
                                    <i class="fa-solid fa-xmark text-[10px]"></i>
                                </button>
                            </span>
                        </div>

                        <button type="submit" aria-label="Envoyer"
                            :disabled="$store.conversationApp.finished || $store.conversationApp.sending"
                            class="flex h-11 shrink-0 items-center gap-2 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/30 active:scale-[.98] disabled:cursor-not-allowed disabled:opacity-50 dark:bg-indigo-500 dark:hover:bg-indigo-400">
                            <i class="fa-solid text-xs"
                                :class="$store.conversationApp.sending ? 'fa-circle-notch fa-spin' : 'fa-paper-plane'"></i>
                            <span class="hidden sm:inline">Envoyer</span>
                        </button>
                    </form>
                    <p class="mt-2 flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                        <i class="fa-solid fa-circle-info"></i>Images : 500 Ko maximum.
                    </p>
                </div>
            </section>
        </main>
    </div>
</section>