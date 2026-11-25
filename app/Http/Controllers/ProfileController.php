<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** My profile: what the signed-in person sets for themselves, open to every user of the account. */
final class ProfileController extends Controller
{
    public function show(): Response
    {
        $me = Auth::user();
        $account = $me->account;

        return Inertia::render('profile/index', [
            'me' => ['id' => $me->id, 'first_name' => $me->first_name, 'last_name' => $me->last_name, 'email' => $me->email],
            'is_owner' => (bool) $me->owner,
            'account' => [
                'name' => $account->name,
                'owners' => $me->owner ? [] : $account->users()->where('owner', true)->orderByName()->get()
                    ->map(fn (User $owner): string => $owner->name)->values()->all(),
            ],
            'task_settings' => [
                'warn_full_day' => $me->prefers('warn_full_day'),
                // Billed time is the owner's: a member has no such switch.
                'show_billed_on_tasks' => $this->seesBilled() ? $me->prefers('show_billed_on_tasks') : null,
            ],
            'helper_settings' => [
                'helper_desktop' => $me->prefers('helper_desktop'),
                'helper_phone' => $me->prefers('helper_phone'),
            ],
        ]);
    }

    public function updateTasks(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'warn_full_day' => ['sometimes', 'boolean'],
            'show_billed_on_tasks' => ['sometimes', 'boolean'],
        ]);
        abort_if(array_key_exists('show_billed_on_tasks', $validated) && ! $this->seesBilled(), 403);

        return $this->savePreferences($validated);
    }

    public function updateAppearance(Request $request): RedirectResponse
    {
        return $this->savePreferences($request->validate([
            'helper_desktop' => ['sometimes', 'boolean'],
            'helper_phone' => ['sometimes', 'boolean'],
        ]));
    }

    private function seesBilled(): bool
    {
        return Auth::user()->account->shows('billing', Auth::user());
    }

    /** @param array<string, mixed> $validated */
    private function savePreferences(array $validated): RedirectResponse
    {
        foreach ($validated as $key => $value) {
            Auth::user()->setPreference($key, (bool) $value);
        }

        return back();
    }
}
