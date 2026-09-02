<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Сводка одного прогона {@see \App\Services\Cards\ClientCardRecomputeService::recomputeAll()}.
 */
final class RecomputeSummaryDto
{
    /**
     * @param  array<int, string>  $failures  ID клиента => текст ошибки
     */
    public function __construct(
        public readonly int $dispatchedCount,
        public readonly array $failures,
    ) {}
}
