<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Stats;

use App\Enums\PartyType;
use App\Enums\RiskLabel;
use App\Models\Client;
use App\Models\ClientCard;
use App\Models\SpoRaw;
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

        $this->service = new SpoStatisticsService;
    }

    public function test_summarizes_counts_by_risk_label_within_period(): void
    {
        $single = $this->createClient('T0000001', 'Клиент Один');
        $this->createCard($single, RiskLabel::SingleCase);
        $this->createSpoRaw($single, '2026-02-10');

        $network = $this->createClient('T0000002', 'Клиент Два');
        $this->createCard($network, RiskLabel::PartOfNetwork);
        $this->createSpoRaw($network, '2026-02-15');

        $summary = $this->service->summarize(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));

        self::assertSame(2, $summary->totalCount);
        self::assertSame(1, $summary->countsByRiskLabel[RiskLabel::SingleCase->value]);
        self::assertSame(1, $summary->countsByRiskLabel[RiskLabel::PartOfNetwork->value]);
        self::assertSame(0, $summary->countsByRiskLabel[RiskLabel::NeedsAttention->value]);
        self::assertSame(0, $summary->countsByRiskLabel[RiskLabel::RepeatingPattern->value]);
    }

    public function test_excludes_spo_outside_period(): void
    {
        $client = $this->createClient('T0000001', 'Клиент Один');
        $this->createCard($client, RiskLabel::SingleCase);
        $this->createSpoRaw($client, '2026-01-15');

        $summary = $this->service->summarize(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));

        self::assertSame(0, $summary->totalCount);
        self::assertSame(0, $summary->countsByRiskLabel[RiskLabel::SingleCase->value]);
    }

    public function test_spo_without_card_counts_towards_total_but_not_any_label(): void
    {
        $client = $this->createClient('T0000001', 'Клиент Один');
        $this->createSpoRaw($client, '2026-02-10');

        $summary = $this->service->summarize(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));

        self::assertSame(1, $summary->totalCount);
        foreach (RiskLabel::cases() as $case) {
            self::assertSame(0, $summary->countsByRiskLabel[$case->value]);
        }
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

    private function createCard(Client $client, RiskLabel $riskLabel): ClientCard
    {
        return ClientCard::create([
            'client_id' => $client->id,
            'risk_label' => $riskLabel,
            'summary' => 'Сводка.',
            'pattern_notes' => null,
            'network_signal' => null,
            'llm_raw_response' => ['ok' => true],
            'history_fingerprint' => hash('sha256', (string) $client->id),
            'computed_at' => now(),
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
