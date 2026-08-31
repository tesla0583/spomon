<?php

declare(strict_types=1);

namespace App\DTOs;

final class SpoStatisticsSummaryDto
{
    /**
     * @param  array<string, int>  $countsByRiskLabel  количество СПО по риск-меткам, ключ —
     *                                                 App\Enums\RiskLabel::value; все 4 значения
     *                                                 всегда присутствуют (0, если нет данных)
     */
    public function __construct(
        public readonly int $totalCount,
        public readonly array $countsByRiskLabel,
    ) {}
}
