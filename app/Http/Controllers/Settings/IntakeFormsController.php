<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\IntakeForm;
use App\Services\Leads\IntakeForms;
use App\Services\Leads\IntakeFormSecrets;
use App\Services\Leads\LeadNotices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

/**
 * Settings / Forms: the owner's website forms through the services the commands and verbs use; a secret is
 * only set, replaced or cleared here, and no answer carries a stored one back.
 */
final class IntakeFormsController extends Controller
{
    public function store(Request $request, IntakeForms $forms): RedirectResponse
    {
        $form = $this->refusable(fn () => $forms->create($this->account(), $this->input($request)), 'name');

        return Redirect::route('settings.forms.show', ['form' => $form['id']])->with('success', __('Form added'));
    }

    public function update(Request $request, int $form, IntakeForms $forms): RedirectResponse
    {
        $this->refusable(fn () => $forms->edit($this->account(), $form, $this->input($request)), 'name');

        return Redirect::back()->with('success', __('Settings saved'));
    }

    public function setTurnstile(Request $request, int $form, IntakeFormSecrets $secrets): RedirectResponse
    {
        $secret = $request->validate(['turnstile_secret' => ['required', 'string']])['turnstile_secret'];
        $this->refusable(fn () => $secrets->setTurnstile($this->account(), $form, $secret), 'turnstile_secret');

        return Redirect::back()->with('success', __('Turnstile secret saved'));
    }

    public function clearTurnstile(int $form, IntakeFormSecrets $secrets): RedirectResponse
    {
        $this->refusable(fn () => $secrets->clearTurnstile($this->account(), $form));

        return Redirect::back()->with('success', __('Turnstile secret removed'));
    }

    /** The new secret travels this once, in this answer, never cached. */
    public function rotateWebhook(int $form, IntakeFormSecrets $secrets): JsonResponse
    {
        $secret = $this->refusable(fn () => $secrets->rotateWebhook($this->account(), $form));

        return response()->json(['secret' => $secret], 201)->header('Cache-Control', 'no-store');
    }

    public function clearWebhook(int $form, IntakeFormSecrets $secrets): RedirectResponse
    {
        $this->refusable(fn () => $secrets->clearWebhook($this->account(), $form));

        return Redirect::back()->with('success', __('Webhook secret removed'));
    }

    public function retry(int $form, LeadNotices $notices): RedirectResponse
    {
        abort_unless(IntakeForm::query()->where('account_id', $this->account())->whereKey($form)->exists(), 404);
        $result = $this->refusable(fn () => $notices->reconcile($this->account(), true, $form));

        return Redirect::back()->with('success', trans_choice('{0} No failed notice to send again.|{1} :count notice goes out again.|[2,*] :count notices go out again.', $result['retried']));
    }

    private function account(): int
    {
        return Auth::user()->account_id;
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $input = $request->validate([
            'name' => ['sometimes', 'nullable', 'string'],
            'pipeline' => ['sometimes', 'nullable', 'string'],
            'source' => ['sometimes', 'nullable', 'string'],
            'origins' => ['sometimes', 'array'],
            'origins.*' => ['string'],
            'notify_to' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ], [], ['origins.*' => __('Allowed site')]);
        if (array_key_exists('active', $input)) {
            $input['active'] = (bool) $input['active'];
        }
        if (($input['notify_to'] ?? null) === '') {
            $input['notify_to'] = null;
        }

        return $input;
    }
}
