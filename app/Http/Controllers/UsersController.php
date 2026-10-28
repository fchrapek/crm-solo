<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;

/** The writes behind Ustawienia · Konto (profile and Użytkownicy). */
final class UsersController extends Controller
{
    public function store(UserRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        Auth::user()->account->users()->create($request->validated());

        return Redirect::back()->with('success', translate_with_gender('created', 'User'));
    }

    public function update(User $user, UserRequest $request): RedirectResponse
    {
        Gate::authorize('update', $user);

        if ($user->isDemoUser()) {
            return Redirect::back()->with('error', __('The demo login cannot be changed or deleted.'));
        }

        $data = $request->safe()->except('current_password');
        $user->fill($data);

        if (isset($data['password'])) {
            $user->setRememberToken(Str::random(60));
        }

        $user->save();

        // Re-login puts this session on the new password hash, so auth.session keeps it and ends the others.
        if ($request->changesOwnPassword()) {
            Auth::login($user, $request->hasCookie(Auth::guard()->getRecallerName()));
        }

        return Redirect::back()->with('success', translate_with_gender('updated', 'User'));
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        if ($user->isDemoUser()) {
            return Redirect::back()->with('error', __('The demo login cannot be changed or deleted.'));
        }

        $user->delete();

        return Redirect::back()->with('success', translate_with_gender('deleted', 'User'));
    }

    public function restore(User $user): RedirectResponse
    {
        Gate::authorize('restore', $user);

        $user->restore();

        return Redirect::back()->with('success', translate_with_gender('restored', 'User'));
    }
}
