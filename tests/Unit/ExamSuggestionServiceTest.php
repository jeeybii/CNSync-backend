<?php

use App\Services\ExamSuggestionService;

test('term bloom suggestions preserve every inferred topic level and fill to at least three', function () {
    $service = new ExamSuggestionService;

    expect($service->bloomLevels([
        ['bloom_level' => 'understanding'],
        ['bloom_level' => 'applying'],
    ], ['analyzing']))->toBe(['understanding', 'applying', 'analyzing']);

    expect($service->bloomLevels([
        ['bloom_level' => 'remembering'],
        ['bloom_level' => 'understanding'],
        ['bloom_level' => 'applying'],
        ['bloom_level' => 'analyzing'],
    ]))->toBe(['remembering', 'understanding', 'applying', 'analyzing']);

    expect($service->bloomLevels([['bloom_level' => 'analyzing']]))
        ->toBe(['analyzing', 'applying', 'evaluating']);
});

test('question type suggestions normalize percentages and allocate exact item counts', function () {
    $service = new ExamSuggestionService;
    $percentages = $service->questionTypePercentages([
        'multiple_choice' => 50,
        'true_false' => 20,
        'essay' => 30,
    ], [['bloom_level' => 'analyzing', 'hours' => 2]]);

    expect($percentages)->toBe([
        'multiple_choice' => 50,
        'true_false' => 20,
        'identification' => 0,
        'essay' => 30,
    ]);
    expect($service->counts($percentages, 7))->toBe([
        'multiple_choice' => 4,
        'true_false' => 1,
        'identification' => 0,
        'essay' => 2,
    ]);
    expect(array_sum($service->counts($percentages, 1)))->toBe(1);
});
