<?php

declare(strict_types=1);

namespace App\Modules\Identity\Controllers;

use App\Core\Auth\JwtHandler;
use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Services\UserService;
use App\Modules\Identity\Validators\AuthValidator;

/**
 * AuthController — OTP-based authentication and self-service profile endpoints.
 *
 * Public:
 *   POST /v1/auth/otp/request  → requestOtp()
 *   POST /v1/auth/otp/verify   → verifyOtp()
 *
 * Authenticated:
 *   GET    /v1/me              → me()
 *   PUT    /v1/me              → updateProfile()
 *   DELETE /v1/me/account      → deleteAccount()
 */
readonly class AuthController
{
    public function __construct(
        private OtpService    $otpService,
        private UserService   $userService,
        private JwtHandler    $jwtHandler,
        private AuthValidator $validator,
    ) {}

    // -------------------------------------------------------------------------
    // Public endpoints
    // -------------------------------------------------------------------------

    /**
     * POST /v1/auth/otp/request
     *
     * Body: { "mobile": "9876543210" }
     */
    public function requestOtp(Request $request): Response
    {
        $mobile = $this->validator->validateMobile($request->getBody('mobile'));

        $otp = $this->otpService->generate($mobile);

        // In production this would dispatch an SMS via an async queue.
        // For now, include the OTP in the response only in non-production envs.
        $responseData = ['message' => 'OTP sent successfully'];

        if (\App\Core\Config\Config::env('APP_ENV', 'production') !== 'production') {
            $responseData['otp'] = $otp; // Dev/staging convenience
        }

        return Response::json($responseData, 200);
    }

    /**
     * POST /v1/auth/otp/verify
     *
     * Body: { "mobile": "9876543210", "otp": "123456" }
     */
    public function verifyOtp(Request $request): Response
    {
        ['mobile' => $mobile, 'otp' => $otp] = $this->validator->validateOtpVerification(
            $request->getBody() ?? [],
        );

        $valid = $this->otpService->verify($mobile, $otp);

        if (!$valid) {
            throw new ValidationException(['otp' => 'Invalid or expired OTP']);
        }

        $user  = $this->userService->findOrCreate($mobile);
        $token = $this->jwtHandler->encode([
            'user_id' => $user['id'],
            'mobile'  => $user['mobile'],
            'roles'   => $user['roles'] ?? [],
        ]);

        return Response::json([
            'token' => $token,
            'user'  => $this->sanitiseUser($user),
        ]);
    }

    // -------------------------------------------------------------------------
    // Authenticated endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/me
     */
    public function me(Request $request): Response
    {
        $userId = $this->resolveUserId($request);
        $user   = $this->userService->getOrFail($userId);

        return Response::json($this->sanitiseUser($user));
    }

    /**
     * PUT /v1/me
     *
     * Body (all optional): { "name": "...", "email": "...", "locale": "hi" }
     */
    public function updateProfile(Request $request): Response
    {
        $userId  = $this->resolveUserId($request);
        $payload = $this->validator->validateProfileUpdate($request->getBody() ?? []);
        $user    = $this->userService->updateProfile($userId, $payload);

        return Response::json($this->sanitiseUser($user));
    }

    /**
     * DELETE /v1/me/account
     *
     * Schedules account for deletion (soft). Returns 202 Accepted.
     */
    public function deleteAccount(Request $request): Response
    {
        $userId = $this->resolveUserId($request);
        $this->userService->requestDeletion($userId);

        return Response::json(
            ['message' => 'Account deletion scheduled. Access will be revoked within 30 days.'],
            202,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveUserId(Request $request): string
    {
        $userCtx = $request->getParam('_user');

        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new \App\Core\Exception\HttpException('Invalid authentication context', 401);
        }

        return (string) $userCtx['user_id'];
    }

    private function sanitiseUser(array $user): array
    {
        // Never return internal/sensitive fields to the caller
        unset($user['password'], $user['deleted_at']);
        return $user;
    }
}
