<?php

declare(strict_types=1);

namespace NM\Services\GlobalSearch\Controllers;

use App\Models\User;
use Core\Exceptions\InputErrorException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Exceptions\Http\ServiceNotInstalledException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Security\CsrfTokenManager;
use Core\View\ViewFactory;
use NM\Services\GlobalSearch\Search\GlobalSearchService;

final class SearchController extends Controller
{
    public function __construct(
        private readonly GlobalSearchService $search,
        private readonly ViewFactory $factory,
        private readonly SessionInterface $session,
    ) {
    }

    public function publicApi(Request $request): Response
    {
        return $this->api($request, 'public', null);
    }

    public function dashboardApi(Request $request): Response
    {
        return $this->api($request, 'dashboard', $this->requireDashboardUser());
    }

    public function publicResults(): Response
    {
        $this->assertSearchAvailable('public');

        return new Response($this->factory->render('public-search-results', [
            'title' => 'Rechercher',
            'search_scope' => 'public',
            'search_api' => '/api/search',
        ]));
    }

    public function dashboardResults(): Response
    {
        $this->assertSearchAvailable('dashboard');
        $user = $this->requireDashboardUser();

        return new Response($this->factory->renderWithLayout('app', 'dashboard', [
            'title' => 'Résultats de recherche',
            'user' => $user->toArray(),
            'navelements' => \App\Helpers\NavbarFactory::navbar($user),
            'admin_permissions' => $user->effectivePermissions('string'),
            'content_name' => 'partials.search.results',
            'search_scope' => 'dashboard',
            'search_api' => '/dashboard/search/api',
            'activeNavTab' => 'search',
            'showHome' => true,
            'breadcrumbs' => [
                ['label' => 'Recherche'],
            ],
        ]));
    }

    private function api(Request $request, string $scope, ?User $user): Response
    {
        try {
            $csrfData = $scope === 'dashboard'
                ? ['csrf_token' => (new CsrfTokenManager($this->session, 'csrf_token'))->generateToken()]
                : [];
            $rawTerm = $request->query('q', '');
            if (!is_string($rawTerm)) {
                $validated = $this->validatePost(
                    data: ['q' => $rawTerm],
                    only: ['q'],
                    rules: ['q' => 'required|string|min:2|max:100|no_html'],
                    csrf_validate: false,
                );
                $rawTerm = (string) $validated['q'];
            }

            $term = trim($rawTerm);
            $availableTypes = $this->search->availableTypes($scope, $user);
            if ($term === '') {
                return $this->jsonResponse([
                    'success' => true,
                    'types' => $availableTypes,
                    ...$csrfData,
                ]);
            }

            $input = $this->validatePost(
                data: [
                    'q' => $rawTerm,
                    'types' => $request->query('types', ''),
                    'page' => $request->query('page', '1'),
                    'per_page' => $request->query('per_page', '20'),
                ],
                only: ['q', 'types', 'page', 'per_page'],
                rules: [
                    'q' => 'required|string|min:2|max:100|no_html',
                    'types' => 'string|max:80|no_html',
                    'page' => 'required|numeric|min:1|max:10000',
                    'per_page' => 'required|numeric|min:1|max:100',
                ],
                csrf_validate: false,
            )->toArray();
            $selectedTypes = array_values(array_filter(
                explode(',', (string) ($input['types'] ?? '')),
                static fn(string $type): bool => $type !== ''
            ));
            $result = $this->search->search(
                (string) $input['q'],
                $selectedTypes,
                $scope,
                $user,
                (int) $input['page'],
                (int) $input['per_page'],
            );

            return $this->jsonResponse([
                'success' => true,
                'types' => $availableTypes,
                ...$csrfData,
                ...$result,
            ]);
        } catch (ServiceNotInstalledException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 503);
        } catch (InputErrorException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
            ], 422);
        }
    }

    private function requireDashboardUser(): User
    {
        $userId = $this->session->getUserId();
        $user = $userId === null ? null : User::find($userId);
        if (
            !$user instanceof User
            || !$user->is_active()
            || (!$user->isSuper() && $user->highestRoleLevel() < User::MODERATOR_LEVEL)
        ) {
            throw new NotFoundHttpException();
        }

        return $user;
    }

    private function assertSearchAvailable(string $scope): void
    {
        try {
            $this->search->assertAvailable($scope);
        } catch (ServiceNotInstalledException) {
            throw new NotFoundHttpException();
        }
    }
}
