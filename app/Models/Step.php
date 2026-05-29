<?php

namespace App\Models;

use App\Enums\StepSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['body', 'source', 'position'])]
class Step extends Model
{
    use HasFactory;

    protected $casts = [
        'is_completed' => 'boolean',
        'source' => StepSource::class,
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
