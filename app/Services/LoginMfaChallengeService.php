<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Post-password MFA login challenges (TOTP / Microsoft Authenticator).
 * Mirrors LoginOtpService challenge lifecycle without storing an emailed code.
 */
class LoginMfaChallengeService
{
    public const EXPIRE_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /**
     * @return array{challenge: string}
     */
    public function create(User $user): array
    {
        $challenge = Str::random(64);

        DB::table('login_mfa_challenges')->updateOrInsert(
            ['email' => strtolower((string) $user->email)],
            [
                'challenge' => Hash::make($challenge),
                'attempts' => 0,
                'created_at' => now(),
            ]
        );

        return ['challenge' => $challenge];
    }

    /**
     * Validate challenge + TOTP. Returns the user on success, or null on failure.
     */
    public function consume(string $email, string $challenge, string $code, TotpService $totp): ?User
    {
        $email = strtolower(trim($email));
        $row = DB::table('login_mfa_challenges')->where('email', $email)->first();

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

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user || ! $user->hasMfaEnabled() || ! is_string($user->mfa_secret) || $user->mfa_secret === '') {
            $this->forget($email);

            return null;
        }

        if (! $totp->verify($user->mfa_secret, $code)) {
            $attempts = ((int) $row->attempts) + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->forget($email);
            } else {
                DB::table('login_mfa_challenges')
                    ->where('email', $email)
                    ->update(['attempts' => $attempts]);
            }

            return null;
        }

        $this->forget($email);

        return $user;
    }

    public function forget(string $email): void
    {
        DB::table('login_mfa_challenges')
            ->where('email', strtolower($email))
            ->delete();
    }
}
