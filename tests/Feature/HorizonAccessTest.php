<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_accounts_owner_may_open_horizon_on_a_hosted_stack(): void
    {
        $operator = Account::factory()->create();
        $owner = User::factory()->for($operator)->create(['owner' => true]);

        $this->assertTrue(Gate::forUser($owner)->allows('viewHorizon'));
    }

    public function test_a_plain_user_and_another_accounts_owner_may_not(): void
    {
        $operator = Account::factory()->create();
        $member = User::factory()->for($operator)->create(['owner' => false]);
        $otherOwner = User::factory()->for(Account::factory())->create(['owner' => true]);

        $this->assertFalse(Gate::forUser($member)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($otherOwner)->allows('viewHorizon'));
    }
}
