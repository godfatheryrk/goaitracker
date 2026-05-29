<?php

namespace App\Http\Controllers;

use App\Enums\StepSource;
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
        $ventureModel = $request->user()->ventures()->findOrFail($venture);

        return view('steps.create', ['venture' => $ventureModel]);
    }

    public function store(CreateStepRequest $request, int $venture): RedirectResponse
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);

        $nextPosition = ($ventureModel->steps()->max('position') ?? -1) + 1;

        $ventureModel->steps()->make([
            'body' => $request->validated('body'),
            'position' => $nextPosition,
        ])->forceFill([
            'owner_id' => $request->user()->id,
            'source' => StepSource::Manual,
        ])->save();

        return redirect()->route('ventures.show', $ventureModel);
    }

    public function edit(Request $request, int $venture, int $step): View
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);
        $stepModel = $ventureModel->steps()->findOrFail($step);

        return view('steps.edit', ['venture' => $ventureModel, 'step' => $stepModel]);
    }

    public function update(EditStepRequest $request, int $venture, int $step): RedirectResponse
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);
        $stepModel = $ventureModel->steps()->findOrFail($step);

        $stepModel->update($request->validated());

        return redirect()->route('ventures.show', $ventureModel);
    }

    public function destroy(Request $request, int $venture, int $step): RedirectResponse
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);
        $stepModel = $ventureModel->steps()->findOrFail($step);

        $stepModel->delete();

        return redirect()->route('ventures.show', $ventureModel);
    }

    public function toggleCompletion(Request $request, int $venture, int $step): JsonResponse|RedirectResponse
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);
        $stepModel = $ventureModel->steps()->findOrFail($step);

        $stepModel->is_completed = ! $stepModel->is_completed;
        $stepModel->save();

        if ($request->wantsJson()) {
            return response()->json([
                'is_completed' => $stepModel->is_completed,
                'completed' => $ventureModel->steps()->where('is_completed', true)->count(),
                'total' => $ventureModel->steps()->count(),
            ]);
        }

        return redirect()->route('ventures.show', $ventureModel);
    }
}
