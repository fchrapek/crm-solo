<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Clients\MonthSettlement;
use App\Services\Clients\SettlementResult;
use App\Support\LocalCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

final class ClientMonthsController extends Controller
{
    public function __construct(private readonly MonthSettlement $settlement) {}

    public function preview(Client $client, string $period): JsonResponse
    {
        abort_unless(LocalCalendar::isMonth($period), 404);

        return response()->json($this->settlement->previewFigures($client, $period));
    }

    public function close(Request $request, Client $client, string $period): RedirectResponse
    {
        abort_unless(LocalCalendar::isMonth($period), 404);
        $data = $request->validate(['preview_hash' => ['required', 'string', 'size:40']]);

        return $this->answered($this->settlement->close($client, $period, $data['preview_hash'], Auth::user()));
    }

    public function reopen(Request $request, Client $client, string $period): RedirectResponse
    {
        abort_unless(LocalCalendar::isMonth($period), 404);
        $data = $request->validate(['closure_id' => ['required', 'integer', 'min:1']]);

        return $this->answered($this->settlement->reopen($client, $period, (int) $data['closure_id'], Auth::user()));
    }

    private function answered(SettlementResult $result): RedirectResponse
    {
        $redirect = Redirect::back()->with($result->isRefused() ? 'error' : 'success', $result->message);

        return $result->toast !== null ? $redirect->with('settlement', $result->toast) : $redirect;
    }
}
