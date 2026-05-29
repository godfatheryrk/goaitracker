<?php

namespace App\Http\Controllers;

use App\Http\Requests\Steps\CreateStepRequest;
use App\Http\Requests\Steps\EditStepRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StepsController extends Controller
{
    public function create(Request $request, int $venture): View
    {
        abort(404);
    }

    public function store(CreateStepRequest $request, int $venture): RedirectResponse
    {
        abort(404);
    }

    public function edit(Request $request, int $venture, int $step): View
    {
        abort(404);
    }

    public function update(EditStepRequest $request, int $venture, int $step): RedirectResponse
    {
        abort(404);
    }

    public function destroy(Request $request, int $venture, int $step): RedirectResponse
    {
        abort(404);
    }

    public function toggleCompletion(Request $request, int $venture, int $step): JsonResponse|RedirectResponse
    {
        abort(404);
    }
}
