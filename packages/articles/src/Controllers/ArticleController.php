<?php

declare(strict_types=1);

namespace NM\Services\Articles\Controllers;

use App\Helpers\NavbarFactory;
use App\Models\User;
use NM\Services\Articles\Models\Article;
use NM\Services\Articles\Models\ArticleCategory;
use NM\Services\Articles\Models\ArticleCategoryAssignment;
use NM\Services\Articles\Services\ArticleConfigurationStore;
use NM\Services\Articles\Services\ArticleService;
use NM\Services\Articles\Services\ArticleServiceState;
use NM\Services\Articles\Services\RichTextSanitizer;
use Core\Exceptions\Http\GateException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Security\CsrfTokenManager;
use Core\View\ViewFactory;
use InvalidArgumentException;

final class ArticleController extends Controller
{
    public function __construct(
        private readonly ArticleService $articles,
        private readonly ArticleConfigurationStore $configuration,
        private readonly ViewFactory $factory,
        private readonly SessionInterface $session,
        private readonly ArticleServiceState $serviceState,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->requireArticleUser('articles.view');
        $this->assertEnabled();
        $canViewStats = $user->isSuper() || $user->hasPermission('articles.stats.view');
        $filters = $this->filters($request, $canViewStats);
        $listing = $this->listingData(
            $this->articles->dashboardListing($filters, $canViewStats),
            $canViewStats
        );

        return $this->dashboardResponse($user, 'dashboard.articles', 'Articles', [
            'article_filters' => $filters,
            'article_listing' => $listing,
            'article_can_create' => $user->isSuper() || $user->hasPermission('articles.create'),
            'article_can_update' => $user->isSuper() || $user->hasPermission('articles.update'),
            'article_can_publish' => $user->isSuper() || $user->hasPermission('articles.publish'),
            'article_can_delete' => $user->isSuper() || $user->hasPermission('articles.delete'),
            'article_can_manage_categories' => $user->isSuper() || $user->hasPermission('articles.categories.manage'),
            'article_can_view_stats' => $canViewStats,
        ]);
    }

    public function statistics(): Response
    {
        $user = $this->requireArticleUser('articles.stats.view');
        $this->assertEnabled();

        return $this->dashboardResponse($user, 'dashboard.articles-statistics', 'Statistiques des articles', [
            'article_statistics' => $this->articles->statistics(),
            'article_ranking' => $this->articles->publicArticleRanking(),
        ], 'article-statistics');
    }

    public function data(Request $request): Response
    {
        $user = $this->requireArticleUser('articles.view');
        $this->assertEnabled();
        $canViewStats = $user->isSuper() || $user->hasPermission('articles.stats.view');

        return $this->jsonResponse([
            'success' => true,
            'data' => $this->listingData(
                $this->articles->dashboardListing($this->filters($request, $canViewStats), $canViewStats),
                $canViewStats
            ),
        ]);
    }

    public function articleData(int $id): Response
    {
        $user = $this->requireArticleUser('articles.update');
        $this->assertEnabled();
        $article = Article::find($id);
        if (!$article instanceof Article) {
            throw new NotFoundHttpException();
        }

        return $this->jsonResponse([
            'success' => true,
            'data' => ['article' => $this->articleDataArray(
                $article,
                includeStats: $user->isSuper() || $user->hasPermission('articles.stats.view')
            )],
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->requireArticleUser('articles.create');
        $this->assertEnabled();
        try {
            $input = $this->articleInput($request);
            if (($input['status'] ?? Article::STATUS_DRAFT) === Article::STATUS_PUBLISHED
                && !$user->isSuper()
                && !$user->hasPermission('articles.publish')) {
                throw new GateException();
            }
            $article = $this->articles->saveArticle(
                $input,
                null,
                (int) $user->getKey()
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Article créé.',
                'data' => ['article' => $this->articleDataArray(
                    $article,
                    includeStats: $user->isSuper() || $user->hasPermission('articles.stats.view')
                )],
            ], 201);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['form' => [$exception->getMessage()]],
            ], 422);
        }
    }

    public function update(Request $request, int $id): Response
    {
        $user = $this->requireArticleUser('articles.update');
        $this->assertEnabled();
        $article = Article::find($id);
        if (!$article instanceof Article) {
            throw new NotFoundHttpException();
        }
        try {
            $input = $this->articleInput($request);
            if (($input['status'] ?? $article->getAttribute('status')) !== $article->getAttribute('status')
                && !$user->isSuper()
                && !$user->hasPermission('articles.publish')) {
                throw new GateException();
            }
            $article = $this->articles->saveArticle($input, $article);

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Article mis à jour.',
                'data' => ['article' => $this->articleDataArray(
                    $article,
                    includeStats: $user->isSuper() || $user->hasPermission('articles.stats.view')
                )],
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['form' => [$exception->getMessage()]],
            ], 422);
        }
    }

    public function publish(Request $request, int $id): Response
    {
        return $this->changeStatus($request, $id, 'articles.publish', Article::STATUS_PUBLISHED);
    }

    public function unpublish(Request $request, int $id): Response
    {
        return $this->changeStatus($request, $id, 'articles.publish', Article::STATUS_DRAFT);
    }

    public function archive(Request $request, int $id): Response
    {
        $this->requireArticleUser('articles.delete');
        $this->assertEnabled();
        $this->csrfManager = new CsrfTokenManager($this->session, self::TOKEN_NAME);
        $this->assertCsrf($request);
        $article = Article::find($id);
        if (!$article instanceof Article) {
            throw new NotFoundHttpException();
        }
        $this->articles->archive($article);

        return $this->jsonResponse(['success' => true, 'message' => 'Article archivé.']);
    }

    public function delete(Request $request, int $id): Response
    {
        $this->requireArticleUser('articles.delete');
        $this->assertEnabled();
        $this->csrfManager = new CsrfTokenManager($this->session, self::TOKEN_NAME);
        $this->assertCsrf($request);
        $article = Article::find($id);
        if (!$article instanceof Article) {
            throw new NotFoundHttpException();
        }
        $article->delete();

        return $this->jsonResponse(['success' => true, 'message' => 'Article supprimé.']);
    }

    public function createCategory(Request $request): Response
    {
        $this->requireArticleUser('articles.categories.manage');
        $this->assertEnabled();
        $this->csrfManager = new CsrfTokenManager($this->session, self::TOKEN_NAME);
        $this->assertCsrf($request);
        try {
            $input = $this->requestData($request);
            $this->articles->createCategory(
                (string) ($input['name'] ?? ''),
                (string) ($input['description'] ?? ''),
            );
            $categories = $this->articles->categories();

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Catégorie créée.',
                'data' => ['categories' => array_map($this->categoryDataArray(...), $categories)],
            ], 201);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['form' => [$exception->getMessage()]],
            ], 422);
        }
    }

    public function deleteCategory(Request $request, int $id): Response
    {
        $this->requireArticleUser('articles.categories.manage');
        $this->assertEnabled();
        $this->csrfManager = new CsrfTokenManager($this->session, self::TOKEN_NAME);
        $this->assertCsrf($request);
        $this->articles->deleteCategory($id);

        return $this->jsonResponse([
            'success' => true,
            'message' => 'Catégorie supprimée.',
            'data' => ['categories' => array_map(
                $this->categoryDataArray(...),
                $this->articles->categories()
            )],
        ]);
    }

    public function preview(int $id): Response
    {
        $this->requireArticleUser('articles.view');
        $this->assertEnabled();
        $article = Article::find($id);
        if (!$article instanceof Article) {
            throw new NotFoundHttpException();
        }
        $article->setAttribute(
            'content',
            RichTextSanitizer::sanitize((string) $article->getAttribute('content', ''))
        );
        $article->setAttribute('categories', $this->categoryNames($id));

        return new Response($this->factory->render('public.articles.detail', [
            'title' => (string) $article->getAttribute('title'),
            'article' => $article,
            'article_preview' => true,
        ]));
    }

    public function publicIndex(Request $request): Response
    {
        $this->assertEnabled();
        $categoryId = max(0, $request->query->getInt('category_id'));
        $configuration = $this->articles->configuration();
        $listing = $this->articles->publicListing(max(1, $request->query->getInt('page', 1)), $categoryId ?: null);

        return new Response($this->factory->render('public.articles.index', [
            'title' => 'Articles',
            'article_listing' => $listing,
            'article_category_id' => $categoryId,
            'article_popular' => $configuration['public_popularity_enabled']
                ? $this->articles->popularArticles(5)
                : [],
        ]));
    }

    public function publicDetail(Request $request, string $slug): Response
    {
        $this->assertEnabled();
        $article = $this->articles->publicArticle($slug);
        if (!$article instanceof Article) {
            throw new NotFoundHttpException();
        }
        $article->setAttribute(
            'content',
            RichTextSanitizer::sanitize((string) $article->getAttribute('content', ''))
        );
        $userAgent = $request->header('User-Agent', '') ?? '';
        if (preg_match('/bot|crawler|spider|slurp|preview|headless/i', $userAgent) !== 1) {
            $this->articles->recordView($article, $this->session->getId());
        }

        return new Response($this->factory->render('public.articles.detail', [
            'title' => (string) $article->getAttribute('title'),
            'article' => $article,
            'article_preview' => false,
            'article_related' => $this->articles->relatedPublicArticles($article),
        ]));
    }

    public function saveConfiguration(Request $request): Response
    {
        $this->requireSuperAdmin();
        $this->assertEnabled();
        try {
            $this->csrfManager = new CsrfTokenManager($this->session, self::TOKEN_NAME);
            $this->assertCsrf($request);
            $this->configuration->save($this->requestData($request));

            return $this->jsonResponse(['success' => true, 'message' => 'Configuration des articles enregistrée.']);
        } catch (InvalidArgumentException|\Core\Exceptions\Http\UnprocessableEntityException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function changeStatus(Request $request, int $id, string $permission, string $status): Response
    {
        $this->requireArticleUser($permission);
        $this->assertEnabled();
        $this->csrfManager = new CsrfTokenManager($this->session, self::TOKEN_NAME);
        $this->assertCsrf($request);
        $article = Article::find($id);
        if (!$article instanceof Article) {
            throw new NotFoundHttpException();
        }
        $wasArchived = $article->getAttribute('status') === Article::STATUS_ARCHIVED;
        if ($status === Article::STATUS_PUBLISHED) {
            $this->articles->publish($article);
        } else {
            $this->articles->unpublish($article);
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => $status === Article::STATUS_PUBLISHED
                ? 'Article publié.'
                : ($wasArchived ? 'Article restauré en brouillon.' : 'Article dépublié.'),
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function dashboardResponse(
        User $user,
        string $contentName,
        string $title,
        array $extra,
        string $activeNavTab = 'articles'
    ): Response
    {
        return new Response($this->factory->renderWithLayout('app', 'dashboard', [
            'title' => $title,
            'csrf_token' => (new CsrfTokenManager($this->session, self::TOKEN_NAME))->generateToken(),
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user, $activeNavTab),
            'admin_permissions' => $user->effectivePermissions('string'),
            'errors' => [],
            'content_name' => $contentName,
            'activeNavTab' => $activeNavTab,
            'showHome' => true,
            'breadcrumbs' => [['label' => $title]],
            ...$extra,
        ]));
    }

    /** @return array<string, mixed> */
    private function articleInput(Request $request): array
    {
        $input = $this->requestData($request);
        if (array_key_exists('content', $input) && !is_string($input['content'])) {
            throw new InvalidArgumentException('Le contenu de l’article doit être du texte mis en forme valide.');
        }

        $articleInput = [
            'title' => (string) ($input['title'] ?? ''),
            'slug' => (string) ($input['slug'] ?? ''),
            'excerpt' => (string) ($input['excerpt'] ?? ''),
            'content' => (string) ($input['content'] ?? ''),
            'featured_image' => (string) ($input['featured_image'] ?? ''),
            'category_ids' => $input['category_ids'] ?? [],
        ];
        if (array_key_exists('status', $input)) {
            if (!is_string($input['status'])) {
                throw new InvalidArgumentException('L’état demandé pour l’article est invalide.');
            }
            $articleInput['status'] = $input['status'];
        }

        return $articleInput;
    }

    /** @return array<string, mixed> */
    private function requestData(Request $request): array
    {
        return $request->isJson() ? $request->json() : $request->request->all();
    }

    /** @return array{search: string, status: string, category_id: int, sort: string, page: int} */
    private function filters(Request $request, bool $canViewStats): array
    {
        $filters = [
            'search' => trim($request->query->getString('search')),
            'status' => $request->query->getString('status', 'all'),
            'category_id' => max(0, $request->query->getInt('category_id')),
            'sort' => $request->query->getString('sort', 'updated'),
            'page' => max(1, $request->query->getInt('page', 1)),
        ];
        if (!in_array($filters['status'], ['all', 'draft', 'published', 'archived'], true)) {
            $filters['status'] = 'all';
        }
        if (!in_array($filters['sort'], ['updated', 'title', 'views'], true)
            || (!$canViewStats && $filters['sort'] === 'views')) {
            $filters['sort'] = 'updated';
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $listing
     * @return array<string, mixed>
     */
    private function listingData(array $listing, bool $includeStats): array
    {
        return [
            'items' => array_map(
                fn(Article $article): array => $this->articleDataArray(
                    $article,
                    includeContent: false,
                    includeStats: $includeStats
                ),
                $listing['items']
            ),
            'total' => $listing['total'],
            'page' => $listing['page'],
            'pages' => $listing['pages'],
            'per_page' => $listing['per_page'],
            'categories' => array_map($this->categoryDataArray(...), $listing['categories']),
            'statistics' => $listing['statistics'],
            'popular' => $listing['popular'],
        ];
    }

    /** @return array<string, mixed> */
    private function articleDataArray(
        Article $article,
        bool $includeContent = true,
        bool $includeStats = true
    ): array
    {
        $categoryIds = $article->getAttribute('category_ids');
        if (!is_array($categoryIds)) {
            $categoryIds = array_map(
                static fn(ArticleCategoryAssignment $assignment): int => (int) $assignment->getAttribute('article_category_id'),
                ArticleCategoryAssignment::query()->where('article_id', '=', (int) $article->getKey())->get()
            );
        }
        $data = [
            'id' => (int) $article->getKey(),
            'author_id' => (int) $article->getAttribute('author_id', 0),
            'author_name' => (string) $article->getAttribute('author_name', ''),
            'title' => (string) $article->getAttribute('title', ''),
            'slug' => (string) $article->getAttribute('slug', ''),
            'excerpt' => (string) $article->getAttribute('excerpt', ''),
            'featured_image' => (string) $article->getAttribute('featured_image', ''),
            'status' => (string) $article->getAttribute('status', ''),
            'published_at' => (string) $article->getAttribute('published_at', ''),
            'created_at' => (string) $article->getAttribute('created_at', ''),
            'updated_at' => (string) $article->getAttribute('updated_at', ''),
            'archived_at' => (string) $article->getAttribute('archived_at', ''),
            'categories' => $article->getAttribute('categories', []),
            'category_ids' => array_values(array_unique(array_map('intval', $categoryIds))),
        ];
        if ($includeContent) {
            $data['content'] = RichTextSanitizer::sanitize(
                (string) $article->getAttribute('content', '')
            );
        }
        if ($includeStats) {
            $data['views_count'] = (int) $article->getAttribute('views_count', 0);
        }

        return $data;
    }

    /** @return array{id: int, name: string, slug: string} */
    private function categoryDataArray(ArticleCategory $category): array
    {
        return [
            'id' => (int) $category->getKey(),
            'name' => (string) $category->getAttribute('name', ''),
            'slug' => (string) $category->getAttribute('slug', ''),
        ];
    }

    /** @return list<string> */
    private function categoryNames(int $articleId): array
    {
        $assignments = ArticleCategoryAssignment::query()->where('article_id', '=', $articleId)->get();
        $ids = array_values(array_filter(array_map(
            static fn(ArticleCategoryAssignment $item): int => (int) $item->getAttribute('article_category_id'),
            $assignments
        )));
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(
            static fn(ArticleCategory $category): string => (string) $category->getAttribute('name'),
            ArticleCategory::query()->whereIn('id', $ids)->get()
        ));
    }

    private function assertCsrf(Request $request): void
    {
        $token = $request->csrftoken();
        if ($token === null || !$this->csrfManager->validateToken($token)) {
            throw new InvalidArgumentException('Le jeton de sécurité est invalide. Actualisez la page et réessayez.');
        }
    }

    private function assertEnabled(): void
    {
        if (!app()->environment()->getBool('DB_ON', false)) {
            throw new \Core\Exceptions\Http\ServiceNotInstalledException('Le service Articles nécessite une base de données configurée.');
        }
        $this->serviceState->assertEnabled();
    }

    private function requireArticleUser(string $permission): User
    {
        $userId = $this->session->getUserId();
        $user = $userId === null ? null : User::find($userId);
        if (!$user instanceof User || !$user->is_active() || $user->highestRoleLevel() < User::MODERATOR_LEVEL) {
            throw new NotFoundHttpException();
        }
        if (!$user->isSuper() && !$user->hasPermission($permission)) {
            throw new GateException();
        }

        return $user;
    }

    private function requireSuperAdmin(): User
    {
        $userId = $this->session->getUserId();
        $user = $userId === null ? null : User::find($userId);
        if (!$user instanceof User || !$user->is_active() || !$user->isSuper()) {
            throw new NotFoundHttpException();
        }

        return $user;
    }
}
