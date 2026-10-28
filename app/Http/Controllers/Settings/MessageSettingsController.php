<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use App\Services\Messaging\MessageSettings;
use App\Services\Messaging\SmsProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Ustawienia / Wiadomości SMS: templates, rules and how messages go out, through the service the verbs use. */
final class MessageSettingsController extends Controller
{
    public function __construct(private readonly MessageSettings $settings) {}

    public function storeTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100'], 'body' => ['required', 'string', 'max:1000']]);
        $accountId = Auth::user()->account_id;
        $key = $this->freeKey(Str::slug(Str::ascii($validated['name'], 'pl'), '_'), fn (string $key): bool => MessageTemplate::query()->where('account_id', $accountId)->where('key', $key)->exists());

        $this->refusable(fn () => $this->settings->template($accountId, $key, $validated['name'], $validated['body']), 'body');

        return Redirect::back()->with('success', __('Settings saved'));
    }

    public function updateTemplate(Request $request, string $key): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100'], 'body' => ['required', 'string', 'max:1000']]);
        $accountId = Auth::user()->account_id;
        abort_unless(MessageTemplate::query()->where('account_id', $accountId)->where('key', $key)->exists(), 404);

        $this->refusable(fn () => $this->settings->template($accountId, $key, $validated['name'], $validated['body']), 'body');

        return Redirect::back()->with('success', __('Settings saved'));
    }

    public function destroyTemplate(string $key): RedirectResponse
    {
        $this->refusable(fn () => $this->settings->deleteTemplate(Auth::user()->account_id, $key));

        return Redirect::back()->with('success', __('Template deleted'));
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $validated = $this->validateRule($request);
        $accountId = Auth::user()->account_id;
        $key = $this->freeKey($validated['template'], fn (string $key): bool => MessageRule::query()->where('account_id', $accountId)->where('key', $key)->exists());

        $this->refusable(fn () => $this->settings->rule($accountId, $key, $validated));

        return Redirect::back()->with('success', __('Settings saved'));
    }

    public function updateRule(Request $request, string $key): RedirectResponse
    {
        $validated = $this->validateRule($request);
        $accountId = Auth::user()->account_id;
        abort_unless(MessageRule::query()->where('account_id', $accountId)->where('key', $key)->exists(), 404);

        $this->refusable(fn () => $this->settings->rule($accountId, $key, $validated));

        return Redirect::back()->with('success', __('Settings saved'));
    }

    public function destroyRule(string $key): RedirectResponse
    {
        $this->refusable(fn () => $this->settings->deleteRule(Auth::user()->account_id, $key));

        return Redirect::back()->with('success', __('Rule deleted'));
    }

    public function sending(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'quiet_from' => ['nullable', 'date_format:H:i'],
            'quiet_to' => ['nullable', 'date_format:H:i'],
            'ascii' => ['nullable', 'boolean'],
            'provider' => ['nullable', 'string', Rule::in(app(SmsProviderRegistry::class)->keys())],
        ]);

        $this->refusable(fn () => $this->settings->sending(Auth::user()->account_id, $validated['quiet_from'] ?? null, $validated['quiet_to'] ?? null, $validated['ascii'] ?? null, $validated['provider'] ?? null), 'provider');

        return Redirect::back()->with('success', __('Settings saved'));
    }

    /** The template text as a client would get it, and how many SMS it takes; JSON for the dialog's live preview. */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:1000']]);

        return response()->json($this->refusable(fn () => $this->settings->preview(Auth::user()->account_id, $validated['body']), 'body'));
    }

    /** @return array<string, mixed> */
    private function validateRule(Request $request): array
    {
        $validated = $request->validate([
            'template' => ['required', 'string', 'max:64'],
            'trigger' => ['required', Rule::in([MessageRule::FIELD_VALUE, MessageRule::DATE_OFFSET])],
            'field' => ['required', 'string', 'max:64'],
            'values' => ['nullable', 'array'],
            'values.*' => ['string', 'max:64'],
            'offset_minutes' => ['nullable', 'integer', 'between:-525600,525600'],
            'at' => ['nullable', 'date_format:H:i'],
            'expire_minutes' => ['required', 'integer', 'between:1,10080'],
            'enabled' => ['required', 'boolean'],
        ]);

        // An empty send time clears it: the reminder goes at the date's own time.
        return [...$validated, 'at' => $validated['at'] ?? '', 'values' => $validated['values'] ?? []];
    }

    /** A key from a name: the slug, then _2, _3 while taken. */
    private function freeKey(string $base, callable $taken): string
    {
        $base = mb_substr(preg_match('/^[a-z]/', $base) ? $base : 'sms_'.$base, 0, 56);
        $key = $base;
        for ($n = 2; $taken($key); $n++) {
            $key = "{$base}_{$n}";
        }

        return $key;
    }
}
