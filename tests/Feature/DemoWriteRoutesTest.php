<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AbsentInDemo;
use App\Http\Middleware\LimitDemoTextWrites;
use App\Http\Middleware\ReadOnlyInDemo;
use App\Models\Account;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public demo is one shared account with no sign-up in front, so every route that changes something
 * is placed in one of four groups here. A new write route fails this test until it is placed.
 */
final class DemoWriteRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** Answer 404 on the demo: integrations, agents, website forms, anything that reaches a third party. */
    private const array ABSENT = [
        'agent.verb', 'mcp.crm', 'DELETE mcp', 'intake.store',
        'client-invoice-drafts.store',
        'integrations.update', 'integrations.sync', 'integrations.disconnect',
        'projects.connect-trello', 'projects.disconnect-trello', 'projects.sync-trello', 'projects.trello-list-mapping',
        'agent-tokens.store', 'agent-tokens.update', 'agent-tokens.destroy',
        'settings.forms.store', 'settings.forms.update', 'settings.forms.retry',
        'settings.forms.turnstile.update', 'settings.forms.turnstile.destroy',
        'settings.forms.webhook.store', 'settings.forms.webhook.destroy',
        'settings.sender.update', 'settings.sender.test',
    ];

    /** Shown and never saved on the demo: the account's settings, its logins and its message queue. */
    private const array READ_ONLY = [
        'users.store', 'users.update', 'users.destroy', 'users.restore',
        'messages.cancel', 'messages.resend',
        'settings.modules.update', 'settings.leadgen.update', 'settings.client-fields.update',
        'settings.sms.templates.store', 'settings.sms.templates.update', 'settings.sms.templates.destroy',
        'settings.sms.rules.store', 'settings.sms.rules.update', 'settings.sms.rules.destroy',
        'settings.sms.sending',
        'settings.email.templates.store', 'settings.email.templates.update', 'settings.email.templates.destroy',
    ];

    /** Open to a visitor, inside the per-visitor write budget and the text cap. */
    private const array BUDGETED = [
        'clients.store', 'clients.update', 'clients.destroy', 'clients.restore', 'clients.pin', 'clients.transition-stage',
        'clients.fields.update', 'clients.month-close-type', 'clients.report-mode', 'clients.update-report-baseline',
        'client-billed.task.update', 'client-billed.task.destroy',
        'client-documents.store', 'client-documents.destroy',
        'lifecycle-events.store', 'lifecycle-events.update-note',
        'client-months.close', 'client-months.reopen', 'month-close.tick',
        'projects.store', 'projects.update', 'projects.destroy',
        'client-reports.store', 'client-reports.update', 'client-reports.destroy', 'client-reports.finalize',
        'client-reports.mark-sent', 'client-reports.opening-balance', 'client-reports.regenerate', 'client-reports.reopen',
        'client-retainers.store', 'client-retainers.update', 'client-retainers.destroy',
        'client-summary-emails.store', 'client-summary-emails.update', 'client-summary-emails.mark-sent',
        'client-summary-emails.send', 'client-summary-emails.tick',
        'contacts.store', 'contacts.update', 'contacts.destroy', 'contacts.restore',
        'day.add.store', 'day.close', 'day.reopen', 'day.hidden.restore', 'day.hidden.client', 'day.hidden.toggle',
        'day.picks.store', 'day.picks.destroy', 'day.picks.dismiss', 'day.picks.complete',
        'day.tasks.complete', 'day.tasks.uncomplete',
        'leads.store', 'leads.update', 'leads.destroy', 'leads.restore', 'leads.convert', 'leads.transition-stage',
        'lead-events.destroy',
        'profile.appearance.update', 'profile.tasks.update',
        'repositories.store', 'repositories.update', 'repositories.destroy',
        'settings.email.preview', 'settings.sms.preview',
        'tasks.store', 'tasks.update', 'tasks.destroy', 'tasks.archived', 'tasks.brief', 'tasks.description',
        'tasks.list-name', 'tasks.reportable',
        'task-attachments.store', 'task-attachments.destroy', 'task-links.store', 'task-links.destroy',
        'time-entries.store', 'time-entries.start', 'time-entries.stop', 'time-entries.update', 'time-entries.destroy',
    ];

    /** Limited by something of their own, named where there is a limiter: the splash, a page-view counter; sign-in, Horizon's gate and the framework's routes have theirs elsewhere. */
    private const array OWN_LIMIT = [
        'login.store' => null, 'logout' => null, 'GET|POST|HEAD broadcasting/auth' => null,
        'waitlist.store' => 'waitlist', 'clients.view' => 'client-views',
        'horizon.jobs-batches.retry' => null, 'horizon.retry-jobs.show' => null, 'horizon.monitoring.store' => null, 'horizon.monitoring-tag.destroy' => null,
    ];

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function readOnlyWrites(): array
    {
        return [
            'a new login' => ['post', '/users', ['first_name' => 'Nowa', 'last_name' => 'Osoba', 'email' => 'nowa@example.test', 'password' => 'a-long-password-1', 'owner' => true]],
            'a module switch' => ['patch', '/settings/modules', ['month_close' => true]],
            'the SMS provider' => ['patch', '/settings/sms/sending', ['provider' => 'smsapi']],
            'a text template' => ['post', '/settings/sms/templates', ['name' => 'Wizyta', 'body' => 'Do zobaczenia']],
            'a lead source' => ['patch', '/settings/leadgen', ['sources' => ['visitor' => 'Visitor']]],
        ];
    }

    public function test_every_write_route_is_placed_in_exactly_one_demo_group(): void
    {
        $placed = [...self::ABSENT, ...self::READ_ONLY, ...self::BUDGETED, ...array_keys(self::OWN_LIMIT)];
        $routes = array_keys($this->writeRoutes());

        $this->assertSame([], array_values(array_diff($routes, $placed)), 'Write routes with no demo group.');
        $this->assertSame([], array_values(array_diff($placed, $routes)), 'Demo groups name a route that is gone.');
        $this->assertSame([], array_keys(array_filter(array_count_values($placed), fn (int $times): bool => $times > 1)), 'A route sits in two demo groups.');
    }

    public function test_each_group_carries_the_guard_it_promises(): void
    {
        $routes = $this->writeRoutes();
        $budget = ThrottleRequests::class.':demo-writes';

        foreach (self::ABSENT as $name) {
            $this->assertContains(AbsentInDemo::class, $routes[$name], "{$name} is not absent on the demo.");
        }
        foreach (self::READ_ONLY as $name) {
            $this->assertContains(ReadOnlyInDemo::class, $routes[$name], "{$name} is not read-only on the demo.");
            $this->assertNotContains(AbsentInDemo::class, $routes[$name], "{$name} is both absent and read-only.");
        }
        foreach (self::BUDGETED as $name) {
            $this->assertContains($budget, $routes[$name], "{$name} is outside the demo write budget.");
            $this->assertContains(LimitDemoTextWrites::class, $routes[$name], "{$name} is outside the demo text cap.");
            $this->assertSame([], array_intersect([AbsentInDemo::class, ReadOnlyInDemo::class], $routes[$name]), "{$name} is budgeted and refused at once.");
        }
        foreach (array_filter(self::OWN_LIMIT) as $name => $limiter) {
            $this->assertContains(ThrottleRequests::class.':'.$limiter, $routes[$name], "{$name} lost its own limit.");
        }
    }

    /** @param  array<string, mixed>  $payload */
    #[DataProvider('readOnlyWrites')]
    public function test_a_settings_write_is_refused_on_the_demo_and_nothing_is_saved(string $method, string $uri, array $payload): void
    {
        $owner = $this->owner();
        $before = $this->settingsState($owner);
        config(['app.demo' => true]);

        $this->actingAs($owner)->from('/settings/general')->{$method}($uri, $payload)
            ->assertRedirect('/settings/general')
            ->assertSessionHas('error', 'Settings are read-only in the demo.')
            ->assertSessionHasErrors(['demo' => 'Settings are read-only in the demo.']);
        $this->actingAs($owner)->json($method, $uri, $payload)->assertForbidden();

        $this->assertSame($before, $this->settingsState($owner));
    }

    public function test_the_settings_page_says_it_is_read_only_on_the_demo_only(): void
    {
        config(['inertia.ssr.enabled' => false]);
        $owner = $this->owner();

        $this->actingAs($owner)->get('/settings/general')->assertInertia(fn (Assert $page) => $page->where('read_only', false));

        config(['app.demo' => true]);

        $this->actingAs($owner)->get('/settings/general')->assertInertia(fn (Assert $page) => $page->where('read_only', true));
        $this->actingAs($owner)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('read_only', true));
    }

    public function test_the_same_write_is_saved_outside_the_demo(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/users', ['first_name' => 'Nowa', 'last_name' => 'Osoba', 'email' => 'nowa@example.test', 'password' => 'a-long-password-1', 'owner' => false])
            ->assertSessionHasNoErrors();

        $this->assertTrue(User::query()->where('email', 'nowa@example.test')->exists());
    }

    private function owner(): User
    {
        $account = Account::create(['name' => 'Demo', 'is_test' => true]);

        return User::factory()->create(['account_id' => $account->id, 'owner' => true]);
    }

    /** @return array<string, mixed> */
    private function settingsState(User $owner): array
    {
        return [
            'users' => User::query()->count(),
            'account' => Account::query()->findOrFail($owner->account_id)->settings,
            'settings' => Setting::query()->orderBy('id')->get(['scope', 'data'])->toArray(),
            'templates' => MessageTemplate::query()->count(),
            'rules' => MessageRule::query()->count(),
        ];
    }

    /** @return array<string, list<string>> the resolved middleware of every route that answers a write method, by name */
    private function writeRoutes(): array
    {
        $routes = [];
        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
                continue;
            }
            $routes[$route->getName() ?? implode('|', $route->methods()).' '.$route->uri()] = Router::gatherRouteMiddleware($route);
        }

        return $routes;
    }
}
