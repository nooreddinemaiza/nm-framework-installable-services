<?php
$page = $page ?? null;
$sections = is_array($sections ?? null) ? $sections : [];
$escape = static fn(mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$shelfLayout = ($layout ?? '') === 'shelf';
?>
<?php if ($shelfLayout): ?>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
    <style>
        .shelf { font-family: 'Anybody', ui-sans-serif, system-ui, sans-serif; }
        .page-shelf-sign { transform-origin: 50% 0; transform: rotate(-1.2deg); }
    </style>
<?php endif; ?>
<main class="managed-page<?= $shelfLayout ? ' shelf min-h-screen overflow-x-hidden bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc]' : '' ?>"
    data-page-layout="<?= $escape($layout ?? 'default') ?>">
    <?php if ($shelfLayout): ?>
        <div class="mx-auto max-w-7xl space-y-16 px-4 pb-24 pt-6 sm:px-6 lg:px-8">
    <?php endif; ?>
    <?php foreach ($sections as $section): ?>
        <section class="managed-page__section managed-page__section--<?= $escape($section['type'] ?? 'content') ?><?= $shelfLayout ? ' min-w-0 space-y-10' : '' ?>"
            data-section-slot="<?= $escape($section['slot'] ?? 'content') ?>">
            <?php foreach (($section['blocks'] ?? []) as $block): ?>
                <?php
                // Block HTML is emitted only after its registered renderer and validator have run.
                echo (string) ($block['html'] ?? '');
                ?>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <?php if ($shelfLayout): ?>
        </div>
    <?php endif; ?>
</main>
