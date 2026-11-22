@extends('layouts.app')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tags = is_array($tags ?? null) ? $tags : [];
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
?>
@title('Tags | ' . app()->config('app.name'))
@description('Explorez les produits, services, catégories et marques par tag.')
@meta('robots', 'index,follow')
@og('type', 'website')
@og('title', 'Tags')
@og('description', 'Explorez les produits, services, catégories et marques par tag.')

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
@include('partials.site-nav', ['active' => ''])
<main class="min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc] [font-family:Anybody,ui-sans-serif,system-ui,sans-serif]">
    <div class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-x-2 text-sm" aria-label="Fil d’Ariane">
            <a class="rounded underline underline-offset-4 <?= $focus ?>" href="/">Accueil</a>
            <span aria-hidden="true">/</span>
            <span class="font-bold" aria-current="page">Tags</span>
        </nav>

        <header class="mt-4 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
            <div class="origin-top rotate-[-1.2deg] w-fit max-w-full">
                <div class="flex justify-between px-8" aria-hidden="true">
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                </div>
                <h1 class="break-words rounded-xl bg-[#ff7a1a] px-6 py-4 text-4xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:80%] dark:shadow-[0_6px_0_#3a5f59] sm:px-8 sm:text-6xl">Explorer par tag</h1>
            </div>
            <div class="sm:max-w-xs sm:text-right">
                <p class="text-lg leading-snug">Retrouvez ensemble les produits, services, catégories et marques qui partagent un même tag.</p>
                <p class="mt-2 text-sm font-bold"><span class="tabular-nums"><?= count($tags) ?></span> tag<?= count($tags) > 1 ? 's' : '' ?></p>
            </div>
        </header>

        <?php if ($tags === []): ?>
            <div class="mt-16 rounded-2xl border-4 border-dashed border-[#10302c] px-6 py-16 text-center dark:border-[#3a5f59]">
                <p class="text-3xl font-extrabold [font-stretch:80%]">Aucun tag public pour le moment</p>
                <p class="mx-auto mt-2 max-w-sm">Les tags apparaîtront ici lorsqu’ils seront associés à des contenus actifs.</p>
                <a class="mt-6 inline-flex min-h-11 items-center rounded-md bg-[#ff7a1a] px-6 font-bold text-[#10302c] shadow-[0_4px_0_#10302c] dark:shadow-[0_4px_0_#3a5f59] <?= $focus ?>" href="/">Retour à l’accueil</a>
            </div>
        <?php else: ?>
            <ul class="mt-16 grid gap-x-4 gap-y-6 sm:grid-cols-2 lg:grid-cols-3" aria-label="Tags">
                <?php foreach ($tags as $tag): ?>
                    <?php $n = (int) $tag['count']; ?>
                    <li>
                        <a href="/tags/<?= $escape(rawurlencode((string) $tag['slug'])) ?>"
                            class="group relative flex min-h-24 items-center justify-between gap-4 rounded-md bg-white py-4 pl-12 pr-5 shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59] before:absolute before:left-4 before:top-1/2 before:h-3.5 before:w-3.5 before:-translate-y-1/2 before:rounded-full before:bg-[#cfe8dc] before:ring-2 before:ring-[#10302c] dark:before:bg-[#0c1c1a] <?= $focus ?>">
                            <span class="break-words text-2xl font-extrabold leading-none [font-stretch:75%] sm:text-3xl"><?= $escape($tag['name']) ?></span>
                            <span class="shrink-0 rounded-md bg-[#ff7a1a] px-2.5 py-1 text-xs font-extrabold tabular-nums text-[#10302c]"><?= $n ?> contenu<?= $n > 1 ? 's' : '' ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</main>
@endsection