<?php

namespace Tests\Unit;

use App\Models\Hub;
use App\Services\HubService;
use App\Support\HubInternalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class HubInternalAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_key_does_not_authorize_restore_or_gc(): void
    {
        config([
            'services.hub_backup.secret' => 'backup-only-secret',
            'services.hub_backup.restore_secret' => 'restore-only-secret',
            'services.hub_backup.gc_file_secret' => 'gc-only-secret',
            'services.hub_backup.deploy_env_secret' => 'deploy-only-secret',
            'hub.current_slug' => 'shared',
            'hub.type' => 'shared',
            'hub.is_control_plane' => false,
        ]);

        Hub::query()->create([
            'name' => 'Shared',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'backup_token' => 'per-hub-backup',
            'restore_token' => 'per-hub-restore',
            'gc_file_token' => 'per-hub-gc',
            'deploy_env_token' => 'per-hub-deploy',
        ]);
        app(HubService::class)->forgetCurrentCache();

        $backupReq = Request::create('/api/internal/hub-backups/run', 'POST');
        $backupReq->headers->set(HubInternalAuth::HEADER_BACKUP, 'per-hub-backup');
        $this->assertTrue(HubInternalAuth::authorizeCurrentHub($backupReq, HubInternalAuth::PURPOSE_BACKUP));

        $restoreWithBackup = Request::create('/api/internal/hub-backups/restore', 'POST');
        $restoreWithBackup->headers->set(HubInternalAuth::HEADER_BACKUP, 'per-hub-backup');
        $this->assertFalse(HubInternalAuth::authorizeCurrentHub($restoreWithBackup, HubInternalAuth::PURPOSE_RESTORE));

        $restoreOk = Request::create('/api/internal/hub-backups/restore', 'POST');
        $restoreOk->headers->set(HubInternalAuth::HEADER_RESTORE, 'per-hub-restore');
        $this->assertTrue(HubInternalAuth::authorizeCurrentHub($restoreOk, HubInternalAuth::PURPOSE_RESTORE));

        $gcWithBackup = Request::create('/api/internal/gc/file/1', 'GET');
        $gcWithBackup->headers->set(HubInternalAuth::HEADER_BACKUP, 'per-hub-backup');
        $this->assertFalse(HubInternalAuth::authorizeCurrentHub($gcWithBackup, HubInternalAuth::PURPOSE_GC_FILE));

        $gcOk = Request::create('/api/internal/gc/file/1', 'GET');
        $gcOk->headers->set(HubInternalAuth::HEADER_GC_FILE, 'per-hub-gc');
        $this->assertTrue(HubInternalAuth::authorizeCurrentHub($gcOk, HubInternalAuth::PURPOSE_GC_FILE));

        $deployWithBackup = Request::create('/api/internal/deploy-env/apply', 'POST');
        $deployWithBackup->headers->set(HubInternalAuth::HEADER_BACKUP, 'per-hub-backup');
        $this->assertFalse(HubInternalAuth::authorizeCurrentHub($deployWithBackup, HubInternalAuth::PURPOSE_DEPLOY_ENV));
    }

    public function test_ensure_all_tokens_mints_distinct_secrets(): void
    {
        $hub = Hub::query()->create([
            'name' => 'Shared',
            'slug' => 'shared-tokens',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
        ]);

        $tokens = HubInternalAuth::ensureAllTokens($hub);
        $hub->refresh();

        $this->assertNotSame($tokens['backup'], $tokens['restore']);
        $this->assertNotSame($tokens['backup'], $tokens['gc_file']);
        $this->assertNotSame($tokens['restore'], $tokens['deploy_env']);
        $this->assertSame($tokens['backup'], $hub->backup_token);
        $this->assertSame($tokens['restore'], $hub->restore_token);
        $this->assertSame($tokens['gc_file'], $hub->gc_file_token);
        $this->assertSame($tokens['deploy_env'], $hub->deploy_env_token);
    }

    public function test_ip_allowlist_blocks_non_matching_clients(): void
    {
        config([
            'services.hub_backup.internal_allowed_ips' => '203.0.113.10',
            'services.hub_backup.secret' => 'backup-secret',
            'hub.current_slug' => 'shared',
            'hub.type' => 'shared',
            'hub.is_control_plane' => false,
        ]);

        Hub::query()->create([
            'name' => 'Shared',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
        ]);
        app(HubService::class)->forgetCurrentCache();

        $request = Request::create('/api/internal/hub-backups/run', 'POST', server: [
            'REMOTE_ADDR' => '198.51.100.1',
        ]);
        $request->headers->set(HubInternalAuth::HEADER_BACKUP, 'backup-secret');

        $this->assertFalse(HubInternalAuth::authorizeCurrentHub($request, HubInternalAuth::PURPOSE_BACKUP));
    }
}
