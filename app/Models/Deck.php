<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deck extends Model
{
    protected $fillable = [
        'user_id',
        'source_assessment_id',
        'title',
        'description',
        'tags',
        'visibility',
        'course_code',
        'course_title',
        'faculty_name',
        'exam_type',
        'number_of_items',
        'tos',
        'questions',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'tos' => 'array',
            'questions' => 'array',
            'number_of_items' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceAssessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'source_assessment_id');
    }
}
