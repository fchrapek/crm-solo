<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Messaging\MessageQueue;
use App\Services\Messaging\MessageSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/** The account's text messages: the queue, cancelling one that waits or failed, and sending a failed one again. */
final class MessagesController extends Controller
{
    public function index(Request $request, MessageQueue $queue): Response
    {
        return Inertia::render('messages/index', $queue->props(Auth::user()->account, $request->only(['status', 'search', 'client'])));
    }

    public function cancel(int $message, MessageSettings $settings): RedirectResponse
    {
        $this->refusable(fn () => $settings->cancel(Auth::user()->account_id, $message, Auth::user()));

        return Redirect::back()->with('success', __('Message cancelled'));
    }

    public function resend(int $message, MessageSettings $settings): RedirectResponse
    {
        $this->refusable(fn () => $settings->resend(Auth::user()->account_id, $message));

        return Redirect::back()->with('success', __('SMS queued'));
    }
}
