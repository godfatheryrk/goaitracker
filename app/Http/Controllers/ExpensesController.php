<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expenses\CreateExpenseRequest;
use App\Http\Requests\Expenses\EditExpenseRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExpensesController extends Controller
{
    public function create(Request $request, int $venture): View
    {
        abort(404);
    }

    public function store(CreateExpenseRequest $request, int $venture): RedirectResponse
    {
        abort(404);
    }

    public function edit(Request $request, int $venture, int $expense): View
    {
        abort(404);
    }

    public function update(EditExpenseRequest $request, int $venture, int $expense): RedirectResponse
    {
        abort(404);
    }

    public function destroy(Request $request, int $venture, int $expense): RedirectResponse
    {
        abort(404);
    }
}
