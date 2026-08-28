<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\RiskLabel;

/**
 * Разобранный результат вызова `submit_client_analysis` из ответа Claude API.
 *
 * {@see self::$rawResponse} хранит полный decoded JSON ответа API (не только tool_use блок) —
 * для аудита, см. CLAUDE.md, раздел "Логика вызова Claude API": "Хранить сырые ответы LLM
 * (для аудита — почему агент решил, что это паттерн)".
 */
final class LlmClientAnalysisResponseDto
{
    /**
     * @param  array<int, array{spo_date: string, entities: array<int, string>}>  $extractedEntities
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $summary,
        public readonly ?string $patternNotes,
        public readonly array $extractedEntities,
        public readonly bool $networkSignalFound,
        public readonly ?string $networkSignalClientReference,
        public readonly RiskLabel $finalLabel,
        public readonly array $rawResponse,
    ) {}
}
