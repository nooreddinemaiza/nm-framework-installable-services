<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Controllers;

use NM\Services\InventoryService\Catalog\CatalogMediaService;
use NM\Services\InventoryService\Catalog\CatalogService;
use NM\Services\InventoryService\Catalog\CatalogVisitCounter;
use App\Helpers\NavbarFactory;
use NM\Services\InventoryService\Inventory\InventoryCatalogService;
use NM\Services\InventoryService\Inventory\Discount\Services\DiscountService;
use App\Models\User;
use NM\Services\InventoryService\Support\RichTextSanitizer;
use Core\Exceptions\Http\GateException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Http\RedirectResponse;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Http\UploadedFile;
use Core\Logging\LoggerInterface;
use Core\Security\CsrfTokenManager;
use Core\Services\IndependentServiceRegistry;
use Core\View\ViewFactory;
use NM\Services\InventoryService\View\PublicViewResolver;
use InvalidArgumentException;

final class CatalogController extends Controller
{
    private ?CatalogVisitCounter $visitCounter = null;

    public function __construct(
        private readonly CatalogService $catalog,
        private readonly CatalogMediaService $catalogMedia,
        private readonly InventoryCatalogService $inventoryCatalog,
        private readonly ViewFactory $factory,
        private readonly SessionInterface $session,
        private readonly DiscountService $discounts,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $filters = [
            'search' => $request->query->getString('search'),
            'status' => $request->query->getString('status', 'all'),
            'kind' => $request->query->getString('kind', 'all'),
            'category_id' => $request->query->getInt('category_id') ?: '',
            'brand_id' => $request->query->getInt('brand_id') ?: '',
            'page' => $request->query->getInt('page', 1),
            'per_page' => $request->query->getInt('per_page', 5),
        ];
        $productId = $request->query->getInt('id');
        $product = $productId > 0 ? $this->catalog->product($productId) : null;
        if ($productId > 0 && $product === null) {
            throw new InvalidArgumentException('Ce produit est introuvable.');
        }

        return $this->renderPage(
            $user,
            $filters,
            $product,
            [],
            $request->query->getString('success'),
            $request->query->getString('error')
        );
    }

    public function search(Request $request): Response
    {
        $this->requireCatalogUser();
        $filters = [
            'search' => $request->query->getString('search'),
            'status' => $request->query->getString('status', 'all'),
            'kind' => $request->query->getString('kind', 'all'),
            'category_id' => $request->query->getInt('category_id') ?: '',
            'brand_id' => $request->query->getInt('brand_id') ?: '',
            'page' => $request->query->getInt('page', 1),
            'per_page' => $request->query->getInt('per_page', 5),
        ];

        return $this->jsonResponse([
            'success' => true,
            'data' => $this->catalog->search($filters),
        ]);
    }

    public function media(Request $request): Response
    {
        $this->requireCatalogUser();
        $productId = $request->query->getInt('product_id');
        if ($productId <= 0 || $this->catalog->product($productId) === null) {
            return $this->jsonResponse(['success' => false, 'message' => 'Ce produit est introuvable.'], 404);
        }

        return $this->jsonResponse([
            'success' => true,
            'data' => ['media' => $this->catalogMedia->forProduct($productId)],
        ]);
    }

    public function categories(Request $request): Response
    {
        $user = $this->requireCatalogUser();

        return $this->renderReferencePage(
            $user,
            'categories',
            $request->query->getString('search'),
            $request->query->getString('success'),
            $request->query->getString('error')
        );
    }

    public function brands(Request $request): Response
    {
        $user = $this->requireCatalogUser();

        return $this->renderReferencePage(
            $user,
            'brands',
            $request->query->getString('search'),
            $request->query->getString('success'),
            $request->query->getString('error')
        );
    }

    public function searchCategories(Request $request): Response
    {
        $this->requireCatalogUser();

        return $this->jsonResponse([
            'success' => true,
            'data' => $this->catalog->searchCategories(
                $request->query->getString('search'),
                max(1, $request->query->getInt('page', 1)),
                $request->query->getInt('per_page', 10),
            ),
        ]);
    }

    public function searchBrands(Request $request): Response
    {
        $this->requireCatalogUser();

        return $this->jsonResponse([
            'success' => true,
            'data' => $this->catalog->searchBrands(
                $request->query->getString('search'),
                max(1, $request->query->getInt('page', 1)),
                $request->query->getInt('per_page', 10),
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        try {
            $productId = $this->catalog->createProduct($this->payload($request), $user);
            if ($this->wantsJson($request)) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Produit enregistré.',
                    'data' => ['product' => $this->catalog->product($productId)],
                ], 201);
            }
            return new RedirectResponse('/dashboard/catalog?id=' . $productId . '&success=' . rawurlencode('Produit enregistré.'));
        } catch (UnprocessableEntityException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'errors' => $exception->getErrors(),
                ], 422);
            }
            return $this->renderPage($user, [], $this->payload($request), $exception->getErrors());
        } catch (InvalidArgumentException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }
            $values = $this->payload($request);
            return $this->renderPage($user, [], $values, ['form' => $exception->getMessage()]);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function update(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $payload = $this->payload($request);
        $productId = (int) ($payload['product_id'] ?? 0);
        try {
            if ($productId <= 0) {
                throw new InvalidArgumentException('Ce produit est introuvable.');
            }
            $this->catalog->updateProduct($productId, $payload, $user);
            if ($this->wantsJson($request)) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Produit enregistré.',
                    'data' => ['product' => $this->catalog->product($productId)],
                ]);
            }
            return new RedirectResponse('/dashboard/catalog?id=' . $productId . '&success=' . rawurlencode('Produit enregistré.'));
        } catch (UnprocessableEntityException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'errors' => $exception->getErrors(),
                ], 422);
            }
            $payload['id'] = $productId;
            return $this->renderPage($user, [], $payload, $exception->getErrors());
        } catch (InvalidArgumentException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }
            $values = $this->payload($request);
            $values['id'] = $productId;
            return $this->renderPage($user, [], $values, ['form' => $exception->getMessage()]);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function status(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $payload = $this->payload($request);
        try {
            $productId = (int) ($payload['product_id'] ?? 0);
            $this->catalog->setProductStatus(
                $productId,
                (string) ($payload['status'] ?? ''),
                $user
            );
            if ($this->wantsJson($request)) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Le statut du produit a été mis à jour.',
                    'data' => ['product' => $this->catalog->product($productId)],
                ]);
            }

            return new RedirectResponse('/dashboard/catalog?success=' . rawurlencode('Le statut du produit a été mis à jour.'));
        } catch (InvalidArgumentException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }
            return $this->renderPage($user, [], null, ['form' => $exception->getMessage()]);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function trending(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $payload = $this->payload($request);
        if (!array_key_exists('is_trending', $payload)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'La valeur de tendance doit être fournie.',
            ], 422);
        }
        try {
            $kind = $payload['kind'] ?? null;
            $entityId = filter_var($payload['entity_id'] ?? null, FILTER_VALIDATE_INT);
            $isTrending = $this->catalog->setTrending(
                is_string($kind) ? $kind : '',
                $entityId === false ? 0 : $entityId,
                $payload['is_trending'] ?? null,
                $user
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => $isTrending ? 'Élément mis en tendance.' : 'Élément retiré des tendances.',
                'data' => ['is_trending' => $isTrending],
            ]);
        } catch (UnprocessableEntityException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
            ], 422);
        } catch (NotFoundHttpException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 404);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function delete(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        try {
            $this->catalog->deleteProduct((int) ($this->payload($request)['product_id'] ?? 0), $user);
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => true, 'message' => 'Produit supprimé.']);
            }
            return new RedirectResponse('/dashboard/catalog?success=' . rawurlencode('Produit supprimé.'));
        } catch (InvalidArgumentException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }
            return $this->renderPage($user, [], null, ['form' => $exception->getMessage()]);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function category(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $payload = $this->payload($request);
        try {
            $parentValue = $payload['parent_id'] ?? null;
            $parentId = null;
            if ($parentValue !== null && $parentValue !== '') {
                if (
                    !is_scalar($parentValue) || filter_var($parentValue, FILTER_VALIDATE_INT) === false
                    || (int) $parentValue <= 0
                ) {
                    throw new InvalidArgumentException('La catégorie parente sélectionnée est invalide.');
                }
                $parentId = (int) $parentValue;
            }
            $categoryId = $this->catalog->createCategory(
                (string) ($payload['name'] ?? ''),
                $parentId,
                $user,
                (string) ($payload['category_creation_token'] ?? ''),
                (string) ($payload['slug'] ?? ''),
                $payload['seo_tags'] ?? null,
                $payload['is_trending'] ?? false
            );
            if ($this->wantsJson($request)) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Catégorie ajoutée.',
                    'data' => ['category' => $this->categoryById($categoryId)],
                ], 201);
            }
            return new RedirectResponse('/dashboard/catalog/categories?success=' . rawurlencode('Catégorie ajoutée.'));
        } catch (UnprocessableEntityException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'errors' => $exception->getErrors(),
                ], 422);
            }
            return $this->renderReferencePage($user, 'categories', '', '', $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }
            return $this->renderReferencePage($user, 'categories', '', '', $exception->getMessage());
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function brand(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $payload = $this->payload($request);
        try {
            $brandId = $this->catalog->createBrand(
                (string) ($payload['name'] ?? ''),
                $user,
                (string) ($payload['slug'] ?? ''),
                $payload['seo_tags'] ?? null,
                $payload['is_trending'] ?? false
            );
            if ($this->wantsJson($request)) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Marque ajoutée.',
                    'data' => ['brand' => $this->brandById($brandId)],
                ], 201);
            }
            return new RedirectResponse('/dashboard/catalog/brands?success=' . rawurlencode('Marque ajoutée.'));
        } catch (UnprocessableEntityException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'errors' => $exception->getErrors(),
                ], 422);
            }
            return $this->renderReferencePage($user, 'brands', '', '', $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }
            return $this->renderReferencePage($user, 'brands', '', '', $exception->getMessage());
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function updateCategory(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $payload = $this->payload($request);
        $id = (int) ($payload['category_id'] ?? 0);
        $parentId = ($payload['parent_id'] ?? '') === '' ? null : (int) $payload['parent_id'];
        try {
            $this->catalog->updateCategory(
                $id,
                (string) ($payload['name'] ?? ''),
                $parentId,
                $user,
                (string) ($payload['slug'] ?? ''),
                $payload['seo_tags'] ?? null,
                $payload['is_trending'] ?? null
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Catégorie mise à jour.',
                'data' => ['category' => $this->categoryById($id)],
            ]);
        } catch (UnprocessableEntityException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
            ], 422);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function deleteCategory(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        try {
            $this->catalog->deleteCategory((int) ($this->payload($request)['category_id'] ?? 0), $user);

            return $this->jsonResponse(['success' => true, 'message' => 'Catégorie supprimée.']);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function updateBrand(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $payload = $this->payload($request);
        $id = (int) ($payload['brand_id'] ?? 0);
        try {
            $this->catalog->updateBrand(
                $id,
                (string) ($payload['name'] ?? ''),
                $user,
                (string) ($payload['slug'] ?? ''),
                $payload['seo_tags'] ?? null,
                $payload['is_trending'] ?? null
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Marque mise à jour.',
                'data' => ['brand' => $this->brandById($id)],
            ]);
        } catch (UnprocessableEntityException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
            ], 422);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function deleteBrand(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        try {
            $this->catalog->deleteBrand((int) ($this->payload($request)['brand_id'] ?? 0), $user);

            return $this->jsonResponse(['success' => true, 'message' => 'Marque supprimée.']);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (GateException $exception) {
            return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }
    }

    public function uploadMedia(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $productId = $request->request->getInt('product_id');
        try {
            $file = $request->files->get('media_file');
            if (!$file instanceof UploadedFile) {
                throw new InvalidArgumentException('Sélectionnez une image ou une vidéo à téléverser.');
            }
            $this->catalogMedia->addUpload(
                $productId,
                $file,
                $request->request->getString('caption'),
                $user
            );

            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => true, 'message' => 'Le média a été ajouté.']);
            }

            return new RedirectResponse('/dashboard/catalog?id=' . $productId . '&success=' . rawurlencode('Le média a été ajouté.'));
        } catch (InvalidArgumentException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return new RedirectResponse('/dashboard/catalog?id=' . $productId . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function linkMedia(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $productId = $request->request->getInt('product_id');
        try {
            $this->catalogMedia->addExternal(
                $productId,
                $request->request->getString('media_type'),
                $request->request->getString('external_url'),
                $request->request->getString('caption'),
                $user
            );

            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => true, 'message' => 'Le lien média a été ajouté.']);
            }

            return new RedirectResponse('/dashboard/catalog?id=' . $productId . '&success=' . rawurlencode('Le lien média a été ajouté.'));
        } catch (InvalidArgumentException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return new RedirectResponse('/dashboard/catalog?id=' . $productId . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function deleteMedia(Request $request): Response
    {
        $user = $this->requireCatalogUser();
        $productId = $request->request->getInt('product_id');
        try {
            $this->catalogMedia->delete($request->request->getInt('media_id'), $productId, $user);

            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => true, 'message' => 'Le média a été supprimé.']);
            }

            return new RedirectResponse('/dashboard/catalog?id=' . $productId . '&success=' . rawurlencode('Le média a été supprimé.'));
        } catch (InvalidArgumentException $exception) {
            if ($this->wantsJson($request)) {
                return $this->jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return new RedirectResponse('/dashboard/catalog?id=' . $productId . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function publicProduct(Request $request, string $slug): Response
    {
        $product = $this->catalog->publicProductBySlug($slug);
        if ($product === null) {
            throw new \Core\Exceptions\Http\NotFoundHttpException();
        }
        $product['description'] = RichTextSanitizer::sanitize((string) ($product['description'] ?? ''));
        if ($request->getMethod() === 'GET') {
            $this->recordPublicVisit(
                ($product['kind'] ?? '') === 'service' ? 'service' : 'product',
                (int) $product['id']
            );
        }
        $discountResult = isset($product['variant_id']) && $product['price'] !== null
            ? $this->discounts->calculateForVariant((int) $product['variant_id'])->toArray()
            : null;
        $relatedProducts = $this->catalog->relatedPublicProducts(
            (int) $product['id'],
            !empty($product['category_slug']) ? (int) $product['category_id'] : null,
            !empty($product['brand_slug']) ? (int) $product['brand_id'] : null,
        );
        foreach (['category', 'brand'] as $relatedKind) {
            $relatedProducts[$relatedKind] = $this->preparePublicProducts($relatedProducts[$relatedKind]);
        }

        $publicViews = new PublicViewResolver($this->factory);
        return $publicViews->render('public.catalog.product', [
            'title' => (string) $product['name'],
            'product' => $product,
            'quote_requests_enabled' => $this->quoteRequestsEnabled(),
            'discount_result' => $discountResult,
            'product_media' => $this->catalogMedia->forProduct((int) $product['id']),
            'related_products' => $relatedProducts,
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
        ]);
    }

    public function publicHome(): Response
    {
        return new Response($this->factory->render('public.catalog.home', [
            'title' => 'Accueil',
            'home_categories' => array_slice($this->catalog->publicCatalogReferences('category'), 0, 6),
            'home_brands' => array_slice($this->catalog->publicCatalogReferences('brand'), 0, 6),
            'trending_products' => $this->publicTrendingSelection(),
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
        ]));
    }

    public function publicStorefront(Request $request): Response
    {
        return new Response($this->factory->render('public.catalog.storefront', [
            'title' => 'Boutique',
            ...$this->publicStorefrontData($request),
            'trending_products' => $this->publicTrendingSelection(),
        ]));
    }

    public function publicStorefrontResults(Request $request): Response
    {
        $data = $this->publicStorefrontData($request);

        return $this->jsonResponse([
            'success' => true,
            'results_html' => $this->factory->render('public.catalog.storefront-results', $data),
            'filters_html' => $this->factory->render('partials.public.storefront-active-filters', $data),
            'filters' => $data['storefront_filters'],
            'categories' => $data['storefront_categories'],
            'brands' => $data['storefront_brands'],
            'total' => $data['storefront_listing']['total'],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicStorefrontData(Request $request): array
    {
        $filters = [
            'search' => trim($request->query->getString('search')),
            'category_id' => $request->query->getInt('category_id'),
            'brand_id' => $request->query->getInt('brand_id'),
            'sort' => $request->query->getString('sort', 'newest'),
            'page' => max(1, $request->query->getInt('page', 1)),
            'per_page' => 15,
        ];
        if (!in_array($filters['sort'], ['newest', 'name_asc', 'name_desc'], true)) {
            $filters['sort'] = 'newest';
        }

        $listing = $this->catalog->publicProductListing($filters);
        $categories = $this->catalog->publicCatalogReferences('category', $filters);
        $brands = $this->catalog->publicCatalogReferences('brand', $filters);
        $selectedCategory = null;
        foreach ($categories as $category) {
            if ((int) $category['id'] === $filters['category_id']) {
                $selectedCategory = $category;
                break;
            }
        }
        $selectedBrand = null;
        foreach ($brands as $brand) {
            if ((int) $brand['id'] === $filters['brand_id']) {
                $selectedBrand = $brand;
                break;
            }
        }

        return [
            'storefront_filters' => $filters,
            'storefront_listing' => [
                ...$listing,
                'items' => $this->preparePublicProducts($listing['items']),
            ],
            'storefront_categories' => $categories,
            'storefront_brands' => $brands,
            'storefront_selected_category' => $selectedCategory,
            'storefront_selected_brand' => $selectedBrand,
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
        ];
    }

    public function publicCategory(Request $request, string $slug): Response
    {
        $category = $this->catalog->publicCategoryBySlug($slug, max(1, $request->query->getInt('page', 1)));
        if ($category === null) {
            throw new \Core\Exceptions\Http\NotFoundHttpException();
        }
        if ($request->getMethod() === 'GET') {
            $this->recordPublicVisit('category', (int) $category['entity']['id']);
        }
        $hero = $this->catalog->publicHeroProduct('category', (int) $category['entity']['id']);
        $heroProducts = $hero === null ? [] : $this->preparePublicProducts([$hero]);

        return new Response($this->factory->render('public.catalog.reference', [
            'title' => (string) $category['entity']['name'],
            'reference' => $category['entity'],
            'reference_kind' => 'category',
            'quote_requests_enabled' => $this->quoteRequestsEnabled(),
            'reference_products' => $this->preparePublicProducts($category['products']),
            'hero_product' => $heroProducts[0] ?? null,
            'reference_pagination' => $category,
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
        ]));
    }

    public function publicCategories(): Response
    {
        return new Response($this->factory->render('public.catalog.references', [
            'title' => 'Catégories',
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
            'reference_kind' => 'category',
            'public_references' => $this->preparePublicReferences($this->catalog->publicReferences('category')),
            'trending_references' => $this->catalog->publicTrendingReferences('category'),
            'trending_products' => $this->preparePublicProducts($this->catalog->publicTrendingProducts()),
        ]));
    }

    public function publicBrand(Request $request, string $slug): Response
    {
        $brand = $this->catalog->publicBrandBySlug($slug, max(1, $request->query->getInt('page', 1)));
        if ($brand === null) {
            throw new \Core\Exceptions\Http\NotFoundHttpException();
        }
        if ($request->getMethod() === 'GET') {
            $this->recordPublicVisit('brand', (int) $brand['entity']['id']);
        }
        $hero = $this->catalog->publicHeroProduct('brand', (int) $brand['entity']['id']);
        $heroProducts = $hero === null ? [] : $this->preparePublicProducts([$hero]);

        return new Response($this->factory->render('public.catalog.reference', [
            'title' => (string) $brand['entity']['name'],
            'reference' => $brand['entity'],
            'reference_kind' => 'brand',
            'quote_requests_enabled' => $this->quoteRequestsEnabled(),
            'reference_products' => $this->preparePublicProducts($brand['products']),
            'hero_product' => $heroProducts[0] ?? null,
            'reference_pagination' => $brand,
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
        ]));
    }

    public function publicBrands(): Response
    {
        $this->register_csrf($this->session, 'token');
        return new Response($this->factory->render('public.catalog.references', [
            'title' => 'Marques',
            'reference_kind' => 'brand',
            'csrf_token' => $this->csrfManager->generateToken(),
            'public_references' => $this->preparePublicReferences($this->catalog->publicReferences('brand')),
            'trending_references' => $this->catalog->publicTrendingReferences('brand'),
            'trending_products' => $this->preparePublicProducts($this->catalog->publicTrendingProducts()),
        ]));
    }

    public function publicTags(): Response
    {
        return new Response($this->factory->render('public.catalog.tags', [
            'title' => 'Tags',
            'tags' => $this->catalog->publicTags(),
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
        ]));
    }

    public function publicTag(string $slug): Response
    {
        $taggedContent = $this->catalog->publicTagBySlug($slug);
        if ($taggedContent === null) {
            throw new \Core\Exceptions\Http\NotFoundHttpException();
        }
        $taggedContent['products'] = $this->preparePublicProducts($taggedContent['products']);

        return new Response($this->factory->render('public.catalog.tag', [
            'title' => (string) $taggedContent['tag'],
            'tagged_content' => $taggedContent,
            'csrf_token' => (new CsrfTokenManager($this->session))->generateToken(),
        ]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recordPublicVisit(string $kind, int $entityId): void
    {
        $this->visitCounter ??= new CatalogVisitCounter(
            $this->catalog,
            $this->session,
            app()->get(LoggerInterface::class)
        );
        $this->visitCounter->record($kind, $entityId);
    }

    private function quoteRequestsEnabled(): bool
    {
        return app()->get(IndependentServiceRegistry::class)->status('quotes') === 'active';
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private function preparePublicProducts(array $products): array
    {
        foreach ($products as &$product) {
            $product['media'] = $this->catalogMedia->forProduct((int) $product['id']);
            $product['discount_result'] = isset($product['variant_id']) && $product['price'] !== null
                ? $this->discounts->calculateForVariant((int) $product['variant_id'])->toArray()
                : null;
        }
        unset($product);

        return $products;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicTrendingSelection(): array
    {
        $products = [];
        $seen = [];
        foreach ($this->catalog->publicTrendingProducts() as $product) {
            $id = (int) ($product['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $products[] = $product;
            if (count($products) === 4) {
                break;
            }
        }

        return $this->preparePublicProducts($products);
    }

    /**
     * @param list<array<string, mixed>> $references
     * @return list<array<string, mixed>>
     */
    private function preparePublicReferences(array $references): array
    {
        foreach ($references as &$reference) {
            $reference['products'] = $this->preparePublicProducts($reference['products'] ?? []);
        }
        unset($reference);

        return $references;
    }

    public function publicMedia(Request $request, string $id): Response
    {
        $mediaId = filter_var($id, FILTER_VALIDATE_INT);
        if ($mediaId === false || $mediaId <= 0) {
            throw new \Core\Exceptions\Http\NotFoundHttpException();
        }

        return $this->catalogMedia->respondPublicMedia($request, (int) $mediaId);
    }

    private function requireCatalogUser(): User
    {
        $userId = $this->session->getUserId();
        $user = $userId === null ? null : User::find($userId);
        if ($user === null) {
            throw new GateException();
        }
        $permissions = [
            'catalog.product.view',
            'catalog.product.create',
            'catalog.product.update',
            'catalog.product.archive',
            'catalog.product.delete',
            'catalog.category.manage',
            'catalog.brand.manage',
            'catalog.media.upload',
            'inventory.discount.view',
            'inventory.discount.manage',
        ];
        if (!$user->isSuper() && !array_filter($permissions, static fn(string $permission): bool => $user->hasPermission($permission))) {
            throw new GateException();
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>|null $product
     */
    private function renderPage(
        User $user,
        array $filters,
        ?array $product,
        array $errors = [],
        string $success = '',
        string $error = '',
    ): Response {
        $csrf = new CsrfTokenManager($this->session, self::TOKEN_NAME);

        return new Response($this->factory->renderWithLayout('app', 'dashboard', [
            'title' => 'Produits',
            'csrf_token' => $csrf->generateToken(),
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user, 'catalog'),
            'admin_permissions' => $user->effectivePermissions('string'),
            'errors' => [],
            'content_name' => 'dashboard.catalog',
            'activeNavTab' => 'catalog',
            'showHome' => true,
            'breadcrumbs' => [['label' => 'Produits']],
            'catalog_result' => $this->catalog->search($filters),
            'catalog_filters' => $filters,
            'catalog_product' => $product,
            'catalog_media' => $product !== null && isset($product['id'])
                ? $this->catalogMedia->forProduct((int) $product['id'])
                : [],
            'catalog_categories' => $this->catalog->categories(),
            'catalog_brands' => $this->catalog->brands(),
            'catalog_stock_units' => $this->inventoryCatalog->units(),
            'catalog_form_errors' => $errors,
            'catalog_default_status' => $this->catalog->defaultStatus(),
            'catalog_sales_statistics_enabled' => (new \App\Services\Installation\ServiceInstallationService(
                app()->get(\Core\Database\ConnectionManager::class)->connection()
            ))->isEnabled('sales'),
            'catalog_success' => $success,
            'catalog_error' => $error,
            'catalog_creation_token' => bin2hex(random_bytes(32)),
            'catalog_category_creation_token' => bin2hex(random_bytes(32)),
        ]));
    }

    private function renderReferencePage(
        User $user,
        string $resource,
        string $search = '',
        string $success = '',
        string $error = '',
    ): Response {
        if (!in_array($resource, ['categories', 'brands'], true)) {
            throw new InvalidArgumentException('Le référentiel demandé est invalide.');
        }

        $isCategories = $resource === 'categories';
        $active = $isCategories ? 'catalog-categories' : 'catalog-brands';
        $title = $isCategories ? 'Catégories' : 'Marques';

        return new Response($this->factory->renderWithLayout('app', 'dashboard', [
            'title' => $title,
            'csrf_token' => (new CsrfTokenManager($this->session, self::TOKEN_NAME))->generateToken(),
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user, $active),
            'admin_permissions' => $user->effectivePermissions('string'),
            'errors' => [],
            'content_name' => 'dashboard.catalog-' . $resource,
            'activeNavTab' => $active,
            'showHome' => true,
            'breadcrumbs' => [
                ['label' => 'Produits', 'url' => '/dashboard/catalog'],
                ['label' => $title],
            ],
            'catalog_categories' => $this->catalog->categories(),
            'catalog_brands' => $this->catalog->brands(),
            'catalog_reference_result' => $isCategories
                ? $this->catalog->searchCategories($search)
                : $this->catalog->searchBrands($search),
            'catalog_reference_search' => $search,
            'catalog_reference_success' => $success,
            'catalog_reference_error' => $error,
            'catalog_category_creation_token' => bin2hex(random_bytes(32)),
        ]));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $json = $request->json();

        return $json !== [] ? $json : $request->all();
    }

    /** @param array<string, mixed> $payload */
    private function optionalString(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload)) {
            return null;
        }
        $value = $payload[$key];
        if (!is_scalar($value) && !($value instanceof \Stringable)) {
            throw new InvalidArgumentException('Une valeur de formulaire est invalide.');
        }

        return (string) $value;
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains(strtolower($request->headers->get('accept', '')), 'application/json');
    }

    /** @return array<string, mixed>|null */
    private function categoryById(int $id): ?array
    {
        foreach ($this->catalog->categories() as $category) {
            if ((int) $category['id'] === $id) {
                return $category;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function brandById(int $id): ?array
    {
        foreach ($this->catalog->brands() as $brand) {
            if ((int) $brand['id'] === $id) {
                return $brand;
            }
        }

        return null;
    }
}
