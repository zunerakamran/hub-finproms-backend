<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Copy control-plane hub settings from the shared registry onto the
 * white-label hub's own database (the deploy that serves that site).
 */
class WhiteLabelHubSyncService
{
    public function __construct(
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    /**
     * Push branding, functionalities, capabilities, and credits to the remote hubs row.
     *
     * @throws InvalidArgumentException
     */
    public function pushSettings(Hub $hub): void
    {
        if ($hub->isShared()) {
            return;
        }

        if (! $hub->hasRemoteDatabaseConfigured()) {
            throw new InvalidArgumentException(
                'Remote database credentials are incomplete for hub "'.$hub->name.'". '
                .'White-label functionalities and users cannot be updated until deploy wiring is complete.'
            );
        }

        try {
            $this->remoteDb->run($hub, function (string $connection) use ($hub): void {
                $now = now();
                $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
                foreach (ActingHubService::CONTROL_PLANE_ROLES as $role) {
                    unset($roleCaps[$role]);
                }

                $payload = [
                    'name' => $hub->name,
                    'type' => Hub::TYPE_WHITE_LABEL,
                    'is_active' => $hub->is_active ? 1 : 0,
                    'primary_color' => $hub->primary_color,
                    'secondary_color' => $hub->secondary_color,
                    'logo_url' => $hub->logo_url,
                    'favicon_url' => $hub->favicon_url,
                    'from_email' => $hub->from_email,
                    'frontend_url' => $hub->frontend_url,
                    'checklist' => json_encode($hub->resolvedChecklist()),
                    'role_capabilities' => json_encode($roleCaps),
                    'subscriber_credits' => $hub->subscriber_credits,
                    'advisor_billing_renew_day' => $hub->advisor_billing_renew_day,
                    'updated_at' => $now,
                ];

                $schema = DB::connection($connection)->getSchemaBuilder();
                $payload = array_filter(
                    $payload,
                    fn (string $column) => $schema->hasColumn('hubs', $column),
                    ARRAY_FILTER_USE_KEY
                );

                $query = DB::connection($connection)->table('hubs')->where('slug', $hub->slug);
                if ($query->exists()) {
                    $query->update($payload);
                } else {
                    $payload['slug'] = $hub->slug;
                    $payload['created_at'] = $now;
                    DB::connection($connection)->table('hubs')->insert($payload);
                }
            });
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                'Could not update the white-label database for "'.$hub->name.'": '.$e->getMessage()
                .' Check deploy wiring (DB host must be reachable from the shared hub — localhost only works if both sites share the same MySQL server).'
            );
        }
    }
}
