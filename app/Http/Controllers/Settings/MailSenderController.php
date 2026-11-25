<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Exceptions\SenderUnavailable;
use App\Http\Controllers\Controller;
use App\Services\Messaging\Sender\AccountSenders;
use App\Services\Messaging\Sender\SenderTests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Settings / Forms, Mail sender: the account's SMTP sender through the service the command uses; the password is write-only. */
final class MailSenderController extends Controller
{
    public function update(Request $request, AccountSenders $senders): RedirectResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string'],
            'port' => ['required', 'integer'],
            'username' => ['required', 'string'],
            'password' => ['nullable', 'string'],
            'from_address' => ['required', 'string'],
            'from_name' => ['nullable', 'string'],
        ]);
        $password = ($validated['password'] ?? '') === '' ? null : $validated['password'];
        // Unset so the request's array never holds the password past this point.
        unset($validated['password']);

        try {
            $senders->save(Auth::user()->account_id, [
                'host' => $validated['host'],
                'port' => (int) $validated['port'],
                'username' => $validated['username'],
                'from_address' => mb_trim($validated['from_address']),
                'from_name' => $validated['from_name'] ?? null,
            ], $password);
        } catch (InvalidArgumentException|SenderUnavailable $e) {
            throw ValidationException::withMessages(['sender' => $e->getMessage()]);
        }

        return Redirect::back()->with('success', __('Sender saved'));
    }

    public function test(SenderTests $tests): RedirectResponse
    {
        $result = $tests->send(Auth::user()->account_id);

        return Redirect::back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
