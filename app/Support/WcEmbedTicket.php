<?php

namespace App\Support;

use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\HubService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Short-lived tickets for hub-scoped WC embed proxy (remote DB resolve).
 *
 * Anonymous callers must not open content-hub remote databases. Authenticated
 * operators mint a ticket (or present a Sanctum session); the ticket is also
 * stored in an HttpOnly cookie so iframe relative asset requests keep working
 * after the query string is dropped.
 */
class WcEmbedTicket
{
    public const QUERY_KEY = 'embed_ticket';

    public const COOKIE = 'wc_embed_ticket';

    public const TTL_SECONDS = 900;

    public static function issue(int $hubId, int $templateRequestId, ?int $ttlSeconds = null): string
    {
        $ttl = max(60, $ttlSeconds ?? self::TTL_SECONDS);
        $exp = time() + $ttl;
        $payload = $hubId.'.'.$templateRequestId.'.'.$exp;
        $sig = hash_hmac('sha256', $payload, self::signingKey());

        return $payload.'.'.$sig;
    }

    public static function verify(?string $ticket, int $hubId, int $templateRequestId): bool
    {
        if (! is_string($ticket) || $ticket === '') {
            return false;
        }

        $parts = explode('.', $ticket);
        if (count($parts) !== 4) {
            return false;
        }

        [$ticketHubId, $ticketTrId, $exp, $sig] = $parts;
        if (! ctype_digit($ticketHubId) || ! ctype_digit($ticketTrId) || ! ctype_digit($exp)) {
            return false;
        }
        if ((int) $ticketHubId !== $hubId || (int) $ticketTrId !== $templateRequestId) {
            return false;
        }
        if ((int) $exp < time()) {
            return false;
        }

        $payload = $ticketHubId.'.'.$ticketTrId.'.'.$exp;
        $expected = hash_hmac('sha256', $payload, self::signingKey());

        return hash_equals($expected, $sig);
    }

    public static function cookie(string $ticket, ?int $ttlSeconds = null): SymfonyCookie
    {
        $minutes = (int) ceil(max(60, $ttlSeconds ?? self::TTL_SECONDS) / 60);

        return Cookie::make(
            self::COOKIE,
            $ticket,
            $minutes,
            '/api/website-compliance/embed-site',
            null,
            config('session.secure', false),
            true,
            false,
            'lax'
        );
    }

    /**
     * Whether this user may mint / use a hub-scoped embed for $hubId.
     */
    public static function userCanAccessRemoteEmbed(User $user, int $hubId): bool
    {
        $target = Hub::query()->find($hubId);
        if (! $target || ! $target->is_active || ! $target->isContentHub()) {
            return false;
        }

        $acting = app(ActingHubService::class);
        if ($acting->canControl($user)) {
            // Control-plane operators: only the hub they are currently acting on
            // (or Central home — no remote embed needed there).
            return (int) $acting->actingHub($user)->id === $hubId;
        }

        $current = app(HubService::class)->current();
        if ((int) $current->id !== $hubId) {
            return false;
        }

        $gate = app(WebsiteComplianceGate::class);

        return $gate->can($user, 'wc_view_all_deployments')
            || $gate->can($user, 'wc_deploy_websites')
            || $gate->can($user, 'wc_request_deployments')
            || $gate->can($user, 'wc_edit_sections')
            || $gate->can($user, 'wc_assign_website_templates');
    }

    private static function signingKey(): string
    {
        return (string) config('app.key');
    }
}
