<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** A demo visitor asked for more than the shared account allows; the answer is a message, never an error page. */
final class DemoLimitReached extends RuntimeException implements ShouldntReport
{
    public static function full(): self
    {
        return new self(__('The demo has reached its limit here. It starts fresh every hour.'));
    }

    public static function sample(): self
    {
        return new self(__('The demo keeps its sample data. Only what you add can be deleted.'));
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // As an error of the form too, so a dialog stays open with what was typed.
        return back()->withErrors(['demo' => $this->getMessage()])->with('error', $this->getMessage());
    }
}
