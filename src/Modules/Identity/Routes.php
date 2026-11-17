<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Cache\RedisCache;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Identity\Controllers\AuthController;
use App\Modules\Identity\Controllers\UserController;
use App\Modules\Identity\Middleware\RoleMiddleware;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Services\UserService;
use App\Modules\Identity\Validators\AuthValidator;

/**
 * Register all Identity module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Identity\Routes::register($router, $connection);
 */
class Routes
{
    public static function register(Router $router, Connection $connection): void
    {
        // -------------------------------------------------------------------------
        // Shared dependencies
        // -------------------------------------------------------------------------

        $redisConfig = Config::get('auth.redis', []);
        $redis = new RedisCache(
            host:     $redisConfig['host']     ?? 'localhost',
            port:     (int) ($redisConfig['port']     ?? 6379),
            database: (int) ($redisConfig['database'] ?? 0),
            password: $redisConfig['password'] ?? null,
        );

        $jwtConfig  = Config::get('auth.jwt', []);
        $jwtHandler = new JwtHandler($jwtConfig['secret'] ?? 'change-me');

        $userModel   = new User($connection);
        $otpService  = new OtpService($redis, $connection);
        $userService = new UserService($userModel);
        $validator   = new AuthValidator();

        $authController = new AuthController($otpService, $userService, $jwtHandler, $validator);
        $userController = new UserController($userService, $userModel);

        // -------------------------------------------------------------------------
        // Middleware instances
        // -------------------------------------------------------------------------

        $authMiddleware  = new AuthMiddleware($jwtHandler);

        // Admin = compliance_admin OR super_admin (matches permissions matrix)
        $adminMiddleware = new RoleMiddleware('compliance_admin', 'super_admin');

        // -------------------------------------------------------------------------
        // Public routes — no auth required
        // -------------------------------------------------------------------------

        // POST /v1/auth/otp/request
        $router->post(
            '/v1/auth/otp/request',
            static fn(Request $req) => $authController->requestOtp($req),
        );

        // POST /v1/auth/otp/verify
        $router->post(
            '/v1/auth/otp/verify',
            static fn(Request $req) => $authController->verifyOtp($req),
        );

        // -------------------------------------------------------------------------
        // Authenticated user self-service routes
        // -------------------------------------------------------------------------

        // GET /v1/me
        $router->get(
            '/v1/me',
            static fn(Request $req) => $authController->me($req),
            [$authMiddleware],
        );

        // PUT /v1/me
        $router->put(
            '/v1/me',
            static fn(Request $req) => $authController->updateProfile($req),
            [$authMiddleware],
        );

        // DELETE /v1/me/account
        $router->delete(
            '/v1/me/account',
            static fn(Request $req) => $authController->deleteAccount($req),
            [$authMiddleware],
        );

        // -------------------------------------------------------------------------
        // Admin routes — auth + admin role
        // -------------------------------------------------------------------------

        // GET /v1/admin/users
        $router->get(
            '/v1/admin/users',
            static fn(Request $req) => $userController->list($req),
            [$authMiddleware, $adminMiddleware],
        );

        // GET /v1/admin/users/{id}
        $router->get(
            '/v1/admin/users/{id}',
            static fn(Request $req) => $userController->show($req),
            [$authMiddleware, $adminMiddleware],
        );

        // PUT /v1/admin/users/{id}/status
        $router->put(
            '/v1/admin/users/{id}/status',
            static fn(Request $req) => $userController->updateStatus($req),
            [$authMiddleware, $adminMiddleware],
        );

        // POST /v1/admin/users/{id}/roles
        $router->post(
            '/v1/admin/users/{id}/roles',
            static fn(Request $req) => $userController->assignRole($req),
            [$authMiddleware, $adminMiddleware],
        );
    }
}
