<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AgentToken;
use App\Services\Agent\AgentAbilities;
use App\Services\Agent\AgentTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The Agenci tab: the owner issues, re-scopes and revokes the account's agent
 * tokens through the same service as the agent-tokens:* commands.
 */
final class AgentTokensController extends Controller
{
    public const array EXPIRY = ['30', '90', '365', 'never'];

    /**
     * JSON, never a redirect: the plain token is answered once in this body
     * and kept out of the session, the flash and every page prop.
     */
    public function store(Request $request, AgentTokens $tokens): JsonResponse
    {
        Gate::authorize('manage-agent-tokens');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array'],
            'abilities.*' => ['string', Rule::in(AgentAbilities::ALL)],
            'expires' => ['required', Rule::in(self::EXPIRY)],
            'current_password' => ['required', 'current_password'],
        ]);

        $expires = $validated['expires'] === 'never' ? null : now()->addDays((int) $validated['expires']);

        try {
            $issued = $tokens->issue(Auth::user(), $validated['name'], array_values($validated['abilities']), $expires);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['abilities' => __($e->getMessage())]);
        }

        return response()
            ->json(['id' => $issued->accessToken->getKey(), 'name' => $issued->accessToken->name, 'token' => $issued->plainTextToken], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $token, AgentTokens $tokens): RedirectResponse
    {
        Gate::authorize('manage-agent-tokens');

        $validated = $request->validate([
            'abilities' => ['required', 'array'],
            'abilities.*' => ['string', Rule::in(AgentAbilities::ALL)],
        ]);

        try {
            $updated = $tokens->setAbilities($this->find($token), array_values($validated['abilities']));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['abilities' => __($e->getMessage())]);
        }

        if ($updated === null) {
            return back()->with('error', __('This token is revoked; create a new one.'));
        }

        return back()->with('success', __('Token abilities saved.'));
    }

    public function destroy(int $token, AgentTokens $tokens): RedirectResponse
    {
        Gate::authorize('manage-agent-tokens');

        $tokens->revoke($this->find($token));

        return back()->with('success', __('Token revoked.'));
    }

    private function find(int $id): AgentToken
    {
        return AgentToken::query()->where('account_id', Auth::user()->account_id)->whereKey($id)->firstOrFail();
    }
}
