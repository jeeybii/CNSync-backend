<?php

namespace App\Models;

use App\Enums\BloomLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TosAllocation extends Model
{
    protected $fillable = [
        'tos_run_id',
        'topic_name',
        'topic_hours',
        'topic_weight',
        'bloom_level',
        'item_count',
    ];

    protected function casts(): array
    {
        return [
            'bloom_level' => BloomLevel::class,
        ];
    }

    public function tosRun(): BelongsTo
    {
        return $this->belongsTo(TosRun::class);
    }
}
