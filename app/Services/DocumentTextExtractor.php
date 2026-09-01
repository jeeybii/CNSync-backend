<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class DocumentTextExtractor
{
    /**
     * @return array<int, array{chunk_index:int,content:string,page_number:int|null,metadata:array<string,mixed>}>
     */
    public function extract(Document $document): array
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->path)) {
            throw new RuntimeException('Document file could not be found for extraction.');
        }

        $mimeType = (string) $document->mime_type;

        $text = match ($mimeType) {
            'application/pdf' => $this->extractPdfText($disk, $document->path),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocxText($disk, $document->path),
            'application/msword' => throw new RuntimeException('Legacy .doc parsing is not supported yet. Please upload DOCX or PDF.'),
            'text/plain' => $this->extractPlainText($disk, $document->path),
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => $this->extractPptxText($disk, $document->path),
            default => throw new RuntimeException(sprintf(
                'Unsupported document mime type [%s].',
                $mimeType,
            )),
        };

        $chunks = $this->chunkText($text);
        if ($chunks === []) {
            throw new RuntimeException('No readable text content found in document.');
        }

        return array_map(
            fn (array $chunk, int $index): array => [
                'chunk_index' => $index,
                'content' => $chunk['content'],
                'page_number' => $chunk['page_number'],
                'metadata' => [
                    'source' => 'document_text_extractor',
                    'mime_type' => $mimeType,
                    'parser' => match ($mimeType) {
                        'application/pdf' => 'pdf',
                        'text/plain' => 'txt',
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                        default => 'docx',
                    },
                ],
            ],
            $chunks,
            array_keys($chunks),
        );
    }

    private function extractPdfText(Filesystem $disk, string $path): string
    {
        $binary = $disk->get($path);

        $text = $this->extractPdfTextViaCommand($disk, $path);
        if (! blank($text)) {
            return $text;
        }

        // Fallback heuristic for simple PDFs when pdftotext is unavailable.
        preg_match_all('/\((.*?)\)\s*Tj/s', $binary, $singleMatches);
        preg_match_all('/\[(.*?)\]\s*TJ/s', $binary, $arrayMatches);

        $parts = $singleMatches[1] ?? [];
        foreach ($arrayMatches[1] ?? [] as $chunk) {
            preg_match_all('/\((.*?)\)/s', $chunk, $inner);
            $parts = array_merge($parts, $inner[1] ?? []);
        }

        $decoded = array_map(
            static fn (string $part): string => stripcslashes($part),
            $parts,
        );

        return trim(preg_replace('/\s+/', ' ', implode(' ', $decoded)) ?? '');
    }

    private function extractPdfTextViaCommand(Filesystem $disk, string $path): ?string
    {
        if (! method_exists($disk, 'path')) {
            return null;
        }

        /** @var string $absolutePath */
        $absolutePath = $disk->path($path);
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $command = sprintf(
            'pdftotext -layout -q %s - 2>%s',
            escapeshellarg($absolutePath),
            $nullDevice,
        );

        $output = shell_exec($command);
        if (! is_string($output)) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $output) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    private function extractPlainText(Filesystem $disk, string $path): string
    {
        $raw = $disk->get($path);

        return trim(preg_replace('/\s+/', ' ', (string) $raw) ?? '');
    }

    private function extractPptxText(Filesystem $disk, string $path): string
    {
        $binary = $disk->get($path);
        $tmpFile = tempnam(sys_get_temp_dir(), 'cnsync-pptx-');

        if ($tmpFile === false) {
            throw new RuntimeException('Unable to create temporary file for PPTX parsing.');
        }

        file_put_contents($tmpFile, $binary);

        try {
            $zip = new ZipArchive;
            if ($zip->open($tmpFile) !== true) {
                throw new RuntimeException('Unable to open PPTX archive.');
            }

            $slideNames = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
                    $slideNames[] = $name;
                }
            }

            sort($slideNames);
            $parts = [];
            foreach ($slideNames as $name) {
                $xml = $zip->getFromName($name);
                if (! is_string($xml) || $xml === '') {
                    continue;
                }

                $xml = str_replace(['</a:p>', '</a:br>', '</p:sld>', '</a:t>'], ["\n", "\n", "\n", ' '], $xml);
                $parts[] = strip_tags($xml);
            }

            $zip->close();

            return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
        } finally {
            @unlink($tmpFile);
        }
    }

    private function extractDocxText(Filesystem $disk, string $path): string
    {
        $binary = $disk->get($path);
        $tmpFile = tempnam(sys_get_temp_dir(), 'cnsync-docx-');

        if ($tmpFile === false) {
            throw new RuntimeException('Unable to create temporary file for DOCX parsing.');
        }

        file_put_contents($tmpFile, $binary);

        try {
            $zip = new ZipArchive;
            if ($zip->open($tmpFile) !== true) {
                throw new RuntimeException('Unable to open DOCX archive.');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if (! is_string($xml) || $xml === '') {
                throw new RuntimeException('DOCX does not contain readable document.xml.');
            }

            $xml = str_replace(['</w:p>', '</w:tr>'], ["\n", "\n"], $xml);
            $text = strip_tags($xml);

            return trim(preg_replace('/\s+/', ' ', $text) ?? '');
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * @return array<int, array{content:string,page_number:int|null}>
     */
    private function chunkText(string $text): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($normalized === '') {
            return [];
        }

        $maxLength = 1200;
        $chunks = [];
        $buffer = '';

        foreach (explode(' ', $normalized) as $token) {
            $candidate = $buffer === '' ? $token : $buffer.' '.$token;
            if (mb_strlen($candidate) > $maxLength) {
                $chunks[] = [
                    'content' => $buffer,
                    'page_number' => null,
                ];
                $buffer = $token;

                continue;
            }

            $buffer = $candidate;
        }

        if ($buffer !== '') {
            $chunks[] = [
                'content' => $buffer,
                'page_number' => null,
            ];
        }

        return $chunks;
    }
}
