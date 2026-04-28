<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionRun extends Model
{
    protected $fillable = [
        'project_id',
        'tos_run_id',
        'requested_items',
        'generated_items',
        'status',
        'failed_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_items' => 'integer',
            'generated_items' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tosRun(): BelongsTo
    {
        return $this->belongsTo(TosRun::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuestionItem::class);
    }
}
