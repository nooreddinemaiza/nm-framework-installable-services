<section class="search-results-page" data-search-results-page
    data-scope="<?= ($search_scope ?? 'public') === 'dashboard' ? 'dashboard' : 'public' ?>"
    data-api="<?= htmlspecialchars((string) ($search_api ?? '/api/search'), ENT_QUOTES, 'UTF-8') ?>">
    <div class="search-results-page__content">
        <h1><?= ($search_scope ?? 'public') === 'dashboard' ? 'Résultats de recherche' : 'Rechercher' ?></h1>
        <?php if (($search_scope ?? 'public') === 'dashboard'): ?>
            <p class="search-results-page__intro">Affinez votre recherche et parcourez tous les résultats accessibles.</p>
        <?php endif; ?>

        <form class="search-results-page__form" data-results-form>
            <label class="sr-only" for="search-results-query">Votre recherche</label>
            <input id="search-results-query" type="search" minlength="2" maxlength="100"
                placeholder="Saisissez au moins deux caractères" data-results-query>
            <button type="submit">Rechercher</button>
        </form>

        <fieldset class="global-search__filters">
            <legend>Types de contenus</legend>
            <div class="global-search__types" data-results-types>
                <span class="global-search__loading">Chargement des contenus disponibles…</span>
            </div>
        </fieldset>

        <p class="search-results-page__status" data-results-status role="status" aria-live="polite">
            Chargement des contenus accessibles…
        </p>
        <ol class="search-results-page__list" data-results-list></ol>
        <nav class="search-results-page__pagination" aria-label="Pagination des résultats">
            <button type="button" data-results-previous disabled>Précédent</button>
            <span data-results-page>Page 1 sur 1</span>
            <button type="button" data-results-next disabled>Suivant</button>
        </nav>
    </div>
</section>
