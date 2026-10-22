<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ComplianceSeparationService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ComplianceSeparationServiceTest extends TestCase
{
    public function test_owner_and_on_behalf_are_conflicted(): void
    {
        $service = new ComplianceSeparationService;
        $owner = new User;
        $owner->id = 10;
        $staff = new User;
        $staff->id = 20;
        $other = new User;
        $other->id = 30;

        $this->assertTrue($service->actorIsConflicted($owner, 10, 20));
        $this->assertTrue($service->actorIsConflicted($staff, 10, 20));
        $this->assertFalse($service->actorIsConflicted($other, 10, 20));
    }

    public function test_acting_as_subject_is_also_conflicted(): void
    {
        $service = new ComplianceSeparationService;
        $staff = new User;
        $staff->id = 20;

        // Staff acting as advisor 10 must not decide on advisor 10's request.
        $this->assertTrue($service->actorIsConflicted($staff, 10, null, 10));
        $this->assertFalse($service->actorIsConflicted($staff, 99, null, 10));
    }

    public function test_assert_blocks_conflicted_actor(): void
    {
        $service = new ComplianceSeparationService;
        $owner = new User;
        $owner->id = 5;

        $this->expectException(ValidationException::class);
        $service->assertActorNotConflicted($owner, 5, null);
    }

    public function test_exclude_conflicted_reviewers(): void
    {
        $service = new ComplianceSeparationService;
        $a = new User;
        $a->id = 1;
        $b = new User;
        $b->id = 2;
        $c = new User;
        $c->id = 3;

        $filtered = $service->excludeConflictedReviewers(collect([$a, $b, $c]), 2, 3);
        $this->assertSame([1], $filtered->pluck('id')->all());
    }
}
