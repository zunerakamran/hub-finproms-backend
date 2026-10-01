<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmailVerificationService
{
    /** Minutes a verification link remains valid. */
    public const EXPIRE_MINUTES = 60;

    /** Seconds before another verification email may be requested. */
    public const THROTTLE_SECONDS = 60;

    /**
     * Create (or replace) a hashed verification token for the user.
     * Returns the plain-text token for the email link.
     */
    public function createToken(User $user): string
    {
        $plain = Str::random(64);

        DB::table('email_verification_tokens')->updateOrInsert(
            ['email' => strtolower((string) $user->email)],
            [
                'token' => Hash::make($plain),
                'created_at' => now(),
            ]
        );

        return $plain;
    }

    /**
     * Whether a resend is allowed (anti-spam throttle).
     */
    public function canResend(string $email): bool
    {
        $row = DB::table('email_verification_tokens')
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
     * Validate the token, mark the user verified, and delete the token row.
     * Returns the verified user, or null on failure.
     */
    public function consume(string $email, string $plainToken): ?User
    {
        $email = strtolower(trim($email));
        $row = DB::table('email_verification_tokens')
            ->where('email', $email)
            ->first();

        if (! $row) {
            return null;
        }

        if ($row->created_at && Carbon::parse($row->created_at)->addMinutes(self::EXPIRE_MINUTES)->isPast()) {
            DB::table('email_verification_tokens')->where('email', $email)->delete();

            return null;
        }

        if (! Hash::check($plainToken, $row->token)) {
            return null;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) {
            DB::table('email_verification_tokens')->where('email', $email)->delete();

            return null;
        }

        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        DB::table('email_verification_tokens')->where('email', $email)->delete();

        return $user->fresh();
    }

    public function forget(string $email): void
    {
        DB::table('email_verification_tokens')
            ->where('email', strtolower($email))
            ->delete();
    }
}
