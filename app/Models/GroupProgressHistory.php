<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupProgressHistory extends Model
{
    protected $fillable = [
        'group_id',
        'participant_count',
        'percentage',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}