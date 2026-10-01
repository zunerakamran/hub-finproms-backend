<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginOtpService
{
    /** Minutes an OTP remains valid. */
    public const EXPIRE_MINUTES = 10;

    /** Seconds before another OTP may be requested for the same email. */
    public const THROTTLE_SECONDS = 60;

    /** Failed verify attempts before the challenge is invalidated. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Create (or replace) a login OTP challenge for the user.
     *
     * @return array{challenge: string, code: string}
     */
    public function create(User $user): array
    {
        $challenge = Str::random(64);
        $code = (string) random_int(100000, 999999);

        DB::table('login_otp_tokens')->updateOrInsert(
            ['email' => strtolower((string) $user->email)],
            [
                'challenge' => Hash::make($challenge),
                'code' => Hash::make($code),
                'attempts' => 0,
                'created_at' => now(),
            ]
        );

        return [
            'challenge' => $challenge,
            'code' => $code,
        ];
    }

    public function canResend(string $email): bool
    {
        $row = DB::table('login_otp_tokens')
            ->where('email', strtolower($email))
            ->first();

        if (! $row || ! $row->created_at) {
            return true;
        }

        return Carbon::parse($row->created_at)
            ->addSeconds(self::THROTTLE_SECONDS)
            ->isPast();
    }

    /**
     * Validate challenge + OTP. Returns the user on success, or null on failure.
     * Increments attempts; deletes the row when expired, maxed out, or consumed.
     */
    public function consume(string $email, string $challenge, string $code): ?User
    {
        $email = strtolower(trim($email));
        $row = DB::table('login_otp_tokens')->where('email', $email)->first();

        if (! $row) {
            return null;
        }

        if ($row->created_at && Carbon::parse($row->created_at)->addMinutes(self::EXPIRE_MINUTES)->isPast()) {
            $this->forget($email);

            return null;
        }

        if (! Hash::check($challenge, $row->challenge)) {
            return null;
        }

        if (! Hash::check($code, $row->code)) {
            $attempts = ((int) $row->attempts) + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->forget($email);
            } else {
                DB::table('login_otp_tokens')
                    ->where('email', $email)
                    ->update(['attempts' => $attempts]);
            }

            return null;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $this->forget($email);

        return $user;
    }

    /**
     * Confirm a pending challenge exists for resend (password already proved).
     */
    public function challengeMatches(string $email, string $challenge): bool
    {
        $email = strtolower(trim($email));
        $row = DB::table('login_otp_tokens')->where('email', $email)->first();

        if (! $row) {
            return false;
        }

        if ($row->created_at && Carbon::parse($row->created_at)->addMinutes(self::EXPIRE_MINUTES)->isPast()) {
            $this->forget($email);

            return false;
        }

        return Hash::check($challenge, $row->challenge);
    }

    public function forget(string $email): void
    {
        DB::table('login_otp_tokens')
            ->where('email', strtolower($email))
            ->delete();
    }
}
