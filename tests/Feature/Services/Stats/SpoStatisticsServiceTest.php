<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Stats;

use App\Enums\PartyType;
use App\Enums\RiskLevel;
use App\Models\Client;
use App\Models\SpoRaw;
use App\Repositories\EntityRepository;
use App\Services\Risk\ClientRiskLevelService;
use App\Services\Stats\SpoStatisticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SpoStatisticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SpoStatisticsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SpoStatisticsService(new ClientRiskLevelService(new EntityRepository));
    }

    public function test_summarizes_spo_counts_by_risk_level_within_period(): void
    {
        // 1 СПО, 0 связей — RiskLevel::Low.
        $low = $this->createClient('T0000001', 'Клиент Низкий');
        $this->createSpoRaw($low, '2026-02-10');

        // 4 СПО — RiskLevel::High.
        $high = $this->createClient('T0000002', 'Клиент Высокий');
        foreach (['2026-02-01', '2026-02-05', '2026-02-10', '2026-02-15'] as $date) {
            $this->createSpoRaw($high, $date);
        }

        $summary = $this->service->summarize(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));

        self::assertSame(5, $summary->totalCount);
        self::assertSame(1, $summary->countsByRiskLevel[RiskLevel::Low->value]);
        self::assertSame(0, $summary->countsByRiskLevel[RiskLevel::Medium->value]);
        self::assertSame(4, $summary->countsByRiskLevel[RiskLevel::High->value]);
    }

    public function test_excludes_spo_outside_period(): void
    {
        $client = $this->createClient('T0000001', 'Клиент Один');
        $this->createSpoRaw($client, '2026-01-15');

        $summary = $this->service->summarize(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));

        self::assertSame(0, $summary->totalCount);
        self::assertSame(0, $summary->countsByRiskLevel[RiskLevel::Low->value]);
    }

    public function test_every_spo_is_counted_in_exactly_one_risk_level_even_without_a_client_card(): void
    {
        // RiskLevel не хранится в ClientCard и не зависит от неё — в отличие от старой
        // LLM-метки, СПО клиента без карточки теперь тоже попадает в какой-то уровень
        // (раньше был возможен разрыв: total учитывал такой СПО, а по меткам — нет).
        $client = $this->createClient('T0000001', 'Клиент Один');
        $this->createSpoRaw($client, '2026-02-10');

        $summary = $this->service->summarize(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));

        self::assertSame(1, $summary->totalCount);
        self::assertSame($summary->totalCount, array_sum($summary->countsByRiskLevel));
    }

    private function createClient(string $docNumber, string $fullName): Client
    {
        return Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => $docNumber,
            'first_name' => $fullName,
            'last_name' => $fullName,
            'middle_name' => null,
            'dob' => '1990-05-10',
            'full_name' => $fullName,
        ]);
    }

    private function createSpoRaw(Client $client, string $transactionDate): SpoRaw
    {
        return SpoRaw::create([
            'client_id' => $client->id,
            'source_file' => 'spo_1.xml',
            'transaction_date' => $transactionDate,
            'currency' => 'TJS',
            'amount' => 1000,
            'amount_nc' => null,
            'transaction_type' => '10.3',
            'transaction_subtype' => '10.3.1',
            'details' => '10.3.1.9',
            'transaction_desc' => 'Перевод',
            'ground_text' => 'Подозрительный перевод.',
            'other_side' => null,
        ]);
    }
}
