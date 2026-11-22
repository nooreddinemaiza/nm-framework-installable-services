<?php
$auth2fa = $user['two_factor_enabled'];
$emailVerified = $user['email_verified_at'];
$tfa_method = $user['two_factor_method'];
$auth2faAction = "/dashboard/account/securite/2fa/" . ($auth2fa ? 'disable' : 'enable');
?>
<x-tabs :attributes="['active' => $securityActiveTab ?? 0, 'variant' => 'default']">
    <x-slot:components label="Mot de passe" icon="<i class='fa-solid fa-key'></i>">
        <x-container :reduiceable="false" size="xxl">
            <form action="/dashboard/account/password/change" method="post">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <x-input name="current_password" :value="$oldData['current_password'] ?? ''"
                        :error="$errors['current_password'] ?? ''" type="password" label="Mot de passe actuel"
                        :required="true" />
                    <x-input name="new_password" :value="$oldData['new_password'] ?? ''"
                        :error="$errors['new_password'] ?? ''" type="password" label="Nouveau mot de passe"
                        :required="true" />
                    <x-input name="confirm_password" :value="$oldData['confirm_password'] ?? ''"
                        :error="$errors['confirm_password'] ?? ''" type="password"
                        label="Confirmer le nouveau mot de passe" :required="true" />

                    <x-checkbox name="disconnect_others" label="Déconnecter les autres sessions" :checked="false"
                        :error="$errors['disconnect_others'][0] ?? null" />
                </div>
                <div class="card-actions">
                    <x-button label="Changer" :icon="['name' => 'edit']" type="submit" class="edit" />
                </div>
            </form>
        </x-container>
    </x-slot:components>
    <x-slot:components label="2FA" icon="<i class='fa-solid fa-shield-halved'></i>" badgeClass="badge-danger"
        :badge="$auth2fa ? '' : '!' ">
        <x-container :reduiceable="false" size="xxl">
            <?php $actualSituation = $auth2fa ? [
                'variant' => 'success',
                'message' => 'L\'authentification à deux facteurs est actuellement activée. Méthode utilisée: <span class="badge badge-primary">' . strtoupper($tfa_method) . '</span>',
            ] : ['variant' => 'warning', 'message' => 'L\'authentification à deux facteurs est actuellement désactivée.'] ?>
            <x-alert :variant="$actualSituation['variant']" :message="$actualSituation['message']"
                :dismissible="false" />
            <x-card title="Activer la double authentification" :passed="$auth2fa">
                <x-form :action="$auth2faAction" method="post">
                    <x-slot:components :passed="$auth2fa">
                        <x-alert :passed="$emailVerified" variant="warning">
                            <x-slot:components>
                                Votre e-mail n'est pas vérifié, vous devez <button type="button" class="btn-link"
                                    role="tab" tabindex="0" @click="setTab(2, false)">
                                    le vérifier
                                </button>
                                avant d'utiliser la méthode email!
                            </x-slot:components>
                        </x-alert>
                        <x-alert variant="infos" :passed="$auth2fa"
                            message="L'authentification à deux facteurs (2FA) ajoute une couche de sécurité supplémentaire à votre compte. Lorsque le 2FA est activé, vous devrez fournir un code supplémentaire généré par une application d'authentification (comme Google Authenticator ou Authy) ou bien envoyé à votre boîte de réception (Gmail, Outlook ...) en plus de votre mot de passe lors de la connexion." />
                        <x-select label="Méthode d'authentification à deux facteurs" name="two_factor_method"
                            :passed="$auth2fa" :required="true" :options="[
                    ['label'=>'EMAIL','value'=>'email','disabled'=>!$emailVerified || ((isset($mail_enabled) && !$mail_enabled) ?? false)],
                    ['label'=>'TOTP','value'=>'totp'],
                ]" :error="$errors['two_factor_method'][0] ?? null" :value="$tfa_method" />
                        </x-select>

                        <div class="card-actions">
                            <x-button label="Activer" :icon="['name' => 'shield']" class="activate" type="submit" />
                        </div>
                    </x-slot:components>
                </x-form>
            </x-card>
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <x-card title="Changer la méthode d'authentification à deux facteurs" :passed="!$auth2fa">
                    <x-form action="/dashboard/account/securite/2fa/change" method="post">
                        <x-slot:components>
                            <x-container :reduiceable="false" size="xxl">
                                <x-alert :passed="$emailVerified" variant="warning">
                                    <x-slot:components>
                                        Votre e-mail n'est pas vérifié, vous devez <button type="button"
                                            class="btn-link" role="tab" tabindex="0" @click="setTab(2, false)">
                                            le vérifier
                                        </button>
                                        avant d'utiliser la méthode email!
                                    </x-slot:components>
                                </x-alert>
                                <x-error title="Erreurs à vérifier" type="form" name="two_factor_method"
                                    :errors="$errors['two_factor_method'][0] ?? []" />
                                <?php
                                $labels = [
                                    'email' => "EMAIL" . ($tfa_method === 'email' ? " est votre méthode actuelle" : ""),
                                    'totp' => "TOTP" . ($tfa_method === 'totp' ? " est votre méthode actuelle" : ""),
                                ];
                                if ($tfa_method === 'email') {
                                    $label = " EMAIL est votre méthode actuelle";
                                } else {
                                    $label = "TOTP est votre méthode actuelle";
                                }
                                ?>
                                <x-radio name="two_factor_method" :label="$labels['email']" :required="true"
                                    :disabled="!$emailVerified || $tfa_method === 'email'" value="email" />
                                <x-radio name="two_factor_method" :label="$labels['totp']" value="totp" :required="true"
                                    :disabled="$tfa_method === 'totp'" />
                                <div class="card-actions">
                                    <x-button label="Changer" :icon="['name' => 'edit']" class="edit" type="submit" />
                                </div>
                            </x-container>
                        </x-slot:components>
                    </x-form>
                </x-card>
                <x-card title="Désactiver la double authentification" :passed="!$auth2fa"
                    :attributes="['x-data'=>'{confirmed:false}']">
                    <form action="<?= $auth2faAction ?>" method="post">
                        <x-error title="Erreurs à vérifier" type="form" name="two_factor_method"
                            :errors="$errors['confirm2fa_desactivation'][0] ?? []" />
                        <div x-show="confirmed">
                            <x-alert variant="danger" :dismissible="false" :passed="!$auth2fa"
                                :attributes="['x-show'=>'confirmed ?? false']">
                                <x-slot:message>La désactivation de l'authentification à deux facteurs supprimera toutes
                                    les
                                    méthodes d'authentification à deux facteurs associées à votre compte. Vous devrez
                                    reconfigurer
                                    le 2FA si vous souhaitez le réactiver ultérieurement. Vous pouvez la désactiver en
                                    confirmant
                                    :
                                    <div
                                        class="card-actions mx-auto flex w-full max-w-lg flex-col items-center justify-center gap-4 md:flex-row">
                                        <x-checkbox name="confirm2fa_desactivation" label="Confirmer la désactivation?"
                                            :checked="false" :passed="!$auth2fa"
                                            :error="$errors['confirm2fa_desactivation'][0] ?? null" :required="true" />
                                        <x-button :icon="['name' =>'error']" class="danger" label="Désactiver"
                                            type="submit" />
                                    </div>
                                </x-slot:message>
                            </x-alert>
                        </div>
                        <div x-show="!confirmed">
                            <x-button :icon="['name' =>'error']" click="confirmed=true" class="danger"
                                label="Désactiver" type="button" />
                        </div>
                    </form>
                </x-card>
            </div>
        </x-container>
    </x-slot:components>
    <x-slot:components label="Vérification" icon="<i class='fa-solid fa-check'></i>" badgeClass="badge-danger"
        :badge="$emailVerified ? '' : '!' ">
        <x-container :reduiceable="false" size="xxl">
            <x-alert :passed="$emailVerified" variant="warning" :dismissible="false"
                title="Votre e-mail n'est pas vérifié, vous devez le vérifier pour bien sécuriser votre compte">
                <x-slot:components>
                @if($mail_enabled)
                    <x-list :ordered="true" type="disc">
                        <x-slot:components>
                            Pour vérifier votre email, vous pouvez utilser lien de vérification envoyé à votre
                            boit e-mail, il vout suffit de cliquer sur ce lien et la vérification se lance
                            automatiquement
                        </x-slot:components>
                        <x-slot:components>
                            Vous pouvez aussi utilser le code de
                            vérification de 6 chiffre envoyé avec le lien, insérez le dans le formulaire de
                            vérification et vérifiez-le.
                        </x-slot:components>
                        <x-slot:components>
                            En vous cliquant sur le bouton 'Demander', le lien et le code de vérification seront
                            envoyé à votre boite e-mail {{ \Core\Support\Str::maskEmail($user['email']) }}
                        </x-slot:components>
                    </x-list>
                    <div class="card-actions">
                        <x-button label="Demander" class="add" type="button"
                            loadingExpr="$store.emailVerication.loading"
                            :attributes="['@click'=>'$store.emailVerication.demandCode();']" />
                        <x-button class="primary" label="Utiliser un code" :icon="['name' => 'key']"
                            :attributes="['@click'=>'$store.emailVerication.open();']" />
                        <x-modal title="Vérification par code" open="isOpen" store="$store.emailVerication"
                            class="z-50">
                            <x-alert
                                title="Utilisez le code de 6 chiffres envoyé à votre boite E-mail, entrez le dans le champ puis cliquez sur 'Vérifier'" />
                            <x-form>
                                <x-slot:components>
                                    <x-input xModel="$store.emailVerication.formData.email_verify_code"
                                        name="email_verify_code" id="email_verify_code" type="text"
                                        label="Code de vérification: " placeholder="123456" :required="true" />
                                </x-slot:components>
                            </x-form>
                            <div class="card-actions">
                                <x-button class="cancel" label="Fermer" :icon="['name' => 'close']"
                                    :attributes=" ['@click'=>'$store.emailVerication.close();']" />
                                <x-button label="Vérifier" class="approve" type="button" :icon="['name' => 'ok']"
                                    loadingExpr="$store.emailVerication.loading"
                                    :attributes="['@click'=>'$store.emailVerication.validateCode();']" />
                            </div>
                        </x-modal>
                    </div>
                    @else
                    <p>
                        Aucun service de mailing n'est configuré. Votre adresse restera non vérifiée jusqu'à un
                        contrôle indépendant par un administrateur. Contactez votre administrateur pour demander
                        cette vérification; l'activation du compte seule ne vérifie pas l'adresse.
                    </p>
                    @endif
                </x-slot:components>
            </x-alert>
            <x-alert :passed="!$emailVerified" variant="success" :dismissible="false" title="Votre e-mail est vérifié"
                message="La modification de votre adresse e-mail nécessite une nouvelle vérification." />
        </x-container>
    </x-slot:components>
    <x-slot:components :passed="!in_array('account.suspend',$admin_permissions) || $is_super"
        label="Désactiver le compte" icon="<i class='fa-solid fa-user-xmark'></i>">
        <x-container :reduiceable="false" size="xxl">
            <form action="{{ $errors['account_desactivation']['formLink'] ?? '/dashboard/account/desactivate'}}"
                method="post">
                <x-alert :variant="$errors['account_desactivation']['variant'] ??'danger'" :dismissible="false"
                    :message="$errors['account_desactivation']['message'] ?? 'Êtes-vous sûr ?'" />
                <x-checkbox name="confirm_desactivation" label="Je confirme" :checked="false"
                    :passed="!empty($errors['account_desactivation']['formLink']) ?? false"
                    :error="$errors['confirm_desactivation'][0] ?? ''" :required="true" />
                <x-input name="desactivation_code" type="text" label="Code de vérification: " placeholder="123456"
                    :passed="empty($errors['account_desactivation']['formLink']) ?? true" :required="true" />
                <div class="card-actions">
                    <x-button label="Désactiver" class="suspend" type="submit" />
                </div>
            </form>
        </x-container>
    </x-slot:components>
    <x-slot:components label="Sessions actives" icon="<i class='fa-solid fa-user-clock'></i>"
        tabBtnAction="$store.sessions.open();">
        @include("partials.dashboard.profile.sessions-tab")
    </x-slot:components>
    @if(!empty($social_auth_enabled))
    <x-slot:components label="Comptes externes" icon="<i class='fa-solid fa-user-plus'></i>">
        <x-container :reduiceable="false" size="xxl" class="my-1 px-2">
            <?php
            $csrf = (new \Core\Security\CsrfTokenManager(app()->session()))->getInputField();
            $available = \NM\Services\SocialAuth\Social\SocialAuth::enabledProviders();
            $emailVerified = !empty($user['email_verified_at']);
            $linked = $available !== []
                ? \NM\Services\SocialAuth\Models\SocialIdentity::forUser(app()->session()->getUserId())
                : [];
            $linkedIds = array_map(static fn($i) => (string) $i->provider, $linked);
            $base = '/dashboard/account/securite/social/';
            $providerMarks = [
                'google' => ['G', 'bg-white text-blue-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-blue-300 dark:ring-slate-700'],
                'microsoft' => ['M', 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
                'github' => ['GH', 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'],
                'facebook' => ['f', 'bg-blue-600 text-white'],
            ];
            ?>
            <div class="space-y-5">
                <x-alert variant="info" title="À quoi sert l’association ?" :dismissible="true">
                    <x-slot:components>
                        Associez un fournisseur à votre compte actuel pour pouvoir vous connecter ensuite avec lui.
                        Vous devez être connecté à ce compte pour effectuer l’association ; elle ne crée pas un nouveau
                        compte et ne se fait jamais automatiquement par correspondance d’adresse e-mail.
                        Vous serez redirigé vers le fournisseur pour autoriser cette opération.
                    </x-slot:components>
                </x-alert>

                <?php if (!$emailVerified): ?>
                    <x-alert variant="warning" title="Vérification de l’adresse e-mail requise" :dismissible="true">
                        <x-slot:components>
                            <button type="button" class="btn-link" role="tab" tabindex="0" @click="setTab(2, false)">
                                Vérifiez
                            </button> d’abord l’adresse e-mail de votre compte depuis l’onglet « Vérification » de cette
                            page.
                            Vous pourrez associer un fournisseur ensuite. Les comptes déjà associés peuvent toujours être
                            dissociés.
                        </x-slot:components>
                    </x-alert>
                <?php endif; ?>

                <?php if ($available === []): ?>
                    <x-alert variant="info" :dismissible="false">
                        <x-slot:components>
                            Aucun fournisseur social n’est disponible. Le service doit être installé et un fournisseur
                            activé et configuré par un administrateur.
                        </x-slot:components>
                    </x-alert>
                <?php else: ?>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <?php foreach ($available as $id => $label): ?>
                            <?php
                            $isLinked = in_array((string) $id, $linkedIds, true);
                            [$mark, $markClass] = $providerMarks[$id] ?? [
                                strtoupper(substr((string) $label, 0, 1)),
                                'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300',
                            ];
                            ?>
                            <article
                                class="flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md dark:border-slate-700 dark:bg-slate-900 dark:hover:border-indigo-500/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span
                                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-lg font-bold <?= $markClass ?>"
                                            aria-hidden="true">
                                            <?= htmlspecialchars($mark, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <div class="min-w-0">
                                            <h3 class="font-semibold text-slate-900 dark:text-white">
                                                <?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?>
                                            </h3>
                                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                                                <?= $isLinked
                                                    ? 'Compte associé'
                                                    : ($emailVerified ? 'Compte non associé' : 'Vérification requise') ?>
                                            </p>
                                        </div>
                                    </div>
                                    <span
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium <?= $isLinked
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                            : ($emailVerified
                                                ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
                                                : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300') ?>">
                                        <span class="h-1.5 w-1.5 rounded-full <?= $isLinked
                                            ? 'bg-emerald-500'
                                            : ($emailVerified ? 'bg-slate-400' : 'bg-amber-500') ?>"></span>
                                        <?= $isLinked ? 'Associé' : ($emailVerified ? 'Disponible' : 'À vérifier') ?>
                                    </span>
                                </div>

                                <p class="mt-4 flex-1 text-sm leading-6 text-slate-600 dark:text-slate-300">
                                    <?= $isLinked
                                        ? 'Vous pouvez utiliser ce compte pour vous connecter à votre profil.'
                                        : ($emailVerified
                                            ? 'Associez votre compte ' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . ' pour l’utiliser lors de vos prochaines connexions.'
                                            : 'Vérifiez votre adresse e-mail pour pouvoir associer ce fournisseur.') ?>
                                </p>

                                <?php if ($isLinked || $emailVerified): ?>
                                    <form method="POST"
                                        action="<?= $base . htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8') . ($isLinked ? '/unlink' : '/link') ?>"
                                        class="mt-5">
                                        <?= $csrf ?>
                                        <x-button :html="$isLinked ? 'Dissocier le compte' : 'Associer le compte'"
                                            :icon="['name' => $isLinked ? 'remove' : 'plus']" type="submit"
                                            :class="$isLinked ? 'reject' : 'approve'" :subClass="'w-full'" />
                                    </form>
                                <?php else: ?>
                                    <div
                                        class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-center text-sm font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                                        <button type="button" class="btn-link" role="tab" tabindex="0" @click="setTab(2, false)">
                                            Vérification
                                        </button> requise pour associer

                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </x-container>
    </x-slot:components>
    @endif
</x-tabs>