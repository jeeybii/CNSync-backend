<?php

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use Illuminate\Support\Facades\Storage;

test('document text extractor parses simple docx content', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $docxBinary = buildDocxBinary('<w:p><w:r><w:t>Week 1 Neural Networks 6 hours</w:t></w:r></w:p>');

    Storage::disk('local')->put('documents/test/syllabus.docx', $docxBinary);

    $document = Document::factory()->for($project)->create([
        'disk' => 'local',
        'path' => 'documents/test/syllabus.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Uploaded,
    ]);

    $chunks = (new DocumentTextExtractor)->extract($document);

    expect($chunks)->not->toBeEmpty()
        ->and($chunks[0]['content'])->toContain('Week 1 Neural Networks 6 hours')
        ->and($chunks[0]['metadata']['parser'])->toBe('docx');
});

test('document text extractor parses simple pdf content fallback', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $pdfBinary = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\nBT (Topic A 3 hours) Tj ET";

    Storage::disk('local')->put('documents/test/syllabus.pdf', $pdfBinary);

    $document = Document::factory()->for($project)->create([
        'disk' => 'local',
        'path' => 'documents/test/syllabus.pdf',
        'mime_type' => 'application/pdf',
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Uploaded,
    ]);

    $chunks = (new DocumentTextExtractor)->extract($document);

    expect($chunks)->not->toBeEmpty()
        ->and($chunks[0]['content'])->toContain('Topic A 3 hours')
        ->and($chunks[0]['metadata']['parser'])->toBe('pdf');
});

function buildDocxBinary(string $documentBody): string
{
    $tempFile = tempnam(sys_get_temp_dir(), 'cnsync-test-docx-');
    if ($tempFile === false) {
        throw new RuntimeException('Unable to create temporary DOCX test file.');
    }

    $zip = new ZipArchive;
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to open ZIP archive for DOCX test file.');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
    $zip->addFromString('word/document.xml', sprintf(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>%s</w:body></w:document>',
        $documentBody,
    ));
    $zip->close();

    $binary = file_get_contents($tempFile);
    @unlink($tempFile);

    if (! is_string($binary)) {
        throw new RuntimeException('Unable to read generated DOCX test file.');
    }

    return $binary;
}
