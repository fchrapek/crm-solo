<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Integrations\InfaktApiException;
use App\Services\Integrations\InfaktDrafting;
use App\Services\Integrations\InfaktDraftRun;
use App\Support\LocalCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use RuntimeException;

/** The To invoice block on Billing: Infakt drafts per invoice group through InfaktDrafting, draft only. */
final class ClientInvoiceDraftsController extends Controller
{
    public function __construct(private readonly InfaktDrafting $drafting) {}

    /** The picker's invoices, read from Infakt. */
    public function sources(Client $client): JsonResponse
    {
        try {
            return response()->json(['invoices' => $this->drafting->copyable($client)->all()]);
        } catch (InfaktApiException $e) {
            return response()->json(['message' => __('Infakt did not answer: :error', ['error' => $e->userMessage()])], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'group' => ['nullable', 'integer', 'min:1'],
            'like' => ['required', 'string', 'max:64'],
        ]);

        try {
            $result = $this->drafting->draft($client, $validated['period'], mb_trim($validated['like']), group: isset($validated['group']) ? (int) $validated['group'] : null, chainOnly: true);
        } catch (InfaktApiException $e) {
            return Redirect::back()->with('error', __('Infakt did not take the draft: :error', ['error' => $e->userMessage()]));
        } catch (RuntimeException $e) {
            return Redirect::back()->with('error', $e->getMessage());
        }

        $named = fn (array $r): string => collect($r['project'])->pluck('name')->filter()->implode(', ') ?: __('Invoice :group', ['group' => $r['group']]);
        $results = collect($result['results']);
        $drafted = $results->filter(fn (array $r): bool => in_array($r['outcome'], [InfaktDraftRun::CREATED, InfaktDraftRun::ALREADY_DRAFTED], true));
        $needs = $results->where('outcome', InfaktDrafting::NEEDS_SOURCE);
        $blocked = $results->where('outcome', InfaktDrafting::BLOCKED);
        $open = $results->reject(fn (array $r): bool => $drafted->contains($r) || $needs->contains($r) || $blocked->contains($r));
        $problems = collect([
            $needs->isNotEmpty() ? __('Pick the invoice to copy for: :groups.', ['groups' => $needs->map($named)->implode('; ')]) : null,
            $blocked->isNotEmpty() ? __('Nothing was sent for :groups: the month holds an older copy not tied to a project. Check it in Infakt.', ['groups' => $blocked->map($named)->implode('; ')]) : null,
            $open->isNotEmpty() ? __('Infakt has not confirmed every draft yet: :groups. Check Infakt.', ['groups' => $open->map($named)->implode('; ')]) : null,
        ])->filter();
        if ($problems->isNotEmpty()) {
            $done = $drafted->isNotEmpty() ? __('Drafted in Infakt: :groups.', ['groups' => $drafted->map($named)->implode('; ')]).' ' : '';

            return Redirect::back()->with('error', $done.$problems->implode(' '));
        }

        $month = LocalCalendar::monthName($validated['period']);

        return Redirect::back()->with('success', count($result['results']) === 1
            ? __('The invoice draft for :month is in Infakt. Nothing was sent.', ['month' => $month])
            : __('The invoice drafts for :month are in Infakt. Nothing was sent.', ['month' => $month]));
    }
}
