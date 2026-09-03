<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\DTOs\LlmClientAnalysisRequestDto;
use App\DTOs\LlmClientAnalysisResponseDto;
use App\Exceptions\ClaudeApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Тонкий клиент к `POST https://api.anthropic.com/v1/messages` через Laravel HTTP-клиент
 * (без стороннего Anthropic SDK — см. CLAUDE.md).
 *
 * Единица вызова — один клиент со всей его историей СПО, а не пачка клиентов и не один
 * XML-файл (см. CLAUDE.md, раздел "Логика вызова Claude API"). Модель принуждается вызвать
 * инструмент `submit_client_analysis` (`tool_choice`), чтобы результат был structured JSON,
 * а не свободный текст.
 */
final class ClaudeApiClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const TOOL_NAME = 'submit_client_analysis';

    public function analyzeClient(LlmClientAnalysisRequestDto $request): LlmClientAnalysisResponseDto
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.api_key'),
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->post(self::API_URL, [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 1024,
            'system' => [
                [
                    'type' => 'text',
                    'text' => ClientAnalysisPrompts::SYSTEM,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'client_id' => $request->clientId,
                        'spo_list' => $request->spoHistory,
                        'known_network_entities' => $request->knownNetworkEntities,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ],
            ],
            'tools' => [$this->toolDefinition()],
            'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
        ]);

        if (! $response->successful()) {
            throw ClaudeApiException::httpError($response->status(), $response->body());
        }

        return $this->toResponseDto($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolDefinition(): array
    {
        return [
            'name' => self::TOOL_NAME,
            'description' => 'Передать структурированный результат анализа истории СПО клиента.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'pattern_notes' => ['type' => ['string', 'null']],
                    'extracted_entities' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'spo_date' => ['type' => 'string'],
                                'entities' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['spo_date', 'entities'],
                        ],
                    ],
                    'network_signal' => [
                        'type' => 'object',
                        'properties' => [
                            'found' => ['type' => 'boolean'],
                            'matched_client_reference' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['found', 'matched_client_reference'],
                    ],
                ],
                'required' => ['summary', 'pattern_notes', 'extracted_entities', 'network_signal'],
            ],
        ];
    }

    private function toResponseDto(Response $response): LlmClientAnalysisResponseDto
    {
        $rawResponse = $response->json();

        if (! is_array($rawResponse)) {
            throw ClaudeApiException::unexpectedResponseFormat('тело ответа не является JSON-объектом.');
        }

        $toolUseBlock = null;

        foreach ($rawResponse['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::TOOL_NAME) {
                $toolUseBlock = $block;

                break;
            }
        }

        if ($toolUseBlock === null) {
            throw ClaudeApiException::unexpectedResponseFormat(
                "в ответе не найден tool_use блок для инструмента '".self::TOOL_NAME."'.",
            );
        }

        $input = $toolUseBlock['input'] ?? null;

        if (! is_array($input)) {
            throw ClaudeApiException::unexpectedResponseFormat('tool_use блок не содержит поле input.');
        }

        $input = $this->recoverFieldsLeakedIntoSummary($input);

        return new LlmClientAnalysisResponseDto(
            summary: (string) ($input['summary'] ?? ''),
            patternNotes: $input['pattern_notes'] ?? null,
            extractedEntities: $input['extracted_entities'] ?? [],
            networkSignalFound: (bool) ($input['network_signal']['found'] ?? false),
            networkSignalClientReference: $input['network_signal']['matched_client_reference'] ?? null,
            rawResponse: $rawResponse,
        );
    }

    /**
     * Известный сбой формата у Claude API: при длинных строковых полях модель иногда
     * "утекает" legacy XML-синтаксис вызова инструментов прямо внутрь строкового поля
     * (у нас — summary) вместо того, чтобы вернуть pattern_notes/extracted_entities/
     * network_signal отдельными полями JSON tool-input'а. Воспроизведено вживую
     * 27.08.2026 на claude-sonnet-5 (см. также известную проблему модели, а не нашего
     * парсинга: https://github.com/anthropics/claude-code/issues/49747). Поломанный
     * summary в этом случае заканчивается на `</summary>`, дальше идут
     * `<parameter name="...">значение</parameter>` блоки; последний блок часто не
     * закрыт — ответ модели просто обрывается на конце строки. Восстанавливаем то, что
     * можем распарсить, вместо того чтобы молча терять pattern_notes/network_signal —
     * для AML-анализа это не косметика, а потеря реального сигнала.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function recoverFieldsLeakedIntoSummary(array $input): array
    {
        $summary = $input['summary'] ?? null;

        if (! is_string($summary) || ! str_contains($summary, '<parameter name=')) {
            return $input;
        }

        Log::warning('ClaudeApiClient: обнаружен известный сбой формата ответа '.
            '(XML-теги внутри summary) — восстанавливаем поля из текста.', [
                'summary' => $summary,
            ]);

        [$cleanSummary, $tail] = array_pad(explode('</summary>', $summary, 2), 2, '');

        if (! preg_match_all('/<parameter name="(\w+)">(.*?)(?:<\/parameter>|$)/s', $tail, $matches, PREG_SET_ORDER)) {
            return $input;
        }

        $recovered = ['summary' => trim($cleanSummary)];

        foreach ($matches as [, $key, $value]) {
            $value = trim($value);

            $recovered[$key] = match ($key) {
                'extracted_entities', 'network_signal' => json_decode($value, true) ?? ($input[$key] ?? null),
                default => $value,
            };
        }

        return array_merge($input, $recovered);
    }
}
