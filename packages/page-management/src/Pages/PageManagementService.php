<?php

declare(strict_types=1);

namespace NM\Services\PageManagement\Pages;

use Core\Cache\CacheInterface;
use Core\Database\Connection;
use Core\Events\EventDispatcher;
use Core\Support\Str;
use InvalidArgumentException;
use NM\Services\PageManagement\Models\ManagedPage;
use NM\Services\PageManagement\Models\PageBlock;
use NM\Services\PageManagement\Models\PageMenu;
use NM\Services\PageManagement\Models\PageMenuItem;
use NM\Services\PageManagement\Models\PageSection;
use NM\Services\PageManagement\Models\PageSectionUsage;
use NM\Services\PageManagement\Models\PageSlugRedirect;
use NM\Services\PageManagement\Models\PageTemplate;
use RuntimeException;

final class PageManagementService
{
    /** @var list<string> */
    private array $reservedSlugs = [
        'admin', 'api', 'assets', 'auth', 'dashboard', 'install', 'login', 'logout',
        'articles', 'catalog', 'products', 'search', 'sitemap.xml',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly PageContentRegistry $registry,
        private readonly ?CacheInterface $cache = null,
        private readonly ?EventDispatcher $events = null,
    ) {
    }

    public function registry(): PageContentRegistry
    {
        return $this->registry;
    }

    /** @param list<string> $slugs */
    public function reserveSlugs(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $slug = $this->normalizeSlug($slug);
            if ($slug !== '') {
                $this->reservedSlugs[] = $slug;
            }
        }
        $this->reservedSlugs = array_values(array_unique($this->reservedSlugs));
    }

    public function generateSlug(string $title, ?int $ignorePageId = null): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('Le titre de la page est requis pour générer un slug.');
        }
        $existing = [];
        foreach (ManagedPage::query()->select('id', 'slug')->get() as $row) {
            if ($row instanceof ManagedPage && (int) $row->getKey() !== $ignorePageId) {
                $existing[] = (string) $row->getAttribute('slug');
            }
        }
        $slug = Str::uniqueSlug($title, [...$existing, ...$this->reservedSlugs]);
        if ($slug === '') {
            throw new InvalidArgumentException('Le titre ne permet pas de générer un slug valide.');
        }

        return $slug;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function savePage(array $input, ?ManagedPage $page = null, ?int $authorId = null, ?int $expectedRevision = null): ManagedPage
    {
        $title = trim((string) ($input['title'] ?? $page?->getAttribute('title') ?? ''));
        if ($title === '' || self::utf8Length($title) > 190) {
            throw new InvalidArgumentException('Le titre est obligatoire et ne doit pas dépasser 190 caractères.');
        }
        if ($expectedRevision !== null && $page !== null
            && (int) $page->getAttribute('revision', 1) !== $expectedRevision) {
            throw new RuntimeException('Cette page a été modifiée par un autre administrateur. Rechargez-la avant de sauvegarder.');
        }

        $rawSlug = trim((string) ($input['slug'] ?? $page?->getAttribute('slug') ?? ''));
        $slug = $rawSlug === '' ? $this->generateSlug($title, $page ? (int) $page->getKey() : null) : $this->normalizeSlug($rawSlug);
        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException('Le slug doit contenir uniquement des minuscules, chiffres et tirets.');
        }
        if (in_array($slug, $this->reservedSlugs, true)) {
            throw new InvalidArgumentException('Ce slug est réservé par une route de l’application.');
        }
        $duplicate = ManagedPage::query()->where('slug', '=', $slug)->first();
        if ($duplicate instanceof ManagedPage && (int) $duplicate->getKey() !== (int) ($page?->getKey() ?? 0)) {
            throw new InvalidArgumentException('Ce slug est déjà utilisé.');
        }

        $status = (string) ($input['status'] ?? $page?->getAttribute('status') ?? 'draft');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new InvalidArgumentException('Le statut de la page est invalide.');
        }
        $visibility = (string) ($input['visibility'] ?? $page?->getAttribute('visibility') ?? 'public');
        if (!in_array($visibility, ['public', 'protected'], true)) {
            throw new InvalidArgumentException('La visibilité doit être publique ou protégée.');
        }
        $layout = (string) ($input['layout'] ?? $page?->getAttribute('layout') ?? 'default');
        if (!$this->registry->hasLayout($layout)) {
            throw new InvalidArgumentException('Le layout demandé n’est pas enregistré.');
        }
        $description = trim((string) ($input['description'] ?? $page?->getAttribute('description') ?? ''));
        if (self::utf8Length($description) > 5000) {
            throw new InvalidArgumentException('La description ne doit pas dépasser 5000 caractères.');
        }

        $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int) $input['parent_id'] : null;
        if ($parentId !== null) {
            $parent = ManagedPage::find($parentId);
            if (!$parent instanceof ManagedPage || ($page !== null && $parentId === (int) $page->getKey())) {
                throw new InvalidArgumentException('La page parente est invalide.');
            }
            $this->assertParentChainDoesNotCycle($parent, $page ? (int) $page->getKey() : null);
        }

        $attributes = [
            'title' => $title,
            'slug' => $slug,
            'description' => $description ?: null,
            'featured_image' => $this->nullableUrl($input['featured_image'] ?? $page?->getAttribute('featured_image')),
            'status' => $status,
            'visibility' => $visibility,
            'parent_id' => $parentId,
            'layout' => $layout,
            'seo_title' => trim((string) ($input['seo_title'] ?? $page?->getAttribute('seo_title') ?? '')) ?: null,
            'seo_description' => trim((string) ($input['seo_description'] ?? $page?->getAttribute('seo_description') ?? '')) ?: null,
            'seo_image' => $this->nullableUrl($input['seo_image'] ?? $page?->getAttribute('seo_image')),
            'canonical_url' => $this->nullableExternalUrl($input['canonical_url'] ?? $page?->getAttribute('canonical_url')),
            'no_index' => $this->boolean($input['no_index'] ?? $page?->getAttribute('no_index') ?? false),
            'show_header' => $this->boolean($input['show_header'] ?? $page?->getAttribute('show_header') ?? true),
            'show_footer' => $this->boolean($input['show_footer'] ?? $page?->getAttribute('show_footer') ?? true),
            'show_announcement_bar' => $this->boolean($input['show_announcement_bar'] ?? $page?->getAttribute('show_announcement_bar') ?? true),
            'add_to_navigation' => $this->boolean($input['add_to_navigation'] ?? $page?->getAttribute('add_to_navigation') ?? false),
            'navigation_label' => trim((string) ($input['navigation_label'] ?? $page?->getAttribute('navigation_label') ?? $title)) ?: $title,
            'navigation_order' => (int) ($input['navigation_order'] ?? $page?->getAttribute('navigation_order') ?? 0),
        ];
        if ($status !== 'published') {
            $attributes['is_homepage'] = false;
            $attributes['homepage_key'] = null;
        }
        if (self::utf8Length((string) $attributes['navigation_label']) > 190
            || $attributes['navigation_order'] < 0
            || $attributes['navigation_order'] > 1_000_000) {
            throw new InvalidArgumentException('Les paramètres de navigation de la page sont invalides.');
        }
        if (self::utf8Length((string) ($attributes['seo_title'] ?? '')) > 255
            || self::utf8Length((string) ($attributes['seo_description'] ?? '')) > 1000) {
            throw new InvalidArgumentException('Les métadonnées SEO sont trop longues.');
        }

        $oldSlug = $page === null ? null : (string) $page->getAttribute('slug');
        $this->connection->transaction(function () use (&$page, $attributes, $authorId, $input, $status, $oldSlug, $slug, $expectedRevision): void {
            if ($page === null) {
                $attributes['author_id'] = $authorId;
                $attributes['published_at'] = $status === 'published' ? date('Y-m-d H:i:s') : null;
                $attributes['revision'] = 1;
                $page = ManagedPage::create($attributes);
            } else {
                $wasPublished = $page->getAttribute('status') === 'published';
                $attributes['published_at'] = $status === 'published'
                    ? ($wasPublished ? $page->getAttribute('published_at') : date('Y-m-d H:i:s'))
                    : null;
                $attributes['revision'] = (int) $page->getAttribute('revision', 1) + 1;
                if ($expectedRevision !== null) {
                    $updated = ManagedPage::query()
                        ->where('id', '=', (int) $page->getKey())
                        ->where('revision', '=', $expectedRevision)
                        ->update($attributes);
                    if ($updated !== 1) {
                        throw new RuntimeException('Cette page a été modifiée par un autre administrateur. Rechargez-la avant de sauvegarder.');
                    }
                    $page = $this->requirePage((int) $page->getKey());
                } else {
                    $page->update($attributes);
                }
            }
            if ($oldSlug !== null && $oldSlug !== $slug) {
                PageSlugRedirect::query()->where('slug', '=', $slug)->delete();
                $redirect = PageSlugRedirect::query()->where('slug', '=', $oldSlug)->first();
                if ($redirect instanceof PageSlugRedirect) {
                    $redirect->update(['page_id' => (int) $page->getKey()]);
                } else {
                    PageSlugRedirect::create(['slug' => $oldSlug, 'page_id' => (int) $page->getKey()]);
                }
            }
            if (array_key_exists('sections', $input)) {
                if (!is_array($input['sections'])) {
                    throw new InvalidArgumentException('La structure des sections doit être un tableau.');
                }
                $this->replacePageSections((int) $page->getKey(), $input['sections'], $authorId);
            }
        });

        $this->invalidate((int) $page->getKey(), $oldSlug, $slug);
        $this->syncPageToNavigation($page);
        $this->dispatch($oldSlug === null ? 'page.created' : 'page.updated', (int) $page->getKey());

        return $page;
    }

    public function publish(int $pageId): ManagedPage
    {
        $page = $this->requirePage($pageId);
        $page->update([
            'status' => 'published',
            'published_at' => $page->getAttribute('published_at') ?: date('Y-m-d H:i:s'),
            'revision' => (int) $page->getAttribute('revision', 1) + 1,
        ]);
        $this->invalidate($pageId, (string) $page->getAttribute('slug'));
        $this->dispatch('page.published', $pageId);

        return $page;
    }

    public function unpublish(int $pageId): ManagedPage
    {
        $page = $this->requirePage($pageId);
        $page->update([
            'status' => 'draft',
            'published_at' => null,
            'is_homepage' => false,
            'homepage_key' => null,
            'revision' => (int) $page->getAttribute('revision', 1) + 1,
        ]);
        $this->invalidate($pageId, (string) $page->getAttribute('slug'));
        $this->dispatch('page.unpublished', $pageId);

        return $page;
    }

    public function archive(int $pageId): ManagedPage
    {
        $page = $this->requirePage($pageId);
        $page->update([
            'status' => 'archived',
            'is_homepage' => false,
            'homepage_key' => null,
            'revision' => (int) $page->getAttribute('revision', 1) + 1,
        ]);
        $this->invalidate($pageId, (string) $page->getAttribute('slug'));
        $this->dispatch('page.archived', $pageId);

        return $page;
    }

    public function designateHomepage(int $pageId): ManagedPage
    {
        $page = $this->requirePage($pageId);
        if ($page->getAttribute('status') !== 'published') {
            throw new InvalidArgumentException('Seule une page publiée peut être désignée comme page d’accueil.');
        }
        $this->connection->transaction(function () use ($pageId): void {
            ManagedPage::query()->whereNotNull('homepage_key')->update([
                'is_homepage' => false,
                'homepage_key' => null,
            ]);
            ManagedPage::query()->where('id', '=', $pageId)->update([
                'is_homepage' => true,
                'homepage_key' => 'default',
                'revision' => (int) $this->requirePage($pageId)->getAttribute('revision', 1) + 1,
            ]);
        });
        $this->invalidate($pageId, (string) $page->getAttribute('slug'));

        return $this->requirePage($pageId);
    }

    public function clearHomepage(): void
    {
        ManagedPage::query()->whereNotNull('homepage_key')->update([
            'is_homepage' => false,
            'homepage_key' => null,
        ]);
        $this->invalidate();
    }

    public function duplicatePage(int $pageId, ?int $authorId = null): ManagedPage
    {
        $page = $this->requirePage($pageId);
        $data = $page->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at'], $data['revision'], $data['is_homepage']);
        $data['title'] = (string) $page->getAttribute('title') . ' (copie)';
        $data['slug'] = '';
        $data['status'] = 'draft';
        $data['sections'] = $this->pageStructure($pageId);

        return $this->savePage($data, authorId: $authorId);
    }

    public function deletePage(int $pageId): void
    {
        $page = $this->requirePage($pageId);
        if (PageMenuItem::query()->where('page_id', '=', $pageId)->first() !== null) {
            throw new InvalidArgumentException('Cette page est utilisée par un menu. Retirez les liens avant sa suppression.');
        }
        if (ManagedPage::query()->where('parent_id', '=', $pageId)->first() !== null) {
            throw new InvalidArgumentException('Cette page a des pages enfants. Réorganisez-les avant sa suppression.');
        }

        $oldSlug = (string) $page->getAttribute('slug');
        $this->connection->transaction(function () use ($pageId): void {
            $ownedSections = PageSection::query()->where('owner_page_id', '=', $pageId)->get();
            PageSectionUsage::query()->where('page_id', '=', $pageId)->delete();
            foreach ($ownedSections as $section) {
                if ($section instanceof PageSection
                    && PageSectionUsage::query()->where('section_id', '=', (int) $section->getKey())->first() === null) {
                    $section->delete();
                }
            }
            $this->requirePage($pageId)->delete();
        });
        $this->invalidate($pageId, $oldSlug);
        $this->dispatch('page.deleted', $pageId);
    }

    public function page(int $pageId): ManagedPage
    {
        return $this->requirePage($pageId);
    }

    /** @return list<ManagedPage> */
    public function pages(array $filters = []): array
    {
        $query = ManagedPage::query();
        if (isset($filters['status']) && in_array($filters['status'], ['draft', 'published', 'archived'], true)) {
            $query->where('status', '=', $filters['status']);
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $query->search(['title', 'slug', 'description'], trim((string) $filters['search']));
        }

        return $query->orderBy('updated_at', 'DESC')->orderBy('id', 'DESC')->limit(100)->get();
    }

    public function publicPage(string $slug = ''): ?ManagedPage
    {
        $query = ManagedPage::query()->where('status', '=', 'published')
            ->where('visibility', '=', 'public')
            ->where(static function ($builder): void {
                $builder->whereNull('published_at')->orWhere('published_at', '<=', date('Y-m-d H:i:s'));
            });
        $page = $slug === '' ? $query->where('is_homepage', '=', true)->first() : $query->where('slug', '=', $slug)->first();

        return $page instanceof ManagedPage ? $page : null;
    }

    /** @return iterable<array{path: string, lastmod: ?string}> */
    public function publicSitemapEntries(): iterable
    {
        $pages = ManagedPage::query()
            ->where('status', '=', 'published')
            ->where('visibility', '=', 'public')
            ->where('no_index', '=', false)
            ->where(static function ($builder): void {
                $builder->whereNull('published_at')->orWhere('published_at', '<=', date('Y-m-d H:i:s'));
            })
            ->orderBy('slug')
            ->get();
        foreach ($pages as $page) {
            if ($page instanceof ManagedPage) {
                yield [
                    'path' => $this->boolean($page->getAttribute('is_homepage'))
                        ? '/'
                        : '/p/' . rawurlencode((string) $page->getAttribute('slug')),
                    'lastmod' => $page->getAttribute('updated_at') === null
                        ? null
                        : (string) $page->getAttribute('updated_at'),
                ];
            }
        }
    }

    /** @return array{page: ManagedPage|null, redirect: ?string} */
    public function resolvePublicPage(string $slug): array
    {
        $page = $this->publicPage($slug);
        if ($page instanceof ManagedPage) {
            return ['page' => $page, 'redirect' => null];
        }
        $redirect = PageSlugRedirect::query()->where('slug', '=', $slug)->first();
        if (!$redirect instanceof PageSlugRedirect) {
            return ['page' => null, 'redirect' => null];
        }
        $target = $this->publicPageForId((int) $redirect->getAttribute('page_id'));

        return [
            'page' => $target,
            'redirect' => $target instanceof ManagedPage ? (string) $target->getAttribute('slug') : null,
        ];
    }

    public function publicRenderData(string $slug = ''): ?array
    {
        if ($this->cache === null || !$this->cache->enabled()) {
            $page = $this->publicPage($slug);

            return $page instanceof ManagedPage ? $this->renderData($page) : null;
        }
        $version = $this->cache->get('page-management.public-version', 0) ?? 0;
        $key = 'page-management.render.' . (int) $version . '.' . ($slug === '' ? '__home__' : $slug);
        $load = function () use ($slug): ?array {
            $page = $this->publicPage($slug);
            return $page instanceof ManagedPage ? $this->renderData($page) : null;
        };

        $result = $this->cache->remember($key, 300, $load);
        return is_array($result) ? $result : null;
    }

    public function previewPage(int $pageId, bool $authorized): ManagedPage
    {
        if (!$authorized) {
            throw new InvalidArgumentException('La prévisualisation nécessite une autorisation.');
        }

        return $this->requirePage($pageId);
    }

    /**
     * Structure normalisée destinée à l'éditeur et au moteur de rendu.
     *
     * @return list<array<string, mixed>>
     */
    public function pageStructure(int $pageId): array
    {
        $usages = PageSectionUsage::query()->where('page_id', '=', $pageId)
            ->orderBy('slot')->orderBy('sort_order')->get();
        $sections = [];
        foreach ($usages as $usage) {
            if (!$usage instanceof PageSectionUsage) {
                continue;
            }
            $section = PageSection::find((int) $usage->getAttribute('section_id'));
            if (!$section instanceof PageSection) {
                continue;
            }
            $blocks = PageBlock::query()->where('section_id', '=', (int) $section->getKey())
                ->orderBy('sort_order')->orderBy('id')->get();
            $sections[] = [
                'id' => (int) $section->getKey(),
                'name' => (string) $section->getAttribute('name'),
                'type' => (string) $section->getAttribute('type'),
                'in_library' => $this->boolean($section->getAttribute('in_library')),
                'library_section_id' => $this->boolean($section->getAttribute('in_library'))
                    ? (int) $section->getKey()
                    : null,
                'settings' => $this->decode($section->getAttribute('settings'), []),
                'usage_mode' => (string) $usage->getAttribute('usage_mode'),
                'slot' => (string) $usage->getAttribute('slot'),
                'sort_order' => (int) $usage->getAttribute('sort_order'),
                'enabled' => $this->boolean($usage->getAttribute('enabled')),
                'blocks' => array_map(static fn(PageBlock $block): array => [
                    'id' => (int) $block->getKey(),
                    'type' => (string) $block->getAttribute('type'),
                    'configuration' => self::decodeStatic($block->getAttribute('configuration'), []),
                    'sort_order' => (int) $block->getAttribute('sort_order'),
                    'enabled' => self::booleanStatic($block->getAttribute('enabled')),
                ], array_values(array_filter($blocks, static fn(mixed $block): bool => $block instanceof PageBlock))),
            ];
        }

        return $sections;
    }

    /**
     * @return array{page: ManagedPage, layout: string, sections: list<array<string, mixed>>, seo: array<string, mixed>}
     */
    public function renderData(ManagedPage $page): array
    {
        return $this->renderStructureData($page, $this->pageStructure((int) $page->getKey()));
    }

    /**
     * Render a working editor structure without saving it or publishing the page.
     *
     * @param array<string, mixed> $input
     * @return array{page: ManagedPage, layout: string, sections: list<array<string, mixed>>, seo: array<string, mixed>}
     */
    public function previewData(array $input, ?int $pageId = null): array
    {
        $page = $pageId === null ? new ManagedPage() : $this->requirePage($pageId);
        $pageAttributes = [
            'id' => $pageId,
            'title' => trim((string) ($input['title'] ?? $page->getAttribute('title', 'Aperçu'))),
            'slug' => (string) ($input['slug'] ?? $page->getAttribute('slug', '')),
            'description' => $input['description'] ?? $page->getAttribute('description'),
            'featured_image' => $input['featured_image'] ?? $page->getAttribute('featured_image'),
            'layout' => $input['layout'] ?? $page->getAttribute('layout', 'default'),
            'seo_title' => $input['seo_title'] ?? $page->getAttribute('seo_title'),
            'seo_description' => $input['seo_description'] ?? $page->getAttribute('seo_description'),
            'seo_image' => $input['seo_image'] ?? $page->getAttribute('seo_image'),
            'canonical_url' => $input['canonical_url'] ?? $page->getAttribute('canonical_url'),
            'no_index' => $input['no_index'] ?? $page->getAttribute('no_index', false),
            'show_header' => $input['show_header'] ?? $page->getAttribute('show_header', true),
            'show_footer' => $input['show_footer'] ?? $page->getAttribute('show_footer', true),
            'show_announcement_bar' => $input['show_announcement_bar'] ?? $page->getAttribute('show_announcement_bar', true),
        ];
        if (!$this->registry->hasLayout((string) $pageAttributes['layout'])) {
            throw new InvalidArgumentException('Le layout demandé n’est pas enregistré.');
        }
        $page = new ManagedPage($pageAttributes);
        $sections = $input['sections'] ?? ($pageId === null ? [] : $this->pageStructure($pageId));
        if (!is_array($sections) || count($sections) > 100) {
            throw new InvalidArgumentException('La structure des sections est invalide.');
        }
        $normalized = [];
        foreach (array_values($sections) as $order => $sectionData) {
            if (!is_array($sectionData)) {
                throw new InvalidArgumentException('Chaque section doit être un objet.');
            }
            if (isset($sectionData['library_section_id'])) {
                $section = PageSection::find((int) $sectionData['library_section_id']);
                if (!$section instanceof PageSection || !$this->boolean($section->getAttribute('in_library'))) {
                    throw new InvalidArgumentException('Une section partagée demandée est introuvable.');
                }
                $normalized[] = [
                    'name' => (string) $section->getAttribute('name'),
                    'type' => (string) $section->getAttribute('type'),
                    'settings' => $this->decode($section->getAttribute('settings'), []),
                    'slot' => (string) ($sectionData['slot'] ?? 'content'),
                    'sort_order' => (int) ($sectionData['sort_order'] ?? $order),
                    'enabled' => $this->boolean($sectionData['enabled'] ?? true),
                    'blocks' => array_map(static fn(PageBlock $block): array => [
                        'type' => (string) $block->getAttribute('type'),
                        'configuration' => self::decodeStatic($block->getAttribute('configuration'), []),
                        'sort_order' => (int) $block->getAttribute('sort_order'),
                        'enabled' => self::booleanStatic($block->getAttribute('enabled')),
                    ], array_values(array_filter(
                        PageBlock::query()->where('section_id', '=', (int) $section->getKey())->orderBy('sort_order')->get(),
                        static fn(mixed $block): bool => $block instanceof PageBlock
                    ))),
                ];
                continue;
            }
            $name = trim((string) ($sectionData['name'] ?? ''));
            $type = (string) ($sectionData['type'] ?? 'content');
            $settings = $sectionData['settings'] ?? [];
            $blocks = $sectionData['blocks'] ?? [];
            if (!is_array($settings) || !is_array($blocks)) {
                throw new InvalidArgumentException('La configuration d’une section ou de ses blocs est invalide.');
            }
            $this->validateSection($name, $type, $settings, $blocks);
            $normalized[] = [
                'name' => $name,
                'type' => $type,
                'settings' => $settings,
                'slot' => (string) ($sectionData['slot'] ?? 'content'),
                'sort_order' => (int) ($sectionData['sort_order'] ?? $order),
                'enabled' => $this->boolean($sectionData['enabled'] ?? true),
                'blocks' => $blocks,
            ];
        }

        return $this->renderStructureData($page, $normalized);
    }

    /**
     * @param list<array<string, mixed>> $structure
     * @return array{page: ManagedPage, layout: string, sections: list<array<string, mixed>>, seo: array<string, mixed>}
     */
    private function renderStructureData(ManagedPage $page, array $structure): array
    {
        $sections = [];
        foreach ($structure as $section) {
            if (!$section['enabled']) {
                continue;
            }
            if (($section['slot'] === 'header' && !$this->boolean($page->getAttribute('show_header')))
                || ($section['slot'] === 'footer' && !$this->boolean($page->getAttribute('show_footer')))
                || (in_array($section['slot'], ['announcement', 'announcement_bar'], true)
                    && !$this->boolean($page->getAttribute('show_announcement_bar')))) {
                continue;
            }
            $blocks = [];
            foreach ($section['blocks'] as $block) {
                $blockEnabled = $this->boolean($block['enabled'] ?? true);
                if (!$blockEnabled) {
                    continue;
                }
                $block['html'] = $this->registry->renderBlock($block['type'], $block['configuration'], [
                    'page' => $page,
                    'section' => $section,
                ]);
                $blocks[] = $block;
            }
            $section['blocks'] = $blocks;
            $sections[] = $section;
        }

        return [
            'page' => $page,
            'layout' => (string) $page->getAttribute('layout'),
            'sections' => $sections,
            'seo' => [
                'title' => $page->getAttribute('seo_title') ?: $page->getAttribute('title'),
                'description' => $page->getAttribute('seo_description') ?: $page->getAttribute('description'),
                'image' => $page->getAttribute('seo_image') ?: $page->getAttribute('featured_image'),
                'canonical' => $page->getAttribute('canonical_url'),
                'no_index' => $this->boolean($page->getAttribute('no_index')),
            ],
        ];
    }

    public function createLibrarySection(
        string $name,
        string $type,
        array $settings,
        array $blocks,
        ?int $authorId = null,
    ): PageSection {
        $name = trim($name);
        $this->validateSection($name, $type, $settings, $blocks);

        $section = $this->connection->transaction(function () use ($name, $type, $settings, $blocks, $authorId): PageSection {
            $section = PageSection::create([
                'name' => $name,
                'type' => $type,
                'settings' => $this->encode($settings),
                'in_library' => true,
                'owner_page_id' => null,
                'created_by' => $authorId,
            ]);
            $this->saveBlocks((int) $section->getKey(), $blocks);

            return $section;
        });
        $this->dispatch('section.created', context: ['section_id' => (int) $section->getKey()]);

        return $section;
    }

    public function saveLibrarySection(int $sectionId, string $name, array $settings, array $blocks): PageSection
    {
        $section = PageSection::find($sectionId);
        if (!$section instanceof PageSection || !$this->boolean($section->getAttribute('in_library'))) {
            throw new InvalidArgumentException('La section de bibliothèque est introuvable.');
        }
        $type = (string) $section->getAttribute('type');
        $this->validateSection(trim($name), $type, $settings, $blocks);
        $this->connection->transaction(function () use ($section, $name, $settings, $blocks): void {
            $section->update(['name' => trim($name), 'settings' => $this->encode($settings)]);
            $this->saveBlocks((int) $section->getKey(), $blocks);
        });
        foreach (PageSectionUsage::query()->where('section_id', '=', $sectionId)->get() as $usage) {
            if ($usage instanceof PageSectionUsage) {
                $this->invalidate((int) $usage->getAttribute('page_id'));
            }
        }
        $this->dispatch('section.updated', context: ['section_id' => $sectionId]);

        return $section;
    }

    public function deleteLibrarySection(int $sectionId): void
    {
        $section = PageSection::find($sectionId);
        if (!$section instanceof PageSection || !$this->boolean($section->getAttribute('in_library'))) {
            throw new InvalidArgumentException('La section de bibliothèque est introuvable.');
        }
        if (PageSectionUsage::query()->where('section_id', '=', $sectionId)->first() !== null) {
            throw new InvalidArgumentException('Cette section est utilisée par une page. Retirez ses références avant sa suppression.');
        }
        $section->delete();
        $this->dispatch('section.deleted', context: ['section_id' => $sectionId]);
    }

    /** @return list<array<string, mixed>> */
    public function librarySections(): array
    {
        $sections = [];
        foreach (PageSection::query()->where('in_library', '=', true)->orderBy('name')->get() as $section) {
            if (!$section instanceof PageSection) {
                continue;
            }
            $usages = PageSectionUsage::query()->where('section_id', '=', (int) $section->getKey())->get();
            $sections[] = [
                'id' => (int) $section->getKey(),
                'name' => (string) $section->getAttribute('name'),
                'type' => (string) $section->getAttribute('type'),
                'settings' => $this->decode($section->getAttribute('settings'), []),
                'usage_count' => count($usages),
                'usage_pages' => array_values(array_filter(array_map(function (PageSectionUsage $usage): ?array {
                    $page = ManagedPage::find((int) $usage->getAttribute('page_id'));

                    return $page instanceof ManagedPage ? [
                        'id' => (int) $page->getKey(),
                        'title' => (string) $page->getAttribute('title'),
                    ] : null;
                }, array_values(array_filter($usages, static fn(mixed $usage): bool => $usage instanceof PageSectionUsage))))),
                'blocks' => array_map(static fn(PageBlock $block): array => [
                    'type' => (string) $block->getAttribute('type'),
                    'configuration' => self::decodeStatic($block->getAttribute('configuration'), []),
                    'sort_order' => (int) $block->getAttribute('sort_order'),
                    'enabled' => self::booleanStatic($block->getAttribute('enabled')),
                ], array_values(array_filter(
                    PageBlock::query()->where('section_id', '=', (int) $section->getKey())->orderBy('sort_order')->get(),
                    static fn(mixed $block): bool => $block instanceof PageBlock
                ))),
            ];
        }

        return $sections;
    }

    public function addSectionToPage(
        int $pageId,
        int $sectionId,
        string $mode,
        string $slot = 'content',
        int $order = 0,
        bool $enabled = true,
    ): PageSectionUsage
    {
        $page = $this->requirePage($pageId);
        $section = PageSection::find($sectionId);
        if (!$section instanceof PageSection || !$this->boolean($section->getAttribute('in_library'))) {
            throw new InvalidArgumentException('La section à réutiliser est introuvable dans la bibliothèque.');
        }
        if (!in_array($mode, ['reference', 'copy'], true)) {
            throw new InvalidArgumentException('Le mode doit être reference ou copy.');
        }
        $this->validateSlot($slot);

        return $this->connection->transaction(function () use ($page, $section, $mode, $slot, $order, $enabled): PageSectionUsage {
            $target = $section;
            if ($mode === 'copy') {
                $target = $this->cloneSection($section, (int) $page->getKey());
            }

            return PageSectionUsage::create([
                'page_id' => (int) $page->getKey(),
                'section_id' => (int) $target->getKey(),
                'usage_mode' => $mode,
                'slot' => $slot,
                'sort_order' => $order,
                'enabled' => $enabled,
            ]);
        });
    }

    public function removeSectionFromPage(int $pageId, int $usageId): void
    {
        $usage = PageSectionUsage::query()->where('id', '=', $usageId)->where('page_id', '=', $pageId)->first();
        if (!$usage instanceof PageSectionUsage) {
            throw new InvalidArgumentException('La section n’est pas rattachée à cette page.');
        }
        $sectionId = (int) $usage->getAttribute('section_id');
        $usage->delete();
        $section = PageSection::find($sectionId);
        if ($section instanceof PageSection && !$this->boolean($section->getAttribute('in_library'))
            && (int) ($section->getAttribute('owner_page_id') ?? 0) === $pageId
            && PageSectionUsage::query()->where('section_id', '=', $sectionId)->first() === null) {
            $section->delete();
        }
        $this->invalidate($pageId);
    }

    public function createTemplateFromPage(int $pageId, string $name, string $description = '', string $category = ''): PageTemplate
    {
        $page = $this->requirePage($pageId);
        $name = trim($name);
        if ($name === '' || self::utf8Length($name) > 190) {
            throw new InvalidArgumentException('Le nom du template est obligatoire (190 caractères maximum).');
        }

        return PageTemplate::create([
            'name' => $name,
            'description' => trim($description) ?: null,
            'category' => trim($category) ?: null,
            'origin' => 'custom',
            'developer_key' => null,
            'structure' => $this->encode($this->pageStructure((int) $page->getKey())),
            'settings' => $this->encode(['layout' => $page->getAttribute('layout')]),
        ]);
    }

    public function createPageFromTemplate(string $template, string $title, ?int $authorId = null): ManagedPage
    {
        $data = null;
        if (str_starts_with($template, 'developer:')) {
            $data = $this->registry->developerTemplate(substr($template, 10));
        } elseif (ctype_digit($template)) {
            $stored = PageTemplate::find((int) $template);
            if ($stored instanceof PageTemplate && $stored->getAttribute('origin') === 'custom') {
                $data = [
                    'structure' => $this->decode($stored->getAttribute('structure'), []),
                    'settings' => $this->decode($stored->getAttribute('settings'), []),
                ];
            }
        }
        if (!is_array($data) || !is_array($data['structure'] ?? null)) {
            throw new InvalidArgumentException('Le template demandé est introuvable.');
        }

        return $this->savePage([
            'title' => $title,
            'layout' => (string) ($data['settings']['layout'] ?? 'default'),
            'status' => 'draft',
            'sections' => $this->templateSections($data['structure']),
        ], authorId: $authorId);
    }

    public function updateTemplate(
        int $templateId,
        string $name,
        string $description = '',
        string $category = '',
        ?array $structure = null,
        ?array $settings = null,
    ): PageTemplate
    {
        $template = PageTemplate::find($templateId);
        if (!$template instanceof PageTemplate || $template->getAttribute('origin') !== 'custom') {
            throw new InvalidArgumentException('Seuls les templates personnalisés peuvent être modifiés.');
        }
        $name = trim($name);
        if ($name === '' || self::utf8Length($name) > 190) {
            throw new InvalidArgumentException('Le nom du template est obligatoire (190 caractères maximum).');
        }
        $attributes = [
            'name' => $name,
            'description' => trim($description) ?: null,
            'category' => trim($category) ?: null,
        ];
        if ($structure !== null) {
            $normalized = $this->templateSections(['sections' => $structure]);
            foreach ($normalized as $section) {
                $this->validateSection(
                    trim((string) $section['name']),
                    (string) $section['type'],
                    $section['settings'],
                    $section['blocks'],
                );
            }
            $attributes['structure'] = $this->encode(['sections' => $normalized]);
        }
        if ($settings !== null) {
            $attributes['settings'] = $this->encode($settings);
        }
        $template->update($attributes);

        return $template;
    }

    /**
     * @param list<array<string, mixed>> $structure
     * @param array<string, mixed> $settings
     */
    public function saveTemplateStructure(int $templateId, array $structure, array $settings = []): PageTemplate
    {
        $template = PageTemplate::find($templateId);
        if (!$template instanceof PageTemplate || $template->getAttribute('origin') !== 'custom') {
            throw new InvalidArgumentException('Seuls les templates personnalisés peuvent être modifiés.');
        }
        $normalized = $this->templateSections(['sections' => $structure]);
        foreach ($normalized as $section) {
            $this->validateSection(
                trim((string) $section['name']),
                (string) $section['type'],
                $section['settings'],
                $section['blocks'],
            );
        }
        $template->update([
            'structure' => $this->encode(['sections' => $normalized]),
            'settings' => $this->encode($settings),
        ]);
        $this->invalidate();

        return $template;
    }

    public function duplicateTemplate(int $templateId, string $name): PageTemplate
    {
        $template = PageTemplate::find($templateId);
        if (!$template instanceof PageTemplate || $template->getAttribute('origin') !== 'custom') {
            throw new InvalidArgumentException('Seuls les templates personnalisés peuvent être dupliqués.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Le nom du template est obligatoire.');
        }

        return PageTemplate::create([
            'name' => $name,
            'description' => $template->getAttribute('description'),
            'category' => $template->getAttribute('category'),
            'origin' => 'custom',
            'developer_key' => null,
            'structure' => $template->getAttribute('structure'),
            'settings' => $template->getAttribute('settings'),
        ]);
    }

    public function deleteTemplate(int $templateId): void
    {
        $template = PageTemplate::find($templateId);
        if (!$template instanceof PageTemplate || $template->getAttribute('origin') !== 'custom') {
            throw new InvalidArgumentException('Seuls les templates personnalisés peuvent être supprimés.');
        }
        $template->delete();
    }

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        $templates = [];
        foreach ($this->registry->developerTemplates() as $template) {
            $templates[] = [...$template, 'origin' => 'developer'];
        }
        foreach (PageTemplate::query()->where('origin', '=', 'custom')->orderBy('name')->get() as $template) {
            if ($template instanceof PageTemplate) {
                $templates[] = [
                    'id' => (int) $template->getKey(),
                    'name' => (string) $template->getAttribute('name'),
                    'description' => (string) $template->getAttribute('description', ''),
                    'category' => (string) $template->getAttribute('category', ''),
                    'origin' => 'custom',
                    'structure' => $this->decode($template->getAttribute('structure'), []),
                    'settings' => $this->decode($template->getAttribute('settings'), []),
                ];
            }
        }

        return $templates;
    }

    public function createMenu(string $name, string $slug, ?string $location = null): PageMenu
    {
        $name = trim($name);
        $slug = trim($slug) === '' ? Str::slug($name) : $this->normalizeSlug($slug);
        if ($name === '' || $slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException('Le nom et le slug du menu sont obligatoires.');
        }
        if (PageMenu::query()->where('slug', '=', $slug)->first() !== null) {
            throw new InvalidArgumentException('Ce slug de menu est déjà utilisé.');
        }

        $menu = PageMenu::create(['name' => $name, 'slug' => $slug, 'location' => trim((string) $location) ?: null]);
        $this->invalidate();
        $this->dispatch('navigation.updated', context: ['menu_id' => (int) $menu->getKey()]);

        return $menu;
    }

    public function updateMenu(int $menuId, string $name, string $slug, ?string $location = null): PageMenu
    {
        $menu = PageMenu::find($menuId);
        if (!$menu instanceof PageMenu) {
            throw new InvalidArgumentException('Le menu demandé est introuvable.');
        }
        $name = trim($name);
        $slug = trim($slug) === '' ? Str::slug($name) : $this->normalizeSlug($slug);
        if ($name === '' || $slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException('Le nom et le slug du menu sont obligatoires.');
        }
        $existing = PageMenu::query()->where('slug', '=', $slug)->first();
        if ($existing instanceof PageMenu && (int) $existing->getKey() !== $menuId) {
            throw new InvalidArgumentException('Ce slug de menu est déjà utilisé.');
        }
        $menu->update(['name' => $name, 'slug' => $slug, 'location' => trim((string) $location) ?: null]);
        $this->invalidate();
        $this->dispatch('navigation.updated', context: ['menu_id' => $menuId]);

        return $menu;
    }

    public function deleteMenu(int $menuId): void
    {
        $menu = PageMenu::find($menuId);
        if (!$menu instanceof PageMenu) {
            throw new InvalidArgumentException('Le menu demandé est introuvable.');
        }
        $menu->delete();
        $this->invalidate();
        $this->dispatch('navigation.updated', context: ['menu_id' => $menuId]);
    }

    /** @param array<string, mixed> $data */
    public function saveMenuItem(int $menuId, array $data, ?int $itemId = null): PageMenuItem
    {
        $menu = PageMenu::find($menuId);
        if (!$menu instanceof PageMenu) {
            throw new InvalidArgumentException('Le menu demandé est introuvable.');
        }
        $label = trim((string) ($data['label'] ?? ''));
        $type = (string) ($data['type'] ?? 'custom');
        if ($label === '' || self::utf8Length($label) > 190 || !in_array($type, ['page', 'external', 'custom'], true)) {
            throw new InvalidArgumentException('Les données de l’élément de menu sont invalides.');
        }
        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null;
        if ($parentId !== null) {
            $parent = PageMenuItem::query()->where('id', '=', $parentId)->where('menu_id', '=', $menuId)->first();
            if (!$parent instanceof PageMenuItem || $parentId === $itemId) {
                throw new InvalidArgumentException('L’élément parent du menu est invalide.');
            }
            $this->assertMenuParentChain($parent, $menuId, $itemId);
        }
        $pageId = isset($data['page_id']) && $data['page_id'] !== '' ? (int) $data['page_id'] : null;
        $destination = trim((string) ($data['destination'] ?? ''));
        if ($type === 'page') {
            if ($pageId === null || !ManagedPage::find($pageId) instanceof ManagedPage) {
                throw new InvalidArgumentException('La destination de type page doit référencer une page existante.');
            }
            $destination = '';
        } else {
            $pageId = null;
            $destination = $this->validatedLink($destination, $type === 'external');
        }
        $target = (string) ($data['target'] ?? '_self');
        if (!in_array($target, ['_self', '_blank'], true)) {
            throw new InvalidArgumentException('La cible du lien est invalide.');
        }
        $attributes = [
            'menu_id' => $menuId,
            'parent_id' => $parentId,
            'page_id' => $pageId,
            'label' => $label,
            'type' => $type,
            'destination' => $destination ?: null,
            'icon' => trim((string) ($data['icon'] ?? '')) ?: null,
            'target' => $target,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'active' => $this->boolean($data['active'] ?? true),
        ];
        $item = $itemId === null ? null : PageMenuItem::query()->where('id', '=', $itemId)->where('menu_id', '=', $menuId)->first();
        if ($itemId !== null && !$item instanceof PageMenuItem) {
            throw new InvalidArgumentException('L’élément de menu à modifier est introuvable.');
        }
        if ($item instanceof PageMenuItem) {
            $item->update($attributes);
        } else {
            $item = PageMenuItem::create($attributes);
        }
        $this->invalidate();
        $this->dispatch('navigation.updated', context: ['menu_id' => $menuId]);

        return $item;
    }

    public function deleteMenuItem(int $menuId, int $itemId): void
    {
        $item = PageMenuItem::query()->where('id', '=', $itemId)->where('menu_id', '=', $menuId)->first();
        if (!$item instanceof PageMenuItem) {
            throw new InvalidArgumentException('L’élément de menu est introuvable.');
        }
        if (PageMenuItem::query()->where('parent_id', '=', $itemId)->first() !== null) {
            throw new InvalidArgumentException('Cet élément possède des sous-menus. Supprimez ou déplacez-les d’abord.');
        }
        $item->delete();
        $this->invalidate();
        $this->dispatch('navigation.updated', context: ['menu_id' => $menuId]);
    }

    /** @return list<array<string, mixed>> */
    public function menuTree(string $slug): array
    {
        $menu = PageMenu::query()->where('slug', '=', $slug)->first();
        if (!$menu instanceof PageMenu) {
            return [];
        }
        $items = PageMenuItem::query()->where('menu_id', '=', (int) $menu->getKey())
            ->orderBy('sort_order')->orderBy('id')->get();
        $byParent = [];
        foreach ($items as $item) {
            if ($item instanceof PageMenuItem && $this->boolean($item->getAttribute('active'))) {
                if ($item->getAttribute('page_id') !== null) {
                    $targetPage = $this->publicPageForId((int) $item->getAttribute('page_id'));
                    if (!$targetPage instanceof ManagedPage) {
                        continue;
                    }
                }
                $parent = $item->getAttribute('parent_id') === null ? 0 : (int) $item->getAttribute('parent_id');
                $byParent[$parent][] = $item;
            }
        }
        return $this->buildMenuTree($byParent, 0, []);
    }

    /** @return list<array<string, mixed>> */
    public function menus(): array
    {
        return array_map(function (PageMenu $menu): array {
            $items = PageMenuItem::query()->where('menu_id', '=', (int) $menu->getKey())
                ->orderBy('sort_order')->orderBy('id')->get();

            return [
                'id' => (int) $menu->getKey(),
                'name' => (string) $menu->getAttribute('name'),
                'slug' => (string) $menu->getAttribute('slug'),
                'location' => $menu->getAttribute('location') === null ? null : (string) $menu->getAttribute('location'),
                'items' => array_map(
                    static fn(PageMenuItem $item): array => $item->toArray(),
                    array_values(array_filter($items, static fn(mixed $item): bool => $item instanceof PageMenuItem)),
                ),
            ];
        }, array_values(array_filter(PageMenu::query()->orderBy('name')->get(), static fn(mixed $menu): bool => $menu instanceof PageMenu)));
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private function replacePageSections(int $pageId, array $sections, ?int $authorId): void
    {
        if (count($sections) > 100) {
            throw new InvalidArgumentException('Une page ne peut pas contenir plus de 100 sections.');
        }
        foreach (PageSectionUsage::query()->where('page_id', '=', $pageId)->get() as $usage) {
            if ($usage instanceof PageSectionUsage) {
                $this->removeSectionFromPage($pageId, (int) $usage->getKey());
            }
        }
        foreach (array_values($sections) as $order => $sectionData) {
            if (!is_array($sectionData)) {
                throw new InvalidArgumentException('Chaque section doit être un objet.');
            }
            if (isset($sectionData['library_section_id'])) {
                $this->addSectionToPage(
                    $pageId,
                    (int) $sectionData['library_section_id'],
                    (string) ($sectionData['usage_mode'] ?? 'reference'),
                    (string) ($sectionData['slot'] ?? 'content'),
                    (int) ($sectionData['sort_order'] ?? $order),
                    $this->boolean($sectionData['enabled'] ?? true),
                );
                continue;
            }
            $name = trim((string) ($sectionData['name'] ?? ''));
            $type = (string) ($sectionData['type'] ?? 'content');
            $settings = $sectionData['settings'] ?? [];
            $blocks = $sectionData['blocks'] ?? [];
            if (!is_array($settings) || !is_array($blocks)) {
                throw new InvalidArgumentException('La configuration d’une section ou de ses blocs est invalide.');
            }
            $this->validateSection($name, $type, $settings, $blocks);
            $section = PageSection::create([
                'name' => $name,
                'type' => $type,
                'settings' => $this->encode($settings),
                'in_library' => false,
                'owner_page_id' => $pageId,
                'created_by' => $authorId,
            ]);
            $this->saveBlocks((int) $section->getKey(), $blocks);
            PageSectionUsage::create([
                'page_id' => $pageId,
                'section_id' => (int) $section->getKey(),
                'usage_mode' => 'copy',
                'slot' => (string) ($sectionData['slot'] ?? 'content'),
                'sort_order' => (int) ($sectionData['sort_order'] ?? $order),
                'enabled' => $this->boolean($sectionData['enabled'] ?? true),
            ]);
        }
    }

    /** @param list<array<string, mixed>> $blocks */
    private function saveBlocks(int $sectionId, array $blocks): void
    {
        if (count($blocks) > 200) {
            throw new InvalidArgumentException('Une section ne peut pas contenir plus de 200 blocs.');
        }
        PageBlock::query()->where('section_id', '=', $sectionId)->delete();
        foreach (array_values($blocks) as $order => $block) {
            if (!is_array($block)) {
                throw new InvalidArgumentException('Chaque bloc doit être un objet.');
            }
            $type = (string) ($block['type'] ?? '');
            $configuration = $block['configuration'] ?? [];
            if (!is_array($configuration)) {
                throw new InvalidArgumentException('La configuration d’un bloc doit être un objet.');
            }
            $normalized = $this->registry->validateBlock($type, $configuration);
            PageBlock::create([
                'section_id' => $sectionId,
                'type' => $type,
                'configuration' => $this->encode($normalized),
                'sort_order' => (int) ($block['sort_order'] ?? $order),
                'enabled' => $this->boolean($block['enabled'] ?? true),
            ]);
        }
    }

    /** @param list<array<string, mixed>> $blocks */
    private function validateSection(string $name, string $type, array $settings, array $blocks): void
    {
        if ($name === '' || self::utf8Length($name) > 190 || !$this->registry->hasSection($type)) {
            throw new InvalidArgumentException('Le nom ou le type de section est invalide.');
        }
        foreach ($blocks as $block) {
            if (!is_array($block) || !is_array($block['configuration'] ?? [])) {
                throw new InvalidArgumentException('La configuration d’un bloc est invalide.');
            }
            $this->registry->validateBlock((string) ($block['type'] ?? ''), $block['configuration'] ?? []);
        }
        $this->encode($settings);
    }

    private function cloneSection(PageSection $section, int $ownerPageId): PageSection
    {
        $clone = PageSection::create([
            'name' => $section->getAttribute('name'),
            'type' => $section->getAttribute('type'),
            'settings' => $section->getAttribute('settings'),
            'in_library' => false,
            'owner_page_id' => $ownerPageId,
            'created_by' => $section->getAttribute('created_by'),
        ]);
        foreach (PageBlock::query()->where('section_id', '=', (int) $section->getKey())->orderBy('sort_order')->get() as $block) {
            if ($block instanceof PageBlock) {
                PageBlock::create([
                    'section_id' => (int) $clone->getKey(),
                    'type' => $block->getAttribute('type'),
                    'configuration' => $block->getAttribute('configuration'),
                    'sort_order' => $block->getAttribute('sort_order'),
                    'enabled' => $block->getAttribute('enabled'),
                ]);
            }
        }

        return $clone;
    }

    /** @param array<string, mixed> $structure
     *  @return list<array<string, mixed>>
     */
    private function templateSections(array $structure): array
    {
        $sections = $structure['sections'] ?? $structure;
        if (!is_array($sections)) {
            throw new InvalidArgumentException('La structure du template est invalide.');
        }
        $result = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                throw new InvalidArgumentException('La structure du template est invalide.');
            }
            $result[] = [
                'name' => $section['name'] ?? '',
                'type' => $section['type'] ?? 'content',
                'settings' => is_array($section['settings'] ?? null) ? $section['settings'] : [],
                'blocks' => is_array($section['blocks'] ?? null) ? $section['blocks'] : [],
                'slot' => $section['slot'] ?? 'content',
                'sort_order' => $section['sort_order'] ?? count($result),
                'enabled' => $section['enabled'] ?? true,
            ];
        }

        return $result;
    }

    /** @param array<string, list<PageMenuItem>> $byParent
     *  @param list<int> $ancestors
     *  @return list<array<string, mixed>>
     */
    private function buildMenuTree(array $byParent, int $parentId, array $ancestors): array
    {
        $tree = [];
        foreach ($byParent[$parentId] ?? [] as $item) {
            $id = (int) $item->getKey();
            if (in_array($id, $ancestors, true)) {
                continue;
            }
            $page = $item->getAttribute('page_id') === null ? null : ManagedPage::find((int) $item->getAttribute('page_id'));
            $tree[] = [
                'id' => $id,
                'label' => (string) $item->getAttribute('label'),
                'type' => (string) $item->getAttribute('type'),
                'destination' => $page instanceof ManagedPage
                    ? ($this->boolean($page->getAttribute('is_homepage')) ? '/' : '/p/' . $page->getAttribute('slug'))
                    : $item->getAttribute('destination'),
                'target' => (string) $item->getAttribute('target'),
                'rel' => $item->getAttribute('target') === '_blank' ? 'noopener noreferrer' : null,
                'icon' => $item->getAttribute('icon'),
                'children' => $this->buildMenuTree($byParent, $id, [...$ancestors, $id]),
            ];
        }

        return $tree;
    }

    private function assertParentChainDoesNotCycle(ManagedPage $parent, ?int $pageId): void
    {
        $visited = [];
        $current = $parent;
        while (true) {
            $id = (int) $current->getKey();
            if ($id === $pageId || isset($visited[$id])) {
                throw new InvalidArgumentException('La hiérarchie des pages ne peut pas contenir de cycle.');
            }
            $visited[$id] = true;
            $next = $current->getAttribute('parent_id');
            if ($next === null) {
                return;
            }
            $current = ManagedPage::find((int) $next);
            if (!$current instanceof ManagedPage) {
                return;
            }
        }
    }

    private function assertMenuParentChain(PageMenuItem $parent, int $menuId, ?int $itemId): void
    {
        $visited = [];
        $current = $parent;
        while (true) {
            $id = (int) $current->getKey();
            if ($id === $itemId || isset($visited[$id])) {
                throw new InvalidArgumentException('La hiérarchie du menu ne peut pas contenir de cycle.');
            }
            $visited[$id] = true;
            $nextId = $current->getAttribute('parent_id');
            if ($nextId === null) {
                return;
            }
            $next = PageMenuItem::query()->where('id', '=', (int) $nextId)->where('menu_id', '=', $menuId)->first();
            if (!$next instanceof PageMenuItem) {
                return;
            }
            $current = $next;
        }
    }

    private function requirePage(int $pageId): ManagedPage
    {
        $page = ManagedPage::find($pageId);
        if (!$page instanceof ManagedPage) {
            throw new InvalidArgumentException('La page demandée est introuvable.');
        }

        return $page;
    }

    private function publicPageForId(int $pageId): ?ManagedPage
    {
        $page = ManagedPage::query()->where('id', '=', $pageId)
            ->where('status', '=', 'published')
            ->where('visibility', '=', 'public')
            ->where(static function ($builder): void {
                $builder->whereNull('published_at')->orWhere('published_at', '<=', date('Y-m-d H:i:s'));
            })
            ->first();

        return $page instanceof ManagedPage ? $page : null;
    }

    private function nullableUrl(mixed $value): ?string
    {
        $url = trim((string) ($value ?? ''));
        return $url === '' ? null : $this->validatedLink($url, false);
    }

    private function nullableExternalUrl(mixed $value): ?string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('L’URL canonique doit être une URL HTTP(S) absolue.');
        }

        return $url;
    }

    private function validatedLink(string $url, bool $mustBeExternal): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $local = str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\');
        $external = is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
        if (($mustBeExternal && !$external) || (!$mustBeExternal && !$local && !$external)) {
            throw new InvalidArgumentException('L’URL de destination est invalide.');
        }

        return $url;
    }

    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    private function validateSlot(string $slot): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,99}$/', $slot)) {
            throw new InvalidArgumentException('L’emplacement de section est invalide.');
        }
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decode(mixed $value, array $default): array
    {
        return self::decodeStatic($value, $default);
    }

    private static function decodeStatic(mixed $value, array $default): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    private function boolean(mixed $value): bool
    {
        return self::booleanStatic($value);
    }

    private static function booleanStatic(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private static function utf8Length(string $value): int
    {
        return preg_match_all('/./us', $value, $matches) === false ? strlen($value) : count($matches[0]);
    }

    private function invalidate(?int $pageId = null, ?string ...$slugs): void
    {
        if ($this->cache === null || !$this->cache->enabled()) {
            return;
        }
        foreach ($slugs as $slug) {
            if ($slug !== null && $slug !== '') {
                $this->cache->delete('page-management.public.' . $slug);
            }
        }
        if ($pageId !== null) {
            $this->cache->delete('page-management.page.' . $pageId);
        }
        $this->cache->increment('page-management.public-version');
    }

    private function syncPageToNavigation(ManagedPage $page): void
    {
        $menu = PageMenu::query()->where('slug', '=', 'primary')->first();
        if (!$menu instanceof PageMenu) {
            return;
        }
        $pageId = (int) $page->getKey();
        $item = PageMenuItem::query()->where('menu_id', '=', (int) $menu->getKey())
            ->where('page_id', '=', $pageId)
            ->first();
        $attributes = [
            'menu_id' => (int) $menu->getKey(),
            'page_id' => $pageId,
            'parent_id' => null,
            'label' => (string) ($page->getAttribute('navigation_label') ?: $page->getAttribute('title')),
            'type' => 'page',
            'destination' => null,
            'sort_order' => (int) $page->getAttribute('navigation_order', 0),
            'target' => '_self',
            'active' => $this->boolean($page->getAttribute('add_to_navigation')),
        ];
        if ($item instanceof PageMenuItem) {
            $item->update($attributes);
        } elseif ($attributes['active']) {
            PageMenuItem::create($attributes);
        }
        $this->invalidate();
        $this->dispatch('navigation.updated', context: ['menu_id' => (int) $menu->getKey()]);
    }

    private function dispatch(string $name, ?int $pageId = null, array $context = []): void
    {
        $this->events?->dispatch(new PageManagementEvent($name, $pageId, $context));
    }
}
