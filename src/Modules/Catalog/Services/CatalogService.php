<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductVariant;

/**
 * CatalogService — orchestration layer for catalog reads and writes.
 *
 * Responsibilities:
 *  - Slug generation (unique, URL-safe)
 *  - Category tree assembly
 *  - Product search with filter normalisation
 *  - Product detail enrichment (variants + attributes)
 *  - Create/update workflows for products, categories, brands
 */
class CatalogService
{
    public function __construct(
        private Category         $categoryModel,
        private Brand            $brandModel,
        private Product          $productModel,
        private ProductVariant   $variantModel,
        private ProductAttribute $attributeModel,
    ) {}

    // -------------------------------------------------------------------------
    // Category operations
    // -------------------------------------------------------------------------

    /**
     * Return the full nested category tree (all nodes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategoryTree(): array
    {
        return $this->categoryModel->getTree();
    }

    /**
     * Return active categories with per-category product counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveCategories(): array
    {
        return $this->categoryModel->getActiveWithCounts();
    }

    /**
     * Find a category by slug, throwing 404 if missing.
     *
     * @return array<string, mixed>
     */
    public function getCategoryBySlug(string $slug): array
    {
        $category = $this->categoryModel->findBySlug($slug);

        if ($category === null) {
            throw new HttpException('Category not found', 404);
        }

        return $category;
    }

    /**
     * Create a new category, auto-generating a unique slug if not provided.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCategory(array $data): array
    {
        $this->validateRequired($data, ['name']);

        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug($data['name'], 'categories');
        } else {
            $this->assertSlugUnique($data['slug'], 'categories');
        }

        return $this->categoryModel->create($data);
    }

    /**
     * Update a category by ID.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateCategory(int $id, array $data): array
    {
        $category = $this->categoryModel->find($id);

        if ($category === null) {
            throw new HttpException('Category not found', 404);
        }

        // If a new slug is provided, ensure it is unique (excluding the current row)
        if (!empty($data['slug']) && $data['slug'] !== $category['slug']) {
            $this->assertSlugUnique($data['slug'], 'categories');
        }

        $this->categoryModel->update($id, $data);

        return $this->categoryModel->find($id) ?? $category;
    }

    // -------------------------------------------------------------------------
    // Brand operations
    // -------------------------------------------------------------------------

    /**
     * Return all active brands.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBrands(): array
    {
        return $this->brandModel->findAll(activeOnly: true);
    }

    /**
     * Find a brand by slug, throwing 404 if missing.
     *
     * @return array<string, mixed>
     */
    public function getBrandBySlug(string $slug): array
    {
        $brand = $this->brandModel->findBySlug($slug);

        if ($brand === null) {
            throw new HttpException('Brand not found', 404);
        }

        return $brand;
    }

    /**
     * Create a new brand.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createBrand(array $data): array
    {
        $this->validateRequired($data, ['name']);

        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug($data['name'], 'brands');
        } else {
            $this->assertSlugUnique($data['slug'], 'brands');
        }

        return $this->brandModel->create($data);
    }

    /**
     * Update a brand by ID.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateBrand(int $id, array $data): array
    {
        $brand = $this->brandModel->find($id);

        if ($brand === null) {
            throw new HttpException('Brand not found', 404);
        }

        if (!empty($data['slug']) && $data['slug'] !== $brand['slug']) {
            $this->assertSlugUnique($data['slug'], 'brands');
        }

        $this->brandModel->update($id, $data);

        return $this->brandModel->find($id) ?? $brand;
    }

    // -------------------------------------------------------------------------
    // Product operations
    // -------------------------------------------------------------------------

    /**
     * Search products with optional filters and pagination.
     *
     * Supported filter keys: category_id, brand_id
     *
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function searchProducts(string $query, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->productModel->search($query, $filters, $page, $perPage);
    }

    /**
     * Return a product detail record enriched with variants and attributes.
     * Throws 404 if the slug does not resolve to an active product.
     *
     * @return array<string, mixed>
     */
    public function getProductBySlug(string $slug): array
    {
        $product = $this->productModel->findBySlug($slug);

        if ($product === null || !(bool) $product['is_active']) {
            throw new HttpException('Product not found', 404);
        }

        return $this->enrichProduct($product);
    }

    /**
     * Return products for a category page.
     *
     * @return array{category: array<string, mixed>, products: array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}}
     */
    public function getCategoryWithProducts(string $slug, int $page = 1, int $perPage = 20): array
    {
        $category = $this->getCategoryBySlug($slug);

        $products = $this->productModel->listByCategory(
            (int) $category['id'],
            $page,
            $perPage,
        );

        return [
            'category' => $category,
            'products' => $products,
        ];
    }

    /**
     * Create a new product, optionally with variants and attributes.
     *
     * Expected $data shape:
     *   name, category_id, [brand_id], [description], [specifications_json],
     *   [slug], [is_active],
     *   [variants: [{sku, name, attributes_json, is_active}]],
     *   [attributes: [{key, value}]]
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createProduct(array $data): array
    {
        $this->validateRequired($data, ['name', 'category_id']);

        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug($data['name'], 'products');
        } else {
            $this->assertSlugUnique($data['slug'], 'products');
        }

        $product   = $this->productModel->create($data);
        $productId = (int) $product['id'];

        // Persist variants if provided
        if (!empty($data['variants']) && is_array($data['variants'])) {
            foreach ($data['variants'] as $v) {
                $v['product_id'] = $productId;
                $this->variantModel->create($v);
            }
        }

        // Persist structured attributes if provided
        if (!empty($data['attributes']) && is_array($data['attributes'])) {
            $this->attributeModel->replaceForProduct($productId, $data['attributes']);
        }

        return $this->enrichProduct($product);
    }

    /**
     * Update a product and optionally replace its attributes.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateProduct(int $id, array $data): array
    {
        $product = $this->productModel->find($id);

        if ($product === null) {
            throw new HttpException('Product not found', 404);
        }

        if (!empty($data['slug']) && $data['slug'] !== $product['slug']) {
            $this->assertSlugUnique($data['slug'], 'products');
        }

        $this->productModel->update($id, $data);

        // Replace attributes if the caller supplies them
        if (isset($data['attributes']) && is_array($data['attributes'])) {
            $this->attributeModel->replaceForProduct($id, $data['attributes']);
        }

        $updated = $this->productModel->find($id) ?? $product;

        return $this->enrichProduct($updated);
    }

    // -------------------------------------------------------------------------
    // Status toggling
    // -------------------------------------------------------------------------

    /**
     * Toggle is_active on any catalog entity.
     *
     * @param 'category'|'brand'|'product'|'variant' $type
     */
    public function toggleStatus(string $type, int $id, bool $active): bool
    {
        return match ($type) {
            'category' => $this->categoryModel->update($id, ['is_active' => (int) $active]),
            'brand'    => $this->brandModel->update($id, ['is_active' => (int) $active]),
            'product'  => $this->productModel->update($id, ['is_active' => (int) $active]),
            'variant'  => $this->variantModel->update($id, ['is_active' => (int) $active]),
            default    => throw new ValidationException(['type' => 'Invalid entity type. Must be: category, brand, product, or variant']),
        };
    }

    // -------------------------------------------------------------------------
    // Slug helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a URL-safe slug from a string and ensure it is unique in the
     * given table by appending an incrementing suffix when collisions occur.
     */
    public function generateUniqueSlug(string $source, string $table): string
    {
        $base = $this->slugify($source);
        $slug = $base;
        $i    = 1;

        while ($this->slugExists($slug, $table)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    /**
     * Convert a string to a lowercase, hyphenated, ASCII slug.
     */
    private function slugify(string $text): string
    {
        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Transliterate common accented characters
        $text = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        // Replace non-alphanumeric characters with hyphens
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        // Strip leading/trailing hyphens
        return trim($text, '-');
    }

    /**
     * Check whether a slug already exists in the given table.
     */
    private function slugExists(string $slug, string $table): bool
    {
        // We reach into the appropriate model's connection indirectly via each
        // model — but since all models share the same Connection, we just use
        // the Category model's connection reference as a convenient vehicle.
        // The cleaner alternative is to inject the Connection directly; for now
        // we keep the service dependency on models only.
        $existing = $this->categoryModel->findBySlug($slug);

        // Delegate to the correct model finder based on table name
        $existing = match ($table) {
            'categories' => $this->categoryModel->findBySlug($slug),
            'brands'     => $this->brandModel->findBySlug($slug),
            'products'   => $this->productModel->findBySlug($slug),
            default      => null,
        };

        return $existing !== null;
    }

    /**
     * Throw a ValidationException if the slug is already taken.
     */
    private function assertSlugUnique(string $slug, string $table): void
    {
        if ($this->slugExists($slug, $table)) {
            throw new ValidationException(['slug' => "The slug '{$slug}' is already in use"]);
        }
    }

    // -------------------------------------------------------------------------
    // Product enrichment
    // -------------------------------------------------------------------------

    /**
     * Attach variants and attributes to a product array.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function enrichProduct(array $product): array
    {
        $productId            = (int) $product['id'];
        $product['variants']  = $this->variantModel->findByProduct($productId);
        $product['attributes'] = $this->attributeModel->findByProduct($productId);

        return $product;
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    /**
     * Assert that required keys are present and non-empty in $data.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $required
     */
    private function validateRequired(array $data, array $required): void
    {
        $errors = [];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
