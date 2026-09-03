<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DailySessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class DailySessionController extends Controller
{
    public function __construct(
        private readonly DailySessionService $service,
    ) {}

    public function attach(): JsonResponse
    {
        try {
            $state = $this->service->attach(Auth::user()->account_id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($state);
    }

    public function detach(): JsonResponse
    {
        $this->service->detach(Auth::user()->account_id);

        return response()->json(['detached' => true]);
    }
}
