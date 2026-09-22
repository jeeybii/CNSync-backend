<?php

namespace App\Models;

use App\Enums\BloomLevel;
use App\Enums\DocumentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'term',
        'week_number',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'float',
            'confidence' => 'float',
            'bloom_level' => BloomLevel::class,
            'week_number' => 'integer',
            'position' => 'integer',
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

    public function materials(): HasMany
    {
        return $this->hasMany(Document::class, 'syllabus_topic_id')->where('kind', DocumentKind::Material);
    }
}
