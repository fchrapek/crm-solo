<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/** The ⌘K palette's search: JSON for a fetch, inside the signed-in account. */
final class SearchController extends Controller
{
    public function __invoke(GlobalSearch $search): JsonResponse
    {
        return response()->json($search->search(
            Auth::user()->account_id,
            (string) Request::input('q', ''),
            Request::boolean('archived'),
            is_string(Request::input('group')) ? Request::input('group') : null,
        ));
    }
}
