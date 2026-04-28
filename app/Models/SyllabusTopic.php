<?php

namespace App\Models;

use App\Enums\BloomLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyllabusTopic extends Model
{
    protected $fillable = [
        'project_id',
        'document_id',
        'topic_name',
        'hours',
        'objective',
        'bloom_level',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'float',
            'confidence' => 'float',
            'bloom_level' => BloomLevel::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
