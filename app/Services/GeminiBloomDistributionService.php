<?php

namespace App\Services;

use App\Enums\BloomLevel;
use RuntimeException;

class GeminiBloomDistributionService
{
    /**
     * @param  array<int, array{name:string,hours:float|int,objective:?string,bloom_level:?string}>  $topics
     * @return array<string, float>
     */
    public function infer(array $topics): array
    {
        if (empty($topics)) {
            throw new RuntimeException('Cannot infer Bloom distribution without syllabus topics.');
        }

        $topicsText = collect($topics)
            ->map(fn (array $topic): string => sprintf(
                '- topic: %s | hours: %s | objective: %s | extracted_bloom: %s',
                $topic['name'],
                (string) $topic['hours'],
                $topic['objective'] ?? 'n/a',
                $topic['bloom_level'] ?? 'n/a',
            ))
            ->implode("\n");

        $prompt = implode("\n", [
            'Infer an exam Bloom distribution from syllabus topics using the Updated Bloom\'s Taxonomy.',
            'Return ONLY JSON with these exact keys: remembering, understanding, applying, analyzing, evaluating, creating.',
            'Each value must be >= 0. Values do not need to sum to 1.',
            'Heavily use objective verbs and topic depth/hours.',
            'Syllabus topics:',
            $topicsText,
        ]);

        $payload = app(AiTextGenerator::class)->generateJson($prompt, 0.1);

        $levels = BloomLevel::values();
        $resolved = [];
        foreach ($levels as $level) {
            $resolved[$level] = max(0.0, (float) ($payload[$level] ?? 0.0));
        }

        $sum = array_sum($resolved);
        if ($sum <= 0) {
            throw new RuntimeException('AI Bloom distribution is invalid (all zero).');
        }

        return array_map(static fn (float $value): float => $value / $sum, $resolved);
    }
}
