<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TosRun extends Model
{
    protected $fillable = [
        'project_id',
        'total_items',
        'topics',
        'bloom_distribution',
    ];

    protected function casts(): array
    {
        return [
            'topics' => 'array',
            'bloom_distribution' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TosAllocation::class);
    }

    public function questionRuns(): HasMany
    {
        return $this->hasMany(QuestionRun::class);
    }
}
