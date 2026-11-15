<?php

test('every production AI workflow uses Gemini', function () {
    expect(config('services.ai.provider'))->toBe('gemini')
        ->and(config('services.ai.syllabus_provider'))->toBe('gemini')
        ->and(config('services.ai.student_provider'))->toBe('gemini');
});
