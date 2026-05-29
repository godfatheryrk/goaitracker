<?php

namespace App\Http\Controllers;

use App\Enums\StepSource;
use App\Http\Requests\Ventures\CreateVentureRequest;
use App\Models\Step;
use App\Services\AiStepSuggester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class VenturesController extends Controller
{
    public function __construct(private readonly AiStepSuggester $suggester) {}

    public function index(Request $request): View
    {
        $ventures = $request->user()->ventures()
            ->withCount([
                'steps',
                'steps as completed_steps_count' => fn ($q) => $q->where('is_completed', true),
                // FR-021: incomplete + deadlined + imminent-or-overdue (deadline <= today+window,
                // which includes past). Single-source window via Step::IMMINENT_WINDOW_DAYS.
                'steps as pressured_steps_count' => fn ($q) => $q
                    ->where('is_completed', false)
                    ->whereNotNull('deadline')
                    ->whereDate('deadline', '<=', today()->addDays(Step::IMMINENT_WINDOW_DAYS)),
            ])
            ->orderByDesc('updated_at')
            ->get();

        return view('ventures.index', ['ventures' => $ventures]);
    }

    public function create(): View
    {
        return view('ventures.create');
    }

    /**
     * Persist a new venture and (on AI success) 7 ai_initial steps.
     *
     * The AI call sits OUTSIDE the persistence transaction: AiStepSuggester
     * manages its own counter transaction, and a venture-insert failure must
     * not roll back a counter increment for an attempt that really happened
     * (NFR(ai-ceiling) accounting). AI failure returns [] — the venture is
     * still created and a flash notice surfaces on the detail view.
     */
    public function store(CreateVentureRequest $request): RedirectResponse
    {
        $user = $request->user();
        $title = $request->string('title')->toString();
        $description = $request->input('description');

        $steps = $this->suggester->suggestSteps($user, $title, $description ?? '');

        $venture = DB::transaction(function () use ($user, $title, $description, $steps) {
            $venture = $user->ventures()->create([
                'title' => $title,
                'description' => $description,
            ]);

            foreach ($steps as $i => $body) {
                $venture->steps()->make([
                    'body' => $body,
                    'position' => $i,
                ])->forceFill([
                    'owner_id' => $user->id,
                    'source' => StepSource::AiInitial,
                ])->save();
            }

            return $venture;
        });

        if (empty($steps)) {
            session()->flash('ai_unavailable', "AI couldn't suggest steps right now — you can add them manually.");
        }

        return redirect()->route('ventures.show', $venture);
    }

    public function show(Request $request, int $venture): View
    {
        $model = $request->user()->ventures()->with('steps')->findOrFail($venture);
        $completed = $model->steps->where('is_completed', true)->count();
        $total = $model->steps->count();

        return view('ventures.show', [
            'venture' => $model,
            'completed' => $completed,
            'total' => $total,
        ]);
    }

    public function destroy(Request $request, int $venture): RedirectResponse
    {
        $request->user()->ventures()->findOrFail($venture)->delete();

        return redirect()->route('ventures.index');
    }
}
