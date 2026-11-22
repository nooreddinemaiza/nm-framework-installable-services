<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$report = is_array($statistics_report ?? null) ? $statistics_report : [];
$isProduct = (bool) ($statistics_product ?? false);
$query = array_filter($statistics_query ?? [], static fn(mixed $value): bool => $value !== '' && $value !== null);
$exportQuery = http_build_query($query);
$exportUrl = '/dashboard/sales/statistics/export' . ($exportQuery !== '' ? '?' . $exportQuery . '&format=' : '?format=');
$period = $report['period'] ?? ['from' => '', 'to' => ''];
$money = static fn(mixed $minor, string $currency): string => number_format((float) $minor / 100, 2, ',', ' ') . ' ' . $currency;
$qty = static fn(mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ',');

/* Raccourcis de période */
$today = new DateTimeImmutable('today');
$productParam = $isProduct ? '&product_id=' . (int) ($report['product']['id'] ?? 0) : '';
$presets = [];
foreach ([7 => '7 jours', 30 => '30 jours', 90 => '90 jours'] as $days => $label) {
    $from = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $to = $today->format('Y-m-d');
    $presets[] = [
        'label' => $label,
        'url' => '/dashboard/sales/statistics?from=' . $from . '&to=' . $to . $productParam,
        'active' => ($period['from'] ?? '') === $from && ($period['to'] ?? '') === $to,
    ];
}

$ui = [
    'card' => 'rounded-3xl border border-slate-200/80 bg-white p-6 shadow-[0_1px_2px_rgba(15,23,42,.04)] dark:border-slate-800 dark:bg-slate-900',
    'btn' => 'inline-flex min-h-10 items-center justify-center gap-2 rounded-full border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-emerald-600 hover:text-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-emerald-400 dark:hover:text-emerald-300',
    'input' => 'mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 dark:border-slate-700 dark:bg-slate-950 dark:text-white',
    'muted' => 'text-sm text-slate-500 dark:text-slate-400',
    'stage' => 'relative overflow-hidden rounded-[2rem] bg-slate-950 text-white shadow-xl shadow-emerald-950/10 bg-[radial-gradient(60rem_28rem_at_15%_-10%,rgba(16,185,129,.35),transparent_60%),radial-gradient(40rem_24rem_at_100%_110%,rgba(245,158,11,.22),transparent_60%)]',
];
?>
<style>
    @keyframes ss-grow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
    @keyframes ss-rise { from { transform: scaleY(0); } to { transform: scaleY(1); } }
    .ss-grow { transform-origin: left center; animation: ss-grow .9s cubic-bezier(.2,.8,.2,1) both; }
    .ss-rise { transform-origin: bottom center; animation: ss-rise .8s cubic-bezier(.2,.8,.2,1) both; }
    @media (prefers-reduced-motion: reduce) { .ss-grow, .ss-rise { animation: none; } }
</style>

<main class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">

    <!-- En-tête -->
    <header class="flex flex-wrap items-start justify-between gap-5">
        <div class="min-w-0">
            <?php if ($isProduct): ?>
                <a href="/dashboard/sales/statistics?from=<?= $escape($period['from']) ?>&to=<?= $escape($period['to']) ?>" class="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-300">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5l-5 5 5 5"/></svg>
                    Retour au classement
                </a>
            <?php endif; ?>
            <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl dark:text-white">
                <?= $isProduct ? $escape($report['product']['name'] ?? 'Statistiques du produit') : 'Qui porte vos ventes ?' ?>
            </h1>
            <p class="mt-2 max-w-xl <?= $ui['muted'] ?>">
                <?= $isProduct
                    ? 'Ventes, profit estimé et mouvements de stock sur la période choisie.'
                    : 'Vos produits les plus vendus, ceux qui montent, et ceux à réapprovisionner.' ?>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="<?= $ui['btn'] ?>" href="<?= $escape($exportUrl . 'csv') ?>">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3v10m0 0l-4-4m4 4l4-4M4 16h12"/></svg>
                CSV
            </a>
            <a class="<?= $ui['btn'] ?>" href="<?= $escape($exportUrl . 'pdf') ?>">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3v10m0 0l-4-4m4 4l4-4M4 16h12"/></svg>
                PDF
            </a>
        </div>
    </header>

    <!-- Période -->
    <form method="get" action="/dashboard/sales/statistics" class="<?= $ui['card'] ?> flex flex-wrap items-end gap-4 !p-4 sm:!px-5">
        <?php if ($isProduct): ?><input type="hidden" name="product_id" value="<?= (int) ($report['product']['id'] ?? 0) ?>"><?php endif; ?>
        <div class="flex flex-wrap items-center gap-1 rounded-full bg-slate-100 p-1 dark:bg-slate-800" role="group" aria-label="Périodes rapides">
            <?php foreach ($presets as $preset): ?>
                <a href="<?= $escape($preset['url']) ?>"
                   class="rounded-full px-3.5 py-1.5 text-sm font-medium transition <?= $preset['active']
                       ? 'bg-white text-emerald-800 shadow-sm dark:bg-slate-950 dark:text-emerald-300'
                       : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white' ?>"
                   <?= $preset['active'] ? 'aria-current="true"' : '' ?>><?= $escape($preset['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Du
            <input class="<?= $ui['input'] ?>" type="date" name="from" value="<?= $escape($period['from']) ?>" required>
        </label>
        <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Au
            <input class="<?= $ui['input'] ?>" type="date" name="to" value="<?= $escape($period['to']) ?>" required>
        </label>
        <button class="inline-flex min-h-10 items-center rounded-full bg-emerald-700 px-5 text-sm font-semibold text-white transition hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:bg-emerald-400 dark:text-slate-950 dark:hover:bg-emerald-300 dark:focus-visible:ring-offset-slate-900" type="submit">Appliquer</button>
        <span class="ml-auto text-xs text-slate-500 dark:text-slate-400">366 jours maximum</span>
    </form>

<?php if ($isProduct): ?>
    <?php
    $product = $report['product'];
    $metrics = $report['metrics'];
    $points = $report['sales'];
    $currency = (string) ($metrics['currency'] ?? '');
    $isService = ($product['kind'] ?? '') === 'service';
    $maxRevenue = max(1.0, ...array_map(static fn(array $point): float => (float) $point['revenue_minor'], $points ?: [['revenue_minor' => 1]]));
    $pointCount = count($points);
    $coords = [];
    foreach ($points as $index => $point) {
        $x = $pointCount > 1 ? 40 + ($index * 540 / ($pointCount - 1)) : 310;
        $y = 190 - ((float) $point['revenue_minor'] / $maxRevenue * 150);
        $coords[] = [$x, $y];
    }
    $line = implode(' ', array_map(static fn(array $c): string => number_format($c[0], 1, '.', '') . ',' . number_format($c[1], 1, '.', ''), $coords));
    $area = $coords === [] ? '' : 'M' . number_format($coords[0][0], 1, '.', '') . ',190 L' . str_replace(' ', ' L', $line) . ' L' . number_format($coords[$pointCount - 1][0], 1, '.', '') . ',190 Z';
    ?>

    <!-- Scène : chiffre d'affaires + courbe -->
    <section class="<?= $ui['stage'] ?> grid gap-6 p-6 sm:p-8 lg:grid-cols-5" aria-label="Chiffre d’affaires du produit">
        <div class="flex flex-col justify-between lg:col-span-2">
            <div>
                <p class="text-sm text-emerald-200/80">Chiffre d’affaires sur la période</p>
                <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums sm:text-5xl"><?= $escape($money($metrics['revenue_minor'], $currency)) ?></p>
                <p class="mt-3 text-sm text-slate-300"><?= $escape($qty($metrics['units_sold'])) ?> unité(s) vendue(s)</p>
            </div>
            <dl class="mt-8 grid grid-cols-2 gap-3">
                <div class="rounded-2xl bg-white/5 p-4 ring-1 ring-white/10">
                    <dt class="text-xs text-slate-300">Profit brut estimé</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums">
                        <?= $metrics['estimated_profit_minor'] === null ? '<span class="text-sm font-medium text-amber-300">Coût d’achat manquant</span>' : $escape($money($metrics['estimated_profit_minor'], $currency)) ?>
                    </dd>
                </div>
                <div class="rounded-2xl bg-white/5 p-4 ring-1 ring-white/10">
                    <dt class="text-xs text-slate-300">Stock disponible</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums"><?= $isService ? '<span class="text-sm font-medium text-slate-300">Sans objet</span>' : $escape($metrics['stock_available'] . ' ' . ($product['unit_code'] ?? '')) ?></dd>
                </div>
            </dl>
        </div>
        <div class="lg:col-span-3">
            <?php if ($points === []): ?>
                <div class="flex h-full min-h-56 items-center justify-center rounded-2xl border border-dashed border-white/20 text-sm text-slate-300">Aucune vente terminée sur cette période.</div>
            <?php else: ?>
                <svg class="h-64 w-full text-emerald-300" viewBox="0 0 600 220" role="img" aria-label="Graphique du chiffre d’affaires par jour">
                    <defs>
                        <linearGradient id="revenue-fill" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="currentColor" stop-opacity=".35"/>
                            <stop offset="100%" stop-color="currentColor" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <?php foreach ([0, 1, 2, 3] as $g): $gy = 190 - $g * 50; ?>
                        <line x1="40" x2="580" y1="<?= $gy ?>" y2="<?= $gy ?>" stroke="rgba(255,255,255,.12)" stroke-dasharray="<?= $g === 0 ? '0' : '3 5' ?>"/>
                        <text x="32" y="<?= $gy + 4 ?>" text-anchor="end" fill="rgba(226,232,240,.6)" font-size="10"><?= $escape(number_format($maxRevenue / 100 * ($g / 3), 0, ',', ' ')) ?></text>
                    <?php endforeach; ?>
                    <path d="<?= $escape($area) ?>" fill="url(#revenue-fill)"/>
                    <polyline points="<?= $escape($line) ?>" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <?php foreach ($points as $index => $point): ?>
                        <circle cx="<?= number_format($coords[$index][0], 1, '.', '') ?>" cy="<?= number_format($coords[$index][1], 1, '.', '') ?>" r="4.5" fill="currentColor" stroke="#020617" stroke-width="2">
                            <title><?= $escape($point['sale_date'] . ' : ' . $money($point['revenue_minor'], $point['currency'])) ?></title>
                        </circle>
                    <?php endforeach; ?>
                    <text x="40" y="212" fill="rgba(226,232,240,.6)" font-size="10"><?= $escape($points[0]['sale_date']) ?></text>
                    <text x="580" y="212" text-anchor="end" fill="rgba(226,232,240,.6)" font-size="10"><?= $escape($points[$pointCount - 1]['sale_date']) ?></text>
                </svg>
            <?php endif; ?>
        </div>
    </section>

    <p class="flex items-start gap-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-950 ring-1 ring-inset ring-amber-200 dark:bg-amber-400/10 dark:text-amber-100 dark:ring-amber-400/20">
        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="10" cy="10" r="7.5"/><path d="M10 6.5v4M10 13.5h.01"/></svg>
        Le profit est une estimation calculée avec le coût d’achat actuel du produit, et non le coût historique de chaque vente.
    </p>

    <section class="grid gap-6 lg:grid-cols-5">
        <article class="<?= $ui['card'] ?> lg:col-span-3">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Jour par jour</h2>
            <p class="<?= $ui['muted'] ?>">Les ventes les plus récentes en premier</p>
            <?php if ($points === []): ?>
                <div class="mt-6 rounded-2xl border border-dashed border-slate-300 py-14 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">Aucune vente terminée sur cette période.</div>
            <?php else: ?>
                <ul class="mt-4 max-h-[26rem] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                    <?php foreach (array_reverse($points) as $point): ?>
                        <?php $w = max(3, (int) round((float) $point['revenue_minor'] / $maxRevenue * 100)); ?>
                        <li class="py-3">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="text-slate-600 dark:text-slate-300"><?= $escape($point['sale_date']) ?></span>
                                <span class="tabular-nums"><span class="font-semibold text-slate-900 dark:text-white"><?= $escape($money($point['revenue_minor'], $point['currency'])) ?></span> <span class="text-slate-500 dark:text-slate-400">· <?= $escape($point['quantity']) ?> vendu(s)</span></span>
                            </div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="ss-grow h-full rounded-full bg-emerald-500" style="width: <?= $w ?>%"></div></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="<?= $ui['card'] ?> lg:col-span-2">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Mouvements de stock</h2>
            <p class="<?= $ui['muted'] ?>">Entrées et sorties de la période</p>
            <?php if (($report['movements'] ?? []) === []): ?>
                <div class="mt-6 rounded-2xl border border-dashed border-slate-300 py-14 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">Aucun mouvement enregistré sur cette période.</div>
            <?php else: ?>
                <ul class="mt-4 max-h-[26rem] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                    <?php foreach ($report['movements'] as $movement): ?>
                        <?php $q = (float) $movement['quantity']; ?>
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900 dark:text-white"><?= $escape($movement['movement_type']) ?></p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400"><?= $escape($movement['operational_at']) ?> · <?= $escape($movement['reference'] ?? '—') ?></p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-sm font-semibold tabular-nums <?= $q < 0
                                ? 'bg-orange-100 text-orange-900 dark:bg-orange-400/15 dark:text-orange-200'
                                : 'bg-emerald-100 text-emerald-900 dark:bg-emerald-400/15 dark:text-emerald-200' ?>"><?= $q > 0 ? '+' : '' ?><?= $escape($movement['quantity']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </section>

    <section class="<?= $ui['card'] ?>" aria-labelledby="product-details-title">
        <h2 id="product-details-title" class="text-lg font-semibold text-slate-900 dark:text-white">Fiche produit</h2>
        <dl class="mt-5 grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ([
                'Référence' => $product['sku'] ?? '—',
                'Type' => $isService ? 'Service' : 'Produit',
                'Catégorie' => $product['category_name'] ?? '—',
                'Marque' => $product['brand_name'] ?? '—',
                'Prix de vente' => ($product['price'] ?? null) === null ? '—' : $product['price'] . ' ' . $product['currency'],
                'Coût d’achat' => ($product['cost_price'] ?? null) === null ? 'Non renseigné' : $product['cost_price'] . ' ' . $product['currency'],
                'Fabricant' => $product['manufacturer'] ?? '—',
                'Statut' => $product['status'] ?? '—',
            ] as $label => $value): ?>
                <div class="border-l-2 border-emerald-600/30 pl-3 dark:border-emerald-400/30">
                    <dt class="text-xs text-slate-500 dark:text-slate-400"><?= $escape($label) ?></dt>
                    <dd class="mt-0.5 font-medium text-slate-900 dark:text-white"><?= $escape($value) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>

<?php else: ?>
    <?php
    $ranking = array_values($report['ranking'] ?? []);
    $outOfStock = $report['out_of_stock'] ?? [];
    $count = count($ranking);
    $largestUnits = max(1.0, ...array_map(static fn(array $row): float => (float) $row['units_sold'], $ranking ?: [['units_sold' => 1]]));
    $totalUnits = array_sum(array_map(static fn(array $row): float => (float) $row['units_sold'], $ranking));
    $currencies = array_values(array_unique(array_map(static fn(array $row): string => (string) $row['currency'], $ranking)));
    $totalRevenue = array_sum(array_map(static fn(array $row): float => (float) $row['revenue_minor'], $ranking));
    $share = static fn(array $row) => $totalUnits > 0 ? (int) round((float) $row['units_sold'] / $totalUnits * 100) : 0;
    $thirdUnits = $count >= 3 ? (float) $ranking[2]['units_sold'] : null;
    $podium = array_slice($ranking, 0, 3);
    $rest = array_slice($ranking, 3);
    $medals = [
        0 => ['label' => '1er', 'badge' => 'from-amber-200 to-amber-400 text-amber-950', 'ped' => 'from-amber-300 to-amber-500/70 text-amber-950', 'h' => 'md:h-32', 'order' => 'md:order-2'],
        1 => ['label' => '2e', 'badge' => 'from-slate-100 to-slate-300 text-slate-900', 'ped' => 'from-slate-200 to-slate-400/60 text-slate-900', 'h' => 'md:h-20', 'order' => 'md:order-1'],
        2 => ['label' => '3e', 'badge' => 'from-orange-200 to-orange-400 text-orange-950', 'ped' => 'from-orange-300 to-orange-500/60 text-orange-950', 'h' => 'md:h-14', 'order' => 'md:order-3'],
    ];
    ?>

    <!-- Scène du podium -->
    <section class="<?= $ui['stage'] ?> p-6 sm:p-10" aria-labelledby="product-ranking-title">
        <div class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <h2 id="product-ranking-title" class="text-2xl font-semibold tracking-tight sm:text-3xl">Le podium de la période</h2>
                <p class="mt-1 text-sm text-slate-300">Commandes terminées, du <?= $escape($period['from']) ?> au <?= $escape($period['to']) ?></p>
            </div>
            <dl class="flex gap-8 text-right">
                <div>
                    <dt class="text-xs text-slate-300">Unités vendues</dt>
                    <dd class="text-2xl font-semibold tabular-nums"><?= $escape($qty($totalUnits)) ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-300">Chiffre d’affaires</dt>
                    <dd class="text-2xl font-semibold tabular-nums text-emerald-300"><?= $ranking === [] ? '—' : (count($currencies) === 1 ? $escape($money($totalRevenue, $currencies[0])) : 'Devises multiples') ?></dd>
                </div>
            </dl>
        </div>

        <?php if ($ranking === []): ?>
            <div class="mt-10 rounded-2xl border border-dashed border-white/20 py-16 text-center text-sm text-slate-300">Aucune donnée de vente pour cette période. Le prochain n°1 reste à écrire.</div>
        <?php else: ?>
            <ol class="mt-10 grid items-end gap-4 md:grid-cols-3">
                <?php foreach ($podium as $i => $row): $m = $medals[$i]; ?>
                    <li class="<?= $m['order'] ?> flex flex-col">
                        <a href="/dashboard/sales/statistics?product_id=<?= (int) $row['id'] ?>&from=<?= $escape($period['from']) ?>&to=<?= $escape($period['to']) ?>"
                           class="group relative flex flex-1 flex-col rounded-3xl bg-white/[.06] p-5 ring-1 ring-white/10 backdrop-blur transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300 <?= $i === 0 ? 'md:pb-8 md:pt-7 ring-amber-300/40' : '' ?>">
                            <?php if ($i === 0): ?>
                                <svg class="absolute -top-5 left-1/2 hidden h-9 w-9 -translate-x-1/2 text-amber-300 md:block" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8z"/></svg>
                            <?php endif; ?>
                            <div class="flex items-center gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-b text-sm font-bold <?= $m['badge'] ?>"><?= $m['label'] ?></span>
                                <span class="min-w-0 truncate text-lg font-semibold group-hover:underline"><?= $escape($row['name']) ?></span>
                            </div>
                            <p class="mt-5 text-4xl font-semibold tracking-tight tabular-nums <?= $i === 0 ? 'sm:text-5xl' : '' ?>"><?= $escape($qty($row['units_sold'])) ?><span class="ml-2 text-sm font-medium text-slate-300">unité(s)</span></p>
                            <p class="mt-1 text-sm text-slate-300 tabular-nums"><?= $escape($money($row['revenue_minor'], $row['currency'])) ?></p>
                            <div class="mt-4 flex items-center gap-3">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/10"><div class="ss-grow h-full rounded-full bg-emerald-300" style="width: <?= max(2, $share($row)) ?>%; animation-delay: <?= .15 * $i ?>s"></div></div>
                                <span class="text-xs font-medium tabular-nums text-emerald-200"><?= $share($row) ?> % des ventes</span>
                            </div>
                        </a>
                        <div class="ss-rise mt-2 hidden items-center justify-center rounded-t-2xl bg-gradient-to-b text-4xl font-bold md:flex <?= $m['h'] ?> <?= $m['ped'] ?>" style="animation-delay: <?= .15 * $i ?>s" aria-hidden="true"><?= $i + 1 ?></div>
                    </li>
                <?php endforeach; ?>
            </ol>

            <p class="mt-8 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-2xl bg-emerald-400/10 px-4 py-3 text-sm text-emerald-100 ring-1 ring-inset ring-emerald-300/20">
                <svg class="h-4 w-4 shrink-0 text-emerald-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 14l5-5 3 3 6-7M13 5h4v4"/></svg>
                <span><strong class="font-semibold"><?= $escape($ranking[0]['name']) ?></strong> mène la période avec <?= $share($ranking[0]) ?> % des unités vendues.</span>
                <?php if (isset($rest[0]) && $thirdUnits !== null): $gap = max(0.0, $thirdUnits - (float) $rest[0]['units_sold']); ?>
                    <span>Il manque <strong class="font-semibold"><?= $escape($qty($gap)) ?></strong> unité(s) à <strong class="font-semibold"><?= $escape($rest[0]['name']) ?></strong> pour monter sur le podium.</span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </section>

    <!-- La suite du classement -->
    <?php if ($rest !== []): ?>
        <section class="<?= $ui['card'] ?>" aria-labelledby="rest-ranking-title">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="rest-ranking-title" class="text-lg font-semibold text-slate-900 dark:text-white">Ils veulent le podium</h2>
                <p class="<?= $ui['muted'] ?>">Chaque barre se mesure au n°1</p>
            </div>
            <ol class="mt-5 divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($rest as $j => $row): ?>
                    <?php
                    $rank = $j + 4;
                    $width = max(2, (int) round((float) $row['units_sold'] / $largestUnits * 100));
                    $gap = $thirdUnits !== null ? max(0.0, $thirdUnits - (float) $row['units_sold']) : null;
                    $close = $gap !== null && $thirdUnits > 0 && $gap / $thirdUnits <= 0.15;
                    ?>
                    <li class="grid grid-cols-[2.75rem_1fr] items-center gap-x-4 gap-y-2 py-4 sm:grid-cols-[2.75rem_minmax(0,1fr)_minmax(0,1.3fr)_auto]">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold tabular-nums text-slate-700 dark:bg-slate-800 dark:text-slate-200"><?= $rank ?></span>
                        <div class="min-w-0">
                            <a class="block truncate font-medium text-slate-900 hover:text-emerald-700 hover:underline dark:text-white dark:hover:text-emerald-300"
                               href="/dashboard/sales/statistics?product_id=<?= (int) $row['id'] ?>&from=<?= $escape($period['from']) ?>&to=<?= $escape($period['to']) ?>"><?= $escape($row['name']) ?></a>
                            <?php if ($gap !== null): ?>
                                <span class="text-xs <?= $close ? 'font-medium text-emerald-700 dark:text-emerald-300' : 'text-slate-500 dark:text-slate-400' ?>">
                                    <?= $close ? 'Presque ! ' : '' ?>À <?= $escape($qty($gap)) ?> unité(s) du podium
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="col-start-2 h-2.5 overflow-hidden rounded-full bg-slate-100 sm:col-start-auto dark:bg-slate-800" role="img" aria-label="<?= $escape($qty($row['units_sold'])) ?> unités">
                            <div class="ss-grow h-full rounded-full <?= $close ? 'bg-emerald-500' : 'bg-slate-400 dark:bg-slate-500' ?>" style="width: <?= $width ?>%"></div>
                        </div>
                        <div class="col-start-2 text-right text-sm tabular-nums sm:col-start-auto">
                            <span class="font-semibold text-slate-900 dark:text-white"><?= $escape($money($row['revenue_minor'], $row['currency'])) ?></span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400"><?= $escape($qty($row['units_sold'])) ?> unité(s) · <?= $share($row) ?> %</span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <!-- Ruptures -->
    <section class="rounded-3xl border border-red-200 bg-red-50/70 p-6 dark:border-red-900/50 dark:bg-red-950/20" aria-labelledby="out-of-stock-title">
        <div class="flex items-center gap-3">
            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-red-600 text-white" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 3l8 14H2L10 3zM10 8.5v3.5M10 14.5h.01"/></svg>
            </span>
            <div>
                <h2 id="out-of-stock-title" class="text-lg font-semibold text-red-950 dark:text-red-100">À réapprovisionner</h2>
                <p class="text-xs text-red-800 dark:text-red-300">Produits actifs en rupture de stock</p>
            </div>
        </div>
        <?php if ($outOfStock === []): ?>
            <p class="mt-4 text-sm text-red-900 dark:text-red-200">Rien en rupture : tout est en rayon.</p>
        <?php else: ?>
            <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($outOfStock as $row): ?>
                    <li>
                        <a class="block rounded-2xl bg-white p-4 text-sm ring-1 ring-red-200 transition hover:ring-red-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 dark:bg-slate-900 dark:ring-red-900/60 dark:hover:ring-red-500"
                           href="/dashboard/sales/statistics?product_id=<?= (int) $row['id'] ?>">
                            <span class="block font-semibold text-slate-900 dark:text-white"><?= $escape($row['name']) ?></span>
                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400"><?= $escape($row['physical_qty']) ?> <?= $escape($row['unit_code'] ?? '') ?> en stock · <?= $escape($row['reserved_qty']) ?> réservée(s)</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
</main>