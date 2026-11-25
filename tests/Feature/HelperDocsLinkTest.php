<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class HelperDocsLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_carries_an_empty_docs_url_until_the_docs_site_is_set(): void
    {
        config(['services.docs.url' => null]);
        $user = User::factory()->create(['account_id' => Account::create(['name' => 'Acc'])->id, 'owner' => true]);

        $this->actingAs($user)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('docsUrl', ''));

        config(['services.docs.url' => 'https://docs.example.com']);

        $this->actingAs($user)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('docsUrl', 'https://docs.example.com'));
    }
}
