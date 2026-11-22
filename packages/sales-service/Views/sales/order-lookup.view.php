@extends('layouts.app')
@script('js', 'api-client.js')
<script src="/dashboard/service-assets/sales-service/order-lookup.js" defer></script>
@meta('csrf_token', $csrf_token)
<?php
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$panel = 'rounded-md bg-white p-6 shadow-[0_6px_0_#10302c] dark:bg-[#143632] dark:shadow-[0_6px_0_#3a5f59] sm:p-8';
?>

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
    <main class="min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc] [font-family:Anybody,ui-sans-serif,system-ui,sans-serif]">
    <div class="mx-auto max-w-2xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-x-2 text-sm" aria-label="Fil d’Ariane">
            <a href="/" class="rounded underline underline-offset-4 <?= $focus ?>">Accueil</a>
            <span aria-hidden="true">/</span>
            <span class="font-bold" aria-current="page">Suivi de commande</span>
        </nav>

        <header class="mt-8">
            <div class="origin-top rotate-[-1.2deg] w-fit max-w-full">
                <div class="flex justify-between px-8" aria-hidden="true">
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                </div>
                <h1 class="break-words rounded-xl bg-[#ff7a1a] px-6 py-4 text-4xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:80%] dark:shadow-[0_6px_0_#3a5f59] sm:px-8 sm:text-6xl">Suivre une commande</h1>
            </div>
            <p class="mt-6 max-w-xl text-base leading-7">
                Saisissez la référence reçue lors de votre commande ainsi que vos coordonnées.
            </p>
        </header>

        <section class="mt-10 <?= $panel ?>">
            <form data-order-lookup-form class="grid gap-4">
                <x-input name="reference" label="Référence de commande" placeholder="CMD-0123456789ABCDEF" required maxlength="20" />
                <x-input name="email" type="email" label="Adresse e-mail utilisée à la commande" required maxlength="190" />
                <x-input name="phone" type="tel" label="Téléphone utilisé à la commande" required maxlength="40" />
                <x-button
                    type="submit"
                    variant="primary"
                    html="Consulter le statut"
                    :icon="false"
                    class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md bg-[#ff7a1a] px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 hover:bg-[#ff7a1a] focus-visible:ring-[#ff7a1a] dark:bg-[#ff7a1a] dark:text-[#10302c] dark:shadow-[0_4px_0_#3a5f59] dark:hover:bg-[#ff7a1a] disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0"
                />
            </form>
            <div data-order-lookup-result class="mt-6" aria-live="polite"></div>
        </section>
    </div>
</main>
@endsection