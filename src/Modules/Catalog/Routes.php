<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Catalog\Controllers\CatalogAdminController;
use App\Modules\Catalog\Controllers\CatalogController;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Identity\Middleware\RoleMiddleware;

/**
 * Register all Catalog module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Catalog\Routes::register($router, $connection);
 */
class Routes
{
    public static function register(Router $router, Connection $connection): void
    {
        // -------------------------------------------------------------------------
        // Shared dependencies
        // -------------------------------------------------------------------------

        $catalogService = new CatalogService(
            new Category($connection),
            new Brand($connection),
            new Product($connection),
            new ProductVariant($connection),
            new ProductAttribute($connection),
        );

        $catalogController      = new CatalogController($catalogService);
        $catalogAdminController = new CatalogAdminController($catalogService);

        // -------------------------------------------------------------------------
        // Middleware instances
        // -------------------------------------------------------------------------

        $jwtConfig      = Config::get('auth.jwt', []);
        $jwtHandler     = new JwtHandler($jwtConfig['secret'] ?? 'change-me');
        $authMiddleware = new AuthMiddleware($jwtHandler);

        // catalog_admin or super_admin may manage catalog entities
        $adminMiddleware = new RoleMiddleware('catalog_admin', 'super_admin');

        // -------------------------------------------------------------------------
        // Public routes — no auth required
        // -------------------------------------------------------------------------

        // GET /v1/catalog/search
        $router->get(
            '/v1/catalog/search',
            static fn(Request $req) => $catalogController->searchProducts($req),
        );

        // GET /v1/catalog/products/{slug}
        $router->get(
            '/v1/catalog/products/{slug}',
            static fn(Request $req) => $catalogController->getProduct($req),
        );

        // GET /v1/catalog/categories
        $router->get(
            '/v1/catalog/categories',
            static fn(Request $req) => $catalogController->getCategories($req),
        );

        // GET /v1/catalog/categories/{slug}
        $router->get(
            '/v1/catalog/categories/{slug}',
            static fn(Request $req) => $catalogController->getCategory($req),
        );

        // GET /v1/catalog/brands
        $router->get(
            '/v1/catalog/brands',
            static fn(Request $req) => $catalogController->getBrands($req),
        );

        // -------------------------------------------------------------------------
        // Internal (admin) routes — auth + admin role required
        // -------------------------------------------------------------------------

        // POST /v1/internal/catalog/products
        $router->post(
            '/v1/internal/catalog/products',
            static fn(Request $req) => $catalogAdminController->createProduct($req),
            [$authMiddleware, $adminMiddleware],
        );

        // PUT /v1/internal/catalog/products/{id}
        $router->put(
            '/v1/internal/catalog/products/{id}',
            static fn(Request $req) => $catalogAdminController->updateProduct($req),
            [$authMiddleware, $adminMiddleware],
        );

        // POST /v1/internal/catalog/categories
        $router->post(
            '/v1/internal/catalog/categories',
            static fn(Request $req) => $catalogAdminController->createCategory($req),
            [$authMiddleware, $adminMiddleware],
        );

        // PUT /v1/internal/catalog/categories/{id}
        $router->put(
            '/v1/internal/catalog/categories/{id}',
            static fn(Request $req) => $catalogAdminController->updateCategory($req),
            [$authMiddleware, $adminMiddleware],
        );

        // POST /v1/internal/catalog/brands
        $router->post(
            '/v1/internal/catalog/brands',
            static fn(Request $req) => $catalogAdminController->createBrand($req),
            [$authMiddleware, $adminMiddleware],
        );

        // PUT /v1/internal/catalog/{type}/{id}/status
        $router->put(
            '/v1/internal/catalog/{type}/{id}/status',
            static fn(Request $req) => $catalogAdminController->toggleStatus($req),
            [$authMiddleware, $adminMiddleware],
        );
    }
}
