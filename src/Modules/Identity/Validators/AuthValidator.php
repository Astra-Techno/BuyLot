<?php

declare(strict_types=1);

namespace App\Modules\Identity\Validators;

use App\Core\Exception\ValidationException;

class AuthValidator
{
    /**
     * Valid Indian mobile number: 10 digits, starting with 6–9.
     */
    public function validateMobile(mixed $mobile): string
    {
        if (empty($mobile)) {
            throw new ValidationException(['mobile' => 'Mobile number is required']);
        }

        $mobile = (string) $mobile;
        // Strip optional +91 or 0 prefix before 10 digits
        $mobile = preg_replace('/^(\+91|0)/', '', $mobile);
        $mobile = trim($mobile);

        if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
            throw new ValidationException(['mobile' => 'Invalid Indian mobile number (must be 10 digits starting with 6–9)']);
        }

        return $mobile;
    }

    /**
     * OTP is exactly 6 decimal digits.
     */
    public function validateOtp(mixed $otp): string
    {
        if (empty($otp) && $otp !== '000000') {
            throw new ValidationException(['otp' => 'OTP is required']);
        }

        $otp = (string) $otp;

        if (!preg_match('/^\d{6}$/', $otp)) {
            throw new ValidationException(['otp' => 'OTP must be exactly 6 digits']);
        }

        return $otp;
    }

    /**
     * Validate mobile + OTP together; return sanitised pair.
     *
     * @return array{mobile: string, otp: string}
     */
    public function validateOtpVerification(array $data): array
    {
        $errors = [];
        $mobile = null;
        $otp    = null;

        try {
            $mobile = $this->validateMobile($data['mobile'] ?? null);
        } catch (ValidationException $e) {
            $errors = array_merge($errors, $e->getErrors());
        }

        try {
            $otp = $this->validateOtp($data['otp'] ?? null);
        } catch (ValidationException $e) {
            $errors = array_merge($errors, $e->getErrors());
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return ['mobile' => $mobile, 'otp' => $otp];
    }

    /**
     * Validate profile update fields; return only the allowed/non-empty subset.
     */
    public function validateProfileUpdate(array $data): array
    {
        $errors  = [];
        $allowed = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                $errors['name'] = 'Name cannot be empty';
            } elseif (strlen($name) < 2) {
                $errors['name'] = 'Name must be at least 2 characters';
            } elseif (strlen($name) > 100) {
                $errors['name'] = 'Name may not exceed 100 characters';
            } else {
                $allowed['name'] = $name;
            }
        }

        if (array_key_exists('email', $data)) {
            $email = trim((string) ($data['email'] ?? ''));
            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Invalid email address';
                } else {
                    $allowed['email'] = strtolower($email);
                }
            }
        }

        if (array_key_exists('locale', $data)) {
            $locale = trim((string) ($data['locale'] ?? ''));
            $supported = ['en', 'hi', 'ta', 'te', 'kn', 'mr', 'bn', 'gu'];
            if (!in_array($locale, $supported, true)) {
                $errors['locale'] = 'Unsupported locale; accepted: ' . implode(', ', $supported);
            } else {
                $allowed['locale'] = $locale;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        if (empty($allowed)) {
            throw new ValidationException(['update' => 'No valid fields to update']);
        }

        return $allowed;
    }
}
