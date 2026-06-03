<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['amount', 'description', 'date'])]
class Expense extends Model
{
    use HasFactory;

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    protected $touches = ['venture'];

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
