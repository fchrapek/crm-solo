<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\WaitlistRequest;
use App\Models\Account;
use App\Models\Lead;
use App\Rules\Turnstile;
use App\Services\Leads\LeadCapture;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The closed-mode splash (config/waitlist.php). A signup is a lead in the
 * owner's funnel, never a user, and every accepted submission gets the same
 * answer whether the email was new, known or a bot's.
 */
final class WaitlistController extends Controller
{
    private const JOINED = 'waitlist_joined';

    public function show(Request $request): Response
    {
        // A broken target shows on the first page load, not after a visitor typed an email.
        $this->target();

        return Inertia::render('auth/czesc', [
            'joined' => (bool) $request->session()->get(self::JOINED, false),
            'turnstileSiteKey' => Turnstile::siteKey(),
        ]);
    }

    public function store(WaitlistRequest $request, LeadCapture $capture): RedirectResponse
    {
        if (! $request->isBot()) {
            $email = (string) $request->validated('email');
            $name = mb_trim((string) $request->validated('name'));

            [$account, $pipeline] = $this->target();

            $capture->captureOnce($account, [
                'pipeline' => $pipeline,
                'source' => (string) config('waitlist.source'),
                'name' => $name !== '' ? $name : $email,
                'email' => $email,
            ]);
        }

        return redirect()->route('czesc')->with(self::JOINED, true);
    }

    /**
     * The account and pipeline signups land in; a misconfigured one answers
     * 503 with a logged reason rather than a thank-you that drops the lead.
     *
     * @return array{0: Account, 1: string}
     */
    private function target(): array
    {
        $account = Account::query()->find(config('waitlist.account_id'));

        if ($account === null) {
            Log::error('Waitlist unavailable: WAITLIST_ACCOUNT_ID does not name an account.', [
                'account_id' => config('waitlist.account_id'),
            ]);

            abort(503);
        }

        $pipeline = (string) (config('waitlist.pipeline') ?: (Lead::pipelines()[0] ?? ''));

        try {
            Lead::pipelineConfig($pipeline);
        } catch (InvalidArgumentException) {
            Log::error('Waitlist unavailable: WAITLIST_PIPELINE is not a lead pipeline.', [
                'pipeline' => $pipeline,
            ]);

            abort(503);
        }

        return [$account, $pipeline];
    }
}
