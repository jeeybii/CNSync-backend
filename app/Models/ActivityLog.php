<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'description',
        'metadata',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Convenience method to record an activity without throwing on failure.
     * Activity logging must never interrupt the primary request flow.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function record(
        ?int $userId,
        string $action,
        string $description,
        ?array $metadata = null,
        ?string $ipAddress = null,
    ): void {
        try {
            static::query()->create([
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'metadata' => $metadata,
                'ip_address' => $ipAddress,
            ]);
        } catch (\Throwable) {
            // Silently swallow — logging must not break the main flow.
        }
    }
}
