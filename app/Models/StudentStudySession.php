<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentStudySession extends Model
{
    protected $fillable = [
        'user_id',
        'student_deck_id',
        'total_cards',
        'cards_completed',
        'hints_used',
        'reveals_used',
        'xp_earned',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_cards' => 'integer',
            'cards_completed' => 'integer',
            'hints_used' => 'integer',
            'reveals_used' => 'integer',
            'xp_earned' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studentDeck(): BelongsTo
    {
        return $this->belongsTo(StudentDeck::class);
    }
}
