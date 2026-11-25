<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AgentToken;
use App\Models\Client;
use App\Models\ClientFieldDefinition;
use App\Models\IntakeForm;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use App\Services\Clients\MonthCloseWorklist;
use App\Services\Integrations\Trello\TrelloListMapper;
use App\Services\Leads\IntakeForms;
use App\Services\Leads\LeadVocabulary;
use App\Services\Messaging\MailDelivery;
use App\Services\Messaging\MessageSettings;
use App\Services\Messaging\Sender\AccountSenders;
use App\Services\Messaging\SmsapiProvider;
use App\Support\JsonSettings;
use App\Support\LocalCalendar;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings: the account's, one page with a tab per area, for its owner only; a person's own settings are on My profile
 * (ProfileController). /settings is the phone's section list and, from 768 px, the General tab, so it carries both.
 */
final class SettingsController extends Controller
{
    public const array TABS = ['general', 'leads', 'forms', 'messages', 'integrations'];

    public function index(): Response
    {
        $this->ownerOnly();

        return Inertia::render('settings/index', [
            'tab' => null,
            'intake_available' => ! config('app.demo'),
            'read_only' => (bool) config('app.demo'),
            'summary' => $this->summary(),
            ...$this->general(),
        ]);
    }

    public function show(string $tab): Response
    {
        $this->ownerOnly();
        abort_if($tab === 'forms' && (bool) config('app.demo'), 404);

        return Inertia::render('settings/index', [
            'tab' => $tab,
            'intake_available' => ! config('app.demo'),
            'read_only' => (bool) config('app.demo'),
            ...match ($tab) {
                'general' => $this->general(),
                'leads' => ['leadgen' => $this->leadgen()],
                'messages' => [...$this->messages(), ...(Auth::user()->account->hasFeature('billing') ? $this->emails() : [])],
                'integrations' => [...$this->agents(), ...$this->integrations()],
                'forms' => ['intake' => $this->intake(null)],
                default => [],
            },
        ]);
    }

    /** One website form's page under Forms: the tab's props with the form picked. */
    public function form(int $form): Response
    {
        $accountId = Auth::user()->account_id;
        abort_unless(IntakeForm::query()->where('account_id', $accountId)->whereKey($form)->exists(), 404);

        return Inertia::render('settings/index', [
            'tab' => 'forms',
            'intake_available' => true,
            'intake' => $this->intake($form),
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

    /** The account's modules, Account's Modules: Month close and Report baseline, both part of billing. */
    public function updateModules(Request $request): RedirectResponse
    {
        abort_unless($this->seesBilled(), 404);
        $validated = $request->validate([
            'month_close' => ['sometimes', 'boolean'],
            'report_baseline' => ['sometimes', 'boolean'],
        ]);
        $account = Auth::user()->account;
        // Best effort: a client the close comes to hold later still shows its month close, whatever the switch says.
        if (array_key_exists('month_close', $validated) && ! $validated['month_close'] && ($count = app(MonthCloseWorklist::class)->heldCount($account)) > 0) {
            throw ValidationException::withMessages(['month_close' => trans_choice('month_close_in_use', $count, ['count' => $count])]);
        }
        JsonSettings::write('accounts', $account->id, array_map(fn (mixed $on): bool => (bool) $on, $validated));

        return back();
    }

    /**
     * The forms with their notice counts and failure reasons, the sender without its password, and what a form may name;
     * never a stored secret.
     *
     * @return array<string, mixed>
     */
    private function intake(?int $formId): array
    {
        $accountId = Auth::user()->account_id;
        $labels = LeadVocabulary::sourceLabels($accountId);

        return [
            'form_id' => $formId,
            'forms' => app(IntakeForms::class)->list($accountId)['forms'],
            'failures' => (object) app(IntakeForms::class)->failureReasons($accountId),
            'sender' => app(AccountSenders::class)->describe($accountId),
            'pipelines' => array_map(fn (string $pipeline): array => ['value' => $pipeline, 'label' => LeadVocabulary::pipelineLabel($accountId, $pipeline) ?? $pipeline], Lead::pipelines()),
            'sources' => array_map(fn (string $source): array => ['value' => $source, 'label' => $labels[$source] ?? $source], LeadVocabulary::sources($accountId)),
            'max_age_days' => LeadIntake::MAX_AGE_DAYS,
        ];
    }

    private function seesBilled(): bool
    {
        return (bool) Auth::user()->owner && Auth::user()->account->shows('billing', Auth::user());
    }

    /**
     * The General tab: the account's users, its modules and its client fields.
     *
     * @return array<string, mixed>
     */
    private function general(): array
    {
        $me = Auth::user();

        return [
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
            'modules' => $this->modules(),
            'client_fields' => $this->clientFields(),
            'reserved_field_keys' => ClientFieldDefinition::RESERVED_KEYS,
        ];
    }

    /** The account's settings are its owner's; anyone else finds no such page. */
    private function ownerOnly(): void
    {
        abort_unless(Gate::allows('manage-account'), 404);
    }

    /**
     * The account's modules: the billing switches, the clients the close holds, and counts for the modules set up elsewhere.
     *
     * @return array{month_close: bool|null, month_close_in_use: int, report_baseline: bool|null, client_fields: int, sms_rules: int}
     */
    private function modules(): array
    {
        $account = Auth::user()->account;
        $billed = $this->seesBilled();

        return [
            'month_close' => $billed ? $account->usesModule('month_close') : null,
            'month_close_in_use' => $billed ? app(MonthCloseWorklist::class)->heldCount($account) : 0,
            'report_baseline' => $billed ? $account->usesModule('report_baseline') : null,
            'client_fields' => ClientFieldDefinition::query()->where('account_id', $account->id)->whereNull('archived_at')->count(),
            'sms_rules' => MessageRule::query()->where('account_id', $account->id)->where('enabled', true)->count(),
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
            'templates' => MessageTemplate::query()->where('account_id', $account->id)->where('channel', MessageTemplate::SMS)->orderBy('id')->get()
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
    private function emails(): array
    {
        $mode = app(MailDelivery::class)->mode();
        $live = $mode === MailDelivery::LIVE;

        return [
            'email_templates' => app(MessageSettings::class)->emailTemplates(Auth::user()->account_id),
            'email_sending' => [
                'live' => $live,
                'refused' => $mode === MailDelivery::REFUSED,
                'from' => $live ? (string) config('mail.from.address') : null,
                'bcc' => Auth::user()->email,
            ],
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
        $boardProjects = $account->projects()->whereNotNull('trello_board_id')->whereNull('archived_at')->get(['id', 'settings']);
        $boards = $boardProjects->count();
        $seenLists = collect(TrelloListMapper::uniqueNames($boardProjects->flatMap(fn (Project $project): array => array_column($project->settings['trello_lists'] ?? [], 'name'))))
            ->sort(fn (string $a, string $b): int => strcasecmp($a, $b))
            ->values()
            ->all();

        $integrations = collect(Integration::PROVIDERS)->map(function (array $config, string $provider) use ($stored, $boards, $seenLists): array {
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
                'skipped_lists' => $provider === 'trello' ? TrelloListMapper::uniqueNames($settings['skipped_lists'] ?? []) : null,
                'seen_lists' => $provider === 'trello' ? $seenLists : null,
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
        $tokens = $this->liveTokens();
        $soonest = $tokens->map(fn (AgentToken $token): ?int => $this->daysLeft($token))->filter(fn (?int $days): bool => $days !== null)->min();

        return [
            'users' => $account->users()->count(),
            'client_fields' => ClientFieldDefinition::query()->where('account_id', $account->id)->whereNull('archived_at')->count(),
            'message_rules' => MessageRule::query()->where('account_id', $account->id)->where('enabled', true)->count(),
            'email_templates' => ! $account->hasFeature('billing') ? null : MessageTemplate::query()->where('account_id', $account->id)->where('channel', MessageTemplate::EMAIL)->count(),
            'default_sources' => count($leadgen['default_sources']),
            'custom_sources' => count($leadgen['custom_sources']),
            'connected' => config('app.demo') ? [] : $account->integrations()->get()
                ->filter(fn (Integration $integration): bool => isset(Integration::PROVIDERS[$integration->provider]) && $integration->is_enabled && $integration->isConfigured())
                ->map(fn (Integration $integration): string => Integration::PROVIDERS[$integration->provider]['name'])
                ->values(),
            'forms' => ! config('app.demo') ? [
                'count' => IntakeForm::query()->where('account_id', $account->id)->count(),
                'sender' => app(AccountSenders::class)->find($account->id) !== null,
            ] : null,
            'tokens' => [
                'count' => $tokens->count(),
                'soonest_days' => $soonest,
            ],
        ];
    }
}
