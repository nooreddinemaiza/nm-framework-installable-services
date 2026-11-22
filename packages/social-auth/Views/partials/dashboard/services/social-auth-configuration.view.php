<?php if (!empty($social_auth_message)): ?>
    <p role="status" class="mb-4 text-sm <?= ($social_auth_variant ?? '') === 'success'
        ? 'text-green-700 dark:text-green-300'
        : 'text-red-700 dark:text-red-300' ?>">
        <?= $escape($social_auth_message) ?>
    </p>
<?php endif; ?>

<x-modal id="social-provider-settings" title="Fournisseurs de connexion sociale" open="socialConfigOpen"
    :closable="true" size="xl">

    <x-alert class="mb-6 rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-relaxed text-indigo-900
                dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-100">
        Les activations, Client IDs et URLs de callback sont enregistrés dans la base.
        Les Client secrets ne peuvent pas être générés par le framework : ils sont délivrés par chaque
        plateforme OAuth.
        Créez une application OAuth chez le fournisseur, enregistrez-y l’URL de callback affichée
        ci-dessous, puis saisissez le secret obtenu ici. Il sera enregistré dans le fichier
        d’environnement du serveur, jamais dans la base ni réaffiché. Laissez le champ vide pour
        conserver le secret déjà enregistré.
    </x-alert>

    <form method="POST" action="/dashboard/settings/services/social-auth" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token ?? '') ?>">

        <?php foreach ($social_providers as $id => $provider): ?>
            <x-container :reduiceable="true" :title="$escape($provider['label'])" :scrollable="true" class="mx-auto w-full rounded-2xl border border-gray-200 bg-white px-4 py-4 shadow-sm transition
                   hover:shadow-md dark:border-gray-800 dark:bg-gray-900 dark:shadow-none" maxHeight="88vh"
                size="xxl" :padded="false">

                <x-slot:actions>
                    <x-badge :variant="$provider['enabled'] ? 'active' : 'inactive'"
                        :label="$provider['enabled'] ? 'Actif' : 'Inactif'" />
                </x-slot:actions>

                <x-slot:components>
                    <x-card class="rounded-xl border border-gray-100 bg-gray-50/60 p-4 sm:p-5
                               dark:border-gray-800 dark:bg-gray-950/40">

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <div class="md:col-span-1">
                                <x-select parent_class="flex flex-col gap-1.5" :name="$escape($id).'_enabled'"
                                    label="Disponibilité" :class="$field" :selected="(string) $provider['enabled']"
                                    :options="[['label' => 'Désactivé', 'value' => '0'],['label' => 'Activé', 'value' => '1'],]" />
                            </div>

                            <div class="md:col-span-2">
                                <x-input :name="$escape($id).'_client_id'" type="text" label="Client ID"
                                    parent_class="flex flex-col gap-1.5" :class="$field"
                                    :attributes="['maxlength' => '512', 'autocomplete' => 'off']" />
                            </div>
                        </div>

                        <div class="mt-5 border-t border-gray-200 pt-5 dark:border-gray-800">
                            <label class="flex flex-col gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                                <span>
                                    URL de callback
                                    <span class="font-normal text-gray-500 dark:text-gray-400">
                                        (laisser vide pour utiliser APP_URL)
                                    </span>
                                </span>
                                <input type="url" name="<?= $escape($id) ?>_redirect_uri"
                                    value="<?= $escape($provider['redirect_uri']) ?>" maxlength="2048"
                                    placeholder="https://votre-domaine/connexion/social/<?= $escape($id) ?>/callback"
                                    class="<?= $field ?> font-mono text-xs">
                            </label>

                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-white px-3 py-2 text-xs
                                    text-gray-600 ring-1 ring-gray-200
                                    dark:bg-gray-900 dark:text-gray-400 dark:ring-gray-800">
                                <span><?= $escape($provider['secret_help']) ?></span>
                                <a href="<?= $escape($provider['credentials_url']) ?>" target="_blank"
                                    rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-indigo-600 underline-offset-2
                                       hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500
                                       dark:text-indigo-400 dark:hover:text-indigo-300">
                                    Ouvrir la plateforme
                                    <?= $escape($provider['label']) ?>
                                    <span aria-hidden="true">↗</span>
                                </a>
                            </div>
                        </div>

                        <div class="mt-5 border-t border-gray-200 pt-5 dark:border-gray-800">
                            <x-input :name="$escape($id).'_client_secret'" type="password"
                                label="Client Secret (laisser vide pour conserver)" parent_class="flex flex-col gap-1.5"
                                :class="$field"
                                :attributes="['maxlength' => '4096', 'autocomplete' => 'new-password']" />

                            <p class="mt-3 inline-flex flex-wrap items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium
                                  <?= $provider['secret_configured']
                                      ? 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300'
                                      : 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' ?>">
                                <span class="h-1.5 w-1.5 rounded-full
                                  <?= $provider['secret_configured']
                                      ? 'bg-green-500 dark:bg-green-400'
                                      : 'bg-amber-500 dark:bg-amber-400' ?>"></span>
                                Client secret
                                <?= $provider['secret_configured'] ? 'déjà enregistré' : 'manquant' ?>
                                dans
                                <code class="rounded bg-black/5 px-1.5 py-0.5 font-mono dark:bg-white/10">
                                    <?= $escape($provider['secret_env']) ?>
                                </code>
                            </p>
                        </div>
                    </x-card>
                </x-slot:components>
            </x-container>
        <?php endforeach; ?>

        <div class="sticky bottom-0 -mx-1 flex justify-end gap-3 border-t border-gray-200 bg-white/90 px-1 py-4
                backdrop-blur dark:border-gray-800 dark:bg-gray-900/90">
            <button type="button" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition
                   hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500
                   dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                @click="socialConfigOpen = false">
                Annuler
            </button>
            <button type="submit" class="<?= $button ?>">Enregistrer</button>
        </div>
    </form>
</x-modal>
