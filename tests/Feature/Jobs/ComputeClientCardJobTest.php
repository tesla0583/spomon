<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\PartyType;
use App\Enums\RiskLabel;
use App\Jobs\ComputeClientCardJob;
use App\Models\Client;
use App\Models\ClientCard;
use App\Models\SpoRaw;
use App\Services\Llm\ClaudeApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ComputeClientCardJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key' => 'test-api-key',
            'services.anthropic.model' => 'claude-sonnet-5',
        ]);
    }

    public function test_first_run_calls_claude_api_and_creates_client_card(): void
    {
        $client = $this->createClientWithSpoRaw();
        $this->fakeSuccessfulClaudeResponse();

        (new ComputeClientCardJob($client->id))->handle(app(ClaudeApiClient::class));

        Http::assertSentCount(1);

        $card = ClientCard::query()->where('client_id', $client->id)->firstOrFail();
        self::assertSame(RiskLabel::SingleCase, $card->risk_label);
        self::assertNotNull($card->history_fingerprint);
        self::assertNotNull($card->computed_at);
    }

    public function test_second_run_with_unchanged_history_does_not_call_api_again(): void
    {
        $client = $this->createClientWithSpoRaw();
        $this->fakeSuccessfulClaudeResponse();

        (new ComputeClientCardJob($client->id))->handle(app(ClaudeApiClient::class));
        (new ComputeClientCardJob($client->id))->handle(app(ClaudeApiClient::class));

        // История СПО клиента не менялась между прогонами — второй прогон не должен
        // дёргать API повторно, fingerprint совпадает.
        Http::assertSentCount(1);
        self::assertSame(1, ClientCard::query()->where('client_id', $client->id)->count());
    }

    public function test_adding_new_spo_raw_changes_fingerprint_and_triggers_new_api_call(): void
    {
        $client = $this->createClientWithSpoRaw();
        $this->fakeSuccessfulClaudeResponse();

        (new ComputeClientCardJob($client->id))->handle(app(ClaudeApiClient::class));
        $firstFingerprint = ClientCard::query()->where('client_id', $client->id)->firstOrFail()->history_fingerprint;

        SpoRaw::create([
            'client_id' => $client->id,
            'source_file' => 'spo_2.xml',
            'transaction_date' => '2026-02-15',
            'currency' => 'TJS',
            'amount' => 5000,
            'amount_nc' => null,
            'transaction_type' => '10.3',
            'transaction_subtype' => '10.3.1',
            'details' => '10.3.1.9',
            'transaction_desc' => 'Второй перевод',
            'ground_text' => 'Ещё один подозрительный перевод.',
            'other_side' => null,
        ]);

        (new ComputeClientCardJob($client->id))->handle(app(ClaudeApiClient::class));

        Http::assertSentCount(2);

        $card = ClientCard::query()->where('client_id', $client->id)->firstOrFail();
        self::assertNotSame($firstFingerprint, $card->history_fingerprint);
    }

    private function createClientWithSpoRaw(): Client
    {
        $client = Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => 'T0000001',
            'first_name' => 'Гулнора',
            'last_name' => 'Файзуллоева',
            'middle_name' => null,
            'dob' => '1990-05-10',
            'full_name' => 'Файзуллоева Гулнора',
        ]);

        SpoRaw::create([
            'client_id' => $client->id,
            'source_file' => 'spo_1.xml',
            'transaction_date' => '2026-01-10',
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

        return $client;
    }

    private function fakeSuccessfulClaudeResponse(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_test',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'name' => 'submit_client_analysis',
                        'input' => [
                            'summary' => 'Единичный случай подозрительного перевода.',
                            'pattern_notes' => null,
                            'extracted_entities' => [],
                            'network_signal' => ['found' => false, 'matched_client_reference' => null],
                            'final_label' => 'единичный случай',
                        ],
                    ],
                ],
            ], 200),
        ]);
    }
}
