<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentDeck extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'status',
        'number_of_items',
        'questions',
        'failed_reason',
    ];

    protected function casts(): array
    {
        return [
            'number_of_items' => 'integer',
            'questions' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studySessions(): HasMany
    {
        return $this->hasMany(StudentStudySession::class);
    }
}
