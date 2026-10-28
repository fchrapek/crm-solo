<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AgentToken;
use App\Models\Client;
use App\Models\ClientFieldDefinition;
use App\Models\Integration;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use App\Services\Leads\LeadVocabulary;
use App\Services\Messaging\MessageSettings;
use App\Services\Messaging\SmsapiProvider;
use App\Support\LocalCalendar;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ustawienia: one page, one tab per area. Shipped defaults come from
 * config/*.php; only the account's customization is stored (settings table)
 * and merged over the defaults at boot. /settings is the phone's section list
 * and, from 768 px, the Konto tab, so it carries both.
 */
final class SettingsController extends Controller
{
    public const array TABS = ['konto', 'wyglad', 'leady', 'pola-klienta', 'sms', 'integracje', 'agenci'];

    public function index(): Response
    {
        return Inertia::render('settings/index', [
            'tab' => null,
            'is_owner' => (bool) Auth::user()->owner,
            'summary' => $this->summary(),
            ...$this->account(),
        ]);
    }

    public function show(string $tab): Response
    {
        if ($tab === 'agenci') {
            Gate::authorize('manage-agent-tokens');
        }

        return Inertia::render('settings/index', [
            'tab' => $tab,
            'is_owner' => (bool) Auth::user()->owner,
            ...match ($tab) {
                'konto' => $this->account(),
                'leady' => ['leadgen' => $this->leadgen()],
                'pola-klienta' => ['client_fields' => $this->clientFields(), 'reserved_field_keys' => ClientFieldDefinition::RESERVED_KEYS],
                'sms' => $this->messages(),
                'integracje' => $this->integrations(),
                'agenci' => $this->agents(),
                default => [],
            },
        ]);
    }

    public function updateLeadgen(Request $request): RedirectResponse
    {
        $defaults = array_values((array) config('leadgen.sources', []));

        $validated = $request->validate([
            'custom_sources' => ['array'],
            'custom_sources.*' => ['string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'distinct'],
            'source_labels' => ['array'],
            'source_labels.*' => ['string', 'max:100'],
        ]);

        // Custom slugs may not shadow shipped defaults — a duplicate would be
        // two list entries for one stored value.
        $custom = array_values(array_diff($validated['custom_sources'] ?? [], $defaults));

        // Keep only labels that refer to a known slug (default or custom).
        $labels = array_intersect_key(
            $validated['source_labels'] ?? [],
            array_flip([...$defaults, ...$custom]),
        );

        $accountId = Auth::user()->account_id;
        Setting::updateOrCreate(
            ['account_id' => $accountId, 'scope' => 'leadgen'],
            ['data' => [...LeadVocabulary::stored($accountId), 'custom_sources' => $custom, 'source_labels' => $labels]],
        );

        return Redirect::back()->with('success', __('Settings saved'));
    }

    /** @return array<string, mixed> */
    private function account(): array
    {
        $me = Auth::user();

        return [
            'me' => ['id' => $me->id, 'first_name' => $me->first_name, 'last_name' => $me->last_name, 'email' => $me->email],
            'users' => $me->account->users()->withTrashed()->orderByName()->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'name' => $user->name,
                    'email' => $user->email,
                    'owner' => (bool) $user->owner,
                    'is_me' => $user->is($me),
                    'deleted_at' => $user->deleted_at,
                    'can_delete' => ! $user->isDemoUser() && ! $user->is($me) && $me->can('delete', $user),
                ])->values(),
        ];
    }

    /** @return array{default_sources: list<string>, custom_sources: list<string>, source_labels: object} */
    private function leadgen(): array
    {
        $stored = LeadVocabulary::stored(Auth::user()->account_id);

        return [
            'default_sources' => array_values((array) config('leadgen.sources', [])),
            'custom_sources' => array_values((array) ($stored['custom_sources'] ?? [])),
            'source_labels' => (object) ($stored['source_labels'] ?? []),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function clientFields(): array
    {
        $accountId = Auth::user()->account_id;
        $stored = Client::query()->where('account_id', $accountId)->whereNotNull('custom_fields')->pluck('custom_fields');

        return app(ClientFieldDefinitions::class)->list($accountId, withArchived: true)
            ->map(fn (ClientFieldDefinition $field): array => [
                ...$this->fieldPayload($field),
                'values_count' => $stored->filter(fn ($values): bool => isset(((array) $values)[$field->key]))->count(),
            ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function fieldPayload(ClientFieldDefinition $field): array
    {
        return [
            'key' => $field->key,
            'label' => $field->label,
            'type' => $field->type,
            'options' => $field->hasOptions() ? array_values((array) $field->options) : [],
            'required' => $field->required,
            'on_overview' => $field->on_overview,
            'archived' => $field->archived_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function messages(): array
    {
        $account = Auth::user()->account;
        $rules = MessageRule::query()->where('account_id', $account->id)->with('template:id,key,name')->orderBy('id')->get();
        $pending = OutboundMessage::query()->where('account_id', $account->id)->where('status', OutboundMessage::PENDING)
            ->selectRaw('rule_id, count(*) as aggregate')->groupBy('rule_id')->pluck('aggregate', 'rule_id');

        return [
            'sending' => [...app(MessageSettings::class)->sendingOf($account), 'smsapi_connected' => SmsapiProvider::connected($account) !== null],
            'templates' => MessageTemplate::query()->where('account_id', $account->id)->orderBy('id')->get()
                ->map(fn (MessageTemplate $template): array => [
                    'key' => $template->key,
                    'name' => $template->name,
                    'body' => (string) $template->body,
                    'rules' => $rules->where('template_id', $template->id)->count(),
                ])->values(),
            'rules' => $rules->map(fn (MessageRule $rule): array => [
                'key' => $rule->key,
                'template' => $rule->template?->key,
                'template_name' => $rule->template?->name,
                'trigger' => $rule->trigger_type,
                'field' => $rule->field(),
                'values' => array_values((array) ($rule->trigger_config['values'] ?? [])),
                'offset_minutes' => (int) ($rule->trigger_config['offset_minutes'] ?? 0),
                'at' => $rule->trigger_config['at'] ?? null,
                'expire_minutes' => $rule->expire_minutes,
                'enabled' => $rule->enabled,
                'pending' => (int) ($pending[$rule->id] ?? 0),
            ])->values(),
            'fields' => app(ClientFieldDefinitions::class)->list($account->id)->map($this->fieldPayload(...))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function integrations(): array
    {
        if (config('app.demo')) {
            return ['integrations' => [], 'demo' => true, 'server' => null];
        }

        $account = Auth::user()->account;
        $stored = $account->integrations()->get()->keyBy('provider');
        $boards = $account->projects()->whereNotNull('trello_board_id')->whereNull('archived_at')->count();

        $integrations = collect(Integration::PROVIDERS)->map(function (array $config, string $provider) use ($stored, $boards): array {
            /** @var Integration|null $integration */
            $integration = $stored->get($provider);
            $settings = $integration?->settings ?? [];

            return [
                'provider' => $provider,
                'name' => $config['name'],
                'is_enabled' => $integration?->is_enabled ?? false,
                'is_configured' => $integration?->isConfigured() ?? false,
                'has_api_key' => $integration?->hasValidApiKey() ?? false,
                'has_trello_api_key' => ! empty($settings['trello_api_key']),
                'has_unreadable_credentials' => ($integration?->unreadableSecrets() ?? []) !== [],
                'last_synced' => $this->stamp($integration?->last_synced_at),
                'last_sync_error' => $integration?->last_sync_error,
                'client_conflicts' => array_values($settings['client_conflicts'] ?? []),
                'boards' => $provider === 'trello' ? $boards : null,
                'syncs' => $config['syncs'] ?? true,
            ];
        })->values();

        $endpoints = (array) config('services.kiwwwi.leads.endpoints', []);
        $credentials = filled(config('services.kiwwwi.leads.username')) && filled(config('services.kiwwwi.leads.app_password'));

        return [
            'integrations' => $integrations,
            'demo' => false,
            'today' => LocalCalendar::today()->toDateString(),
            // Whether server-side values are set, never the values; they belong to the operator's account only.
            'server' => ! $account->isOperator() || ! Auth::user()->owner ? null : [
                'openai' => filled(config('services.openai.api_key')),
                'kiwwwi_forms' => [
                    'polled' => $credentials ? count(array_filter($endpoints, fn ($endpoint): bool => is_array($endpoint) && filled($endpoint['base_url'] ?? null))) : 0,
                    'total' => count($endpoints),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function agents(): array
    {
        return [
            'tokens' => $this->liveTokens()->map(fn (AgentToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'user' => $token->tokenable?->name,
                'abilities' => array_values((array) $token->abilities),
                'last_used' => $this->stamp($token->last_used_at),
                'expires_in_days' => $this->daysLeft($token),
            ])->values(),
            'today' => LocalCalendar::today()->toDateString(),
            'abilities' => AgentAbilities::ALL,
            'demo' => (bool) config('app.demo'),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, AgentToken> */
    private function liveTokens(): \Illuminate\Support\Collection
    {
        return AgentToken::query()
            ->with('tokenable')
            ->where('account_id', Auth::user()->account_id)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')
            ->get();
    }

    /** @return array{at: string, day: string, time: string}|null */
    private function stamp(?CarbonInterface $at): ?array
    {
        if ($at === null) {
            return null;
        }
        $local = $at->copy()->setTimezone(LocalCalendar::timezone());

        return ['at' => $at->toIso8601String(), 'day' => $local->toDateString(), 'time' => $local->format('H:i')];
    }

    /** Whole local days until the token expires: 0 on its last day, null when it never does. */
    private function daysLeft(AgentToken $token): ?int
    {
        if ($token->expires_at === null) {
            return null;
        }

        return (int) LocalCalendar::today()->diffInDays($token->expires_at->copy()->setTimezone(LocalCalendar::timezone())->startOfDay());
    }

    /** One line per section for the phone list. */
    private function summary(): array
    {
        $account = Auth::user()->account;
        $leadgen = $this->leadgen();
        $tokens = Gate::allows('manage-agent-tokens') ? $this->liveTokens() : null;
        $soonest = $tokens?->map(fn (AgentToken $token): ?int => $this->daysLeft($token))->filter(fn (?int $days): bool => $days !== null)->min();

        return [
            'users' => $account->users()->count(),
            'client_fields' => ClientFieldDefinition::query()->where('account_id', $account->id)->whereNull('archived_at')->count(),
            'message_rules' => MessageRule::query()->where('account_id', $account->id)->where('enabled', true)->count(),
            'default_sources' => count($leadgen['default_sources']),
            'custom_sources' => count($leadgen['custom_sources']),
            'connected' => config('app.demo') ? [] : $account->integrations()->get()
                ->filter(fn (Integration $integration): bool => $integration->is_enabled && $integration->isConfigured())
                ->map(fn (Integration $integration): string => Integration::PROVIDERS[$integration->provider]['name'] ?? $integration->provider)
                ->values(),
            'tokens' => $tokens === null ? null : [
                'count' => $tokens->count(),
                'soonest_days' => $soonest,
            ],
        ];
    }
}
