<?php

namespace App\Http\Controllers\Api;

use App\Enums\BloomLevel;
use App\Enums\DocumentKind;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use App\Models\SyllabusTopic;
use App\Models\TosRun;
use App\Services\ExamSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TosController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $runs = $project->tosRuns()
            ->with(['allocations' => fn ($query) => $query->orderBy('id')])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $runs]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->assertRequiredDocumentsUploaded($project);

        $validated = $request->validate([
            'total_items' => ['required', 'integer', 'min:1', 'max:500'],
            'topics' => ['required', 'array', 'min:1'],
            'topics.*.name' => ['required', 'string', 'max:255'],
            'topics.*.hours' => ['required', 'numeric', 'gt:0'],
            'bloom_levels' => ['required', 'array', 'size:3'],
            'bloom_levels.*' => ['required', 'string', Rule::in(BloomLevel::values())],
        ]);

        $this->validateUniqueBloomLevels($validated['bloom_levels']);

        $bloomDistribution = $this->buildEqualDistribution($validated['bloom_levels']);
        $topicWeights = $this->buildTopicWeights($validated['topics']);
        $topicItemCounts = $this->allocateByLargestRemainder($topicWeights, (int) $validated['total_items']);

        $tosRun = $this->createRunFromTopics(
            $project,
            (int) $validated['total_items'],
            $validated['topics'],
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
            'bloom_levels' => ['required', 'array', 'size:3'],
            'bloom_levels.*' => ['required', 'string', Rule::in(BloomLevel::values())],
            'term' => ['nullable', 'string', Rule::in(['midterm', 'final'])],
        ]);

        $term = $validated['term'] ?? null;
        $this->assertRequiredDocumentsUploaded($project);

        $this->validateUniqueBloomLevels($validated['bloom_levels']);

        $document = $this->resolveSyllabusDocument($project, $validated['syllabus_document_id'] ?? null);
        $topics = $document->syllabusTopics()
            ->when($term !== null, fn ($query) => $query->where('term', $term))
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'topic_name', 'hours'])
            ->map(fn (SyllabusTopic $topic): array => [
                'syllabus_topic_id' => $topic->id,
                'name' => $topic->topic_name,
                'hours' => $topic->hours,
            ])
            ->all();

        if (empty($topics)) {
            return response()->json([
                'message' => 'No extracted syllabus topics found for this document.',
            ], 422);
        }

        $bloomDistribution = $this->buildEqualDistribution($validated['bloom_levels']);
        $topicWeights = $this->buildTopicWeights($topics);
        $topicItemCounts = $this->allocateByLargestRemainder($topicWeights, (int) $validated['total_items']);

        $tosRun = $this->createRunFromTopics(
            $project,
            (int) $validated['total_items'],
            $topics,
            $bloomDistribution,
            $topicWeights,
            $topicItemCounts,
            $term,
        );

        return response()->json(['data' => $tosRun], 201);
    }

    public function storePairFromSyllabus(Request $request, Project $project, ExamSuggestionService $suggestions): JsonResponse
    {
        $validated = $request->validate([
            'syllabus_document_id' => ['nullable', 'integer'],
            'midterm' => ['required', 'array'],
            'final' => ['required', 'array'],
            'midterm.total_items' => ['required', 'integer', 'min:1', 'max:500'],
            'final.total_items' => ['required', 'integer', 'min:1', 'max:500'],
            'midterm.bloom_levels' => ['sometimes', 'array', 'min:3', 'max:6'],
            'final.bloom_levels' => ['sometimes', 'array', 'min:3', 'max:6'],
            'midterm.bloom_levels.*' => ['string', Rule::in(BloomLevel::values())],
            'final.bloom_levels.*' => ['string', Rule::in(BloomLevel::values())],
            'midterm.question_type_percentages' => ['sometimes', 'array'],
            'final.question_type_percentages' => ['sometimes', 'array'],
            'midterm.question_type_percentages.*' => ['numeric', 'min:0'],
            'final.question_type_percentages.*' => ['numeric', 'min:0'],
        ]);

        $this->assertRequiredDocumentsUploaded($project);
        $document = $this->resolveSyllabusDocument($project, $validated['syllabus_document_id'] ?? null);
        $runs = [];

        foreach (['midterm', 'final'] as $term) {
            $termTopics = $document->syllabusTopics()
                ->where('term', $term)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'topic_name', 'hours', 'objective', 'bloom_level'])
                ->map(fn (SyllabusTopic $topic): array => [
                    'syllabus_topic_id' => $topic->id,
                    'name' => $topic->topic_name,
                    'hours' => $topic->hours,
                    'objective' => $topic->objective,
                    'bloom_level' => $topic->bloom_level?->value,
                ])
                ->all();

            if ($termTopics === []) {
                throw ValidationException::withMessages([
                    $term => [sprintf('The syllabus needs at least one %s topic before generating both exams.', $term)],
                ]);
            }

            $bloomLevels = $validated[$term]['bloom_levels']
                ?? $document->suggested_bloom_levels_by_term[$term]
                ?? $suggestions->bloomLevels($termTopics);
            $this->validateUniqueBloomLevels($bloomLevels);
            if (count($bloomLevels) < 3 || count($bloomLevels) > 6) {
                throw ValidationException::withMessages([
                    "$term.bloom_levels" => ['Select at least three distinct Bloom levels.'],
                ]);
            }

            $overrides = $validated[$term]['question_type_percentages'] ?? null;
            if ($overrides !== null && array_diff(array_keys($overrides), ExamSuggestionService::QUESTION_TYPES) !== []) {
                throw ValidationException::withMessages([
                    "$term.question_type_percentages" => ['Only Multiple Choice, True/False, Identification, and Essay are supported.'],
                ]);
            }
            if ($overrides !== null && array_sum($overrides) <= 0) {
                throw ValidationException::withMessages([
                    "$term.question_type_percentages" => ['Question-type percentages must contain a positive value.'],
                ]);
            }

            $percentages = $suggestions->questionTypePercentages(
                $overrides ?? ($document->suggested_question_type_percentages_by_term[$term] ?? null),
                $termTopics,
            );
            $totalItems = (int) $validated[$term]['total_items'];
            $topics = array_map(fn (array $topic): array => [
                'syllabus_topic_id' => $topic['syllabus_topic_id'],
                'name' => $topic['name'],
                'hours' => $topic['hours'],
            ], $termTopics);
            $weights = $this->buildTopicWeights($topics);
            $runs[$term] = [
                'total_items' => $totalItems,
                'topics' => $topics,
                'bloom_distribution' => $this->buildEqualDistribution($bloomLevels),
                'topic_weights' => $weights,
                'topic_item_counts' => $this->allocateByLargestRemainder($weights, $totalItems),
                'question_type_percentages' => $percentages,
                'question_type_distribution' => $suggestions->counts($percentages, $totalItems),
            ];
        }

        return DB::transaction(function () use ($project, $runs): JsonResponse {
            $created = [];
            foreach (['midterm', 'final'] as $term) {
                $plan = $runs[$term];
                $tosRun = $this->createRunFromTopics(
                    $project,
                    $plan['total_items'],
                    $plan['topics'],
                    $plan['bloom_distribution'],
                    $plan['topic_weights'],
                    $plan['topic_item_counts'],
                    $term,
                    $plan['question_type_distribution'],
                );
                $created[$term] = [
                    'tos_run' => $tosRun,
                    'question_type_percentages' => $plan['question_type_percentages'],
                    'question_type_distribution' => $plan['question_type_distribution'],
                ];
            }

            return response()->json(['data' => $created], 201);
        });
    }

    public function show(Project $project, TosRun $tosRun): JsonResponse
    {
        return response()->json([
            'data' => $tosRun->load([
                'allocations' => fn ($query) => $query->orderBy('id'),
            ]),
        ]);
    }

    /**
     * Builds an equal-weight distribution from exactly 3 selected Bloom levels.
     *
     * @param  array<int, string>  $levels
     * @return array<string, float>
     */
    private function buildEqualDistribution(array $levels): array
    {
        $weight = 1.0 / count($levels);

        return array_fill_keys($levels, $weight);
    }

    /**
     * @param  array<int, string>  $levels
     */
    private function validateUniqueBloomLevels(array $levels): void
    {
        if (count($levels) !== count(array_unique($levels))) {
            throw ValidationException::withMessages([
                'bloom_levels' => ['Each cognitive level must be selected only once.'],
            ]);
        }
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
        array $topicItemCounts,
        ?string $term = null,
        ?array $questionTypeDistribution = null
    ): TosRun {
        $bloomLevels = array_keys($bloomDistribution);

        // Compute global bloom targets first so the overall distribution is
        // correct regardless of how many items each topic receives.
        // Without this, per-topic LR rounding causes early bloom levels to
        // absorb all items (e.g. 13/7/0 instead of 7/7/6 for 20 items).
        $globalBloomCounts = $this->allocateByLargestRemainder(
            array_values($bloomDistribution),
            $totalItems,
        );

        // Remaining global budget per bloom level — draw from it per topic.
        /** @var array<string, int> $bloomBudget */
        $bloomBudget = array_combine($bloomLevels, $globalBloomCounts);

        $allocationRows = [];
        foreach ($topics as $index => $topic) {
            $topicTotalItems = $topicItemCounts[$index];
            if ($topicTotalItems === 0) {
                continue;
            }

            $totalBudget = array_sum($bloomBudget);
            if ($totalBudget <= 0) {
                break;
            }

            // Distribute this topic's items proportionally from the remaining budget.
            $weights = array_map(
                static fn (int $b): float => $b / $totalBudget,
                array_values($bloomBudget),
            );
            $topicBloomCounts = $this->allocateByLargestRemainder($weights, $topicTotalItems);

            foreach ($bloomLevels as $bi => $bloomLevel) {
                $itemCount = $topicBloomCounts[$bi];
                if ($itemCount === 0) {
                    continue;
                }

                $bloomBudget[$bloomLevel] -= $itemCount;

                $allocationRows[] = [
                    'syllabus_topic_id' => $topic['syllabus_topic_id'] ?? null,
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

        return DB::transaction(function () use ($project, $totalItems, $topics, $bloomDistribution, $allocationRows, $term, $questionTypeDistribution): TosRun {
            $run = $project->tosRuns()->create([
                'total_items' => $totalItems,
                'topics' => $topics,
                'bloom_distribution' => $bloomDistribution,
                'term' => $term,
                'question_type_distribution' => $questionTypeDistribution,
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

    private function assertRequiredDocumentsUploaded(Project $project): void
    {
        $syllabusCount = Document::query()
            ->where('project_id', $project->id)
            ->where('kind', DocumentKind::Syllabus)
            ->count();

        if ($syllabusCount !== 1) {
            throw ValidationException::withMessages([
                'syllabus' => ['Exactly one syllabus document is required before generating TOS.'],
            ]);
        }

        $materialCount = Document::query()
            ->where('project_id', $project->id)
            ->where('kind', DocumentKind::Material)
            ->count();

        if ($materialCount < 1) {
            throw ValidationException::withMessages([
                'materials' => ['Upload at least one learning material before generating TOS.'],
            ]);
        }
    }
}
