<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class HostExecDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('Running commands on this machine is turned off for this instance.'));
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'host_exec_disabled'], 403);
    }
}
