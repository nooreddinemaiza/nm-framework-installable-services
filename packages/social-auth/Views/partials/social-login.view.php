<?php
/**
 * Boutons de connexion sociale : uniquement les fournisseurs activés et configurés.
 * À inclure sous le formulaire de connexion (voir docs/social-auth.md).
 * Les couleurs d'icône sont gérées dans Svg::brand().
 */
$socialProviders = \NM\Services\SocialAuth\Social\SocialAuth::enabledProviders();

// Classes littérales pour que Tailwind les détecte au build.
$socialButton = 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-white text-[#10302c] shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] dark:focus-visible:ring-offset-[#143632]';
?>
<?php if ($socialProviders !== []): ?>
<div class="mt-4 w-full min-w-0 max-w-full space-y-4 sm:mt-6">
  <div class="flex items-center gap-3" role="separator">
    <span class="flex-1 border-t-2 border-dashed border-[#10302c]/25 dark:border-[#cfe8dc]/25"></span>
    <p class="min-w-0 shrink text-center text-sm font-bold">ou continuer avec</p>
    <span class="flex-1 border-t-2 border-dashed border-[#10302c]/25 dark:border-[#cfe8dc]/25"></span>
  </div>

  <div class="flex flex-wrap items-center justify-center gap-3 pb-1">
    <?php foreach ($socialProviders as $id => $label):
      $key   = strtolower((string) $id);
      $link  = '/connexion/social/' . rawurlencode((string) $id);
      $title = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
      $icon  = \Core\View\Component\Svg::brand($key, 'h-5 w-5');
      ?>
      <a href="<?= $link ?>" title="<?= $title ?>" aria-label="Continuer avec <?= $title ?>"
        class="<?= $socialButton ?>">
        <?= $icon ?>
        <span class="sr-only"><?= $title ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>