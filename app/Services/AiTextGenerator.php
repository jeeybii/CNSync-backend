<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AiTextGenerator
{
    /**
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    public function generateJson(
        string $prompt,
        float $temperature = 0.2,
        ?array $schema = null,
        ?string $ollamaModel = null,
        ?string $providerOverride = null,
    ): array {
        $provider = strtolower($providerOverride ?? (string) config('services.ai.provider', 'gemini'));

        $jsonText = match ($provider) {
            'ollama' => $this->generateWithOllama($prompt, $temperature, $schema, $ollamaModel),
            'gemini' => $this->generateWithGemini($prompt, $temperature, $schema),
            default => throw new RuntimeException("Unsupported AI provider: {$provider}."),
        };

        $decoded = $this->decodeJson($jsonText);
        if (! is_array($decoded)) {
            throw new RuntimeException(ucfirst($provider).' returned invalid JSON.');
        }

        return $decoded;
    }

    /** @param array<string, mixed>|null $schema */
    private function generateWithOllama(
        string $prompt,
        float $temperature,
        ?array $schema,
        ?string $modelOverride = null,
    ): string {
        $baseUrls = $this->ollamaBaseUrls();
        $model = filled($modelOverride)
            ? $modelOverride
            : (string) config('services.ollama.model', 'gemma3:4b');

        if ($baseUrls === [] || blank($model)) {
            throw new RuntimeException('Ollama URL or model is missing. Check OLLAMA_BASE_URL and OLLAMA_MODEL.');
        }

        $options = [
            'temperature' => $temperature,
            'num_ctx' => max(2048, (int) config('services.ollama.context_length', 4096)),
        ];

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'think' => false,
            'format' => $schema === null ? 'json' : $this->normalizeOllamaSchema($schema),
            'options' => $options,
            'keep_alive' => (string) config('services.ollama.keep_alive', '30m'),
        ];

        $response = $this->sendThroughOllamaPool($baseUrls, $payload);

        $text = $response->json('response');
        if (! is_string($text) || blank($text)) {
            throw new RuntimeException('Ollama returned an empty response.');
        }

        return $text;
    }

    /**
     * @param  array<int, string>  $baseUrls
     * @param  array<string, mixed>  $payload
     */
    private function sendThroughOllamaPool(array $baseUrls, array $payload): Response
    {
        $startedAt = hrtime(true);
        $waitTimeout = max(0, (int) config('services.ollama.pool_wait_timeout', 1800));
        $pollMicroseconds = max(50, (int) config('services.ollama.pool_poll_ms', 250)) * 1000;
        $requestTimeout = (int) config('services.ollama.timeout', 900);
        $retries = max(1, (int) config('services.ollama.retries', 2));
        $failedServers = [];
        $lastException = null;

        while (true) {
            foreach ($baseUrls as $priority => $baseUrl) {
                if (isset($failedServers[$baseUrl])) {
                    continue;
                }

                $lock = Cache::lock(
                    'ollama-server:'.hash('sha256', $baseUrl),
                    max(60, ($requestTimeout * $retries) + 60),
                );

                if (! $lock->get()) {
                    continue;
                }

                try {
                    Log::info('Ollama server selected.', [
                        'base_url' => $baseUrl,
                        'priority' => $priority + 1,
                        'model' => $payload['model'] ?? null,
                    ]);

                    return $this->sendWithRetries(
                        fn (): Response => Http::timeout($requestTimeout)
                            ->connectTimeout((int) config('services.ollama.connect_timeout', 10))
                            ->post("{$baseUrl}/api/generate", $payload),
                        $retries,
                        (int) config('services.ollama.retry_sleep_ms', 1000),
                    );
                } catch (RequestException $exception) {
                    if (! in_array($exception->response?->status(), [429, 500, 502, 503, 504], true)) {
                        throw $exception;
                    }

                    $failedServers[$baseUrl] = true;
                    $lastException = $exception;
                } catch (Throwable $exception) {
                    $failedServers[$baseUrl] = true;
                    $lastException = $exception;
                } finally {
                    $lock->release();
                }
            }

            if (count($failedServers) === count($baseUrls)) {
                throw $lastException ?? new RuntimeException('All Ollama servers are unavailable.');
            }

            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            if ($waitTimeout > 0 && $elapsedSeconds >= $waitTimeout) {
                throw new RuntimeException('Timed out waiting for an available Ollama server.');
            }

            usleep($pollMicroseconds);
        }
    }

    /** @return array<int, string> */
    private function ollamaBaseUrls(): array
    {
        $legacyBaseUrl = rtrim(trim((string) config('services.ollama.base_url')), '/');
        $configured = config('services.ollama.base_urls', []);
        if (! is_array($configured)) {
            $configured = [];
        }

        $urls = array_map(
            fn (mixed $url): string => rtrim(trim((string) $url), '/'),
            $configured,
        );

        $urls = array_values(array_unique(array_filter($urls)));

        // Preserve runtime/test overrides that only replace the original
        // single-server setting.
        if (filled($legacyBaseUrl) && ($urls === [] || $urls[0] !== $legacyBaseUrl)) {
            return [$legacyBaseUrl];
        }

        return $urls;
    }

    /** @param array<string, mixed>|null $schema */
    private function generateWithGemini(string $prompt, float $temperature, ?array $schema): string
    {
        $apiKey = (string) config('services.gemini.api_key');
        if (blank($apiKey)) {
            throw new RuntimeException('Gemini API key is missing. Set GEMINI_API_KEY in .env.');
        }

        $models = array_values(array_unique(array_filter([
            (string) config('services.gemini.model'),
            (string) config('services.gemini.fallback_model'),
        ])));
        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $lastResponse = null;

        foreach ($models as $model) {
            $generationConfig = [
                'temperature' => $temperature,
                'responseMimeType' => 'application/json',
            ];
            if ($schema !== null) {
                $generationConfig['responseSchema'] = $schema;
                $generationConfig['maxOutputTokens'] = 65536;
            }

            $lastResponse = $this->sendWithRetries(
                fn (): Response => Http::withHeaders(['x-goog-api-key' => $apiKey])
                    ->timeout((int) config('services.gemini.timeout', 900))
                    ->connectTimeout(15)
                    ->post(sprintf('%s/v1beta/models/%s:generateContent', $baseUrl, $model), [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => $generationConfig,
                    ]),
                (int) config('services.gemini.retries', 2),
                (int) config('services.gemini.retry_sleep_ms', 400),
                false,
            );

            if ($lastResponse->successful()) {
                $text = data_get($lastResponse->json(), 'candidates.0.content.parts.0.text');
                if (is_string($text) && filled($text)) {
                    return $text;
                }
            }
        }

        $lastResponse?->throw();
        throw new RuntimeException('Gemini returned an empty response.');
    }

    /**
     * @param  callable(): Response  $request
     */
    private function sendWithRetries(callable $request, int $retries, int $sleepMs, bool $throw = true): Response
    {
        $attempts = max(1, $retries);
        $lastResponse = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $lastResponse = $request();
            if ($lastResponse->successful()) {
                return $lastResponse;
            }

            if (! in_array($lastResponse->status(), [429, 500, 502, 503, 504], true)) {
                break;
            }

            if ($attempt < $attempts) {
                usleep(max(200, $sleepMs) * 1000 * $attempt);
            }
        }

        if ($throw) {
            $lastResponse?->throw();
        }

        return $lastResponse ?? throw new RuntimeException('AI request failed without a response.');
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(string $jsonText): ?array
    {
        $jsonText = trim($jsonText);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $jsonText, $matches) === 1) {
            $jsonText = trim($matches[1]);
        }

        $decoded = json_decode($jsonText, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Gemini accepts uppercase schema types and a nullable flag. Ollama expects
     * standard JSON Schema types and represents nullability in the type itself.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function normalizeOllamaSchema(array $schema): array
    {
        $normalized = [];

        foreach ($schema as $key => $value) {
            if ($key === 'nullable') {
                continue;
            }

            if ($key === 'type' && is_string($value)) {
                $type = strtolower($value);
                $normalized[$key] = ($schema['nullable'] ?? false) === true
                    ? [$type, 'null']
                    : $type;

                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = array_is_list($value)
                    ? array_map(
                        fn (mixed $item): mixed => is_array($item) ? $this->normalizeOllamaSchema($item) : $item,
                        $value,
                    )
                    : $this->normalizeOllamaSchema($value);

                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
