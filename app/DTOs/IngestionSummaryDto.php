<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Сводка одного прогона {@see \App\Services\Ingestion\SpoFileIngestionService::ingestFromDirectory()}.
 */
final class IngestionSummaryDto
{
    /**
     * @param  array<string, string>  $failures  имя файла => текст ошибки (XML не разобран/не сохранён)
     * @param  array<int, string>  $cardFailures  ID клиента => текст ошибки (XML сохранён, но
     *                                            пересчёт карточки через Claude API не удался —
     *                                            см. SpoFileIngestionService)
     */
    public function __construct(
        public readonly int $processedCount,
        public readonly int $skippedCount,
        public readonly int $failedCount,
        public readonly array $failures,
        public readonly array $cardFailures = [],
    ) {}
}
