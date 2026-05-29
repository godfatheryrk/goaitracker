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
        $ventureModel = $request->user()->ventures()->findOrFail($venture);

        return view('expenses.create', ['venture' => $ventureModel]);
    }

    public function store(CreateExpenseRequest $request, int $venture): RedirectResponse
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);

        $ventureModel->expenses()
            ->make($request->validated())
            ->forceFill(['owner_id' => $request->user()->id])
            ->save();

        return redirect()->route('ventures.show', $ventureModel);
    }

    public function edit(Request $request, int $venture, int $expense): View
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);
        $expenseModel = $ventureModel->expenses()->findOrFail($expense);

        return view('expenses.edit', ['venture' => $ventureModel, 'expense' => $expenseModel]);
    }

    public function update(EditExpenseRequest $request, int $venture, int $expense): RedirectResponse
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);
        $expenseModel = $ventureModel->expenses()->findOrFail($expense);

        $expenseModel->update($request->validated());

        return redirect()->route('ventures.show', $ventureModel);
    }

    public function destroy(Request $request, int $venture, int $expense): RedirectResponse
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);
        $expenseModel = $ventureModel->expenses()->findOrFail($expense);

        $expenseModel->delete();

        return redirect()->route('ventures.show', $ventureModel);
    }
}
