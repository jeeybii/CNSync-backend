<?php

use App\Services\AiTextGenerator;
use App\Services\GeminiQuestionGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('it generates structured json through ollama', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');
    config()->set('services.ollama.model', 'gemma3:4b');

    Http::fake([
        'http://100.73.128.62:11434/api/generate' => Http::response([
            'model' => 'gemma3:4b',
            'response' => '{"question":"What does a router use?","answer":"A routing table"}',
            'done' => true,
        ]),
    ]);

    $result = app(AiTextGenerator::class)->generateJson('Generate one question.', 0.1);

    expect($result)
        ->toBe([
            'question' => 'What does a router use?',
            'answer' => 'A routing table',
        ]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'http://100.73.128.62:11434/api/generate'
            && $request['model'] === 'gemma3:4b'
            && $request['stream'] === false
            && $request['think'] === false
            && $request['format'] === 'json'
            && $request['options']['num_ctx'] === 4096;
    });
});

test('it prefers the primary ollama server and uses the secondary while primary is busy', function () {
    $primary = 'http://100.97.16.32:11434';
    $secondary = 'http://100.73.128.62:11434';
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', $primary);
    config()->set('services.ollama.base_urls', [$primary, $secondary]);
    config()->set('services.ollama.model', 'qwen3:8b');

    Http::fake([
        "{$secondary}/api/generate" => Http::response([
            'response' => '{"server":"secondary"}',
            'done' => true,
        ]),
    ]);

    $primaryLock = Cache::lock('ollama-server:'.hash('sha256', $primary), 60);
    expect($primaryLock->get())->toBeTrue();

    try {
        $result = app(AiTextGenerator::class)->generateJson('Select a server.');
    } finally {
        $primaryLock->release();
    }

    expect($result)->toBe(['server' => 'secondary']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === "{$secondary}/api/generate");
});

test('it supports an ollama json schema', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');

    Http::fake([
        '*' => Http::response([
            'response' => '{"topics":[]}',
            'done' => true,
        ]),
    ]);

    $schema = [
        'type' => 'OBJECT',
        'properties' => [
            'topics' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'objective' => ['type' => 'STRING', 'nullable' => true],
                    ],
                ],
            ],
        ],
        'required' => ['topics'],
    ];

    app(AiTextGenerator::class)->generateJson('Extract topics.', 0.1, $schema);

    Http::assertSent(function ($request): bool {
        $format = $request['format'];

        return $format['type'] === 'object'
            && data_get($format, 'properties.topics.type') === 'array'
            && data_get($format, 'properties.topics.items.type') === 'object'
            && data_get($format, 'properties.topics.items.properties.objective.type') === ['string', 'null']
            && data_get($format, 'properties.topics.items.properties.objective.nullable') === null;
    });
});

test('it supports an ollama model override for specialized tasks', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');
    config()->set('services.ollama.model', 'qwen3:8b');

    Http::fake([
        '*' => Http::response([
            'response' => '{"topics":[]}',
            'done' => true,
        ]),
    ]);

    app(AiTextGenerator::class)->generateJson('Extract topics.', 0.1, null, 'gemma3:4b');

    Http::assertSent(fn ($request): bool => $request['model'] === 'gemma3:4b');
});

test('it supports overriding ollama with gemini for a specialized task', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.gemini.api_key', 'specialized-key');
    config()->set('services.gemini.model', 'gemini-2.5-flash');
    config()->set('services.gemini.fallback_model', 'gemini-2.5-flash-lite');
    config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com');

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => '{"answer":"Gemini"}']]],
            ]],
        ]),
    ]);

    $result = app(AiTextGenerator::class)->generateJson('Answer as JSON.', 0.1, null, null, 'gemini');

    expect($result)->toBe(['answer' => 'Gemini']);
    Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'specialized-key'));
});

test('it routes essay generation to gemini while the default provider is ollama', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.gemini.api_key', 'specialized-key');
    config()->set('services.gemini.model', 'gemini-2.5-flash');
    config()->set('services.gemini.fallback_model', 'gemini-2.5-flash-lite');
    config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com');

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'question_text' => 'Explain how subnetting improves address management in an organization.',
                        'options' => [],
                        'answer_key' => "1. Network division: Subnetting separates a large address space into smaller networks.\n2. Address conservation: It supports efficient allocation.\n3. Administration: Smaller broadcast domains are easier to manage.",
                    ], JSON_THROW_ON_ERROR),
                ]]],
            ]],
        ]),
    ]);

    $question = app(GeminiQuestionGenerator::class)->generateOne(
        'IP Addressing',
        'analyzing',
        'essay',
        [['content' => 'Subnetting divides address space into manageable networks.', 'page_number' => 1, 'document_id' => 1]],
    );

    expect($question['question_text'])->toContain('subnetting')
        ->and($question['answer_key'])->not->toContain('1.')
        ->and($question['answer_key'])->not->toContain("\n")
        ->and($question['answer_key'])->toContain('Network division:')
        ->and($question['answer_key'])->toContain('Administration:');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'generativelanguage.googleapis.com')
        && data_get($request->data(), 'generationConfig.responseSchema.properties.options.items.type') === 'STRING'
        && data_get($request->data(), 'generationConfig.responseSchema.properties.options.maxItems') === 0);
});

test('it normalizes harmless ollama true false formatting differences', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');

    Http::fake([
        '*' => Http::response([
            'response' => json_encode([
                'question_text' => 'A router forwards packets between networks.',
                'question_type' => 'true_false',
                'options' => ['false', 'true'],
                'answer_key' => true,
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ]),
    ]);

    $question = app(GeminiQuestionGenerator::class)->generateOne(
        'Routers',
        'understanding',
        'true_false',
        [['content' => 'Routers forward packets.', 'page_number' => 1, 'document_id' => 1]],
    );

    expect($question['options'])->toBe(['True', 'False'])
        ->and($question['answer_key'])->toBe('True');

    Http::assertSent(fn ($request): bool => data_get($request->data(), 'format.properties.answer_key.enum') === ['True', 'False']);
});

test('it resolves a true false letter answer through its referenced option', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');

    Http::fake([
        '*' => Http::response([
            'response' => json_encode([
                'question_text' => 'A router forwards packets between networks.',
                'options' => ['Incorrect', 'Not applicable', 'True', 'False'],
                'answer_key' => 'C',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ]),
    ]);

    $question = app(GeminiQuestionGenerator::class)->generateOne(
        'Routers',
        'understanding',
        'true_false',
        [['content' => 'Routers forward packets.', 'page_number' => 1, 'document_id' => 1]],
    );

    expect($question['options'])->toBe(['True', 'False'])
        ->and($question['answer_key'])->toBe('True');
});

test('it regenerates an invalid true false question', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');

    Http::fakeSequence()
        ->push([
            'response' => json_encode([
                'question_text' => 'Which device forwards packets between networks?',
                'options' => ['True', 'False'],
                'answer_key' => 'True',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ])
        ->push([
            'response' => json_encode([
                'question_text' => 'A router forwards packets between different networks.',
                'options' => ['True', 'False'],
                'answer_key' => 'True',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ]);

    $question = app(GeminiQuestionGenerator::class)->generateOne(
        'Routers',
        'understanding',
        'true_false',
        [['content' => 'Routers forward packets.', 'page_number' => 1, 'document_id' => 1]],
    );

    expect($question['question_text'])->toBe('A router forwards packets between different networks.');
    Http::assertSentCount(2);
});

test('it regenerates a question that duplicates an accepted exam item', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');

    Http::fakeSequence()
        ->push([
            'response' => json_encode([
                'question_text' => 'Which device forwards packets between different networks?',
                'options' => ['A. Router', 'B. Switch', 'C. Hub', 'D. Repeater'],
                'answer_key' => 'A',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ])
        ->push([
            'response' => json_encode([
                'question_text' => 'Which routing-table field identifies where a packet should be sent next?',
                'options' => ['A. Next hop', 'B. Frame check', 'C. Port speed', 'D. MAC vendor'],
                'answer_key' => 'A',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ]);

    $question = app(GeminiQuestionGenerator::class)->generateOne(
        'Routing',
        'understanding',
        'multiple_choice',
        [['content' => 'Routers use routing tables and next hops.', 'page_number' => 1, 'document_id' => 1]],
        ['Which device forwards packets between different networks?'],
    );

    expect($question['question_text'])->toContain('routing-table')
        ->and($question['options'][0])->toBe('Next hop');
    Http::assertSentCount(2);
});

test('it detects paraphrased questions that test the same switch concept', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');

    Http::fakeSequence()
        ->push([
            'response' => json_encode([
                'question_text' => 'Which of the following best describes the role of a network switch in managing data transmission within a local area network?',
                'options' => ['Routes between networks', 'Forwards frames using physical addresses', 'Assigns IP addresses', 'Encrypts all traffic'],
                'answer_key' => 'B',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ])
        ->push([
            'response' => json_encode([
                'question_text' => 'What information does a switch learn to build its forwarding table?',
                'options' => ['Source MAC addresses', 'DNS records', 'IP lease times', 'User passwords'],
                'answer_key' => 'A',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ]);

    $question = app(GeminiQuestionGenerator::class)->generateOne(
        'Network Hardware Essentials',
        'understanding',
        'multiple_choice',
        [['content' => 'Switches learn source MAC addresses.', 'page_number' => 1, 'document_id' => 1]],
        ['Which of the following best describes the primary function of a network switch in a local area network?'],
    );

    expect($question['question_text'])->toContain('forwarding table');
    Http::assertSentCount(2);
});

test('it rejects matching questions that reuse the same column a concepts', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.gemini.api_key', 'specialized-key');
    config()->set('services.gemini.model', 'gemini-2.5-flash');
    config()->set('services.gemini.fallback_model', 'gemini-2.5-flash-lite');
    config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com');

    Http::fake([
        '*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'question_text' => 'Connect each administrative task to its corresponding explanation.',
                        'options' => [
                            'column_a' => ['Managing User and Group Accounts', 'Backup and Fault Tolerance', 'Monitoring System Reliability and Performance', 'Storage and File System Management'],
                            'column_b' => ['Manages identities', 'Maintains backups', 'Tracks metrics', 'Organizes storage'],
                        ],
                        'answer_key' => '1-A,2-B,3-C,4-D',
                    ], JSON_THROW_ON_ERROR),
                ]]],
            ]],
        ]),
    ]);

    expect(fn () => app(GeminiQuestionGenerator::class)->generateOne(
        'Network Management',
        'understanding',
        'matching_type',
        [['content' => 'Network administrators manage users, backups, monitoring, and storage.', 'page_number' => 1, 'document_id' => 1]],
        [[
            'question_text' => 'Match the network management task with the appropriate description.',
            'question_type' => 'matching_type',
            'options' => [
                'column_a' => ['Managing User and Group Accounts', 'Backup and Fault Tolerance', 'Monitoring System Reliability and Performance', 'Storage and File System Management'],
                'column_b' => ['Controls permissions', 'Provides recovery', 'Monitors performance', 'Maintains files'],
            ],
        ]],
    ))->toThrow(RuntimeException::class, 'The question duplicates an existing exam item.');

    Http::assertSentCount(3);
});

test('it keeps the least similar valid candidate when ollama cannot avoid duplication', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');

    Http::fake([
        '*' => Http::response([
            'response' => json_encode([
                'question_text' => 'Which device forwards data packets between different networks?',
                'options' => ['Router', 'Switch', 'Hub', 'Repeater'],
                'answer_key' => 'A',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ]),
    ]);

    $question = app(GeminiQuestionGenerator::class)->generateOne(
        'Routing',
        'remembering',
        'multiple_choice',
        [['content' => 'Routers forward packets.', 'page_number' => 1, 'document_id' => 1]],
        ['Which device forwards packets between different networks?'],
    );

    expect($question['answer_key'])->toBe('A');
    Http::assertSentCount(5);
});

test('it still rejects an exact duplicate after ollama exhausts its retries', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');

    Http::fake([
        '*' => Http::response([
            'response' => json_encode([
                'question_text' => 'Which device forwards packets between different networks?',
                'options' => ['Router', 'Switch', 'Hub', 'Repeater'],
                'answer_key' => 'A',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ]),
    ]);

    expect(fn () => app(GeminiQuestionGenerator::class)->generateOne(
        'Routing',
        'remembering',
        'multiple_choice',
        [['content' => 'Routers forward packets.', 'page_number' => 1, 'document_id' => 1]],
        ['Which device forwards packets between different networks?'],
    ))->toThrow(RuntimeException::class, 'The question duplicates an existing exam item.');

    Http::assertSentCount(5);
});

test('matching generation preserves complete ipv4 addresses', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.gemini.api_key', 'specialized-key');
    config()->set('services.gemini.model', 'gemini-2.5-flash');
    config()->set('services.gemini.fallback_model', 'gemini-2.5-flash-lite');
    config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com');

    Http::fake([
        '*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'question_text' => 'Match each IPv4 address with its classification.',
                        'options' => [
                            'column_a' => ['192.168.1.10', '203.0.113.45'],
                            'column_b' => ['Private address', 'Documentation address'],
                        ],
                        'answer_key' => '1-A,2-B',
                    ], JSON_THROW_ON_ERROR),
                ]]],
            ]],
        ]),
    ]);

    $question = app(GeminiQuestionGenerator::class)->generateOne(
        'IP Addressing',
        'understanding',
        'matching_type',
        [['content' => 'IPv4 private and documentation ranges.', 'page_number' => 1, 'document_id' => 1]],
    );

    expect($question['options']['column_a'])->toBe(['192.168.1.10', '203.0.113.45']);
});
