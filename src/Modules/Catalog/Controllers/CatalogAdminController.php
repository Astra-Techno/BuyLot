<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Catalog\Services\CatalogService;

/**
 * CatalogAdminController — internal catalog management endpoints.
 *
 * All routes require authentication + catalog_admin or super_admin role.
 *
 * POST /v1/internal/catalog/products
 * PUT  /v1/internal/catalog/products/{id}
 * POST /v1/internal/catalog/categories
 * PUT  /v1/internal/catalog/categories/{id}
 * POST /v1/internal/catalog/brands
 * PUT  /v1/internal/catalog/{type}/{id}/status
 */
readonly class CatalogAdminController
{
    public function __construct(
        private CatalogService $catalogService,
    ) {}

    // -------------------------------------------------------------------------
    // Product endpoints
    // -------------------------------------------------------------------------

    /**
     * POST /v1/internal/catalog/products
     *
     * Body (all fields except name and category_id are optional):
     *   name, category_id, [brand_id], [description], [slug],
     *   [specifications_json], [is_active],
     *   [variants: [{sku, name, attributes_json, is_active}]],
     *   [attributes: [{key, value}]]
     */
    public function createProduct(Request $request): Response
    {
        $data    = $request->getBody() ?? [];
        $product = $this->catalogService->createProduct($data);

        return Response::json($product, 201);
    }

    /**
     * PUT /v1/internal/catalog/products/{id}
     *
     * Body (all optional): name, category_id, brand_id, description, slug,
     *   specifications_json, is_active,
     *   attributes: [{key, value}]  — replaces all existing attributes when present
     */
    public function updateProduct(Request $request): Response
    {
        $id      = (int) $request->getParam('id');
        $data    = $request->getBody() ?? [];
        $product = $this->catalogService->updateProduct($id, $data);

        return Response::json($product);
    }

    // -------------------------------------------------------------------------
    // Category endpoints
    // -------------------------------------------------------------------------

    /**
     * POST /v1/internal/catalog/categories
     *
     * Body: name, [slug], [parent_id], [description], [icon_url],
     *   [sort_order], [is_active]
     */
    public function createCategory(Request $request): Response
    {
        $data     = $request->getBody() ?? [];
        $category = $this->catalogService->createCategory($data);

        return Response::json($category, 201);
    }

    /**
     * PUT /v1/internal/catalog/categories/{id}
     *
     * Body (all optional): name, slug, parent_id, description,
     *   icon_url, sort_order, is_active
     */
    public function updateCategory(Request $request): Response
    {
        $id       = (int) $request->getParam('id');
        $data     = $request->getBody() ?? [];
        $category = $this->catalogService->updateCategory($id, $data);

        return Response::json($category);
    }

    // -------------------------------------------------------------------------
    // Brand endpoints
    // -------------------------------------------------------------------------

    /**
     * POST /v1/internal/catalog/brands
     *
     * Body: name, [slug], [logo_url], [is_verified], [is_active]
     */
    public function createBrand(Request $request): Response
    {
        $data  = $request->getBody() ?? [];
        $brand = $this->catalogService->createBrand($data);

        return Response::json($brand, 201);
    }

    // -------------------------------------------------------------------------
    // Status toggle
    // -------------------------------------------------------------------------

    /**
     * PUT /v1/internal/catalog/{type}/{id}/status
     *
     * Path params:
     *   type  — one of: category, brand, product, variant
     *   id    — entity primary key
     *
     * Body: { "is_active": true|false|0|1 }
     */
    public function toggleStatus(Request $request): Response
    {
        $type = (string) $request->getParam('type');
        $id   = (int) $request->getParam('id');
        $body = $request->getBody() ?? [];

        if (!isset($body['is_active'])) {
            throw new ValidationException(['is_active' => 'The is_active field is required']);
        }

        $active  = (bool) $body['is_active'];
        $updated = $this->catalogService->toggleStatus($type, $id, $active);

        if (!$updated) {
            return Response::json(['message' => 'No changes applied'], 200);
        }

        return Response::json([
            'message'   => 'Status updated successfully',
            'type'      => $type,
            'id'        => $id,
            'is_active' => $active,
        ]);
    }
}
