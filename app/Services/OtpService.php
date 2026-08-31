<?php

namespace App\Services;

use App\Models\Otp;
use App\Services\Sms\Contracts\SmsGatewayInterface;
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

    protected function generateCode(): string
    {
        $defaultCode = config('otp.default_code');
        $allowedEnvironments = config('otp.default_code_environments', []);

        if ($defaultCode && app()->environment($allowedEnvironments) && ! app()->environment('production')) {
            return (string) $defaultCode;
        }

        $length = config('otp.length', 4);
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
