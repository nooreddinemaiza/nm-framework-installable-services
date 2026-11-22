<?php
$searchRequest = app()->get(\Core\Http\Request::class);
$searchIsDashboard = str_starts_with($searchRequest->getPathInfo(), '/dashboard');
$searchApi = $searchIsDashboard ? '/dashboard/search/api' : '/api/search';
$searchResultsUrl = $searchIsDashboard ? '/dashboard/search/results' : '/search/results';
?>
<div class="global-search" data-scope="<?= $searchIsDashboard ? 'dashboard' : 'public' ?>" data-global-search
    data-api="<?= htmlspecialchars($searchApi, ENT_QUOTES, 'UTF-8') ?>"
    data-results-url="<?= htmlspecialchars($searchResultsUrl, ENT_QUOTES, 'UTF-8') ?>">
    @if($showSearchTrigger ?? true)
    <button type="button" class="global-search__trigger global-search__trigger--dashboard"
        data-search-open aria-haspopup="dialog" aria-controls="global-search-dialog">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="10.8" cy="10.8" r="6.8"></circle>
            <path d="m16 16 4.5 4.5"></path>
        </svg>
    </button>
    @endif
    <dialog id="global-search-dialog" class="global-search__dialog" data-search-dialog
        aria-labelledby="global-search-title">
        <section class="global-search__panel">
            <header class="global-search__heading">
                <div class="global-search__sign">
                    <div aria-hidden="true"><span></span><span></span></div>
                    <h2 id="global-search-title">Rechercher</h2>
                </div>
                <button type="button" class="global-search__close" data-search-close aria-label="Fermer la recherche">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m6 6 12 12M18 6 6 18"></path>
                    </svg>
                </button>
            </header>

            <form class="global-search__form" data-search-form>
                <label class="sr-only" for="global-search-query">Votre recherche</label>
                <input id="global-search-query" name="q" type="search" minlength="2" maxlength="100"
                    placeholder="Saisissez votre recherche" autocomplete="off" data-search-query>
                <button type="submit" data-search-submit>Rechercher</button>
            </form>

            <fieldset class="global-search__filters">
                <legend>Rechercher dans</legend>
                <div class="global-search__types" data-search-types>
                    <span class="global-search__loading">Chargement des contenus disponibles…</span>
                </div>
            </fieldset>

            <div class="global-search__status" data-search-status role="status" aria-live="polite">
                Choisissez un ou plusieurs types de contenus, puis lancez la recherche.
            </div>
            <ol class="global-search__results" data-search-results aria-label="Résultats de recherche"></ol>
            <a class="global-search__all" data-search-all hidden>Voir tous les résultats <span
                    aria-hidden="true">→</span></a>
        </section>
    </dialog>
    @if($searchIsDashboard)
    <dialog class="global-search__detail-dialog" data-search-detail-dialog aria-labelledby="search-detail-title">
        <section class="global-search__detail-panel">
            <header class="global-search__detail-heading">
                <h2 id="search-detail-title" data-search-detail="heading"></h2>
                <button type="button" class="global-search__detail-close" data-search-detail-close
                    aria-label="Fermer les détails">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m6 6 12 12M18 6 6 18"></path>
                    </svg>
                </button>
            </header>

            <div data-search-detail-user hidden>
                <div class="global-search__detail-user">
                    <img data-search-detail="profile_image" alt="" hidden>
                    <span data-search-detail-initial></span>
                    <div class="global-search__detail-user-name">
                        <strong data-search-detail="name"></strong>
                        <span><span data-search-detail="username"></span> · #<span data-search-detail="id"></span></span>
                    </div>
                </div>
                <div class="global-search__detail-badges">
                    <span data-search-detail="status"></span>
                    <span data-search-detail="role"></span>
                    <span data-search-detail="two_factor"></span>
                </div>
                <dl class="global-search__detail-list">
                    <div><dt>Adresse e-mail</dt><dd data-search-detail="email"></dd></div>
                    <div><dt>E-mail vérifié</dt><dd data-search-detail="email_verified"></dd></div>
                    <div data-search-detail-two-factor hidden><dt>Méthode 2FA</dt><dd data-search-detail="tfa_method"></dd></div>
                    <div><dt>Créé le</dt><dd data-search-detail="created_at"></dd></div>
                    <div><dt>Dernière activité</dt><dd data-search-detail="last_activity"></dd></div>
                </dl>
            </div>

            <div data-search-detail-group hidden>
                <p class="global-search__detail-description" data-search-detail="description"></p>
                <dl class="global-search__detail-list">
                    <div><dt>Identifiant</dt><dd data-search-detail="slug"></dd></div>
                    <div><dt>Visibilité</dt><dd data-search-detail="visibility"></dd></div>
                    <div><dt>Adhésion</dt><dd data-search-detail="membership"></dd></div>
                </dl>
            </div>

            <footer class="global-search__detail-footer">
                <button type="button" data-search-detail-close>Fermer</button>
            </footer>
        </section>
    </dialog>
    @endif
</div>