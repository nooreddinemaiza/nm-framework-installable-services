@extends('layouts.app')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$categories = is_array($home_categories ?? null) ? $home_categories : [];
$brands = is_array($home_brands ?? null) ? $home_brands : [];
$trendingProducts = array_values(is_array($trending_products ?? null) ? $trending_products : []);
$heroProduct = $trendingProducts[0] ?? null;
$carouselTrendingProducts = count($trendingProducts) > 1
    ? array_slice($trendingProducts, 1, 3)
    : $trendingProducts;
$heroImage = null;
foreach (is_array($heroProduct['media'] ?? null) ? $heroProduct['media'] : [] as $media) {
    if (($media['media_type'] ?? '') === 'image' && !empty($media['url'])) {
        $heroImage = $media;
        break;
    }
}
$categoryShelves = array_chunk($categories, 4);
$brandShelves = array_chunk($brands, 4);
$quickCategories = array_slice($categories, 0, 6);

$formatPrice = static function (string $price, string $currency): string {
    $parts = explode('.', $price, 2);
    $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $parts[0]);
    $fraction = rtrim($parts[1] ?? '', '0');

    return $integer . ($fraction !== '' ? ',' . $fraction : '') . ' ' . $currency;
};

// Produit à la une : prix, remise et libellés préparés une fois.
$hero = null;
if ($heroProduct !== null) {
    $heroName = (string) ($heroProduct['name'] ?? '');
    $heroHasPrice = array_key_exists('price', $heroProduct) && $heroProduct['price'] !== null && $heroProduct['price'] !== '';
    $heroDiscount = is_array($heroProduct['discount_result'] ?? null) ? $heroProduct['discount_result'] : null;
    $heroHasDiscount = $heroHasPrice && !empty($heroDiscount['applied_discounts']);
    $heroPrice = $heroHasDiscount ? (string) $heroDiscount['final_price'] : (string) ($heroProduct['price'] ?? '');
    $heroCurrency = strtoupper((string) ($heroProduct['currency'] ?? app()->env('APP_CURRENCY', 'MAD')));
    $heroPercent = 0;
    if ($heroHasDiscount && is_numeric($heroProduct['price']) && (float) $heroProduct['price'] > 0 && is_numeric($heroPrice)) {
        $heroPercent = (int) round((1 - ((float) $heroPrice / (float) $heroProduct['price'])) * 100);
    }
    $hero = [
        'name' => $heroName,
        'isService' => ($heroProduct['kind'] ?? '') === 'service',
        'hasPrice' => $heroHasPrice,
        'hasDiscount' => $heroHasDiscount,
        'percent' => $heroPercent,
        'priceLabel' => $heroHasPrice ? $formatPrice($heroPrice, $heroCurrency) : '',
        'oldPriceLabel' => $heroHasDiscount ? $formatPrice((string) $heroProduct['price'], $heroCurrency) : '',
        'imageAlt' => (string) (($heroImage['caption'] ?? '') ?: $heroName),
        'href' => '/products/' . rawurlencode((string) ($heroProduct['slug'] ?? '')),
    ];
}

$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$focusOrange = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#10302c] focus-visible:ring-offset-2 focus-visible:ring-offset-[#ff7a1a] dark:focus-visible:ring-[#cfe8dc]';
$btnPrimary = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-[#ff7a1a] px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:shadow-[0_4px_0_#3a5f59] ' . $focus;
$btnSoft = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-white px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59] ' . $focus;
$btnOnOrange = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-[#10302c] px-6 text-base font-bold text-[#cfe8dc] shadow-[0_4px_0_#0c1c1a] transition hover:-translate-y-0.5 dark:bg-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59] ' . $focusOrange;
$tag = 'relative inline-flex min-h-11 items-center rounded-md bg-white py-2 pl-10 pr-5 font-bold shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] before:absolute before:left-3.5 before:top-1/2 before:h-3 before:w-3 before:-translate-y-1/2 before:rounded-full before:bg-[#cfe8dc] before:ring-2 before:ring-[#10302c] dark:before:bg-[#0c1c1a] ' . $focus;
$panel = 'rounded-md bg-white p-6 shadow-[0_6px_0_#10302c] dark:bg-[#143632] dark:shadow-[0_6px_0_#3a5f59] sm:p-8';
$description = 'Parcourez les produits et services du catalogue, puis consultez chaque fiche pour choisir ce qui vous convient.';
?>
@title('Accueil | ' . app()->config('app.name'))
@description($description)
@meta('csrf_token', $csrf_token ?? "")
@meta('robots', 'index,follow')
@og('type', 'website')
@og('title', (string) app()->config('app.name'))
@og('description', $description)

@section('content')
@cdn('scripts', '/dashboard/service-assets/inventory-service/product-modal.js')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
@include('partials.site-nav', ['active' => 'home'])
<main class="min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc] [font-family:Anybody,ui-sans-serif,system-ui,sans-serif]">
    <div class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">

        <!-- Hero : le produit du moment, posé sur sa planche, avant tout le reste -->
        <section aria-labelledby="home-title">
            <?php if ($hero !== null): ?>
                <div class="grid items-center gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] lg:gap-12">
                    <div class="relative min-w-0 overflow-hidden rounded-t-xl border-b-[14px] border-[#10302c] bg-[#e4f3eb] dark:border-[#3a5f59] dark:bg-[#143632]">
                        <div class="relative aspect-[4/3] max-h-[28rem] w-full">
                            <?php if ($heroImage !== null): ?>
                                <img src="<?= $escape($heroImage['url']) ?>" alt="<?= $escape($hero['imageAlt']) ?>" loading="eager" fetchpriority="high" class="absolute inset-0 h-full w-full object-contain p-6 sm:p-10">
                            <?php else: ?>
                                <div class="absolute inset-0 flex items-center justify-center text-[#10302c]/40 dark:text-[#cfe8dc]/40">
                                    <svg viewBox="0 0 24 24" class="h-20 w-20" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m7.5 4.3 9 5.2M21 8v8a2 2 0 0 1-1 1.7l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.7l7-4a2 2 0 0 1 2 0l7 4ZM3.3 7l8.7 5 8.7-5M12 22V12"/></svg>
                                </div>
                            <?php endif; ?>
                            <span class="absolute left-4 top-4 z-10 rounded-md bg-[#ff7a1a] px-3 py-1.5 text-sm font-extrabold text-[#10302c] shadow-[0_3px_0_#10302c] [font-stretch:80%] dark:shadow-[0_3px_0_#3a5f59]">Tendance</span>
                            <?php if ($hero['percent'] > 0): ?>
                                <span class="absolute right-4 top-4 z-10 flex h-16 w-16 rotate-6 items-center justify-center rounded-full bg-[#ff7a1a] text-lg font-extrabold tabular-nums text-[#10302c] ring-2 ring-[#10302c]">−<?= $hero['percent'] ?> %</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <div class="origin-top rotate-[-1.2deg] w-fit max-w-full">
                            <div class="flex justify-between px-6" aria-hidden="true">
                                <span class="h-8 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                                <span class="h-8 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                            </div>
                            <h1 id="home-title" class="break-words rounded-xl bg-[#ff7a1a] px-5 py-3 text-3xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:80%] dark:shadow-[0_6px_0_#3a5f59] sm:px-6 sm:text-5xl"><?= $escape($hero['name']) ?></h1>
                        </div>
                        <div class="mt-6 flex flex-wrap items-center gap-2">
                            <span class="rounded-md bg-[#10302c] px-2.5 py-1 text-xs font-bold text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]"><?= $hero['isService'] ? 'Service' : 'Produit' ?></span>
                            <?php if (!empty($heroProduct['brand_name'])): ?>
                                <span class="text-sm font-bold"><?= $escape($heroProduct['brand_name']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (trim(strip_tags((string) ($heroProduct['description'] ?? ''))) !== ''): ?>
                            <p class="mt-3 max-w-xl break-words text-base leading-7 line-clamp-3"><?= $escape(trim(strip_tags((string) $heroProduct['description']))) ?></p>
                        <?php endif; ?>
                        <div class="mt-5 flex flex-wrap items-center gap-4">
                            <?php if ($hero['hasPrice']): ?>
                                <p class="flex flex-wrap items-baseline gap-3">
                                    <span class="inline-block rounded-md bg-[#ff7a1a] px-4 py-2 text-3xl font-extrabold tabular-nums leading-none text-[#10302c] shadow-[0_4px_0_#10302c] [font-stretch:80%] dark:shadow-[0_4px_0_#3a5f59]"><?= $escape($hero['priceLabel']) ?></span>
                                    <?php if ($hero['hasDiscount']): ?>
                                        <span class="text-sm tabular-nums line-through opacity-75"><?= $escape($hero['oldPriceLabel']) ?></span>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <a class="<?= $btnSoft ?>" href="<?= $escape($hero['href']) ?>">Découvrir la fiche <span aria-hidden="true">→</span></a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="origin-top rotate-[-1.2deg] w-fit max-w-full">
                    <div class="flex justify-between px-6" aria-hidden="true">
                        <span class="h-8 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                        <span class="h-8 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                    </div>
                    <h1 id="home-title" class="break-words rounded-xl bg-[#ff7a1a] px-5 py-3 text-3xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:80%] dark:shadow-[0_6px_0_#3a5f59] sm:px-6 sm:text-5xl">La prochaine tendance se prépare</h1>
                </div>
                <p class="mt-6 max-w-xl text-base leading-7"><?= $escape($description) ?></p>
            <?php endif; ?>

            <!-- Recherche et accès rapide, sous le produit -->
            <div class="mt-12">
                <form action="/boutique" method="get" role="search" class="flex max-w-2xl flex-col gap-3 sm:flex-row">
                    <label for="home-search" class="sr-only">Rechercher un produit ou un service</label>
                    <input id="home-search" name="search" type="search" placeholder="Que cherchez-vous aujourd’hui ?" class="min-h-12 w-full min-w-0 rounded-md border-2 border-[#10302c] bg-white px-4 text-base font-bold text-[#10302c] shadow-[0_4px_0_#10302c] placeholder:font-normal placeholder:text-[#10302c]/60 dark:border-[#3a5f59] dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59] dark:placeholder:text-[#0c1c1a]/60 <?= $focus ?>">
                    <button type="submit" class="shrink-0 <?= $btnPrimary ?> min-h-12 px-7 text-base">Rechercher</button>
                </form>
                <?php if ($quickCategories !== []): ?>
                    <nav class="mt-6" aria-label="Accès rapide aux catégories">
                        <ul class="flex flex-wrap gap-3">
                            <?php foreach ($quickCategories as $category): ?>
                                <li><a class="<?= $tag ?>" href="/boutique?category_id=<?= (int) $category['id'] ?>"><?= $escape($category['name']) ?></a></li>
                            <?php endforeach; ?>
                            <li><a class="<?= $tag ?>" href="/boutique">Toute la boutique</a></li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </section>

        <!-- Chiffres de l'étagère -->
        <?php if ($categories !== [] || $brands !== [] || $trendingProducts !== []): ?>
            <section class="mt-16" aria-label="Le catalogue en chiffres">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <?php if ($categories !== []): ?>
                        <div class="rounded-md bg-white px-6 py-5 shadow-[0_4px_0_#10302c] dark:bg-[#143632] dark:shadow-[0_4px_0_#3a5f59]">
                            <dt class="text-sm font-bold">Catégories à explorer</dt>
                            <dd class="mt-1 text-5xl font-extrabold tabular-nums leading-none [font-stretch:75%]"><?= count($categories) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($brands !== []): ?>
                        <div class="rounded-md bg-white px-6 py-5 shadow-[0_4px_0_#10302c] dark:bg-[#143632] dark:shadow-[0_4px_0_#3a5f59]">
                            <dt class="text-sm font-bold">Marques sur l’étagère</dt>
                            <dd class="mt-1 text-5xl font-extrabold tabular-nums leading-none [font-stretch:75%]"><?= count($brands) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($trendingProducts !== []): ?>
                        <div class="rounded-md bg-[#ff7a1a] px-6 py-5 text-[#10302c] shadow-[0_4px_0_#10302c] dark:shadow-[0_4px_0_#3a5f59]">
                            <dt class="text-sm font-bold">Articles en tendance</dt>
                            <dd class="mt-1 text-5xl font-extrabold tabular-nums leading-none [font-stretch:75%]"><?= count($trendingProducts) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </section>
        <?php endif; ?>

        <!-- Carrousel des tendances (partial existant, inchangé) -->
        @include('partials.public.trending-banner', ['trending_products' => $carouselTrendingProducts])

        <!-- Pourquoi commencer ici -->
        <section class="mt-16" aria-labelledby="home-why-title">
            <h2 id="home-why-title" class="text-3xl font-extrabold [font-stretch:80%] sm:text-4xl">Choisir sans se compliquer</h2>
            <div class="mt-6 grid gap-6 md:grid-cols-3">
                <div class="<?= $panel ?>">
                    <h3 class="text-2xl font-extrabold leading-none [font-stretch:75%]">Des fiches claires</h3>
                    <p class="mt-3 text-base leading-7">Photos, description, prix et remises sont réunis sur une seule page. Vous voyez tout avant de décider.</p>
                </div>
                <div class="<?= $panel ?>">
                    <h3 class="text-2xl font-extrabold leading-none [font-stretch:75%]">Une recherche qui va droit au but</h3>
                    <p class="mt-3 text-base leading-7">Tapez un mot, filtrez par catégorie ou par marque, et retrouvez l’article qui vous correspond.</p>
                </div>
                <div class="<?= $panel ?>">
                    <h3 class="text-2xl font-extrabold leading-none [font-stretch:75%]">Un panier toujours à portée</h3>
                    <p class="mt-3 text-base leading-7">Ajoutez, modifiez les quantités, vérifiez le total : votre sélection vous suit pendant la visite.</p>
                </div>
            </div>
        </section>

        <?php if ($categories !== []): ?>
            <section id="categories" class="mt-16 scroll-mt-24" aria-labelledby="home-categories-title">
                <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                    <h2 id="home-categories-title" class="text-3xl font-extrabold [font-stretch:80%] sm:text-4xl">Choisissez votre rayon</h2>
                    <a class="inline-flex min-h-11 items-center rounded font-bold underline underline-offset-4 <?= $focus ?>" href="/categories">Toutes les catégories</a>
                </div>
                <div class="space-y-10">
                    <?php foreach ($categoryShelves as $shelfIndex => $shelf): ?>
                        <div class="-mx-4 overflow-x-auto pb-3 [scrollbar-width:thin] sm:mx-0" role="region" tabindex="0" aria-label="Catégories, rangée <?= $shelfIndex + 1 ?>">
                            <div class="w-max min-w-full border-b-[14px] border-[#10302c] px-4 dark:border-[#3a5f59] sm:px-0">
                                <ul class="flex items-end gap-3">
                                    <?php foreach ($shelf as $category): ?>
                                        <li class="w-52 shrink-0 sm:w-60">
                                            <a class="flex min-h-44 flex-col justify-between rounded-t-md bg-[#ff7a1a] p-4 text-[#10302c] transition hover:-translate-y-1 <?= $focus ?>" href="/boutique?category_id=<?= (int) $category['id'] ?>">
                                                <span class="text-sm font-bold"><?= (int) $category['product_count'] ?> éléments</span>
                                                <span class="mt-6 block break-words text-3xl font-extrabold leading-none [font-stretch:70%]"><?= $escape($category['name']) ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($brands !== []): ?>
            <section class="mt-16" aria-labelledby="home-brands-title">
                <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                    <h2 id="home-brands-title" class="text-3xl font-extrabold [font-stretch:80%] sm:text-4xl">Retrouvez vos marques</h2>
                    <a class="inline-flex min-h-11 items-center rounded font-bold underline underline-offset-4 <?= $focus ?>" href="/brands">Toutes les marques</a>
                </div>
                <div class="space-y-10">
                    <?php foreach ($brandShelves as $shelfIndex => $shelf): ?>
                        <div class="-mx-4 overflow-x-auto pb-3 [scrollbar-width:thin] sm:mx-0" role="region" tabindex="0" aria-label="Marques, rangée <?= $shelfIndex + 1 ?>">
                            <div class="w-max min-w-full border-b-[14px] border-[#10302c] px-4 dark:border-[#3a5f59] sm:px-0">
                                <ul class="flex items-end gap-3">
                                    <?php foreach ($shelf as $brand): ?>
                                        <li class="w-52 shrink-0 sm:w-60">
                                            <a class="flex min-h-44 flex-col justify-between rounded-t-md bg-[#10302c] p-4 text-[#cfe8dc] transition hover:-translate-y-1 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] <?= $focus ?>" href="/boutique?brand_id=<?= (int) $brand['id'] ?>">
                                                <span class="text-sm font-bold"><?= (int) $brand['product_count'] ?> éléments</span>
                                                <span class="mt-6 block break-words text-3xl font-extrabold leading-none [font-stretch:70%]"><?= $escape($brand['name']) ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($categories === [] && $brands === []): ?>
            <div class="mt-16 rounded-2xl border-4 border-dashed border-[#10302c] px-6 py-16 text-center dark:border-[#3a5f59]">
                <p class="text-3xl font-extrabold [font-stretch:80%]">Le catalogue arrive bientôt</p>
                <p class="mx-auto mt-2 max-w-sm">Les produits, services et catégories publiés apparaîtront ici. Revenez dans un instant ou explorez la boutique.</p>
                <a class="mt-6 <?= $btnPrimary ?>" href="/boutique">Ouvrir la boutique</a>
            </div>
        <?php endif; ?>

        <!-- Appel à l'action final -->
        <section class="mt-16" aria-labelledby="home-cta-title">
            <div class="flex flex-col gap-6 rounded-md bg-[#ff7a1a] p-6 text-[#10302c] shadow-[0_6px_0_#10302c] dark:shadow-[0_6px_0_#3a5f59] sm:flex-row sm:items-center sm:justify-between sm:p-10">
                <div class="min-w-0">
                    <h2 id="home-cta-title" class="break-words text-3xl font-extrabold leading-none [font-stretch:75%] sm:text-5xl">Prêt à remplir votre panier ?</h2>
                    <p class="mt-3 max-w-xl text-base leading-7">Toute la boutique est à un clic. Prenez le temps de regarder : la bonne trouvaille est peut-être déjà sur l’étagère.</p>
                </div>
                <a class="shrink-0 <?= $btnOnOrange ?>" href="/boutique">Voir toute la boutique</a>
            </div>
        </section>
    </div>
</main>
<footer class="shelf border-t-2 border-[#10302c]/20 bg-[#cfe8dc] text-[#10302c] dark:border-[#cfe8dc]/20 dark:bg-[#0c1c1a] dark:text-[#cfe8dc]">
    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-8 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
        <p>&copy; <?= date('Y') ?> <?= $escape(app()->config('app.name')) ?></p>
        <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Navigation de pied de page">
            <a class="inline-flex min-h-11 items-center rounded underline underline-offset-4 <?= $focus ?>" href="/">Accueil</a>
            <a class="inline-flex min-h-11 items-center rounded underline underline-offset-4 <?= $focus ?>" href="/boutique">Boutique</a>
            <?php if ($categories !== []): ?><a class="inline-flex min-h-11 items-center rounded underline underline-offset-4 <?= $focus ?>" href="/categories">Catégories</a><?php endif; ?>
            <?php if ($brands !== []): ?><a class="inline-flex min-h-11 items-center rounded underline underline-offset-4 <?= $focus ?>" href="/brands">Marques</a><?php endif; ?>
        </nav>
    </div>
</footer>
@endsection