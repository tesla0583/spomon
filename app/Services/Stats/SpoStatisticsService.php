<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\SpoStatisticsSummaryDto;
use App\Enums\RiskLevel;
use App\Models\Client;
use App\Models\SpoRaw;
use App\Services\Risk\ClientRiskLevelService;
use Carbon\CarbonInterface;

/**
 * Автоматическая статистика по СПО за период (замена ручного подсчёта
 * руководству, см. CLAUDE.md, "Суть проекта").
 */
final class SpoStatisticsService
{
    public function __construct(
        private readonly ClientRiskLevelService $riskLevelService,
    ) {}

    /**
     * `RiskLevel` не хранится в БД — считается на лету по каждому уникальному клиенту,
     * встретившемуся в периоде (не по каждой строке `spo_raw`), а затем количество его
     * СПО в периоде целиком относится к соответствующему уровню. `RiskLevel`
     * вычислим всегда (не зависит от наличия `ClientCard`), поэтому в отличие от
     * прежней группировки по LLM-метке здесь сумма по уровням ВСЕГДА равна totalCount
     * — расхождений "СПО есть, метки нет" больше не бывает.
     */
    public function summarize(CarbonInterface $from, CarbonInterface $to): SpoStatisticsSummaryDto
    {
        $from = $from->clone()->startOfDay();
        $to = $to->clone()->endOfDay();

        $spoCountsByClient = SpoRaw::query()
            ->whereBetween('transaction_date', [$from, $to])
            ->selectRaw('client_id, count(*) as cnt')
            ->groupBy('client_id')
            ->pluck('cnt', 'client_id');

        $totalCount = (int) $spoCountsByClient->sum();

        $countsByRiskLevel = [];
        foreach (RiskLevel::cases() as $case) {
            $countsByRiskLevel[$case->value] = 0;
        }

        $clients = Client::query()->whereIn('id', $spoCountsByClient->keys())->get();

        foreach ($clients as $client) {
            $level = $this->riskLevelService->calculate($client);
            $countsByRiskLevel[$level->value] += (int) $spoCountsByClient[$client->id];
        }

        return new SpoStatisticsSummaryDto($totalCount, $countsByRiskLevel);
    }
}
