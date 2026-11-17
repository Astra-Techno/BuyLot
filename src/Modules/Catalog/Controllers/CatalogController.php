<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Catalog\Services\CatalogService;

/**
 * CatalogController — public-facing catalog endpoints.
 *
 * GET /v1/catalog/search
 * GET /v1/catalog/products/{slug}
 * GET /v1/catalog/categories
 * GET /v1/catalog/categories/{slug}
 * GET /v1/catalog/brands
 */
readonly class CatalogController
{
    public function __construct(
        private CatalogService $catalogService,
    ) {}

    // -------------------------------------------------------------------------
    // Product endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/catalog/search
     *
     * Query params:
     *   q           string   Full-text search term (default: '')
     *   category_id int      Filter by category
     *   brand_id    int      Filter by brand
     *   page        int      Page number (default: 1)
     *   per_page    int      Items per page, max 100 (default: 20)
     */
    public function searchProducts(Request $request): Response
    {
        $query   = (string) ($request->getQuery('q') ?? '');
        $page    = max(1, (int) ($request->getQuery('page') ?? 1));
        $perPage = min(100, max(1, (int) ($request->getQuery('per_page') ?? 20)));

        $filters = [];

        if ($request->getQuery('category_id') !== null) {
            $filters['category_id'] = (int) $request->getQuery('category_id');
        }

        if ($request->getQuery('brand_id') !== null) {
            $filters['brand_id'] = (int) $request->getQuery('brand_id');
        }

        $result = $this->catalogService->searchProducts($query, $filters, $page, $perPage);

        return Response::json($result);
    }

    /**
     * GET /v1/catalog/products/{slug}
     *
     * Returns the product record enriched with variants and attributes.
     */
    public function getProduct(Request $request): Response
    {
        $slug    = (string) $request->getParam('slug');
        $product = $this->catalogService->getProductBySlug($slug);

        return Response::json($product);
    }

    // -------------------------------------------------------------------------
    // Category endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/catalog/categories
     *
     * Returns the full nested category tree with product counts on active nodes.
     */
    public function getCategories(Request $request): Response
    {
        $tree = $this->catalogService->getCategoryTree();

        return Response::json(['data' => $tree]);
    }

    /**
     * GET /v1/catalog/categories/{slug}
     *
     * Returns the category record plus a paginated product list.
     *
     * Query params:
     *   page     int  (default: 1)
     *   per_page int  (default: 20)
     */
    public function getCategory(Request $request): Response
    {
        $slug    = (string) $request->getParam('slug');
        $page    = max(1, (int) ($request->getQuery('page') ?? 1));
        $perPage = min(100, max(1, (int) ($request->getQuery('per_page') ?? 20)));

        $result = $this->catalogService->getCategoryWithProducts($slug, $page, $perPage);

        return Response::json($result);
    }

    // -------------------------------------------------------------------------
    // Brand endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/catalog/brands
     *
     * Returns the list of all active brands ordered alphabetically.
     */
    public function getBrands(Request $request): Response
    {
        $brands = $this->catalogService->getBrands();

        return Response::json(['data' => $brands]);
    }
}
