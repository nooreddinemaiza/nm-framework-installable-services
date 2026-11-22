@cdn('scripts','/dashboard/service-assets/messaging/conversations-admin.js')
<?php
$conversationColumns = [
    ['key' => 'id', 'label' => 'ID', 'width' => '5rem'],
    ['key' => 'subject', 'label' => 'Conversation'],
    ['key' => 'status', 'label' => 'État'],
    ['key' => 'messages', 'label' => 'Messages'],
    ['key' => 'participants', 'label' => 'Participants', 'sortable' => false],
    ['key' => 'expires_at', 'label' => 'Expiration'],
    ['key' => 'actions', 'label' => 'Actions', 'sortable' => false, 'align' => 'right'],
];
$messageColumns = [
    ['key' => 'id', 'label' => 'ID', 'width' => '5rem'],
    ['key' => 'sender', 'label' => 'Expéditeur'],
    ['key' => 'conversation', 'label' => 'Conversation'],
    ['key' => 'content', 'label' => 'Contenu', 'sortable' => false],
    ['key' => 'type', 'label' => 'Type'],
    ['key' => 'created_at', 'label' => 'Date'],
    ['key' => 'actions', 'label' => 'Actions', 'sortable' => false, 'align' => 'right'],
];
$statusOptions = [
    ['label' => 'Tous les états', 'value' => 'all'],
    ['label' => 'Actives', 'value' => 'active'],
    ['label' => 'Terminées', 'value' => 'finished'],
    ['label' => 'Préservées', 'value' => 'preserved'],
];
$kindOptions = [
    ['label' => 'Privées + groupes', 'value' => 'all'],
    ['label' => 'Privées', 'value' => 'direct'],
    ['label' => 'Groupes', 'value' => 'group'],
];
$typeOptions = [
    ['label' => 'Tous les types', 'value' => 'all'],
    ['label' => 'Texte', 'value' => 'text'],
    ['label' => 'Images', 'value' => 'image'],
];
?>

<x-toast position="bottom-right" :max="2" size="lg">
    <x-slot:components :passed="!$isSuperAdmin" variant="warning" duration="0">
        En tant que super-administrateur, vous pouvez consulter <b>le contenu</b> de tous les messages et
        les
        <b>supprimer définitivement</b>. <br> Ces actions sont <b>irréversibles</b>.
    </x-slot:components>
    <x-slot:components :passed="$isSuperAdmin" variant="infos" duration="0">
        Cette liste affiche les métadonnées uniquement. Vous ne verrez que les conversations auxquelles vous
        participez.
    </x-slot:components>
</x-toast>
<section x-cloak x-data="{ tab: 'conversations' }" @admin-tab.window="tab = $event.detail"
    class="mx-auto max-w-7xl space-y-5 px-2 pb-6 sm:px-4 lg:px-6">
    <x-container :reduiceable="false" size="xxl">
        <x-slot:components>
            <x-container :reduiceable="true" title="Gestion des conversations" :open="true">
                <x-slot:actions>
                    <x-button parent_class="mr-1" label="Ma messagerie" class="link" type="button"
                        click="window.location='/dashboard/conversations'" />
                    <x-button :passed="!$canConfigure" parent_class="mr-1" label="Paramètres"
                        title="Paramètres des conversations" class="link" type="button"
                        click="window.location='/dashboard/conversations/policies'" />
                </x-slot:actions>
                <x-slot:components :passed="!$isSuperAdmin"
                    :attributes="['x-init'=>'$store.messageAdmin.enabled = true; $store.messageAdmin.loadStats()']">
                    <h2 class="my-2">Statistiques</h2>
                    <div class=" grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <!-- Cartes de synthèse (super-admin) -->
                        <template x-for="card in $store.messageAdmin.statCards" :key="card.label">
                            <div
                                class="rounded-xl border border-slate-100 bg-slate-50 p-3 dark:border-slate-700/50 dark:bg-slate-800/50">
                                <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"
                                    x-text="card.label"></div>
                                <div class="mt-1 text-xl font-semibold text-slate-900 dark:text-slate-100"
                                    x-text="card.value">
                                </div>
                            </div>
                        </template>
                    </div>
                </x-slot:components>
            </x-container>
        </x-slot:components>

        <x-slot:components>
            <!-- Onglets -->
            <div class="my-3 flex gap-2 border-b border-slate-200 dark:border-slate-700">
                <button type="button" @click="tab = 'conversations'"
                    :class="tab === 'conversations' ? 'border-violet-600 text-violet-700 dark:text-violet-300' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400'"
                    class="-mb-px border-b-2 px-4 py-2 text-sm font-medium">
                    Conversations
                    <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700"
                        x-text="$store.conversationAdmin.totalItems"></span>
                </button>
                <?php if ($isSuperAdmin): ?>
                    <button type="button" @click="tab = 'messages'"
                        :class="tab === 'messages' ? 'border-violet-600 text-violet-700 dark:text-violet-300' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400'"
                        class="-mb-px border-b-2 px-4 py-2 text-sm font-medium">
                        Messages
                        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700"
                            x-text="$store.messageAdmin.totalItems"></span>
                    </button>
                <?php endif; ?>
            </div>

            <!-- ═════════════ Onglet : Conversations ═════════════ -->
            <div x-show="tab === 'conversations'">
                <x-table id="conversations-table" variant="plain" store="conversationAdmin" store-rows-key="list"
                    store-total-key="totalItems" store-loading-key="loading" store-query-method="query" store-autoload
                    :columns="$conversationColumns" sortable selectable sticky-header max-height="460px" :page-size="10"
                    :page-sizes="[5, 10, 25, 50, 100]" empty-message="Aucune conversation à afficher"
                    no-results-message="Aucun résultat trouvé">

                    <x-slot:actions>
                        <x-container :reduiceable="true" size="xxl" title="" class="my-1">
                            <x-slot:actions>
                                <x-select parent_class="flex col items-right my-3 px-3" name="conv_status"
                                    :attributes="['@change' => '$store.conversationAdmin.setStatus($event.target.value)']"
                                    :options="$statusOptions" />
                                <x-select parent_class="flex col items-right my-3 px-3" name="conv_kind"
                                    :attributes="['@change' => '$store.conversationAdmin.setKind($event.target.value)']"
                                    :options="$kindOptions" />
                                <x-input name="conv_search" type="search" placeholder="Sujet ou n°…"
                                    parent_class="flex flex-col gap-1"
                                    :attributes="['x-model' => '$store.conversationAdmin.filters.search', '@input.debounce.300ms' => '$store.conversationAdmin.refilter()']" />
                                <x-button parent_class="mr-1" label="Actualiser" class="link" type="button"
                                    click="reload();" />
                            </x-slot:actions>
                            <div class="grid grid-cols-1 items-end gap-4 p-5 sm:grid-cols-2 lg:grid-cols-5">
                                <x-input type="date" label="Créée après le"
                                    :attributes="['x-model' => '$store.conversationAdmin.filters.dateFrom', '@change' => '$store.conversationAdmin.refilter()']" />
                                <x-input type="date" label="Créée avant le"
                                    :attributes="['x-model' => '$store.conversationAdmin.filters.dateTo', '@change' => '$store.conversationAdmin.refilter()']" />
                                <x-button label="Réinitialiser" class="link" type="button"
                                    click="$store.conversationAdmin.resetFilters();" />
                            </div>
                        </x-container>
                    </x-slot:actions>

                    <x-slot:bulk>
                        <x-button :passed="!$canPreserve" parent_class="mr-1" label="Préserver la sélection"
                            class="link" type="button"
                            :attributes="['@click' => '$store.conversationAdmin.bulk(\'preserve\', selectedRows.map(r => r.id))']" />
                        <x-button :passed="!$canSuspend" parent_class="mr-1" label="Terminer la sélection"
                            class="delete" type="button"
                            :attributes="['@click' => '$store.conversationAdmin.bulk(\'terminate\', selectedRows.map(r => r.id))']" />
                        <x-button :passed="!$canDelete" parent_class="mr-1" label="Supprimer la sélection"
                            class="delete" type="button"
                            :attributes="['@click' => '$store.conversationAdmin.bulk(\'delete\', selectedRows.map(r => r.id))']"
                            :icon="['name' => 'delete']" />
                    </x-slot:bulk>

                    <x-slot:cell-subject>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-gray-900 dark:text-gray-100"
                                x-text="row.subject || (row.is_group ? 'Conversation de groupe' : 'Conversation privée')">
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                <span x-text="row.is_group ? 'Groupe' : 'Privée'"></span> ·
                                <span x-text="row.created_at_human"></span>
                            </div>
                        </div>
                    </x-slot:cell-subject>

                    <x-slot:cell-status>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="$store.conversationAdmin.statusClass(row)"
                            x-text="$store.conversationAdmin.statusLabel(row)"></span>
                    </x-slot:cell-status>

                    <x-slot:cell-messages>
                        <span class="text-sm tabular-nums text-gray-700 dark:text-gray-200"
                            x-text="`${row.message_count} / ${row.message_limit}`"></span>
                    </x-slot:cell-messages>

                    <x-slot:cell-actions>
                        <div class="flex items-center justify-end gap-1">
                            <?php if ($isSuperAdmin): ?>
                                <x-button title="Voir les messages" label=""
                                    class="cursor-pointer rounded-lg p-1.5 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                    type="button" click="$store.messageAdmin.filterByConversation(row.id);"
                                    :icon="['name' => 'view']" />
                                <x-button title="Ouvrir la conversation" label=""
                                    class="cursor-pointer rounded-lg p-1.5 text-violet-600 transition-colors hover:bg-violet-50 dark:text-violet-300 dark:hover:bg-violet-500/10"
                                    type="button" click="window.location='/dashboard/conversations?open=' + row.id;"
                                    :icon="['name' => 'view']" />
                            <?php endif; ?>
                            <template x-if="row.can_preserve && !row.preserved && row.status === 'active'">
                                <x-button title="Préserver" label="Préserver"
                                    class="cursor-pointer rounded-lg px-2 py-1 text-xs text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-500/10"
                                    type="button" click="$store.conversationAdmin.act(row,'preserve');" />
                            </template>
                            <template x-if="row.can_suspend && row.status === 'active'">
                                <x-button title="Terminer" label="Terminer"
                                    class="cursor-pointer rounded-lg px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10"
                                    type="button" click="$store.conversationAdmin.act(row,'terminate');" />
                            </template>
                            <template x-if="row.can_delete && row.status === 'finished'">
                                <x-button title="Supprimer définitivement" label=""
                                    class="cursor-pointer rounded-lg p-1.5 text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20"
                                    type="button" click="$store.conversationAdmin.act(row,'delete');"
                                    :icon="['name' => 'trash']" />
                            </template>
                        </div>
                    </x-slot:cell-actions>
                </x-table>
            </div>

            <?php if ($isSuperAdmin): ?>
                <!-- ═════════════ Onglet : Messages (super-admin) ═════════════ -->
                <div x-show="tab === 'messages'">
                    <x-table id="messages-table" variant="plain" store="messageAdmin" store-rows-key="list"
                        store-total-key="totalItems" store-loading-key="loading" store-query-method="query" store-autoload
                        :columns="$messageColumns" sortable selectable sticky-header max-height="520px" :page-size="10"
                        :page-sizes="[5, 10, 25, 50, 100]" empty-message="Aucun message"
                        no-results-message="Aucun résultat trouvé">

                        <x-slot:actions>
                            <x-container :reduiceable="true" size="xxl" title="" class="my-1">
                                <x-slot:actions>
                                    <x-select parent_class="flex col items-right my-3 px-3" name="msg_type"
                                        :attributes="['@change' => '$store.messageAdmin.setType($event.target.value)']"
                                        :options="$typeOptions" />
                                    <x-select parent_class="flex col items-right my-3 px-3" name="msg_kind"
                                        :attributes="['@change' => '$store.messageAdmin.setKind($event.target.value)']"
                                        :options="$kindOptions" />
                                    <x-input name="msg_search" type="search" placeholder="Texte, expéditeur, sujet, n°…"
                                        parent_class="flex flex-col gap-1"
                                        :attributes="['x-model' => '$store.messageAdmin.filters.search', '@input.debounce.300ms' => '$store.messageAdmin.refilter()']" />
                                    <x-button parent_class="mr-1" label="Actualiser" class="link" type="button"
                                        click="$store.messageAdmin.refreshAll();" />
                                    <x-button parent_class="mr-1" label="Supprimer tout" class="delete" type="button"
                                        click="$store.messageAdmin.removeAll();" :icon="['name' => 'delete']" />
                                </x-slot:actions>
                                <div class="grid grid-cols-1 items-end gap-4 p-5 sm:grid-cols-2 lg:grid-cols-5">
                                    <x-input type="date" label="Envoyé après le"
                                        :attributes="['x-model' => '$store.messageAdmin.filters.dateFrom', '@change' => '$store.messageAdmin.refilter()']" />
                                    <x-input type="date" label="Envoyé avant le"
                                        :attributes="['x-model' => '$store.messageAdmin.filters.dateTo', '@change' => '$store.messageAdmin.refilter()']" />
                                    <template x-if="$store.messageAdmin.filters.conversationId">
                                        <span
                                            class="inline-flex items-center gap-2 self-center rounded-full bg-violet-100 px-3 py-1 text-xs font-medium text-violet-800 dark:bg-violet-500/10 dark:text-violet-300">
                                            Conversation #<span x-text="$store.messageAdmin.filters.conversationId"></span>
                                            <button type="button" class="font-bold" title="Retirer le filtre"
                                                @click="$store.messageAdmin.clearConversation()">×</button>
                                        </span>
                                    </template>
                                    <x-button label="Réinitialiser" class="link" type="button"
                                        click="$store.messageAdmin.resetFilters();" />
                                </div>
                            </x-container>
                        </x-slot:actions>

                        <x-slot:bulk>
                            <x-button parent_class="mr-1" label="Supprimer la sélection" class="delete" type="button"
                                :attributes="['@click' => '$store.messageAdmin.removeSelected(selectedRows.map(r => r.id))']"
                                :icon="['name' => 'delete']" />
                        </x-slot:bulk>

                        <x-slot:cell-sender>
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-gray-900 dark:text-gray-100"
                                    x-text="row.sender_name"></div>
                                <div class="truncate text-xs text-gray-500 dark:text-gray-400"
                                    x-text="row.sender_username ? '@' + row.sender_username : ''"></div>
                            </div>
                        </x-slot:cell-sender>

                        <x-slot:cell-conversation>
                            <div class="min-w-0">
                                <button type="button" title="Filtrer sur cette conversation"
                                    class="truncate text-sm font-medium text-violet-700 hover:underline dark:text-violet-300"
                                    @click="$store.messageAdmin.filterByConversation(row.conversation_id)"
                                    x-text="row.conversation_label"></button>
                                <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="row.participants"></span>
                                    <span x-show="row.conversation_preserved"
                                        class="ml-1 rounded bg-amber-100 px-1 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">préservée</span>
                                    <span x-show="row.conversation_status === 'finished'"
                                        class="ml-1 rounded bg-slate-100 px-1 text-slate-700 dark:bg-slate-700 dark:text-slate-200">terminée</span>
                                </div>
                            </div>
                        </x-slot:cell-conversation>

                        <x-slot:cell-content>
                            <template x-if="row.type === 'image'">
                                <span
                                    class="inline-flex items-center rounded bg-sky-100 px-2 py-0.5 text-xs text-sky-800 dark:bg-sky-500/10 dark:text-sky-300"
                                    x-text="row.media_available ? 'Image' : 'Image supprimée'"></span>
                            </template>
                            <template x-if="row.type !== 'image'">
                                <p class="max-w-md truncate text-sm text-gray-800 dark:text-gray-100" :title="row.body"
                                    x-text="row.preview"></p>
                            </template>
                        </x-slot:cell-content>

                        <x-slot:cell-type>
                            <span class="text-xs text-gray-600 dark:text-gray-300"
                                x-text="row.type === 'image' ? 'Image' : 'Texte'"></span>
                        </x-slot:cell-type>

                        <x-slot:cell-created_at>
                            <div class="text-xs text-gray-600 dark:text-gray-300">
                                <div x-text="row.created_at"></div>
                                <div class="text-gray-400" x-text="row.created_at_human"></div>
                            </div>
                        </x-slot:cell-created_at>

                        <x-slot:cell-actions>
                            <div class="flex items-center justify-end gap-1">
                                <x-button title="Voir" label=""
                                    class="cursor-pointer rounded-lg p-1.5 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                    type="button" click="$store.messageAdmin.view(row);" :icon="['name' => 'view']" />
                                <x-button title="Supprimer" label=""
                                    class="cursor-pointer rounded-lg p-1.5 text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20"
                                    type="button" click="$store.messageAdmin.removeOne(row);" :icon="['name' => 'trash']" />
                            </div>
                        </x-slot:cell-actions>
                    </x-table>
                </div>
            <?php endif; ?>
        </x-slot:components>
    </x-container>

    <?php if ($isSuperAdmin): ?>
        <!-- Détail d'un message -->
        <x-modal title="Détail du message" open="viewing" store="$store.messageAdmin" :closable="true"
            id="admin-message-modal" size="xl">
            <template x-if="$store.messageAdmin.viewing">
                <div class="space-y-4 p-4 text-sm">
                    <dl class="grid grid-cols-2 gap-3">
                        <div
                            class="rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-700/50 dark:bg-gray-800/50">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Expéditeur</dt>
                            <dd class="mt-1 font-semibold text-gray-900 dark:text-gray-100"
                                x-text="$store.messageAdmin.viewing.sender_name"></dd>
                        </div>
                        <div
                            class="rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-700/50 dark:bg-gray-800/50">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Date
                            </dt>
                            <dd class="mt-1 font-semibold text-gray-900 dark:text-gray-100"
                                x-text="$store.messageAdmin.viewing.created_at"></dd>
                        </div>
                        <div
                            class="col-span-2 rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-700/50 dark:bg-gray-800/50">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Conversation</dt>
                            <dd class="mt-1 font-semibold text-gray-900 dark:text-gray-100">
                                <span x-text="$store.messageAdmin.viewing.conversation_label"></span>
                                <span class="font-normal text-gray-500"
                                    x-text="'— ' + $store.messageAdmin.viewing.participants"></span>
                            </dd>
                        </div>
                    </dl>

                    <template x-if="$store.messageAdmin.viewing.type === 'image' && $store.messageAdmin.viewing.media_url">
                        <img :src="$store.messageAdmin.viewing.media_url" alt="Image du message"
                            class="max-h-80 rounded-lg border border-gray-200 dark:border-gray-700" />
                    </template>
                    <template x-if="$store.messageAdmin.viewing.type === 'image' && !$store.messageAdmin.viewing.media_url">
                        <p class="italic text-gray-500">L’image a expiré ou a été supprimée.</p>
                    </template>
                    <template x-if="$store.messageAdmin.viewing.type !== 'image'">
                        <p class="whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 text-gray-900 dark:bg-gray-800/50 dark:text-gray-100"
                            x-text="$store.messageAdmin.viewing.body"></p>
                    </template>

                    <div class="flex justify-end gap-2">
                        <x-button title="Supprimer" label="Supprimer" class="delete" type="button"
                            click="$store.messageAdmin.removeOne($store.messageAdmin.viewing);"
                            :icon="['name' => 'trash']" />
                        <x-button title="Fermer" label="Fermer" class="link" type="button"
                            :attributes="['@click' => '$store.messageAdmin.viewing = null']" />
                    </div>
                </div>
            </template>
        </x-modal>
    <?php endif; ?>
</section>