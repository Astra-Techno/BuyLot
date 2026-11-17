<?php

declare(strict_types=1);

namespace App\Core\Auth;

use RuntimeException;

/**
 * Maps resource type strings to their Policy class implementations.
 *
 * Usage:
 *   $registry = PolicyRegistry::getInstance();
 *   $registry->register('bids', BidPolicy::class);
 *   $policy = $registry->resolve('bids'); // returns BidPolicy instance
 */
final class PolicyRegistry
{
    private static ?self $instance = null;

    /** @var array<string, class-string<Policy>> resource -> Policy FQCN */
    private array $policies = [];

    /** @var array<string, Policy> Cached policy instances */
    private array $resolved = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** Reset singleton (useful in tests). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Register a policy class for a resource type.
     *
     * @param string $resource  Resource identifier (e.g. "bids", "orders")
     * @param class-string<Policy> $policyClass  Fully qualified Policy class name
     */
    public function register(string $resource, string $policyClass): self
    {
        if (!is_subclass_of($policyClass, Policy::class)) {
            throw new RuntimeException(
                "Policy class [{$policyClass}] must extend " . Policy::class,
            );
        }

        $this->policies[$resource] = $policyClass;

        // Invalidate cached instance if re-registering
        unset($this->resolved[$resource]);

        return $this;
    }

    /**
     * Resolve a Policy instance for the given resource type.
     *
     * @throws RuntimeException if no policy is registered for the resource
     */
    public function resolve(string $resource): Policy
    {
        if (!isset($this->policies[$resource])) {
            throw new RuntimeException(
                "No authorization policy registered for resource [{$resource}]",
            );
        }

        if (!isset($this->resolved[$resource])) {
            $class = $this->policies[$resource];
            $this->resolved[$resource] = new $class();
        }

        return $this->resolved[$resource];
    }

    /**
     * Check whether a policy is registered for a resource.
     */
    public function has(string $resource): bool
    {
        return isset($this->policies[$resource]);
    }

    /**
     * Get all registered resource -> policy class mappings.
     *
     * @return array<string, class-string<Policy>>
     */
    public function all(): array
    {
        return $this->policies;
    }

    /**
     * Bulk-register multiple policies.
     *
     * @param array<string, class-string<Policy>> $map
     */
    public function registerMany(array $map): self
    {
        foreach ($map as $resource => $policyClass) {
            $this->register($resource, $policyClass);
        }

        return $this;
    }
}
