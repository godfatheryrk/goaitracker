<?php

namespace App\Http\Controllers;

use App\Enums\StepSource;
use App\Http\Requests\Steps\CreateStepRequest;
use App\Http\Requests\Steps\EditStepRequest;
use App\Http\Requests\Steps\SuggestStepsRequest;
use App\Services\AiStepSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StepsController extends Controller
{
    public function __construct(private readonly AiStepSuggester $suggester) {}

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

    /**
     * Call the AI suggester for 7 NEW candidate steps and render the preview.
     *
     * The venture-resolution findOrFail MUST be the first statement so an
     * unauthorized POST throws 404 BEFORE the suggester is dispatched —
     * otherwise user B could burn user A's per-user AI ceiling on a rejected
     * request (the F-02 counter increments before dispatch). The suggester
     * call sits outside any transaction (it manages its own counter
     * transaction). On [] (provider failure OR over-ceiling) reuse S-01's
     * amber ai_unavailable flash, extension-specific wording.
     */
    public function suggest(Request $request, int $venture): View|RedirectResponse
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);

        $currentSteps = $ventureModel->steps()->pluck('body')->toArray();

        $suggestions = $this->suggester->suggestSteps(
            $request->user(),
            $ventureModel->title,
            $ventureModel->description ?? '',
            $currentSteps,
        );

        if (empty($suggestions)) {
            session()->flash('ai_unavailable', "AI couldn't suggest more steps right now — try again later or add steps manually.");

            return redirect()->route('ventures.show', $ventureModel);
        }

        return view('steps.suggestions.preview', [
            'venture' => $ventureModel,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Persist the user-chosen subset of previewed suggestions as ai_extension
     * steps appended to the tail of the list. No AI call here — the suggester
     * already ran in the earlier suggest() request. source is set via
     * forceFill (not mass-assignable) so a tampered payload cannot pollute the
     * FR-008 ai_initial metric pool. Position is computed once before the loop
     * then incremented per row so all kept rows land contiguously.
     */
    public function storeSuggestions(SuggestStepsRequest $request, int $venture): RedirectResponse
    {
        $ventureModel = $request->user()->ventures()->findOrFail($venture);

        $rows = $request->validated('suggestions', []);
        $chosen = array_values(array_filter($rows, fn ($row) => ! empty($row['keep'])));

        $nextPosition = ($ventureModel->steps()->max('position') ?? -1) + 1;

        DB::transaction(function () use ($ventureModel, $chosen, $request, &$nextPosition) {
            foreach ($chosen as $row) {
                $ventureModel->steps()->make([
                    'body' => $row['body'],
                    'position' => $nextPosition,
                ])->forceFill([
                    'owner_id' => $request->user()->id,
                    'source' => StepSource::AiExtension,
                ])->save();

                $nextPosition++;
            }
        });

        return redirect()->route('ventures.show', $ventureModel);
    }
}
