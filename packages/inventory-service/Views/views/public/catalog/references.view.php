@extends('layouts.app')

<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isBrand = ($reference_kind ?? '') === 'brand';
$label = $isBrand ? 'Marques' : 'Catégories';
$singular = $isBrand ? 'marque' : 'catégorie';
$path = $isBrand ? '/brands/' : '/categories/';
$description = 'Parcourez les ' . strtolower($label) . ' et découvrez les produits associés.';
$references = $public_references ?? [];
$trendingReferences = $trending_references ?? [];
$trendingProducts = $trending_products ?? [];
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
?>
@title($label . ' | ' . app()->config('app.name'))
@description($description)
@meta('csrf_token', $csrf_token ?? "")
@meta('robots', 'index,follow')
@og('type', 'website')
@og('title', $label)
@og('description', $description)

@section('content')
@cdn('scripts', '/dashboard/service-assets/inventory-service/product-modal.js')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
@include('partials.site-nav', ['active' => $isBrand ? 'brands' : 'categories'])
<main class="min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc] [font-family:Anybody,ui-sans-serif,system-ui,sans-serif]">
    <div class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-x-2 text-sm" aria-label="Fil d’Ariane">
            <a class="rounded underline underline-offset-4 <?= $focus ?>" href="/">Accueil</a>
            <span aria-hidden="true">/</span>
            <span class="font-bold" aria-current="page"><?= $escape($label) ?></span>
        </nav>

        <header class="mt-4 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
            <div class="origin-top rotate-[-1.2deg] w-fit max-w-full">
                <div class="flex justify-between px-8" aria-hidden="true">
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                </div>
                <h1 class="rounded-xl bg-[#ff7a1a] px-8 py-4 text-5xl font-extrabold leading-none shadow-[0_6px_0_#10302c] [font-stretch:80%] dark:shadow-[0_6px_0_#3a5f59] sm:text-7xl text-[#10302c]"><?= $escape($label) ?></h1>
            </div>
            <div class="sm:max-w-xs sm:text-right">
                <p class="text-lg leading-snug"><?= $escape($description) ?></p>
                <?php if ($references !== []): ?>
                    <p class="mt-2 text-sm font-bold"><?= count($references) ?> <?= count($references) === 1 ? $escape($singular) . ' active' : $escape($singular) . 's actives' ?></p>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($trendingReferences !== []): ?>
            <section class="mt-12" aria-labelledby="trending-references-title">
                <h2 id="trending-references-title" class="mb-4 text-2xl font-extrabold [font-stretch:80%]"><?= $escape($label) ?> tendance</h2>
                <ul class="flex flex-wrap gap-x-4 gap-y-3">
                    <?php foreach ($trendingReferences as $reference): ?>
                        <li>
                            <a class="relative inline-flex min-h-11 items-center rounded-md bg-white py-2 pl-10 pr-5 font-bold shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] before:absolute before:left-3.5 before:top-1/2 before:h-3 before:w-3 before:-translate-y-1/2 before:rounded-full before:bg-[#cfe8dc] before:ring-2 before:ring-[#10302c] dark:before:bg-[#0c1c1a] <?= $focus ?>" href="<?= $escape($path . rawurlencode((string) $reference['slug'])) ?>"><?= $escape($reference['name']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($trendingProducts !== []): ?>
            <section class="mt-12" aria-labelledby="trending-products-title">
                <h2 id="trending-products-title" class="mb-4 text-2xl font-extrabold [font-stretch:80%]">Produits et services tendance</h2>
                <div class="-mx-4 overflow-x-auto pb-3 [scrollbar-width:thin] sm:mx-0" role="region" tabindex="0" aria-label="Produits et services tendance">
                    <div class="w-max min-w-full border-b-[14px] border-[#10302c] px-4 dark:border-[#3a5f59] sm:px-0">
                        <ul class="flex items-end gap-3">
                            <?php foreach ($trendingProducts as $product): ?>
                                <li class="w-44 shrink-0 sm:w-52">
                                    @include('partials.public.product-card', ['product' => $product, 'escape' => $escape])
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($references === []): ?>
            <div class="mt-16 rounded-2xl border-4 border-dashed border-[#10302c] px-6 py-16 text-center dark:border-[#3a5f59]">
                <p class="text-3xl font-extrabold [font-stretch:80%]">Aucune <?= $escape($singular) ?> active pour le moment</p>
                <p class="mx-auto mt-2 max-w-sm">Revenez bientôt ou parcourez l’ensemble des produits depuis l’accueil.</p>
                <a class="mt-6 inline-flex min-h-11 items-center rounded-md bg-[#ff7a1a] px-6 font-bold text-[#10302c] shadow-[0_4px_0_#10302c] dark:shadow-[0_4px_0_#3a5f59] <?= $focus ?>" href="/">Retour à l’accueil</a>
            </div>
        <?php else: ?>
            <nav class="sticky top-0 z-20 -mx-4 mt-12 bg-[#cfe8dc]/95 px-4 py-3 backdrop-blur dark:bg-[#0c1c1a]/95 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8" aria-label="Aller à une <?= $escape($singular) ?>">
                <ul class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]">
                    <?php foreach ($references as $reference): ?>
                        <li class="shrink-0">
                            <a class="inline-flex min-h-9 items-center border-b-4 border-[#10302c] bg-white px-3 text-sm font-bold transition hover:bg-[#ff7a1a] dark:border-[#3a5f59] dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:hover:bg-[#ff7a1a] <?= $focus ?>" href="#ref-<?= $escape($reference['slug']) ?>"><?= $escape($reference['name']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <div class="mt-4 divide-y-2 divide-dashed divide-[#10302c]/25 dark:divide-[#cfe8dc]/20">
                <?php foreach ($references as $reference): ?>
                    <?php
                    $count = (int) $reference['product_count'];
                    $shown = count($reference['products'] ?? []);
                    $link = $path . rawurlencode((string) $reference['slug']);
                    ?>
                    <section id="ref-<?= $escape($reference['slug']) ?>" class="grid scroll-mt-16 grid-cols-[auto_minmax(0,1fr)] gap-4 py-10 sm:gap-6" aria-labelledby="title-<?= $escape($reference['slug']) ?>">
                        <h2 id="title-<?= $escape($reference['slug']) ?>" class="[writing-mode:vertical-rl] rotate-180 [font-stretch:62%] self-stretch break-words rounded-lg bg-[#10302c] px-2 py-4 text-4xl font-extrabold leading-none text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a] sm:px-3 sm:text-5xl"><?= $escape($reference['name']) ?></h2>

                        <div class="min-w-0">
                            <?php if (!empty($reference['products'])): ?>
                                <div class="-mr-4 overflow-x-auto pb-3 [scrollbar-width:thin] sm:mr-0" role="region" tabindex="0" aria-label="Produits associés à <?= $escape($reference['name']) ?>">
                                    <div class="w-max min-w-full border-b-[14px] border-[#10302c] pr-4 dark:border-[#3a5f59] sm:pr-0">
                                        <ul class="flex items-end gap-3">
                                            <?php foreach ($reference['products'] as $product): ?>
                                                <li class="w-44 shrink-0 sm:w-52">
                                                    @include('partials.public.product-card', ['product' => $product, 'escape' => $escape])
                                                </li>
                                            <?php endforeach; ?>
                                            <?php if ($count > $shown): ?>
                                                <li class="w-36 shrink-0">
                                                    <a class="flex h-44 flex-col justify-end rounded-t-md bg-[#ff7a1a] p-3 text-[#10302c] transition hover:-translate-y-1 <?= $focus ?>" href="<?= $escape($link) ?>">
                                                        <span class="text-5xl font-black leading-none tabular-nums">+<?= $count - $shown ?></span>
                                                        <span class="mt-1 text-sm font-bold">Voir tous les produits</span>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="border-b-[14px] border-[#10302c] pb-6 dark:border-[#3a5f59]">
                                    <p class="text-sm">Aucun produit actif dans cette <?= $escape($singular) ?> pour le moment.</p>
                                </div>
                            <?php endif; ?>

                            <div class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm">
                                <p><span class="font-extrabold tabular-nums"><?= $count ?></span> <?= $count === 1 ? 'produit associé' : 'produits associés' ?></p>
                                <a class="rounded font-bold underline underline-offset-4 <?= $focus ?>" href="<?= $escape($link) ?>">Ouvrir la <?= $escape($singular) ?> <?= $escape($reference['name']) ?></a>
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
@endsection