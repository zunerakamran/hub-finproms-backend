<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\WhiteLabelDatabaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplianceStatusDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    private ?string $remoteSqlitePath = null;

    protected function tearDown(): void
    {
        if ($this->remoteSqlitePath && is_file($this->remoteSqlitePath)) {
            @unlink($this->remoteSqlitePath);
        }

        parent::tearDown();
    }

    public function test_hub_endpoint_exposes_default_compliance_status_labels(): void
    {
        $this->createSharedHub();

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.compliance_status_labels.pending', 'Pending')
            ->assertJsonPath('hub.compliance_status_labels.approved_with_feedback', 'Approved with Feedback');
    }

    public function test_authorized_role_can_update_compliance_status_display_names(): void
    {
        $hub = $this->createSharedHub();
        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $this->putJson('/api/client-admin/compliance-status-display-names', [
            'statuses' => [
                'pending' => 'Awaiting review',
                'approved_with_feedback' => 'Approved (notes)',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('compliance_status_labels.pending', 'Awaiting review')
            ->assertJsonPath('compliance_status_labels.approved_with_feedback', 'Approved (notes)')
            ->assertJsonPath('compliance_status_labels.approved', 'Approved');

        $hub->refresh();
        $this->assertSame('Awaiting review', $hub->compliance_status_display_names['pending']);
        $this->assertSame(
            'Approved (notes)',
            $hub->complianceStatusLabel('Approved with Feedback')
        );
    }

    public function test_white_label_compliance_status_names_sync_to_remote(): void
    {
        [, $hub] = $this->actingPowerAdminOnWiredHub();

        $this->putJson('/api/client-admin/compliance-status-display-names', [
            'statuses' => [
                'rejected' => 'Declined',
            ],
        ])->assertOk();

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $row = DB::connection($connection)->table('hubs')->where('slug', 'myhub')->first();
            $names = is_string($row->compliance_status_display_names)
                ? json_decode($row->compliance_status_display_names, true)
                : (array) $row->compliance_status_display_names;
            $this->assertSame('Declined', $names['rejected'] ?? null);
        } finally {
            $remote->disconnect($hub);
        }
    }

    private function createSharedHub(array $extra = []): Hub
    {
        return Hub::query()->create(array_merge([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ], $extra));
    }

    private function actingPowerAdminOnWiredHub(): array
    {
        $this->createSharedHub();
        $hub = $this->makeWiredWhiteLabelHub();

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);
        Sanctum::actingAs($admin);

        return [$admin, $hub];
    }

    private function makeWiredWhiteLabelHub(): Hub
    {
        $this->remoteSqlitePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wl-status-'.uniqid('', true).'.sqlite';
        touch($this->remoteSqlitePath);

        $hub = Hub::query()->create([
            'name' => 'My Hub',
            'slug' => 'myhub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
            'frontend_url' => 'https://myhub.fin-proms.com',
            'db_driver' => 'sqlite',
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_database' => $this->remoteSqlitePath,
            'db_username' => 'test',
            'db_password' => 'secret',
        ]);

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);

        Schema::connection($connection)->create('hubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('white_label');
            $table->boolean('is_active')->default(true);
            $table->string('primary_color')->nullable();
            $table->string('secondary_color')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('from_email')->nullable();
            $table->string('frontend_url')->nullable();
            $table->json('checklist')->nullable();
            $table->json('role_capabilities')->nullable();
            $table->json('role_display_names')->nullable();
            $table->json('compliance_status_display_names')->nullable();
            $table->unsignedInteger('subscriber_credits')->nullable();
            $table->unsignedTinyInteger('advisor_billing_renew_day')->nullable();
            $table->timestamps();
        });

        DB::connection($connection)->table('hubs')->insert([
            'name' => 'My Hub',
            'slug' => 'myhub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => 1,
            'checklist' => json_encode(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $remote->disconnect($hub);

        return $hub;
    }
}
