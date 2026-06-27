<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Project;
use App\Models\QuestionItem;
use App\Models\QuestionRun;
use App\Models\TosRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AssessmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Assessment::query()
            ->where('user_id', $request->user()->id)
            ->with(['questionRun.items' => fn ($q) => $q->orderBy('sequence')])
            ->latest()
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Assessment $a): array => $this->serializeAssessment($a)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'question_run_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'exam_type' => ['required', 'string', 'max:64'],
            'number_of_items' => ['required', 'integer', 'min:0'],
            'number_of_sets' => ['sometimes', 'integer', 'in:1,2'],
            'tos' => ['required', 'array'],
        ]);

        $user = $request->user();
        $project = Project::query()
            ->where('user_id', $user->id)
            ->whereKey($validated['project_id'])
            ->firstOrFail();

        $questionRun = QuestionRun::query()
            ->where('project_id', $project->id)
            ->whereKey($validated['question_run_id'])
            ->with([
                'items' => fn ($q) => $q->orderBy('sequence'),
                'tosRun.allocations',
            ])
            ->firstOrFail();

        $numberOfSets = (int) ($validated['number_of_sets'] ?? 1);
        $setBSequence = $numberOfSets === 2
            ? $this->buildShuffledSequence($questionRun->items)
            : null;

        $assessment = Assessment::query()->create([
            'user_id' => $user->id,
            'project_id' => $validated['project_id'],
            'question_run_id' => $validated['question_run_id'],
            'title' => $validated['title'],
            'exam_type' => $validated['exam_type'],
            'number_of_items' => $validated['number_of_items'],
            'number_of_sets' => $numberOfSets,
            'set_b_sequence' => $setBSequence,
            'tos' => $validated['tos'],
        ]);

        $assessment->setRelation('questionRun', $questionRun);

        return response()->json([
            'data' => $this->serializeAssessment($assessment, withComputedTos: true),
        ], 201);
    }

    public function show(Assessment $assessment): JsonResponse
    {
        $assessment->load([
            'questionRun.items' => fn ($q) => $q->orderBy('sequence'),
            'questionRun.tosRun.allocations',
        ]);

        return response()->json([
            'data' => $this->serializeAssessment($assessment, withComputedTos: true),
        ]);
    }

    public function update(Request $request, Assessment $assessment): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'exam_type' => ['sometimes', 'string', 'max:64'],
            'number_of_items' => ['sometimes', 'integer', 'min:0'],
            'number_of_sets' => ['sometimes', 'integer', 'in:1,2'],
            'tos' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('number_of_sets', $validated)) {
            $numberOfSets = (int) $validated['number_of_sets'];

            if ($numberOfSets === 2 && $assessment->number_of_sets !== 2) {
                $assessment->load(['questionRun.items' => fn ($q) => $q->orderBy('sequence')]);
                $validated['set_b_sequence'] = $this->buildShuffledSequence(
                    $assessment->questionRun->items,
                );
            } elseif ($numberOfSets === 1) {
                $validated['set_b_sequence'] = null;
            }
        }

        $assessment->update($validated);
        $assessment->load([
            'questionRun.items' => fn ($q) => $q->orderBy('sequence'),
            'questionRun.tosRun.allocations',
        ]);

        return response()->json([
            'data' => $this->serializeAssessment($assessment, withComputedTos: true),
        ]);
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        $assessment->delete();

        return response()->json(null, 204);
    }

    /**
     * @param  Collection<int, QuestionItem>  $items
     * @return array<int, int>
     */
    private function buildShuffledSequence(Collection $items): array
    {
        $ids = $items->sortBy('sequence')->pluck('id')->toArray();

        // Guarantee the shuffle differs from Set A when more than one item exists.
        do {
            shuffle($ids);
        } while (count($ids) > 1 && $ids === $items->sortBy('sequence')->pluck('id')->toArray());

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAssessment(Assessment $assessment, bool $withComputedTos = false): array
    {
        $run = $assessment->questionRun;
        $items = $run?->items ?? collect();
        $setAItems = $items->sortBy('sequence')->values();

        $computedTos = null;
        $setBComputedTos = null;

        if ($withComputedTos && $run?->tosRun !== null) {
            $tosRun = $run->tosRun;

            $computedTos = $this->buildComputedTos(
                $tosRun,
                $items,
                fn (QuestionItem $item): int => (int) $item->sequence,
            );

            if ($assessment->number_of_sets === 2 && ! empty($assessment->set_b_sequence)) {
                // Map question ID → 1-indexed Set B position
                $setBPositions = [];
                foreach ($assessment->set_b_sequence as $idx => $id) {
                    $setBPositions[(int) $id] = $idx + 1;
                }

                $setBComputedTos = $this->buildComputedTos(
                    $tosRun,
                    $items,
                    fn (QuestionItem $item): int => $setBPositions[$item->id] ?? (int) $item->sequence,
                );
            }
        }

        return [
            'id' => $assessment->id,
            'created_at' => $assessment->created_at?->toIso8601String(),
            'updated_at' => $assessment->updated_at?->toIso8601String(),
            'title' => $assessment->title,
            'exam_type' => $assessment->exam_type,
            'number_of_items' => $assessment->number_of_items,
            'number_of_sets' => $assessment->number_of_sets,
            'project_id' => $assessment->project_id,
            'question_run_id' => $assessment->question_run_id,
            'tos' => $assessment->tos,
            'question_items' => $setAItems
                ->map(fn (QuestionItem $item): array => $this->serializeQuestionItem($item))
                ->values()
                ->all(),
            'set_b_items' => $assessment->number_of_sets === 2 && ! empty($assessment->set_b_sequence)
                ? $this->orderItemsByIds($items, $assessment->set_b_sequence)
                : null,
            'computed_tos' => $computedTos,
            'set_b_computed_tos' => $setBComputedTos,
        ];
    }

    /**
     * Builds the CNSC-format TOS from question items and TOS run allocations.
     *
     * Topics are ordered according to the original syllabus order stored in `TosRun->topics`.
     * For each topic × bloom level cell, item numbers are collected via `$positionOf` so the
     * same method works for both Set A (original sequence) and Set B (shuffled sequence).
     *
     * @param  Collection<int, QuestionItem>  $items
     */
    private function buildComputedTos(TosRun $tosRun, Collection $items, \Closure $positionOf): array
    {
        $allocations = $tosRun->allocations;

        // Distinct bloom levels in first-appearance order from allocations
        $bloomLevels = $allocations
            ->pluck('bloom_level')
            ->map(fn ($b) => $b->value)
            ->unique()
            ->values()
            ->toArray();

        // Syllabus topic order preserved from the TOS run snapshot
        $topicOrder = collect($tosRun->topics ?? [])->pluck('name')->toArray();
        $allocationsByTopic = $allocations->groupBy('topic_name');

        // Start with topics in syllabus order, then append any extras from allocations
        $orderedTopicNames = collect($topicOrder)
            ->filter(fn (string $name): bool => $allocationsByTopic->has($name))
            ->values();

        foreach ($allocationsByTopic->keys() as $name) {
            if (! $orderedTopicNames->contains($name)) {
                $orderedTopicNames->push($name);
            }
        }

        // Collect raw weights. When only a subset of topics have items (e.g. 5
        // items across 13 syllabus topics), the active topics' weights only sum
        // to a fraction of 100. Re-normalise them relative to each other so
        // the displayed weight column sums to 100% among shown topics.
        $rawWeights = $orderedTopicNames->map(
            fn (string $name): float => (float) $allocationsByTopic->get($name)->first()->topic_weight * 100
        )->values()->toArray();

        $activeSum = array_sum($rawWeights) ?: 100.0;
        $rescaled = array_map(static fn (float $w): float => ($w / $activeSum) * 100, $rawWeights);

        $normalizedWeights = $this->largestRemainderRound($rescaled, 100);

        $topicRows = $orderedTopicNames->values()->map(function (string $topicName, int $idx) use ($allocationsByTopic, $bloomLevels, $items, $positionOf, $normalizedWeights): array {
            $firstAlloc = $allocationsByTopic->get($topicName)->first();
            $topicItems = $items->filter(
                fn (QuestionItem $item): bool => $item->topic_name === $topicName
            );

            $bloomData = [];
            foreach ($bloomLevels as $bloomLevel) {
                $levelItems = $topicItems
                    ->filter(fn (QuestionItem $item): bool => $item->bloom_level->value === $bloomLevel)
                    ->sortBy($positionOf);

                $positions = $levelItems
                    ->map(fn (QuestionItem $item): int => $positionOf($item))
                    ->values()
                    ->toArray();

                $bloomData[$bloomLevel] = [
                    'item_count' => count($positions),
                    'item_numbers' => $positions,
                    'item_range' => $this->formatItemRange($positions),
                ];
            }

            return [
                'topic_name' => $topicName,
                'hours' => (float) $firstAlloc->topic_hours,
                'weight' => $normalizedWeights[$idx],
                'total_items' => $topicItems->count(),
                'bloom_levels' => $bloomData,
            ];
        })->values()->toArray();

        // Column totals
        $totalsByBloom = [];
        foreach ($bloomLevels as $bloomLevel) {
            $count = 0;
            foreach ($topicRows as $row) {
                $count += $row['bloom_levels'][$bloomLevel]['item_count'];
            }
            $totalsByBloom[$bloomLevel] = $count;
        }

        return [
            'bloom_levels' => $bloomLevels,
            'topics' => $topicRows,
            'totals' => [
                'hours' => (float) collect($topicRows)->sum('hours'),
                'weight' => 100.0,
                'total_items' => $items->count(),
                'by_bloom_level' => $totalsByBloom,
            ],
        ];
    }

    /**
     * Rounds an array of floats to integers while guaranteeing the sum equals $target.
     * Uses the largest-remainder method to distribute rounding differences.
     *
     * @param  array<int, float>  $values
     * @return array<int, int>
     */
    private function largestRemainderRound(array $values, int $target): array
    {
        $floored = array_map('intval', array_map('floor', $values));
        $remainders = array_map(
            fn (float $v, int $f): float => $v - $f,
            $values,
            $floored,
        );

        $toDistribute = $target - array_sum($floored);
        arsort($remainders);

        foreach (array_keys($remainders) as $i) {
            if ($toDistribute <= 0) {
                break;
            }
            $floored[$i]++;
            $toDistribute--;
        }

        return $floored;
    }

    /**
     * Converts an array of consecutive or non-consecutive integers into a human-readable
     * range string (e.g. [1,2,3,7,8] → "1-3, 7-8").
     *
     * @param  array<int, int>  $positions
     */
    private function formatItemRange(array $positions): ?string
    {
        if (empty($positions)) {
            return null;
        }

        $sorted = $positions;
        sort($sorted);

        $ranges = [];
        $start = $sorted[0];
        $end = $sorted[0];

        for ($i = 1, $total = count($sorted); $i < $total; $i++) {
            if ($sorted[$i] === $end + 1) {
                $end = $sorted[$i];
            } else {
                $ranges[] = $start === $end ? (string) $start : "{$start}-{$end}";
                $start = $end = $sorted[$i];
            }
        }
        $ranges[] = $start === $end ? (string) $start : "{$start}-{$end}";

        return implode(', ', $ranges);
    }

    /**
     * Returns items ordered by the given ID sequence, re-numbered 1-indexed.
     *
     * @param  Collection<int, QuestionItem>  $items
     * @param  array<int, int>  $idSequence
     * @return array<int, array<string, mixed>>
     */
    private function orderItemsByIds(Collection $items, array $idSequence): array
    {
        $indexed = $items->keyBy('id');
        $result = [];

        foreach ($idSequence as $position => $id) {
            $item = $indexed->get($id);
            if ($item === null) {
                continue;
            }
            $serialized = $this->serializeQuestionItem($item);
            $serialized['sequence'] = $position + 1;
            $result[] = $serialized;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeQuestionItem(QuestionItem $item): array
    {
        return [
            'id' => $item->id,
            'topic_name' => $item->topic_name,
            'bloom_level' => $item->bloom_level->value,
            'question_type' => $item->question_type,
            'sequence' => $item->sequence,
            'question_text' => $item->question_text,
            'options' => $item->options,
            'answer_key' => $item->answer_key,
            'citations' => $item->citations,
        ];
    }
}
