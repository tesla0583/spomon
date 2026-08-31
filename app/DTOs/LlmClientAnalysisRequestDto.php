<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Вход для {@see \App\Services\Llm\ClaudeApiClient::analyzeClient()} — вся хронологическая
 * история СПО одного клиента плюс реестр уже известных сетевых сущностей.
 *
 * См. CLAUDE.md, раздел "Логика вызова Claude API": единица вызова — один клиент, не пачка
 * клиентов и не один XML-файл. {@see self::$knownNetworkEntities} заполняется
 * App\Repositories\EntityRepository::findKnownNetworkEntityReferences() — обычным SQL,
 * не LLM (агент лишь помечает совпадение с уже известным реестром).
 */
final class LlmClientAnalysisRequestDto
{
    /**
     * @param  array<int, array{date: ?string, amount: ?float, currency: ?string, description: ?string, counterparty_structured: array<string, string|null>|null}>  $spoHistory
     * @param  array<int, string>  $knownNetworkEntities
     */
    public function __construct(
        public readonly int $clientId,
        public readonly array $spoHistory,
        public readonly array $knownNetworkEntities,
    ) {}
}
