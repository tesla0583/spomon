<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\PartyType;
use App\Models\Client;
use App\Models\ClientCard;
use App\Models\SpoRaw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SpoRecomputeCardsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_recomputes_card_for_a_specific_client(): void
    {
        $client = $this->createClientWithSpoRaw();
        $this->fakeSuccessfulClaudeResponse();

        $this->artisan('spo:recompute-cards', ['client_id' => $client->id])
            ->assertExitCode(0);

        Http::assertSentCount(1);
        self::assertSame(1, ClientCard::query()->where('client_id', $client->id)->count());
    }

    public function test_without_argument_recomputes_only_clients_that_have_spo_raws(): void
    {
        $clientWithSpo = $this->createClientWithSpoRaw();
        Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => 'T0000002',
            'first_name' => 'Петр',
            'last_name' => 'Иванов',
            'middle_name' => null,
            'dob' => '1980-01-01',
            'full_name' => 'Иванов Петр',
        ]);
        $this->fakeSuccessfulClaudeResponse();

        $this->artisan('spo:recompute-cards')->assertExitCode(0);

        // Клиент без единой СПО не должен вызывать LLM.
        Http::assertSentCount(1);
        self::assertSame(1, ClientCard::query()->where('client_id', $clientWithSpo->id)->count());
    }

    public function test_second_run_with_unchanged_history_does_not_call_api_again(): void
    {
        $client = $this->createClientWithSpoRaw();
        $this->fakeSuccessfulClaudeResponse();

        $this->artisan('spo:recompute-cards', ['client_id' => $client->id])->assertExitCode(0);
        $this->artisan('spo:recompute-cards', ['client_id' => $client->id])->assertExitCode(0);

        // history_fingerprint не изменился — второй запуск не должен снова дёргать API.
        Http::assertSentCount(1);
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
                        ],
                    ],
                ],
            ], 200),
        ]);
    }
}
