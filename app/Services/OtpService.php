<?php

namespace App\Services;

use App\Models\Otp;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(protected SmsGatewayInterface $smsGateway) {}

    public function issue(string $phone, string $type, ?int $userId = null, ?array $payload = null): Otp
    {
        Otp::where('phone', $phone)
            ->where('type', $type)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();

        $otp = Otp::create([
            'user_id' => $userId,
            'phone' => $phone,
            'code' => $code,
            'type' => $type,
            'payload' => $payload,
            'expires_at' => now()->addMinutes(config('otp.expiry_minutes', 5)),
        ]);

        $this->smsGateway->send(
            $phone,
            "Your Marine Booking verification code is {$code}. It expires in " . config('otp.expiry_minutes', 5) . ' minutes.'
        );

        return $otp;
    }

    public function verify(string $phone, string $code): Otp
    {
        $otp = Otp::where('phone', $phone)
            ->where('code', $code)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired verification code.'],
            ]);
        }

        $otp->update(['consumed_at' => now()]);

        return $otp;
    }

    /** Sends a fresh code of the same kind as the last one, unless the cooldown has not passed. */
    public function resend(string $phone): void
    {
        if (! $this->canResend($phone)) {
            throw ValidationException::withMessages([
                'phone' => ['Please wait before requesting another code.'],
            ]);
        }

        $last = $this->latest($phone);

        if (! $last) {
            throw ValidationException::withMessages([
                'phone' => ['No previous verification request found for this phone.'],
            ]);
        }

        $this->issue($phone, $last->type, $last->user_id, $last->payload);
    }

    public function canResend(string $phone): bool
    {
        $last = Otp::where('phone', $phone)->latest('created_at')->first();

        if (! $last) {
            return true;
        }

        return $last->created_at->diffInSeconds(now()) >= config('otp.resend_cooldown_seconds', 60);
    }

    /**
     * Returns the most recent OTP row for a phone (any type), so resend()
     * can reissue with the same type + payload without the caller needing
     * to already know whether it was a registration or login attempt.
     */
    public function latest(string $phone): ?Otp
    {
        return Otp::where('phone', $phone)->latest('created_at')->first();
    }

    /**
     * Is the fixed code in use? See config/otp.php: an explicit OTP_DEFAULT_CODE_ENABLED wins in
     * any environment; when it is not set at all, only local and testing use the fixed code.
     */
    protected function defaultCodeEnabled(): bool
    {
        $code = config('otp.default_code');
        $switch = config('otp.default_code_enabled');

        $enabled = ($switch === null || $switch === '')
            ? app()->environment(config('otp.default_code_environments', []))
            : filter_var($switch, FILTER_VALIDATE_BOOLEAN);

        if (! $enabled) {
            return false;
        }

        if ($code === null || $code === '') {
            // Fail closed: a switch that is on with no code behind it must not weaken anything.
            Log::error('OTP_DEFAULT_CODE_ENABLED is on but OTP_DEFAULT_CODE is empty, so random codes are being used.');

            return false;
        }

        if (app()->environment('production')) {
            Log::warning('A FIXED one-time code is being issued in PRODUCTION (OTP_DEFAULT_CODE_ENABLED is on). Anyone who knows a phone number can sign in as that person. Turn it off before real users sign in.');
        }

        return true;
    }

    protected function generateCode(): string
    {
        if ($this->defaultCodeEnabled()) {
            return (string) config('otp.default_code');
        }

        $length = config('otp.length', 4);
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
