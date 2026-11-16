<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Group extends Model
{
    use HasFactory;

    // Les champs autorisés à être modifiés
    protected $fillable = [
        'name',
        'capacity',
        'current_count',
        'status',
    ];

    // Relation : un groupe possède plusieurs participants
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    // Calcul du pourcentage à partir des vrais participants
    public function getFillPercentageAttribute(): float
    {
        if ($this->capacity <= 0) {
            return 0;
        }

        $count = $this->participants()->count();

        return round(($count / $this->capacity) * 100, 2);
    }
    public function progressHistories(): HasMany
{
    return $this->hasMany(GroupProgressHistory::class);
}
}
