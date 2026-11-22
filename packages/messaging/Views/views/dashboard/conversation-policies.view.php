@cdn('scripts','/dashboard/service-assets/messaging/conversations.js')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$json = static fn(mixed $value): string => htmlspecialchars(
    json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<section x-cloak x-data x-init="$store.conversationApp.initializePolicies($el)" data-csrf="<?= $escape($csrf_token) ?>"
    data-roles="<?= $json($policyRoles) ?>" data-policies="<?= $json($policies) ?>"
    data-group-policy="<?= $json($groupPolicy) ?>" class="mx-auto max-w-7xl space-y-5 px-2 pb-6 sm:px-4 lg:px-6">
    <x-container :reduiceable="false" size="xxl" title="Paramètres des conversations">
        <x-slot:actions>
            <a href="/dashboard/conversations/admin"
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600">Gestion des
                conversations</a>
        </x-slot:actions>
        <x-slot:components>
            <div class="space-y-6">
                <x-alert>
                    Les limites et la durée de conservation sont copiées dans une conversation à sa création. Les
                    modifications ne changent pas les conversations déjà ouvertes.
                    Les règles directes spécifiques remplacent les valeurs de repli; les autres paires de rôles
                    utilisent les valeurs de repli.
                </x-alert>
                <x-container :reduiceable="true" size="xxl" title="Valeurs de repli des conversations privées"
                    class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-sm">Messages maximum
                            <input type="number" min="1" max="10000"
                                x-model.number="$store.conversationApp.groupPolicy.fallback.message_limit"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                        </label>
                        <label class="text-sm">Durée (minutes)
                            <input type="number" min="60" max="525600"
                                x-model.number="$store.conversationApp.groupPolicy.fallback.lifetime_minutes"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                        </label>
                        <label class="text-sm">Images consécutives maximum
                            <input type="number" min="1" max="20"
                                x-model.number="$store.conversationApp.groupPolicy.fallback.image_streak_limit"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                        </label>
                    </div>
                </x-container>
                <x-container :reduiceable="true" size="xxl" title="Valeurs de repli des conversations de groupe"
                    class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-sm">Messages maximum
                            <input type="number" min="1" max="10000"
                                x-model.number="$store.conversationApp.groupPolicy.message_limit"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                        </label>
                        <label class="text-sm">Durée (minutes)
                            <input type="number" min="60" max="525600"
                                x-model.number="$store.conversationApp.groupPolicy.lifetime_minutes"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                        </label>
                        <label class="text-sm">Images consécutives maximum
                            <input type="number" min="1" max="20"
                                x-model.number="$store.conversationApp.groupPolicy.image_streak_limit"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                        </label>
                    </div>
                </x-container>
                <x-container :reduiceable="true" size="xxl" title="Médias temporaires"
                    class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <x-alert>
                        Une image reste disponible pendant cette durée à partir de son envoi, même si elle est vue
                        immédiatement ou si le destinataire n’ouvre pas la conversation. Les médias préservés ne sont
                        pas supprimés.
                    </x-alert>
                    <label class="block max-w-sm text-sm">Durée de conservation (minutes)
                        <input type="number" min="1" max="525600"
                            x-model.number="$store.conversationApp.groupPolicy.media_retention_minutes"
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                    </label>
                    <div class="my-2">
                        <h2 class="mb-2 text-lg font-semibold text-slate-900 dark:text-white">Image de remplacement</h2>
                        <x-alert variant="warning">
                            L'envoi de l'image au serveur se fait automatiquement, assurez-vous de bien choisir l'image.
                        </x-alert>
                        <x-file-upload name="defaultMedia" label="Choisir une image"
                            accept="image/jpeg,image/png,image/webp" :preview="false" :compact="true"
                            currentImageSrc="'/dashboard/conversations/default-media?v=' + $store.conversationApp.defaultMediaVersion"
                            onFileSelect="if ($refs.input.files[0]) { $store.conversationApp.uploadDefaultMedia($refs.input.files[0]); reset(); }" />
                    </div>
                </x-container>
                <x-container :reduiceable="true" size="xxl" title="Règles privées par paire de rôles"
                    class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <x-alert>
                        Une règle est orientée : le rôle du créateur et celui du destinataire sont traités séparément.
                    </x-alert>
                    <div class="grid gap-3 lg:grid-cols-2">
                        <label class="text-sm">Rôle du créateur
                            <select x-model="$store.conversationApp.newPolicy.sr_id"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                                <template x-for="role in $store.conversationApp.policyRoles" :key="role.id">
                                    <option :value="String(role.id)" x-text="role.name"></option>
                                </template>
                            </select>
                        </label>
                        <label class="text-sm">Rôle du destinataire
                            <select x-model="$store.conversationApp.newPolicy.rr_id"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                                <template x-for="role in $store.conversationApp.policyRoles" :key="role.id">
                                    <option :value="String(role.id)" x-text="role.name"></option>
                                </template>
                            </select>
                        </label>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <label class="text-sm">Messages maximum
                            <input type="number" min="1" max="10000"
                                x-model.number="$store.conversationApp.newPolicy.message_limit"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                        </label>
                        <label class="text-sm">Durée (minutes)
                            <input type="number" min="60" max="525600"
                                x-model.number="$store.conversationApp.newPolicy.lifetime_minutes"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                        </label>
                        <label class="text-sm">Images consécutives maximum
                            <input type="number" min="1" max="20"
                                x-model.number="$store.conversationApp.newPolicy.image_streak_limit"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-700">
                        </label>
                    </div>
                    <button type="button" @click="$store.conversationApp.addPolicyOverride()"
                        class="mt-3 rounded-lg border border-indigo-300 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-300">
                        Ajouter la règle
                    </button>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-700">
                            <thead class="text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="px-2 py-2">Créateur → destinataire</th>
                                    <th class="px-2 py-2">Messages</th>
                                    <th class="px-2 py-2">Minutes</th>
                                    <th class="px-2 py-2">Images</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(rule, index) in $store.conversationApp.policyOverrides"
                                    :key="`${rule.sr_id}:${rule.rr_id}`">
                                    <tr class="border-t border-slate-100 dark:border-slate-700">
                                        <td class="px-2 py-2"
                                            x-text="`${rule.starter_role_name || $store.conversationApp.policyRoles.find(r => Number(r.id) === Number(rule.sr_id))?.name} → ${rule.recipient_role_name || $store.conversationApp.policyRoles.find(r => Number(r.id) === Number(rule.rr_id))?.name}`">
                                        </td>
                                        <td class="px-2 py-2"><input type="number" min="1" max="10000"
                                                x-model.number="rule.message_limit"
                                                class="w-24 rounded border border-slate-300 px-2 py-1 dark:border-slate-600 dark:bg-slate-700">
                                        </td>
                                        <td class="px-2 py-2"><input type="number" min="60" max="525600"
                                                x-model.number="rule.lifetime_minutes"
                                                class="w-28 rounded border border-slate-300 px-2 py-1 dark:border-slate-600 dark:bg-slate-700">
                                        </td>
                                        <td class="px-2 py-2"><input type="number" min="1" max="20"
                                                x-model.number="rule.image_streak_limit"
                                                class="w-20 rounded border border-slate-300 px-2 py-1 dark:border-slate-600 dark:bg-slate-700">
                                        </td>
                                        <td class="px-2 py-2 text-right"><button type="button"
                                                @click="$store.conversationApp.removePolicyOverride(index)"
                                                class="text-red-700 hover:underline dark:text-red-300">Supprimer</button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="!$store.conversationApp.policyOverrides.length">
                                    <td colspan="5" class="px-2 py-4 text-center text-slate-500">Aucune règle
                                        particulière. Les valeurs de repli s’appliquent.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </x-container>
                <div class="flex justify-end">
                    <button type="button" @click="$store.conversationApp.savePolicies()"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700">Enregistrer
                        les paramètres</button>
                </div>
            </div>
        </x-slot:components>
    </x-container>
</section>