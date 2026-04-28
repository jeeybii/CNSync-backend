<?php

namespace App\Models;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'kind',
        'status',
        'failed_reason',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'status' => DocumentStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function statusTransitions(): HasMany
    {
        return $this->hasMany(DocumentStatusTransition::class);
    }

    public function extractedChunks(): HasMany
    {
        return $this->hasMany(ExtractedChunk::class);
    }

    public function syllabusTopics(): HasMany
    {
        return $this->hasMany(SyllabusTopic::class);
    }

    public function transitionTo(DocumentStatus $status, ?string $failedReason = null): void
    {
        /** @var DocumentStatus $currentStatus */
        $currentStatus = $this->status;

        if (! $currentStatus->canTransitionTo($status)) {
            throw InvalidDocumentStatusTransitionException::fromStatuses($currentStatus, $status);
        }

        if ($status === DocumentStatus::Failed && blank($failedReason)) {
            throw new InvalidArgumentException('A failed status requires a failure reason.');
        }

        DB::transaction(function () use ($status, $failedReason, $currentStatus): void {
            $this->update([
                'status' => $status,
                'failed_reason' => $status === DocumentStatus::Failed ? $failedReason : null,
            ]);

            $this->statusTransitions()->create([
                'from_status' => $currentStatus,
                'to_status' => $status,
                'failed_reason' => $status === DocumentStatus::Failed ? $failedReason : null,
                'transitioned_at' => now(),
            ]);
        });
    }
}
