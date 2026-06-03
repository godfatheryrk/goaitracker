<?php

namespace App\Models;

use App\Enums\StepSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['body', 'position', 'deadline'])]
class Step extends Model
{
    use HasFactory;

    /**
     * Days-out threshold below which an upcoming deadline counts as "imminent".
     * Single source of truth for both the FR-021 list-pressure query
     * (VenturesController::index) and the FR-020 detail-view classification
     * (Step::deadlinePressure()). If these two drift, the list marker and the
     * detail badge disagree about the same step — this constant is the contract.
     */
    public const IMMINENT_WINDOW_DAYS = 3;

    protected $casts = [
        'is_completed' => 'boolean',
        'source' => StepSource::class,
        'deadline' => 'date',
    ];

    protected $touches = ['venture'];

    /**
     * Deadline-only pressure level for the FR-020 detail badge.
     *
     * Completion-agnostic by design: it never inspects $is_completed, so the
     * badge's data-pressure-class always carries the *would-be* emphasis even
     * for a step that loads completed — letting the live toggle restore it on
     * un-complete (Phase 2). Whether the emphasis is currently *shown* is a
     * separate render-time decision (! $step->is_completed), made in the view.
     *
     * @return 'overdue'|'imminent'|null
     */
    public function deadlinePressure(): ?string
    {
        if ($this->deadline === null) {
            return null;
        }

        if ($this->deadline->lessThan(today())) {
            return 'overdue';
        }

        if ($this->deadline->lessThanOrEqualTo(today()->addDays(self::IMMINENT_WINDOW_DAYS))) {
            return 'imminent';
        }

        return null;
    }

    /** @return BelongsTo<Venture, $this> */
    public function venture(): BelongsTo
    {
        return $this->belongsTo(Venture::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
