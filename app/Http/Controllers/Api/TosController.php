<?php

namespace App\Http\Controllers\Api;

use App\Enums\BloomLevel;
use App\Enums\DocumentKind;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use App\Models\SyllabusTopic;
use App\Models\TosRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TosController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $runs = $project->tosRuns()
            ->with(['allocations' => fn ($query) => $query->orderBy('topic_name')->orderBy('bloom_level')])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $runs]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'total_items' => ['required', 'integer', 'min:1', 'max:500'],
            'topics' => ['required', 'array', 'min:1'],
            'topics.*.name' => ['required', 'string', 'max:255'],
            'topics.*.hours' => ['required', 'numeric', 'gt:0'],
            'bloom_distribution' => ['sometimes', 'array'],
        ]);

        $totalItems = (int) $validated['total_items'];
        $topics = $validated['topics'];
        $bloomDistributionInput = $validated['bloom_distribution'] ?? [];

        $bloomDistribution = $this->normalizeBloomDistribution($bloomDistributionInput);
        $topicWeights = $this->buildTopicWeights($topics);
        $topicItemCounts = $this->allocateByLargestRemainder($topicWeights, $totalItems);
        $tosRun = $this->createRunFromTopics(
            $project,
            $totalItems,
            $topics,
            $bloomDistribution,
            $topicWeights,
            $topicItemCounts,
        );

        return response()->json(['data' => $tosRun], 201);
    }

    public function storeFromSyllabus(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'total_items' => ['required', 'integer', 'min:1', 'max:500'],
            'syllabus_document_id' => ['nullable', 'integer'],
            'bloom_distribution' => ['sometimes', 'array'],
        ]);

        $document = $this->resolveSyllabusDocument($project, $validated['syllabus_document_id'] ?? null);
        $topics = $document->syllabusTopics()
            ->orderByDesc('hours')
            ->get(['topic_name', 'hours'])
            ->map(fn (SyllabusTopic $topic): array => [
                'name' => $topic->topic_name,
                'hours' => $topic->hours,
            ])
            ->all();

        if (empty($topics)) {
            return response()->json([
                'message' => 'No extracted syllabus topics found for this document.',
            ], 422);
        }

        $totalItems = (int) $validated['total_items'];
        $bloomDistribution = $this->normalizeBloomDistribution($validated['bloom_distribution'] ?? []);
        $topicWeights = $this->buildTopicWeights($topics);
        $topicItemCounts = $this->allocateByLargestRemainder($topicWeights, $totalItems);

        $tosRun = $this->createRunFromTopics(
            $project,
            $totalItems,
            $topics,
            $bloomDistribution,
            $topicWeights,
            $topicItemCounts,
        );

        return response()->json(['data' => $tosRun], 201);
    }

    public function show(Project $project, TosRun $tosRun): JsonResponse
    {
        return response()->json([
            'data' => $tosRun->load([
                'allocations' => fn ($query) => $query->orderBy('topic_name')->orderBy('bloom_level'),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $bloomDistributionInput
     * @return array<string, float>
     */
    private function normalizeBloomDistribution(array $bloomDistributionInput): array
    {
        $levels = BloomLevel::values();

        $resolved = [];
        foreach ($levels as $level) {
            $resolved[$level] = (float) ($bloomDistributionInput[$level] ?? 1.0);
        }

        $sum = array_sum($resolved);
        if ($sum <= 0) {
            throw new InvalidArgumentException('Bloom distribution must have a positive total weight.');
        }

        return array_map(static fn (float $value): float => $value / $sum, $resolved);
    }

    /**
     * @param  array<int, array<string, mixed>>  $topics
     * @return array<int, float>
     */
    private function buildTopicWeights(array $topics): array
    {
        $hours = array_map(static fn (array $topic): float => (float) $topic['hours'], $topics);
        $totalHours = array_sum($hours);

        if ($totalHours <= 0) {
            throw new InvalidArgumentException('Topics must have a positive total number of hours.');
        }

        return array_map(static fn (float $hour): float => $hour / $totalHours, $hours);
    }

    /**
     * @param  array<int, float>  $weights
     * @return array<int, int>
     */
    private function allocateByLargestRemainder(array $weights, int $total): array
    {
        $base = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $raw = $weight * $total;
            $base[$index] = (int) floor($raw);
            $remainders[$index] = $raw - $base[$index];
        }

        $allocated = array_sum($base);
        $remaining = $total - $allocated;

        arsort($remainders, SORT_NUMERIC);

        foreach (array_keys($remainders) as $index) {
            if ($remaining === 0) {
                break;
            }

            $base[$index]++;
            $remaining--;
        }

        ksort($base);

        return $base;
    }

    /**
     * @param  array<int, array{name:string,hours:float|int}>  $topics
     * @param  array<string, float>  $bloomDistribution
     * @param  array<int, float>  $topicWeights
     * @param  array<int, int>  $topicItemCounts
     */
    private function createRunFromTopics(
        Project $project,
        int $totalItems,
        array $topics,
        array $bloomDistribution,
        array $topicWeights,
        array $topicItemCounts
    ): TosRun {
        $allocationRows = [];
        foreach ($topics as $index => $topic) {
            $topicTotalItems = $topicItemCounts[$index];
            if ($topicTotalItems === 0) {
                continue;
            }

            $bloomItemCounts = $this->allocateByLargestRemainder(array_values($bloomDistribution), $topicTotalItems);
            $bloomLevels = array_keys($bloomDistribution);

            foreach ($bloomLevels as $bloomIndex => $bloomLevel) {
                $itemCount = $bloomItemCounts[$bloomIndex];
                if ($itemCount === 0) {
                    continue;
                }

                $allocationRows[] = [
                    'topic_name' => $topic['name'],
                    'topic_hours' => (float) $topic['hours'],
                    'topic_weight' => $topicWeights[$index],
                    'bloom_level' => $bloomLevel,
                    'item_count' => $itemCount,
                ];
            }
        }

        $allocatedItems = array_sum(array_column($allocationRows, 'item_count'));
        if ($allocatedItems !== $totalItems) {
            throw new InvalidArgumentException(sprintf(
                'Deterministic allocation invariant failed: expected %d items, allocated %d.',
                $totalItems,
                $allocatedItems,
            ));
        }

        return DB::transaction(function () use ($project, $totalItems, $topics, $bloomDistribution, $allocationRows): TosRun {
            $run = $project->tosRuns()->create([
                'total_items' => $totalItems,
                'topics' => $topics,
                'bloom_distribution' => $bloomDistribution,
            ]);

            $run->allocations()->createMany($allocationRows);

            return $run->fresh(['allocations']);
        });
    }

    private function resolveSyllabusDocument(Project $project, ?int $documentId): Document
    {
        $query = Document::query()
            ->where('project_id', $project->id)
            ->where('kind', DocumentKind::Syllabus);

        if ($documentId !== null) {
            return $query->whereKey($documentId)->firstOrFail();
        }

        return $query->latest('id')->firstOrFail();
    }
}
