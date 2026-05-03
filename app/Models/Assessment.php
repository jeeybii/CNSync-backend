<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assessment extends Model
{
    protected $fillable = [
        'user_id',
        'project_id',
        'question_run_id',
        'title',
        'exam_type',
        'number_of_items',
        'tos',
    ];

    protected function casts(): array
    {
        return [
            'number_of_items' => 'integer',
            'tos' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function questionRun(): BelongsTo
    {
        return $this->belongsTo(QuestionRun::class);
    }
}
