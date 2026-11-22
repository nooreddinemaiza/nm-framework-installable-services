<?php

declare(strict_types=1);

namespace NM\Services\Articles\Services;

use App\Models\User;
use Core\Database\Model;
use Core\Database\QueryBuilder;
use Core\Support\Str;
use DateTimeImmutable;
use InvalidArgumentException;
use NM\Services\Articles\Models\Article;
use NM\Services\Articles\Models\ArticleCategory;
use NM\Services\Articles\Models\ArticleCategoryAssignment;
use NM\Services\Articles\Models\ArticleView;

final class ArticleService
{
    public function __construct(private readonly ArticleConfigurationStore $configurationStore)
    {
    }

    /** @return array{items_per_page: int, view_deduplication_ttl: int, public_popularity_enabled: bool} */
    public function configuration(): array
    {
        return $this->configurationStore->values();
    }

    public function generateSlug(string $title, ?int $ignoreArticleId = null): string
    {
        $base = trim($title);
        if ($base === '') {
            throw new InvalidArgumentException('Le titre de l’article est requis pour générer un slug.');
        }

        $existing = [];
        foreach (Article::query()->select('id', 'slug')->get() as $row) {
            if ($row instanceof Article && (int) $row->getKey() !== $ignoreArticleId) {
                $existing[] = (string) $row->getAttribute('slug');
            }
        }

        $slug = Str::uniqueSlug($base, $existing);
        if ($slug === '') {
            throw new InvalidArgumentException('Le titre ne permet pas de générer un slug valide.');
        }

        return $slug;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveArticle(array $input, ?Article $article = null, ?int $authorId = null): Article
    {
        $title = trim((string) ($input['title'] ?? ''));
        $contentInput = $input['content'] ?? '';
        if (!is_string($contentInput)) {
            throw new InvalidArgumentException('Le contenu de l’article doit être du texte mis en forme valide.');
        }
        $content = RichTextSanitizer::sanitize(trim($contentInput), rejectInvalidInlineImages: true);
        if ($title === '' || self::utf8Length($title) > 190) {
            throw new InvalidArgumentException('Le titre est obligatoire et ne doit pas dépasser 190 caractères.');
        }
        if (!RichTextSanitizer::hasContent($content)) {
            throw new InvalidArgumentException('Le contenu de l’article est obligatoire.');
        }

        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->generateSlug($title, $article === null ? null : (int) $article->getKey());
        } elseif (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException('Le slug doit contenir uniquement des minuscules, chiffres et tirets.');
        } else {
            $duplicate = Article::query()->where('slug', '=', $slug)->first();
            if ($duplicate instanceof Article && (int) $duplicate->getKey() !== (int) ($article?->getKey() ?? 0)) {
                throw new InvalidArgumentException('Ce slug est déjà utilisé.');
            }
        }

        $status = (string) ($input['status'] ?? $article?->getAttribute('status') ?? Article::STATUS_DRAFT);
        $allowedStatuses = [Article::STATUS_DRAFT, Article::STATUS_PUBLISHED];
        if ($article?->getAttribute('status') === Article::STATUS_ARCHIVED) {
            $allowedStatuses[] = Article::STATUS_ARCHIVED;
        }
        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException('L’état demandé pour l’article est invalide.');
        }
        $categoryIds = $this->validatedCategoryIds($input['category_ids'] ?? []);
        $attributes = [
            'title' => $title,
            'slug' => $slug,
            'excerpt' => trim((string) ($input['excerpt'] ?? '')),
            'content' => $content,
            'featured_image' => trim((string) ($input['featured_image'] ?? '')) ?: null,
            'status' => $status,
            'primary_category_id' => $categoryIds[0] ?? null,
        ];
        if (self::utf8Length($attributes['excerpt']) > 1000) {
            throw new InvalidArgumentException('Le résumé ne doit pas dépasser 1000 caractères.');
        }
        if (self::utf8Length((string) ($attributes['featured_image'] ?? '')) > 255) {
            throw new InvalidArgumentException('L’URL de l’image principale est trop longue.');
        }
        $image = $attributes['featured_image'];
        if (is_string($image) && $image !== '') {
            $parts = parse_url($image);
            $isLocalPath = str_starts_with($image, '/') && !str_starts_with($image, '//') && !str_contains($image, '\\');
            $isHttpUrl = is_array($parts)
                && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                && !empty($parts['host'])
                && !isset($parts['user'])
                && !isset($parts['pass']);
            if (!$isLocalPath && !$isHttpUrl) {
                throw new InvalidArgumentException('L’image doit utiliser une URL HTTP(S) ou un chemin local absolu.');
            }
        }

        if ($article === null) {
            $attributes['author_id'] = $authorId;
            $attributes['published_at'] = $status === Article::STATUS_PUBLISHED ? date('Y-m-d H:i:s') : null;
            $article = Article::create($attributes);
        } else {
            if ($status === Article::STATUS_PUBLISHED && !$article->isPublished()) {
                $attributes['published_at'] = date('Y-m-d H:i:s');
            } elseif ($status === Article::STATUS_DRAFT) {
                $attributes['published_at'] = null;
            }
            $article->update($attributes);
        }

        $this->replaceCategories((int) $article->getKey(), $categoryIds);

        return $article;
    }

    public function publish(Article $article): Article
    {
        $article->update([
            'status' => Article::STATUS_PUBLISHED,
            'archived_at' => null,
            'published_at' => $article->getAttribute('published_at') ?: date('Y-m-d H:i:s'),
        ]);

        return $article;
    }

    public function unpublish(Article $article): Article
    {
        $article->update([
            'status' => Article::STATUS_DRAFT,
            'published_at' => null,
            'archived_at' => null,
        ]);

        return $article;
    }

    public function archive(Article $article): Article
    {
        $article->update(['status' => Article::STATUS_ARCHIVED, 'archived_at' => date('Y-m-d H:i:s')]);

        return $article;
    }

    /**
     * @param array{search?: string, status?: string, category_id?: int, sort?: string, page?: int} $filters
     * @return array{items: list<Article>, total: int, page: int, pages: int, per_page: int, categories: list<ArticleCategory>, statistics: array<string, int>, popular: list<array<string, mixed>>}
     */
    public function dashboardListing(array $filters, bool $includeStatistics = true): array
    {
        $configuration = $this->configuration();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = $configuration['items_per_page'];
        $query = $this->filteredQuery($filters, publicOnly: false);
        $total = (clone $query)->count();
        $sort = (string) ($filters['sort'] ?? 'updated');
        if ($sort === 'views') {
            $query->orderBy('updated_at', 'DESC')->orderBy('id', 'DESC');
            $allMatches = $query->get();
            $viewCounts = $this->viewCounts(array_map(static fn(Article $article): int => (int) $article->getKey(), $allMatches));
            usort($allMatches, static fn(Article $a, Article $b): int =>
                ($viewCounts[(int) $b->getKey()] ?? 0) <=> ($viewCounts[(int) $a->getKey()] ?? 0)
                ?: ((string) $b->getAttribute('updated_at') <=> (string) $a->getAttribute('updated_at'))
            );
            $items = array_slice($allMatches, ($page - 1) * $perPage, $perPage);
        } else {
            $query->orderBy($sort === 'title' ? 'title' : 'updated_at', $sort === 'title' ? 'ASC' : 'DESC')
                ->orderBy('id', 'DESC')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage);
            $items = $query->get();
        }
        $items = array_values(array_filter($items, static fn(mixed $item): bool => $item instanceof Article));
        $this->attachArticleDetails($items);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'categories' => ArticleCategory::query()->where('status', '=', 'active')->orderBy('name')->get(),
            'statistics' => $includeStatistics ? $this->statistics() : [],
            'popular' => $includeStatistics ? $this->popularArticles(5, publicOnly: false) : [],
        ];
    }

    /** @return list<ArticleCategory> */
    public function categories(): array
    {
        return ArticleCategory::query()->where('status', '=', 'active')->orderBy('name', 'ASC')->get();
    }

    public function createCategory(string $name, string $description = ''): ArticleCategory
    {
        $name = trim($name);
        if ($name === '' || self::utf8Length($name) > 190) {
            throw new InvalidArgumentException('Le nom de la catégorie est obligatoire (190 caractères maximum).');
        }
        $existing = ArticleCategory::query()->select('slug')->get();
        $slugs = array_values(array_filter(array_map(
            static fn(mixed $row): ?string => $row instanceof ArticleCategory ? (string) $row->getAttribute('slug') : null,
            $existing
        )));

        return ArticleCategory::create([
            'name' => $name,
            'slug' => Str::uniqueSlug($name, $slugs),
            'description' => trim($description),
            'status' => 'active',
        ]);
    }

    public function deleteCategory(int $categoryId): void
    {
        $category = ArticleCategory::find($categoryId);
        if (!$category instanceof ArticleCategory) {
            throw new InvalidArgumentException('Cette catégorie est introuvable.');
        }
        ArticleCategoryAssignment::query()->where('article_category_id', '=', $categoryId)->delete();
        $category->delete();
    }

    public function publicArticle(string $slug): ?Article
    {
        $article = Article::query()
            ->where('slug', '=', $slug)
            ->where('status', '=', Article::STATUS_PUBLISHED)
            ->whereNull('archived_at')
            ->where(static function (QueryBuilder $query): void {
                $query->whereNull('published_at')->orWhere('published_at', '<=', date('Y-m-d H:i:s'));
            })
            ->first();
        if (!$article instanceof Article) {
            return null;
        }
        $this->attachArticleDetails([$article]);

        return $article;
    }

    /**
     * @return list<array{id: int, title: string, slug: string, excerpt: string, featured_image: string, published_at: string, categories: list<string>}>
     */
    public function relatedPublicArticles(Article $article, int $limit = 4): array
    {
        $limit = min(12, max(1, $limit));
        $articleId = (int) $article->getKey();
        $categoryIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) $article->getAttribute('category_ids', [])
        ))));
        $related = [];

        if ($categoryIds !== []) {
            $candidateIds = array_values(array_unique(array_filter(array_map(
                static fn(mixed $row): int => $row instanceof Model ? (int) $row->getAttribute('article_id') : 0,
                ArticleCategoryAssignment::query()
                    ->whereIn('article_category_id', $categoryIds)
                    ->select('article_id')
                    ->get()
            ), static fn(int $id): bool => $id > 0 && $id !== $articleId)));
            if ($candidateIds !== []) {
                $related = $this->publicArticleQuery($articleId)
                    ->whereIn('id', $candidateIds)
                    ->orderBy('published_at', 'DESC')
                    ->orderBy('id', 'DESC')
                    ->limit($limit)
                    ->get();
            }
        }

        $related = array_values(array_filter($related, static fn(mixed $item): bool => $item instanceof Article));
        if (count($related) < $limit) {
            $excludedIds = array_merge(
                [$articleId],
                array_map(static fn(Article $item): int => (int) $item->getKey(), $related)
            );
            $fallback = $this->publicArticleQuery($articleId)
                ->whereNotIn('id', $excludedIds)
                ->orderBy('published_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit($limit - count($related))
                ->get();
            $related = array_merge(
                $related,
                array_values(array_filter($fallback, static fn(mixed $item): bool => $item instanceof Article))
            );
        }

        $this->attachArticleDetails($related);

        return array_map(static fn(Article $item): array => [
            'id' => (int) $item->getKey(),
            'title' => (string) $item->getAttribute('title', ''),
            'slug' => (string) $item->getAttribute('slug', ''),
            'excerpt' => (string) $item->getAttribute('excerpt', ''),
            'featured_image' => (string) $item->getAttribute('featured_image', ''),
            'published_at' => (string) ($item->getAttribute('published_at') ?: $item->getAttribute('created_at')),
            'categories' => $item->getAttribute('categories', []),
        ], $related);
    }

    /**
     * @return array{items: list<Article>, total: int, page: int, pages: int, per_page: int, categories: list<ArticleCategory>}
     */
    public function publicListing(int $page, ?int $categoryId = null): array
    {
        $filters = ['status' => Article::STATUS_PUBLISHED, 'category_id' => $categoryId ?? 0];
        $query = $this->filteredQuery($filters, publicOnly: true);
        $total = (clone $query)->count();
        $perPage = $this->configuration()['items_per_page'];
        $items = $query->orderBy('published_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($perPage)
            ->offset((max(1, $page) - 1) * $perPage)
            ->get();
        $items = array_values(array_filter($items, static fn(mixed $item): bool => $item instanceof Article));
        $this->attachArticleDetails($items);

        return [
            'items' => $items,
            'total' => $total,
            'page' => max(1, $page),
            'pages' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'categories' => ArticleCategory::query()->where('status', '=', 'active')->orderBy('name', 'ASC')->get(),
        ];
    }

    public function recordView(Article $article, string $sessionId): bool
    {
        $ttl = $this->configuration()['view_deduplication_ttl'];
        $fingerprint = hash('sha256', $sessionId . '|' . (string) $article->getKey());
        $cutoff = (new DateTimeImmutable('-' . $ttl . ' seconds'))->format('Y-m-d H:i:s');
        $record = ArticleView::query()
            ->where('article_id', '=', (int) $article->getKey())
            ->where('fingerprint', '=', $fingerprint)
            ->first();
        if ($record instanceof ArticleView) {
            if ((string) $record->getAttribute('last_viewed_at') >= $cutoff) {
                return false;
            }
            $record->update([
                'view_count' => (int) $record->getAttribute('view_count', 1) + 1,
                'last_viewed_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        }

        ArticleView::create([
            'article_id' => (int) $article->getKey(),
            'session_key' => $fingerprint,
            'fingerprint' => $fingerprint,
            'view_count' => 1,
            'last_viewed_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /** @return array<string, int> */
    public function statistics(): array
    {
        return [
            'total' => Article::query()->count(),
            'published' => Article::query()->where('status', '=', Article::STATUS_PUBLISHED)->whereNull('archived_at')->count(),
            'drafts' => Article::query()->where('status', '=', Article::STATUS_DRAFT)->count(),
            'views' => (int) ArticleView::query()->sum('view_count'),
        ];
    }

    /**
     * @return list<array{id: int, title: string, slug: string, views: int}>
     */
    public function popularArticles(int $limit = 10, bool $publicOnly = true): array
    {
        $query = Article::query();
        if ($publicOnly) {
            $query->where('status', '=', Article::STATUS_PUBLISHED)->whereNull('archived_at')
                ->where(static function (QueryBuilder $builder): void {
                    $builder->whereNull('published_at')->orWhere('published_at', '<=', date('Y-m-d H:i:s'));
                });
        }
        $articles = $query->select('id', 'title', 'slug', 'status', 'updated_at')->orderBy('updated_at', 'DESC')->get();
        $counts = $this->viewCounts(array_map(
            static fn(mixed $article): int => $article instanceof Article ? (int) $article->getKey() : 0,
            $articles
        ));
        usort($articles, static fn(Article $a, Article $b): int =>
            ($counts[(int) $b->getKey()] ?? 0) <=> ($counts[(int) $a->getKey()] ?? 0)
            ?: ((string) $b->getAttribute('updated_at') <=> (string) $a->getAttribute('updated_at'))
        );

        return array_map(static fn(Article $article): array => [
            'id' => (int) $article->getKey(),
            'title' => (string) $article->getAttribute('title'),
            'slug' => (string) $article->getAttribute('slug'),
            'views' => $counts[(int) $article->getKey()] ?? 0,
        ], array_slice($articles, 0, max(1, $limit)));
    }

    /**
     * @return list<array{id: int, title: string, slug: string, views: int, updated_at: string}>
     */
    public function publicArticleRanking(): array
    {
        $articles = Article::query()
            ->where('status', '=', Article::STATUS_PUBLISHED)
            ->whereNull('archived_at')
            ->where(static function (QueryBuilder $query): void {
                $query->whereNull('published_at')->orWhere('published_at', '<=', date('Y-m-d H:i:s'));
            })
            ->select('id', 'title', 'slug', 'updated_at')
            ->get();
        $articles = array_values(array_filter($articles, static fn(mixed $article): bool => $article instanceof Article));
        $counts = $this->viewCounts(array_map(
            static fn(Article $article): int => (int) $article->getKey(),
            $articles
        ));
        usort($articles, static fn(Article $left, Article $right): int =>
            ($counts[(int) $right->getKey()] ?? 0) <=> ($counts[(int) $left->getKey()] ?? 0)
            ?: ((string) $right->getAttribute('updated_at') <=> (string) $left->getAttribute('updated_at'))
        );

        return array_map(static fn(Article $article): array => [
            'id' => (int) $article->getKey(),
            'title' => (string) $article->getAttribute('title', ''),
            'slug' => (string) $article->getAttribute('slug', ''),
            'views' => $counts[(int) $article->getKey()] ?? 0,
            'updated_at' => (string) $article->getAttribute('updated_at', ''),
        ], $articles);
    }

    /** @param array<string, mixed> $filters */
    private function filteredQuery(array $filters, bool $publicOnly): QueryBuilder
    {
        $query = Article::query()->select(
            'id',
            'author_id',
            'primary_category_id',
            'title',
            'slug',
            'excerpt',
            'featured_image',
            'status',
            'published_at',
            'created_at',
            'updated_at',
            'archived_at',
        );
        $status = (string) ($filters['status'] ?? 'all');
        if ($publicOnly) {
            $query->where('status', '=', Article::STATUS_PUBLISHED)->whereNull('archived_at')
                ->where(static function (QueryBuilder $builder): void {
                    $builder->whereNull('published_at')->orWhere('published_at', '<=', date('Y-m-d H:i:s'));
                });
        } elseif (in_array($status, [Article::STATUS_DRAFT, Article::STATUS_PUBLISHED, Article::STATUS_ARCHIVED], true)) {
            $query->where('status', '=', $status);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->search(['title', 'excerpt', 'content'], $search);
        }
        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $linkedIds = ArticleCategoryAssignment::query()
                ->where('article_category_id', '=', $categoryId)
                ->select('article_id')
                ->get();
            $ids = array_values(array_unique(array_filter(array_map(
                static fn(mixed $row): int => $row instanceof Model ? (int) $row->getAttribute('article_id') : (int) ($row['article_id'] ?? 0),
                $linkedIds
            ))));
            $query->where(static function (QueryBuilder $builder) use ($categoryId, $ids): void {
                $builder->where('primary_category_id', '=', $categoryId);
                if ($ids !== []) {
                    $builder->orWhereIn('id', $ids);
                }
            });
        }

        return $query;
    }

    private function publicArticleQuery(int $excludeArticleId): QueryBuilder
    {
        return Article::query()
            ->select('id', 'author_id', 'primary_category_id', 'title', 'slug', 'excerpt', 'featured_image', 'status', 'published_at', 'created_at', 'updated_at', 'archived_at')
            ->where('status', '=', Article::STATUS_PUBLISHED)
            ->whereNull('archived_at')
            ->where('id', '<>', $excludeArticleId)
            ->where(static function (QueryBuilder $query): void {
                $query->whereNull('published_at')->orWhere('published_at', '<=', date('Y-m-d H:i:s'));
            });
    }

    /** @param list<Article> $articles */
    private function attachArticleDetails(array $articles): void
    {
        $ids = array_values(array_filter(array_map(
            static fn(Article $article): int => (int) $article->getKey(),
            $articles
        )));
        if ($ids === []) {
            return;
        }
        $views = $this->viewCounts($ids);
        $authorIds = array_values(array_unique(array_filter(array_map(
            static fn(Article $article): int => (int) $article->getAttribute('author_id', 0),
            $articles
        ))));
        $authors = [];
        if ($authorIds !== []) {
            foreach (User::query()->select('id', 'name')->whereIn('id', $authorIds)->get() as $author) {
                if ($author instanceof User) {
                    $authors[(int) $author->getKey()] = (string) $author->getAttribute('name');
                }
            }
        }
        $assignments = ArticleCategoryAssignment::query()->whereIn('article_id', $ids)->get();
        $categoryIds = [];
        $categoriesByArticle = [];
        foreach ($assignments as $assignment) {
            if (!$assignment instanceof ArticleCategoryAssignment) {
                continue;
            }
            $articleId = (int) $assignment->getAttribute('article_id');
            $categoryId = (int) $assignment->getAttribute('article_category_id');
            $categoryIds[] = $categoryId;
            $categoriesByArticle[$articleId][] = $categoryId;
        }
        foreach ($articles as $article) {
            $primaryId = (int) $article->getAttribute('primary_category_id', 0);
            if ($primaryId > 0) {
                $categoryIds[] = $primaryId;
                $categoriesByArticle[(int) $article->getKey()][] = $primaryId;
            }
        }
        $categoriesById = [];
        if ($categoryIds !== []) {
            foreach (ArticleCategory::query()->whereIn('id', array_values(array_unique($categoryIds)))->get() as $category) {
                if ($category instanceof ArticleCategory) {
                    $categoriesById[(int) $category->getKey()] = (string) $category->getAttribute('name');
                }
            }
        }

        foreach ($articles as $article) {
            $articleId = (int) $article->getKey();
            $names = [];
            foreach (array_unique($categoriesByArticle[$articleId] ?? []) as $categoryId) {
                if (isset($categoriesById[$categoryId])) {
                    $names[] = $categoriesById[$categoryId];
                }
            }
            $article->setAttribute('categories', $names);
            $article->setAttribute(
                'category_ids',
                array_values(array_unique(array_map('intval', $categoriesByArticle[$articleId] ?? [])))
            );
            $article->setAttribute('views_count', $views[$articleId] ?? 0);
            $article->setAttribute('author_name', $authors[(int) $article->getAttribute('author_id', 0)] ?? '');
        }
    }

    /** @param list<int> $articleIds
     * @return array<int, int>
     */
    private function viewCounts(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }
        $counts = [];
        foreach (ArticleView::query()->whereIn('article_id', $articleIds)->get() as $view) {
            if ($view instanceof ArticleView) {
                $articleId = (int) $view->getAttribute('article_id');
                $counts[$articleId] = ($counts[$articleId] ?? 0) + (int) $view->getAttribute('view_count', 1);
            }
        }

        return $counts;
    }

    /** @return list<int> */
    private function validatedCategoryIds(mixed $input): array
    {
        if (!is_array($input)) {
            throw new InvalidArgumentException('La sélection des catégories est invalide.');
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => filter_var($value, FILTER_VALIDATE_INT) ?: 0,
            $input
        ), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $found = ArticleCategory::query()->whereIn('id', $ids)->where('status', '=', 'active')->get();
        $foundIds = array_map(static fn(ArticleCategory $category): int => (int) $category->getKey(), $found);
        if (array_diff($ids, $foundIds) !== []) {
            throw new InvalidArgumentException('Une catégorie sélectionnée est introuvable ou inactive.');
        }

        return $ids;
    }

    /** @param list<int> $categoryIds */
    private function replaceCategories(int $articleId, array $categoryIds): void
    {
        ArticleCategoryAssignment::query()->where('article_id', '=', $articleId)->delete();
        foreach ($categoryIds as $categoryId) {
            ArticleCategoryAssignment::create([
                'article_id' => $articleId,
                'article_category_id' => $categoryId,
            ]);
        }
    }

    private static function utf8Length(string $value): int
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Le texte doit être encodé en UTF-8.');
        }

        return strlen((string) preg_replace('/[\x80-\xbf]/', '', $value));
    }
}
