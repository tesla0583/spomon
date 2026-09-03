<?php

declare(strict_types=1);

namespace App\DTOs;

final class SpoStatisticsSummaryDto
{
    /**
     * @param  array<string, int>  $countsByRiskLevel  количество СПО по уровням риска, ключ —
     *                                                 App\Enums\RiskLevel::value; все 3 значения
     *                                                 всегда присутствуют (0, если нет данных)
     */
    public function __construct(
        public readonly int $totalCount,
        public readonly array $countsByRiskLevel,
    ) {}
}
