<?php

namespace App\Models;

use App\Enums\BloomLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionItem extends Model
{
    protected $fillable = [
        'question_run_id',
        'topic_name',
        'bloom_level',
        'question_type',
        'sequence',
        'question_text',
        'options',
        'answer_key',
        'citations',
    ];

    protected function casts(): array
    {
        return [
            'bloom_level' => BloomLevel::class,
            'options' => 'array',
            'citations' => 'array',
        ];
    }

    public function questionRun(): BelongsTo
    {
        return $this->belongsTo(QuestionRun::class);
    }
}
