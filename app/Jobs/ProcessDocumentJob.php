<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\DocumentTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $documentId
    ) {}

    public function handle(DocumentTextExtractor $extractor): void
    {
        $document = Document::query()->find($this->documentId);

        if (! $document) {
            return;
        }

        try {
            $document->transitionTo(DocumentStatus::Parsing);

            $chunks = $extractor->extract($document);

            $document->extractedChunks()->delete();
            $document->extractedChunks()->createMany($chunks);

            $document->transitionTo(DocumentStatus::Extracted);
            $document->transitionTo(DocumentStatus::Analyzed);
        } catch (\Throwable $exception) {
            $document->transitionTo(DocumentStatus::Failed, $exception->getMessage());

            throw $exception;
        }
    }
}
