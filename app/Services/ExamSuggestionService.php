<?php

namespace App\Services;

use App\Enums\BloomLevel;

class ExamSuggestionService
{
    public const QUESTION_TYPES = ['multiple_choice', 'true_false', 'identification', 'essay', 'matching_type'];

    public const BLOOM_QUESTION_TYPES = [
        'remembering' => ['identification', 'multiple_choice', 'matching_type'],
        'understanding' => ['multiple_choice', 'matching_type', 'true_false'],
        'applying' => ['multiple_choice', 'essay'],
        'analyzing' => ['multiple_choice', 'essay'],
        'evaluating' => ['essay', 'multiple_choice'],
        'creating' => ['essay'],
    ];

    /**
     * @param  array<int, array<string, mixed>>  $topics
     * @param  array<int, mixed>  $additionalLevels
     * @return array<int, string>
     */
    public function bloomLevels(array $topics, array $additionalLevels = []): array
    {
        $topicLevels = array_values(array_unique(array_filter(
            array_column($topics, 'bloom_level'),
            fn (mixed $level): bool => is_string($level) && in_array($level, BloomLevel::values(), true),
        )));
        $taxonomy = BloomLevel::values();
        $nearbyLevels = $taxonomy;
        if ($topicLevels !== []) {
            usort($nearbyLevels, function (string $a, string $b) use ($taxonomy, $topicLevels): int {
                $distance = fn (string $candidate): int => min(array_map(
                    fn (string $level): int => abs(array_search($candidate, $taxonomy, true) - array_search($level, $taxonomy, true)),
                    $topicLevels,
                ));

                return $distance($a) <=> $distance($b)
                    ?: array_search($a, $taxonomy, true) <=> array_search($b, $taxonomy, true);
            });
        }

        $levels = [];
        foreach (array_merge($topicLevels, $additionalLevels, $nearbyLevels) as $level) {
            if (is_string($level) && in_array($level, BloomLevel::values(), true) && ! in_array($level, $levels, true)) {
                $levels[] = $level;
            }
            if (count($levels) >= max(3, count($topicLevels))) {
                break;
            }
        }

        return $levels;
    }

    /**
     * @param  array<string, mixed>|null  $suggested
     * @param  array<int, array<string, mixed>>  $topics
     * @return array<string, int>
     */
    public function questionTypePercentages(?array $suggested, array $topics): array
    {
        $weights = array_fill_keys(self::QUESTION_TYPES, 0.0);
        if ($suggested !== null) {
            foreach (self::QUESTION_TYPES as $type) {
                $value = $suggested[$type] ?? 0;
                $weights[$type] = is_numeric($value) ? max(0.0, (float) $value) : 0.0;
            }
        }

        if (array_sum($weights) <= 0) {
            foreach ($topics as $topic) {
                $hours = max(0.0, (float) ($topic['hours'] ?? 1));
                $types = self::BLOOM_QUESTION_TYPES[$topic['bloom_level'] ?? 'understanding']
                    ?? self::BLOOM_QUESTION_TYPES['understanding'];
                $share = 100 / count($types);
                foreach ($types as $type) {
                    $weights[$type] += $share * $hours;
                }
            }
        }

        if (array_sum($weights) <= 0) {
            $weights['multiple_choice'] = 100;
        }

        return $this->allocate($weights, 100);
    }

    /**
     * @param  array<string, int|float>  $percentages
     * @return array<string, int>
     */
    public function counts(array $percentages, int $items): array
    {
        $weights = [];
        foreach (self::QUESTION_TYPES as $type) {
            $weights[$type] = max(0.0, (float) ($percentages[$type] ?? 0));
        }

        return $this->allocate($weights, $items);
    }

    /**
     * @param  array<string, float>  $weights
     * @return array<string, int>
     */
    private function allocate(array $weights, int $total): array
    {
        $sum = array_sum($weights);
        $raw = [];
        $counts = [];
        foreach ($weights as $type => $weight) {
            $raw[$type] = $sum > 0 ? ($weight / $sum) * $total : 0;
            $counts[$type] = (int) floor($raw[$type]);
        }

        $remainder = $total - array_sum($counts);
        $types = array_keys($weights);
        usort($types, fn (string $a, string $b): int => ($raw[$b] - $counts[$b]) <=> ($raw[$a] - $counts[$a]));
        foreach (array_slice($types, 0, $remainder) as $type) {
            $counts[$type]++;
        }

        return $counts;
    }
}
