<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\SpoStatisticsSummaryDto;
use App\Enums\RiskLabel;
use App\Models\SpoRaw;
use Carbon\CarbonInterface;

/**
 * Автоматическая статистика по СПО за период (замена ручного подсчёта
 * руководству, см. CLAUDE.md, "Суть проекта").
 */
final class SpoStatisticsService
{
    public function summarize(CarbonInterface $from, CarbonInterface $to): SpoStatisticsSummaryDto
    {
        $from = $from->clone()->startOfDay();
        $to = $to->clone()->endOfDay();

        $totalCount = SpoRaw::query()
            ->whereBetween('transaction_date', [$from, $to])
            ->count();

        $countsByLabel = SpoRaw::query()
            ->join('clients', 'clients.id', '=', 'spo_raw.client_id')
            ->join('client_cards', 'client_cards.client_id', '=', 'clients.id')
            ->whereBetween('spo_raw.transaction_date', [$from, $to])
            ->whereNotNull('client_cards.risk_label')
            ->selectRaw('client_cards.risk_label as risk_label, count(*) as cnt')
            ->groupBy('client_cards.risk_label')
            ->pluck('cnt', 'risk_label');

        $countsByRiskLabel = [];
        foreach (RiskLabel::cases() as $case) {
            $countsByRiskLabel[$case->value] = (int) ($countsByLabel[$case->value] ?? 0);
        }

        return new SpoStatisticsSummaryDto($totalCount, $countsByRiskLabel);
    }
}
