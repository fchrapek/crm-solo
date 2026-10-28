<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Agent\AgentRefusal;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\ValidationException;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Runs a service write; a refusal comes back as a form error, on the field it names when it names one,
     * translated when the lang files carry its text.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    protected function refusable(callable $write, string $key = 'form'): mixed
    {
        try {
            return $write();
        } catch (AgentRefusal $e) {
            throw ValidationException::withMessages([(string) ($e->context['field'] ?? $key) => __($e->getMessage())]);
        }
    }
}
