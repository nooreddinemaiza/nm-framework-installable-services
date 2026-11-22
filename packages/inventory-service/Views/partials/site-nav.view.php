<?php
$publicNav = \App\Helpers\NavbarFactory::publicNavbar($active ?? '', $elements ?? true);
?>
@cdn('scripts', '/dashboard/service-assets/inventory-service/api-client.js')
@if($publicNav['cart_enabled'])
@cdn('scripts', '/dashboard/service-assets/inventory-service/sales-cart.js')
@endif

<header
    class="sticky top-0 z-50 border-b-2 border-[#10302c]/25 bg-[#cfe8dc]/95 shadow-[0_3px_0_#10302c] backdrop-blur [font-family:Anybody,ui-sans-serif,system-ui,sans-serif] dark:border-[#cfe8dc]/25 dark:bg-[#0c1c1a]/95 dark:shadow-[0_3px_0_#3a5f59]">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <x-nav :items="$publicNav['items']" :active="$publicNav['active']" :orientation="$publicNav['orientation']"
            :variant="$publicNav['variant']" :size="$publicNav['size']" :collapsible="$publicNav['collapsible']"
            :id="$publicNav['attributes']['id']" :label="$publicNav['label']" :classes="$publicNav['classes']"
            class="w-full border-0 bg-transparent text-[#10302c] dark:bg-transparent dark:text-[#cfe8dc]">
            <x-slot:brand>
                <div class="flex min-w-0 items-center gap-1 sm:gap-2">
                    <a href="/"
                        class="inline-flex min-h-11 min-w-0 items-center gap-2 font-extrabold text-[#10302c] dark:text-[#cfe8dc]">
                        <img src="/assets/image/logo.png" alt="Logo" class="h-6 w-auto object-contain sm:h-7">
                        <span
                            class="min-w-0 truncate"><?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </a>
                    @if($global_search_enabled ?? false)
                    <button type="button" data-search-open-proxy data-search-scope="public" aria-label="Rechercher"
                        aria-haspopup="dialog" aria-controls="global-search-dialog"
                        class="relative grid h-9 w-9 shrink-0 place-items-center rounded-full text-slate-600 transition-colors hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] dark:text-slate-300 dark:hover:bg-slate-800 md:hidden">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            aria-hidden="true">
                            <circle cx="10.8" cy="10.8" r="6.8" />
                            <path stroke-linecap="round" d="m16 16 4.5 4.5" />
                        </svg>
                    </button>
                    @endif
                    @if($publicNav['cart_enabled'])
                    <button type="button" data-cart-open aria-label="Ouvrir le panier" aria-haspopup="dialog"
                        aria-controls="sales-cart-panel"
                        class="relative grid h-9 w-9 shrink-0 place-items-center rounded-full text-slate-600 transition-colors hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] dark:text-slate-300 dark:hover:bg-slate-800 md:hidden">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3 4h2l2.2 11.1a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L21 8H6" />
                            <circle cx="10" cy="20" r="1" />
                            <circle cx="18" cy="20" r="1" />
                        </svg>
                        <span data-cart-count
                            class="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-teal-700 px-1.5 py-0.5 text-xs font-bold text-white dark:bg-teal-400 dark:text-slate-950">0</span>
                    </button>
                    @endif
                </div>
            </x-slot:brand>
            <x-slot:end>
                @if($publicNav['cart_enabled'])
                <button type="button" data-cart-open aria-haspopup="dialog" aria-controls="sales-cart-panel"
                    aria-label="Ouvrir le panier"
                    class="relative grid h-9 w-9 shrink-0 place-items-center rounded-full text-slate-600 transition-colors hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] dark:text-slate-300 dark:hover:bg-slate-800">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3 4h2l2.2 11.1a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L21 8H6" />
                        <circle cx="10" cy="20" r="1" />
                        <circle cx="18" cy="20" r="1" />
                    </svg>
                    <span data-cart-count
                        class="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-teal-700 px-1.5 py-0.5 text-xs font-bold text-white dark:bg-teal-400 dark:text-slate-950">0</span>
                </button>
                @endif

                @if($global_search_enabled)
                <button type="button" data-search-open-proxy data-search-scope="public" aria-label="Rechercher"
                    aria-haspopup="dialog" aria-controls="global-search-dialog"
                    class="relative hidden h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-600 transition-colors hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] dark:text-slate-300 dark:hover:bg-slate-800 md:inline-flex">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                        aria-hidden="true">
                        <circle cx="10.8" cy="10.8" r="6.8" />
                        <path stroke-linecap="round" d="m16 16 4.5 4.5" />
                    </svg>
                </button>
                @endif
                @include('layouts.theme-toggle')
            </x-slot:end>
        </x-nav>
        @if($global_search_enabled ?? false)
        @include('partials.search.dialog', ['showSearchTrigger' => false])
        @endif
    </div>
</header>

@if($publicNav['cart_enabled'])
@include('partials.public.cart-drawer')
@endif