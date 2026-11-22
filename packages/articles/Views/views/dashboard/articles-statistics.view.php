<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$statistics = is_array($article_statistics ?? null) ? $article_statistics : [];
$ranking = is_array($article_ranking ?? null) ? $article_ranking : [];
$podium = array_slice($ranking, 0, 3);
$rest = array_slice($ranking, 3);
$largest = max(1, ...array_map(static fn(array $article): int => (int) ($article['views'] ?? 0), $ranking ?: [['views' => 1]]));
$cards = [
    ['label' => 'Articles', 'value' => $statistics['total'] ?? 0, 'tone' => 'text-zinc-900 dark:text-white'],
    ['label' => 'Publiés', 'value' => $statistics['published'] ?? 0, 'tone' => 'text-emerald-700 dark:text-emerald-300'],
    ['label' => 'Brouillons', 'value' => $statistics['drafts'] ?? 0, 'tone' => 'text-amber-700 dark:text-amber-300'],
    ['label' => 'Consultations', 'value' => $statistics['views'] ?? 0, 'tone' => 'text-blue-700 dark:text-blue-300'],
];
$medals = [
    'inline-flex h-10 w-10 items-center justify-center rounded-full bg-amber-300 text-sm font-bold text-amber-950',
    'inline-flex h-10 w-10 items-center justify-center rounded-full bg-zinc-300 text-sm font-bold text-zinc-800 dark:bg-zinc-600 dark:text-white',
    'inline-flex h-10 w-10 items-center justify-center rounded-full bg-orange-300 text-sm font-bold text-orange-950',
];
$panel = 'overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950';
$cell = 'px-4 py-3 align-middle';
$muted = 'text-zinc-600 dark:text-zinc-400';
?>

<x-container title="Statistiques des articles" :reduiceable="false" size="xxl" class="my-1">
    <x-slot:actions>
        <a href="/dashboard/articles" class="inline-flex min-h-10 items-center rounded-md border border-zinc-300 px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-900">Retour aux articles</a>
    </x-slot:actions>
    <x-slot:components>
        <section class="relative overflow-hidden rounded-xl bg-zinc-950 p-6 text-white shadow-lg sm:p-8" aria-labelledby="article-ranking-title">
            <div class="pointer-events-none absolute -right-20 -top-32 h-80 w-80 rounded-full bg-emerald-400/20 blur-3xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-40 left-1/3 h-72 w-72 rounded-full bg-amber-400/10 blur-3xl" aria-hidden="true"></div>
            <div class="relative">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-200">Le lectorat, en un coup d’œil</p>
                <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 id="article-ranking-title" class="text-2xl font-semibold tracking-tight sm:text-3xl">Les articles qui attirent les lecteurs</h2>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-300">Classement des articles publiés selon leurs consultations cumulées. Les consultations sont comptées par session, avec la fenêtre de déduplication configurée pour le service.</p>
                    </div>
                    <p class="rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm text-zinc-200">
                        <?= number_format(count($ranking), 0, ',', ' ') ?> article(s) classé(s)
                    </p>
                </div>

                <?php if ($podium === []): ?>
                    <p class="mt-8 rounded-lg border border-dashed border-white/20 px-4 py-10 text-center text-sm text-zinc-300">Aucun article publié à classer pour le moment.</p>
                <?php else: ?>
                    <ol class="mt-8 grid items-end gap-3 md:grid-cols-3" aria-label="Podium des articles les plus consultés">
                        <?php foreach ($podium as $index => $article): ?>
                            <?php
                            $rank = $index + 1;
                            $slug = (string) ($article['slug'] ?? '');
                            $title = (string) ($article['title'] ?? '');
                            $featured = $rank === 1;
                            ?>
                            <li class="<?= $featured ? 'md:order-2 md:-translate-y-3' : ($rank === 2 ? 'md:order-1' : 'md:order-3') ?> rounded-lg border border-white/10 bg-white/[0.06] p-5 <?= $featured ? 'ring-1 ring-amber-300/40' : '' ?>">
                                <div class="flex items-start justify-between gap-3">
                                    <span class="<?= $medals[$index] ?>">#<?= $rank ?></span>
                                    <span class="text-right">
                                        <strong class="block text-2xl font-semibold tabular-nums"><?= number_format((int) ($article['views'] ?? 0), 0, ',', ' ') ?></strong>
                                        <span class="text-xs text-zinc-400">consultation(s)</span>
                                    </span>
                                </div>
                                <h3 class="mt-5 line-clamp-2 min-h-12 text-lg font-semibold leading-snug">
                                    <a class="rounded hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300"
                                        href="/articles/<?= rawurlencode($slug) ?>" target="_blank" rel="noopener"><?= $escape($title) ?></a>
                                </h3>
                                <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-white/10" aria-hidden="true">
                                    <div class="h-full rounded-full <?= $featured ? 'bg-amber-300' : 'bg-emerald-300' ?>" style="width: <?= max(2, (int) round((int) ($article['views'] ?? 0) / $largest * 100)) ?>%"></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </section>

        <section class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Indicateurs des articles">
            <?php foreach ($cards as $card): ?>
                <article class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <p class="text-sm <?= $muted ?>"><?= $escape($card['label']) ?></p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums <?= $card['tone'] ?>"><?= number_format((int) $card['value'], 0, ',', ' ') ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($rest !== []): ?>
            <section class="<?= $panel ?> mt-4" aria-labelledby="article-ranking-rest-title">
                <header class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                    <h2 id="article-ranking-rest-title" class="text-sm font-semibold text-zinc-900 dark:text-white">La suite du classement</h2>
                    <p class="mt-1 text-xs <?= $muted ?>">Chaque barre est mesurée par rapport à l’article en tête.</p>
                </header>
                <ol class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <?php foreach ($rest as $index => $article): ?>
                        <?php
                        $views = (int) ($article['views'] ?? 0);
                        $slug = (string) ($article['slug'] ?? '');
                        ?>
                        <li class="grid grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 px-4 py-4 sm:grid-cols-[3rem_minmax(0,1.3fr)_minmax(8rem,1fr)_auto]">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-zinc-100 text-sm font-semibold tabular-nums text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"><?= $index + 4 ?></span>
                            <a class="min-w-0 truncate font-medium text-zinc-900 hover:underline dark:text-white"
                                href="/articles/<?= rawurlencode($slug) ?>" target="_blank" rel="noopener"><?= $escape($article['title'] ?? '') ?></a>
                            <div class="col-span-2 col-start-2 h-2 overflow-hidden rounded-full bg-zinc-100 sm:col-span-1 sm:col-start-auto" role="img" aria-label="<?= number_format($views, 0, ',', ' ') ?> consultation(s)">
                                <div class="h-full rounded-full bg-emerald-500" style="width: <?= max(1, (int) round($views / $largest * 100)) ?>%"></div>
                            </div>
                            <span class="text-right text-sm font-semibold tabular-nums text-zinc-800 dark:text-zinc-200"><?= number_format($views, 0, ',', ' ') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>

        <section class="<?= $panel ?> mt-4" aria-labelledby="article-statistics-title">
            <header class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 id="article-statistics-title" class="text-sm font-semibold text-zinc-900 dark:text-white">Indicateurs suivis</h2>
            </header>
            <dl class="grid gap-4 p-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium text-zinc-900 dark:text-white">Consultations</dt>
                    <dd class="mt-1 <?= $muted ?>">Une même session ne compte qu’une fois pendant la fenêtre de déduplication.</dd>
                </div>
                <div>
                    <dt class="font-medium text-zinc-900 dark:text-white">Classement</dt>
                    <dd class="mt-1 <?= $muted ?>">Seuls les articles publiés et visibles sur le site sont classés.</dd>
                </div>
            </dl>
        </section>
    </x-slot:components>
</x-container>
