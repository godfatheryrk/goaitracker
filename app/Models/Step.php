<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['owner_id', 'body', 'is_completed', 'source', 'position'])]
class Step extends Model
{
    public const SOURCE_AI_INITIAL = 'ai_initial';

    public const SOURCE_AI_EXTENSION = 'ai_extension';

    public const SOURCE_MANUAL = 'manual';

    protected $casts = [
        'is_completed' => 'boolean',
    ];

    public function venture(): BelongsTo
    {
        return $this->belongsTo(Venture::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
