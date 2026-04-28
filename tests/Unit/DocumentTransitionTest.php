<?php

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Services\DocumentTextExtractor;
use Illuminate\Support\Facades\Storage;

test('document only allows forward lifecycle transitions', function () {
    $document = Document::factory()->create([
        'status' => DocumentStatus::Uploaded,
    ]);

    $document->transitionTo(DocumentStatus::Parsing);
    $document->transitionTo(DocumentStatus::Extracted);
    $document->transitionTo(DocumentStatus::Analyzed);
    $document->transitionTo(DocumentStatus::TosReady);
    $document->transitionTo(DocumentStatus::QuestionsReady);

    expect($document->fresh()->status)->toBe(DocumentStatus::QuestionsReady);
    expect($document->statusTransitions()->count())->toBe(5);

    $toStatuses = $document->statusTransitions()
        ->orderBy('transitioned_at')
        ->orderBy('id')
        ->pluck('to_status')
        ->all();

    expect($toStatuses)->toBe([
        DocumentStatus::Parsing,
        DocumentStatus::Extracted,
        DocumentStatus::Analyzed,
        DocumentStatus::TosReady,
        DocumentStatus::QuestionsReady,
    ]);
});

test('document rejects invalid lifecycle transitions', function () {
    $document = Document::factory()->create([
        'status' => DocumentStatus::Uploaded,
    ]);

    expect(fn () => $document->transitionTo(DocumentStatus::QuestionsReady))
        ->toThrow(InvalidDocumentStatusTransitionException::class);
});

test('document requires failure reason when transitioning to failed', function () {
    $document = Document::factory()->create([
        'status' => DocumentStatus::Parsing,
    ]);

    expect(fn () => $document->transitionTo(DocumentStatus::Failed))
        ->toThrow(InvalidArgumentException::class);
});

test('document persists failure reason in transition trail', function () {
    $document = Document::factory()->create([
        'status' => DocumentStatus::Parsing,
    ]);

    $document->transitionTo(DocumentStatus::Failed, 'Parser timeout');

    $transition = $document->statusTransitions()->latest('id')->first();

    expect($transition)->not->toBeNull()
        ->and($transition->from_status)->toBe(DocumentStatus::Parsing)
        ->and($transition->to_status)->toBe(DocumentStatus::Failed)
        ->and($transition->failed_reason)->toBe('Parser timeout');
});

test('process document job marks document as failed when file is missing', function () {
    Storage::fake('local');

    app()->bind(DocumentTextExtractor::class, fn () => new DocumentTextExtractor);

    $document = Document::factory()->create([
        'disk' => 'local',
        'path' => 'documents/404/missing.pdf',
        'mime_type' => 'application/pdf',
        'status' => DocumentStatus::Uploaded,
    ]);

    expect(fn () => app()->call([(new ProcessDocumentJob($document->id)), 'handle']))
        ->toThrow(RuntimeException::class, 'Document file could not be found for extraction.');

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->failed_reason)->toBe('Document file could not be found for extraction.');
});
