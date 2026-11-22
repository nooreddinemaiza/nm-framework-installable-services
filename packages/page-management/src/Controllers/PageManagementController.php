<?php

declare(strict_types=1);

namespace NM\Services\PageManagement\Controllers;

use App\Helpers\NavbarFactory;
use App\Models\User;
use Core\Exceptions\Http\GateException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Http\RedirectResponse;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Security\CsrfTokenManager;
use Core\View\ViewFactory;
use InvalidArgumentException;
use NM\Services\PageManagement\Models\ManagedPage;
use NM\Services\PageManagement\Pages\PageManagementService;
use NM\Services\PageManagement\Services\PageManagementState;
use RuntimeException;

final class PageManagementController extends Controller
{
    public function __construct(
        private readonly PageManagementService $pages,
        private readonly ViewFactory $views,
        private readonly SessionInterface $session,
        private readonly PageManagementState $serviceState,
    ) {
    }

    public function index(): Response
    {
        $user = $this->requireUser('pages.view');
        $this->assertEnabled();
        $permissions = $user->effectivePermissions('string');
        $pageData = [
            'items' => array_map(static fn(ManagedPage $page): array => $page->toArray(), $this->pages->pages()),
            'permissions' => $permissions,
            'layouts' => $this->pages->registry()->layouts(),
            'blocks' => $this->pages->registry()->blocks(),
            'sections' => $this->pages->registry()->sections(),
            'section_examples' => $this->pages->registry()->sectionExamples(),
            'templates' => $this->pages->templates(),
            'library' => $this->pages->librarySections(),
            'csrfToken' => (new CsrfTokenManager($this->session, self::TOKEN_NAME))->generateToken(),
        ];

        return new Response($this->views->renderWithLayout('app', 'dashboard', [
            'title' => 'Gestion des pages',
            'csrf_token' => $pageData['csrfToken'],
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user, 'pages'),
            'admin_permissions' => $permissions,
            'errors' => [],
            'content_name' => 'dashboard.page-management',
            'activeNavTab' => 'pages',
            'showHome' => true,
            'breadcrumbs' => [['label' => 'Gestion des pages']],
            'page_management_data' => $pageData,
        ]));
    }

    public function data(Request $request): Response
    {
        $this->requireUser('pages.view');
        $this->assertEnabled();

        return $this->jsonResponse([
            'success' => true,
            'data' => array_map(
                static fn(ManagedPage $page): array => $page->toArray(),
                $this->pages->pages([
                    'status' => (string) $request->input('status', ''),
                    'search' => (string) $request->input('search', ''),
                ])
            ),
        ]);
    }

    public function pageData(int $id): Response
    {
        $this->requireUser('pages.view');
        $this->assertEnabled();
        $page = $this->pages->page($id);

        return $this->jsonResponse([
            'success' => true,
            'data' => [
                'page' => $page->toArray(),
                'sections' => $this->pages->pageStructure($id),
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->requireUser('pages.create');
        $this->assertEnabled();
        $input = $this->requestData($request);
        $this->authorizeFields($user, $input, creating: true);
        try {
            $page = $this->pages->savePage($input, authorId: (int) $user->getKey());

            return $this->jsonResponse(['success' => true, 'data' => ['page' => $page->toArray()]], 201);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(Request $request, int $id): Response
    {
        $user = $this->requireUser('pages.update');
        $this->assertEnabled();
        $input = $this->requestData($request);
        $existing = $this->pages->page($id);
        $this->authorizeFields($user, $input, currentStatus: (string) $existing->getAttribute('status'));
        try {
            $page = $this->pages->savePage(
                $input,
                $existing,
                (int) $user->getKey(),
                isset($input['revision']) ? (int) $input['revision'] : null,
            );

            return $this->jsonResponse(['success' => true, 'data' => ['page' => $page->toArray()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    public function publish(int $id): Response
    {
        $this->requireUser('pages.publish');
        $this->assertEnabled();
        try {
            return $this->jsonResponse(['success' => true, 'data' => ['page' => $this->pages->publish($id)->toArray()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function unpublish(int $id): Response
    {
        $this->requireUser('pages.publish');
        $this->assertEnabled();

        return $this->jsonResponse(['success' => true, 'data' => ['page' => $this->pages->unpublish($id)->toArray()]]);
    }

    public function archive(int $id): Response
    {
        $this->requireUser('pages.archive');
        $this->assertEnabled();

        return $this->jsonResponse(['success' => true, 'data' => ['page' => $this->pages->archive($id)->toArray()]]);
    }

    public function designateHomepage(int $id): Response
    {
        $this->requireUser('pages.manage_settings');
        $this->assertEnabled();
        try {
            return $this->jsonResponse(['success' => true, 'data' => ['page' => $this->pages->designateHomepage($id)->toArray()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function duplicate(int $id): Response
    {
        $user = $this->requireUser('pages.create');
        $this->assertEnabled();
        try {
            $page = $this->pages->duplicatePage($id, (int) $user->getKey());

            return $this->jsonResponse(['success' => true, 'data' => ['page' => $page->toArray()]], 201);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function delete(int $id): Response
    {
        $this->requireUser('pages.delete');
        $this->assertEnabled();
        try {
            $this->pages->deletePage($id);

            return $this->jsonResponse(['success' => true]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function preview(int $id): Response
    {
        $this->requireUser('pages.preview');
        $this->assertEnabled();
        $page = $this->pages->previewPage($id, true);

        return $this->jsonResponse(['success' => true, 'data' => $this->pages->renderData($page)]);
    }

    public function previewWorking(Request $request): Response
    {
        $this->requireUser('pages.preview');
        $this->assertEnabled();
        $input = $this->requestData($request);
        $pageId = isset($input['id']) && (int) $input['id'] > 0 ? (int) $input['id'] : null;
        try {
            return $this->jsonResponse([
                'success' => true,
                'data' => $this->pages->previewData($input, $pageId),
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function templates(): Response
    {
        $this->requireUser('pages.view');
        $this->assertEnabled();

        return $this->jsonResponse(['success' => true, 'data' => $this->pages->templates()]);
    }

    public function createTemplate(Request $request, int $pageId): Response
    {
        $this->requireUser('pages.manage_templates');
        $this->assertEnabled();
        $input = $this->requestData($request);
        try {
            $template = $this->pages->createTemplateFromPage(
                $pageId,
                (string) ($input['name'] ?? ''),
                (string) ($input['description'] ?? ''),
                (string) ($input['category'] ?? ''),
            );

            return $this->jsonResponse(['success' => true, 'data' => ['template' => $template->toArray()]], 201);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function updateTemplate(Request $request, int $templateId): Response
    {
        $this->requireUser('pages.manage_templates');
        $this->assertEnabled();
        $input = $this->requestData($request);
        try {
            if (array_key_exists('structure', $input) && !is_array($input['structure'])) {
                throw new InvalidArgumentException('La structure du template doit être un tableau.');
            }
            if (array_key_exists('settings', $input) && !is_array($input['settings'])) {
                throw new InvalidArgumentException('Les paramètres du template doivent être un objet.');
            }
            $template = $this->pages->updateTemplate(
                $templateId,
                (string) ($input['name'] ?? ''),
                (string) ($input['description'] ?? ''),
                (string) ($input['category'] ?? ''),
                isset($input['structure']) && is_array($input['structure']) ? $input['structure'] : null,
                isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : null,
            );

            return $this->jsonResponse(['success' => true, 'data' => ['template' => $template->toArray()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function duplicateTemplate(Request $request, int $templateId): Response
    {
        $this->requireUser('pages.manage_templates');
        $this->assertEnabled();
        $input = $this->requestData($request);
        try {
            $template = $this->pages->duplicateTemplate($templateId, (string) ($input['name'] ?? ''));

            return $this->jsonResponse(['success' => true, 'data' => ['template' => $template->toArray()]], 201);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function deleteTemplate(int $templateId): Response
    {
        $this->requireUser('pages.manage_templates');
        $this->assertEnabled();
        try {
            $this->pages->deleteTemplate($templateId);

            return $this->jsonResponse(['success' => true]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function createFromTemplate(Request $request): Response
    {
        $user = $this->requireUser('pages.create');
        $this->assertEnabled();
        $input = $this->requestData($request);
        $this->authorizeFields($user, ['sections' => []], creating: true);
        try {
            $page = $this->pages->createPageFromTemplate(
                (string) ($input['template'] ?? ''),
                (string) ($input['title'] ?? ''),
                (int) $user->getKey(),
            );

            return $this->jsonResponse(['success' => true, 'data' => ['page' => $page->toArray()]], 201);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function sections(): Response
    {
        $this->requireUser('pages.view');
        $this->assertEnabled();

        return $this->jsonResponse(['success' => true, 'data' => $this->pages->librarySections()]);
    }

    public function createSection(Request $request): Response
    {
        $user = $this->requireUser('pages.manage_sections');
        $this->assertEnabled();
        $input = $this->requestData($request);
        try {
            $section = $this->pages->createLibrarySection(
                (string) ($input['name'] ?? ''),
                (string) ($input['type'] ?? 'content'),
                is_array($input['settings'] ?? null) ? $input['settings'] : [],
                is_array($input['blocks'] ?? null) ? $input['blocks'] : [],
                (int) $user->getKey(),
            );

            return $this->jsonResponse(['success' => true, 'data' => ['section' => $section->toArray()]], 201);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function updateSection(Request $request, int $sectionId): Response
    {
        $this->requireUser('pages.manage_sections');
        $this->assertEnabled();
        $input = $this->requestData($request);
        try {
            $section = $this->pages->saveLibrarySection(
                $sectionId,
                (string) ($input['name'] ?? ''),
                is_array($input['settings'] ?? null) ? $input['settings'] : [],
                is_array($input['blocks'] ?? null) ? $input['blocks'] : [],
            );

            return $this->jsonResponse(['success' => true, 'data' => ['section' => $section->toArray()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function deleteSection(int $sectionId): Response
    {
        $this->requireUser('pages.manage_sections');
        $this->assertEnabled();
        try {
            $this->pages->deleteLibrarySection($sectionId);

            return $this->jsonResponse(['success' => true]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function removeSection(int $pageId, int $usageId): Response
    {
        $this->requireUser('pages.manage_sections');
        $this->assertEnabled();
        try {
            $this->pages->removeSectionFromPage($pageId, $usageId);

            return $this->jsonResponse(['success' => true]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function addLibrarySection(Request $request, int $pageId): Response
    {
        $this->requireUser('pages.manage_sections');
        $this->assertEnabled();
        $input = $this->requestData($request);
        try {
            $usage = $this->pages->addSectionToPage(
                $pageId,
                (int) ($input['section_id'] ?? 0),
                (string) ($input['mode'] ?? 'reference'),
                (string) ($input['slot'] ?? 'content'),
                (int) ($input['sort_order'] ?? 0),
                (bool) ($input['enabled'] ?? true),
            );

            return $this->jsonResponse(['success' => true, 'data' => ['usage' => $usage->toArray()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function menus(): Response
    {
        $this->requireUser('pages.view');
        $this->assertEnabled();

        return $this->jsonResponse(['success' => true, 'data' => $this->pages->menus()]);
    }

    public function saveMenu(Request $request, ?int $menuId = null): Response
    {
        $this->requireUser('pages.manage_navigation');
        $this->assertEnabled();
        $input = $this->requestData($request);
        try {
            $menu = $menuId === null
                ? $this->pages->createMenu(
                    (string) ($input['name'] ?? ''),
                    (string) ($input['slug'] ?? ''),
                    isset($input['location']) ? (string) $input['location'] : null,
                )
                : $this->pages->updateMenu(
                    $menuId,
                    (string) ($input['name'] ?? ''),
                    (string) ($input['slug'] ?? ''),
                    isset($input['location']) ? (string) $input['location'] : null,
                );

            return $this->jsonResponse(['success' => true, 'data' => ['menu' => $menu->toArray()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function deleteMenu(int $menuId): Response
    {
        $this->requireUser('pages.manage_navigation');
        $this->assertEnabled();
        try {
            $this->pages->deleteMenu($menuId);

            return $this->jsonResponse(['success' => true]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function saveMenuItem(Request $request, int $menuId, ?int $itemId = null): Response
    {
        $this->requireUser('pages.manage_navigation');
        $this->assertEnabled();
        try {
            $item = $this->pages->saveMenuItem($menuId, $this->requestData($request), $itemId);

            return $this->jsonResponse(['success' => true, 'data' => ['item' => $item->toArray()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function deleteMenuItem(int $menuId, int $itemId): Response
    {
        $this->requireUser('pages.manage_navigation');
        $this->assertEnabled();
        try {
            $this->pages->deleteMenuItem($menuId, $itemId);

            return $this->jsonResponse(['success' => true]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function publicPage(string $slug): Response
    {
        $this->assertEnabled();
        $resolution = $this->pages->resolvePublicPage($slug);
        if ($resolution['redirect'] !== null) {
            return new RedirectResponse('/p/' . rawurlencode($resolution['redirect']), 301);
        }
        $page = $resolution['page'];
        if ($page === null) {
            throw new NotFoundHttpException();
        }
        $data = $this->pages->publicRenderData($slug);
        if ($data === null) {
            throw new NotFoundHttpException();
        }
        $seo = $data['seo'];
        $this->views->meta()->title((string) $seo['title']);
        $this->views->meta()->og('title', (string) $seo['title']);
        if (is_string($seo['description']) && $seo['description'] !== '') {
            $this->views->meta()->description($seo['description']);
            $this->views->meta()->og('description', $seo['description']);
        }
        if (is_string($seo['canonical']) && $seo['canonical'] !== '') {
            $this->views->meta()->canonical($seo['canonical']);
        }
        if ($seo['no_index']) {
            $this->views->meta()->meta('robots', 'noindex, nofollow');
        }
        if (is_string($seo['image']) && $seo['image'] !== '') {
            $this->views->meta()->og('image', $seo['image']);
            $this->views->meta()->twitter('image', $seo['image']);
        }
        $layout = $this->pages->registry()->layouts()[$data['layout']] ?? null;
        $layoutView = is_array($layout) ? ($layout['view'] ?? null) : null;
        $html = is_string($layoutView) && $layoutView !== ''
            ? $this->views->renderWithLayout($layoutView, 'public.pages.managed', $data)
            : $this->views->render('public.pages.managed', $data);

        return new Response($html);
    }

    private function assertEnabled(): void
    {
        if (!app()->environment()->getBool('DB_ON', false)) {
            throw new \Core\Exceptions\Http\ServiceNotInstalledException('Le service Gestion des pages nécessite une base de données configurée.');
        }
        $this->serviceState->assertEnabled();
    }

    private function requireUser(string $permission): User
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

    /** @param array<string, mixed> $input */
    private function authorizeFields(
        User $user,
        array $input,
        bool $creating = false,
        string $currentStatus = 'draft',
    ): void
    {
        if (isset($input['status']) && $input['status'] === 'published') {
            $this->assertPermission($user, 'pages.publish');
        }
        if (isset($input['status']) && $input['status'] === 'archived') {
            $this->assertPermission($user, 'pages.archive');
        }
        if (isset($input['status']) && $input['status'] === 'draft' && $currentStatus === 'published') {
            $this->assertPermission($user, 'pages.publish');
        }
        if (array_key_exists('sections', $input)) {
            $this->assertPermission($user, 'pages.manage_blocks');
        }
        if (array_intersect(['seo_title', 'seo_description', 'seo_image', 'canonical_url', 'no_index'], array_keys($input)) !== []) {
            $this->assertPermission($user, 'pages.manage_seo');
        }
        if (array_intersect(['layout', 'show_header', 'show_footer', 'show_announcement_bar', 'parent_id', 'visibility'], array_keys($input)) !== []) {
            $this->assertPermission($user, 'pages.manage_settings');
        }
        if (array_intersect(['add_to_navigation', 'navigation_label', 'navigation_order'], array_keys($input)) !== []) {
            $this->assertPermission($user, 'pages.manage_navigation');
        }
        if ($creating && isset($input['sections']) && $input['sections'] !== []) {
            $this->assertPermission($user, 'pages.manage_blocks');
        }
    }

    private function assertPermission(User $user, string $permission): void
    {
        if (!$user->isSuper() && !$user->hasPermission($permission)) {
            throw new GateException();
        }
    }

    /** @return array<string, mixed> */
    private function requestData(Request $request): array
    {
        return $request->isJson() ? $request->json() : $request->request->all();
    }
}
