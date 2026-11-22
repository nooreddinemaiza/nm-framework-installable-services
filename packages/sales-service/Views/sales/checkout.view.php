@extends('layouts.app')
@script('js', 'api-client.js')
@script('js', 'Toaster.js')
<script src="/dashboard/service-assets/sales-service/sales-checkout.js" defer></script>
@meta('csrf_token', $csrf_token)
<?php
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$panel = 'rounded-md bg-white p-4 shadow-[0_6px_0_#10302c] dark:bg-[#143632] dark:shadow-[0_6px_0_#3a5f59] sm:p-8';
$radio = 'rounded-md border-2 border-[#10302c] bg-white p-4 shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 has-[:checked]:-translate-y-0.5 has-[:checked]:bg-[#ff7a1a] has-[:checked]:ring-2 has-[:checked]:ring-[#10302c] has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[#ff7a1a] dark:border-[#3a5f59] dark:bg-[#143632] dark:shadow-[0_3px_0_#3a5f59] dark:has-[:checked]:bg-[#ff7a1a] dark:has-[:checked]:text-[#10302c] dark:has-[:checked]:ring-[#cfe8dc]';
?>

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
@include('partials.site-nav', ['active' => ''])
<main class="min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc] [font-family:Anybody,ui-sans-serif,system-ui,sans-serif]">
    <div class="mx-auto max-w-6xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-x-2 text-sm" aria-label="Fil d’Ariane">
            <a href="/" class="rounded underline underline-offset-4 <?= $focus ?>">Accueil</a>
            <span aria-hidden="true">/</span>
            <span class="font-bold" aria-current="page">Commande</span>
        </nav>

        <header class="mt-8">
            <div class="origin-top rotate-[-1.2deg] w-fit max-w-full">
                <div class="flex justify-between px-8" aria-hidden="true">
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                </div>
            <h1
                class="break-words rounded-xl bg-[#ff7a1a] px-5 py-3 text-2xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:80%] [overflow-wrap:anywhere] dark:shadow-[0_6px_0_#3a5f59] sm:px-6 sm:py-4 sm:text-3xl lg:px-8 lg:py-5 lg:text-4xl">
                Votre commande</h1>
            </div>
            <p class="mt-6 max-w-2xl text-base leading-7">
                Vérifiez les articles et indiquez comment vous souhaitez recevoir votre commande.
            </p>
        </header>

        @if($checkout_error !== '')
        <div class="mt-6">
            <x-alert variant="danger" appearance="soft" :icon="false" :dismissible="false" :message="$checkout_error" />
        </div>
        @endif
        <noscript>
            <p role="alert"
                class="mt-6 rounded-md border-2 border-dashed border-[#10302c] bg-white px-4 py-3 text-sm font-bold dark:border-[#3a5f59] dark:bg-[#143632]">
                Activez JavaScript pour transférer le panier enregistré dans ce navigateur.
            </p>
        </noscript>

        <form method="post" action="/checkout/order" data-storefront-checkout-form
            class="mt-12 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_24rem]">
            <x-input type="hidden" name="checkout_token" :value="$checkout_token" parent_class="hidden" />
            <x-input type="hidden" name="items_json" value="[]" parent_class="hidden" />

            <aside class="order-last space-y-6 lg:sticky lg:top-6 lg:order-2">
                {{-- Étiquette suspendue --}}
                <section
                    class="relative pt-10 before:absolute before:left-1/2 before:top-3 before:h-3.5 before:w-3.5 before:-translate-x-1/2 before:rounded-full before:bg-[#cfe8dc] before:ring-2 before:ring-[#10302c] dark:before:bg-[#0c1c1a]">
                    <span class="absolute left-1/2 top-0 h-3 w-px bg-[#10302c] dark:bg-[#8fb8ad]"
                        aria-hidden="true"></span>
                    <div class="<?= $panel ?>">
                        <h2 class="text-2xl font-extrabold [font-stretch:80%] sm:text-3xl">Récapitulatif</h2>
                        <p class="mt-1 text-sm">Vérifiez les articles et le total de votre commande.</p>
                        <ul data-checkout-items
                            class="mt-4 divide-y-2 divide-dashed divide-[#10302c]/25 dark:divide-[#cfe8dc]/25"></ul>
                        <p data-checkout-empty hidden
                            class="mt-4 rounded-md border-2 border-dashed border-[#10302c] p-4 text-sm dark:border-[#3a5f59]">
                            Votre panier est vide.
                            <a href="/" class="font-bold underline underline-offset-4 <?= $focus ?>">Retourner aux
                                produits</a>
                        </p>
                        <div
                            class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t-2 border-dashed border-[#10302c]/25 pt-4 dark:border-[#cfe8dc]/25">
                            <span class="text-sm font-bold">Total des articles</span>
                            <output data-checkout-total
                                class="inline-block max-w-full break-words rounded-md bg-[#ff7a1a] px-4 py-2 text-2xl font-extrabold tabular-nums leading-none text-[#10302c] shadow-[0_4px_0_#10302c] [font-stretch:80%] dark:shadow-[0_4px_0_#3a5f59] sm:text-3xl">—</output>
                        </div>
                        <p data-checkout-saving hidden class="mt-3 text-right text-sm font-bold tabular-nums"></p>
                        <p class="mt-3 text-xs leading-5 opacity-75">
                            Les frais éventuels de livraison seront confirmés séparément; aucun frais n’est ajouté ici.
                        </p>
                    </div>
                </section>

                <x-alert variant="warning" appearance="soft" :icon="false" :dismissible="false">
                    Le paiement en ligne n’est pas encore configuré. Cette étape enregistre une commande en attente;
                    aucun paiement ne sera prélevé.
                </x-alert>

                <x-button type="submit" variant="primary" html="Confirmer la commande" :icon="false"
                    class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-md bg-[#ff7a1a] px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 hover:bg-[#ff7a1a] focus-visible:ring-[#ff7a1a] dark:bg-[#ff7a1a] dark:text-[#10302c] dark:shadow-[0_4px_0_#3a5f59] dark:hover:bg-[#ff7a1a] disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0"
                    :attributes="['data-checkout-submit' => true]" />
            </aside>

            <div class="order-first space-y-8 lg:order-1">
                <section class="<?= $panel ?>">
                    <h2 class="text-2xl font-extrabold [font-stretch:80%] sm:text-3xl">Vos coordonnées</h2>
                    <p class="mt-1 text-sm">Elles permettront à l’équipe de traiter la commande et de vous contacter.
                    </p>
                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <x-input name="customer_name" label="Nom complet" :required="true" maxlength="190"
                            parent_class="sm:col-span-2" :attributes="['autocomplete' => 'name']" />
                        <x-input name="customer_email" type="email" label="Adresse e-mail" :required="true"
                            maxlength="190" :attributes="['autocomplete' => 'email']" />
                        <x-input name="customer_phone" type="tel" label="Téléphone" :required="true" maxlength="40"
                            :attributes="['autocomplete' => 'tel']" />
                    </div>
                </section>

                <section class="<?= $panel ?>">
                    <h2 class="text-2xl font-extrabold [font-stretch:80%] sm:text-3xl">Remise de la commande</h2>
                    <p class="mt-1 text-sm">Aucun frais de livraison n’est calculé à cette étape.</p>
                    <fieldset class="mt-6">
                        <legend class="sr-only">Choisir le mode de remise</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="<?= $radio ?>">
                                <x-radio name="fulfillment_mode" value="pickup" label="Retrait" :checked="true"
                                    :required="true" />
                                <p class="ml-7 mt-1 text-sm">
                                    Les détails seront confirmés après l’envoi de la commande.
                                </p>
                            </div>
                            <div class="<?= $radio ?>">
                                <x-radio name="fulfillment_mode" value="delivery" label="Demander une livraison" />
                                <p class="ml-7 mt-1 text-sm">
                                    L’adresse et les frais seront confirmés par l’équipe.
                                </p>
                            </div>
                        </div>
                    </fieldset>

                    <div data-delivery-details hidden>
                        <div
                            class="mt-6 grid gap-4 border-t-2 border-dashed border-[#10302c]/25 pt-6 dark:border-[#cfe8dc]/25 sm:grid-cols-2">
                            <x-input name="delivery_street" label="Adresse" maxlength="190" parent_class="sm:col-span-2"
                                :attributes="['autocomplete' => 'street-address']" />
                            <x-input name="delivery_city" label="Ville" maxlength="100"
                                :attributes="['autocomplete' => 'address-level2']" />
                            <x-input name="delivery_postal_code" label="Code postal" maxlength="20"
                                :attributes="['autocomplete' => 'postal-code']" />
                        </div>
                    </div>
                </section>
            </div>
        </form>
    </div>
</main>
@endsection