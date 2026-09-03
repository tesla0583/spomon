<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\PartyType;
use App\Models\Client;
use App\Models\ClientCard;
use App\Models\SpoRaw;
use App\Services\Cards\ClientCardRecomputeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ClientCardRecomputeServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClientCardRecomputeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ClientCardRecomputeService;
    }

    public function test_list_client_ids_pending_recompute_returns_only_clients_with_spo_raws(): void
    {
        $clientWithSpo = $this->createClientWithSpoRaw('T0000001', 'Файзуллоева Гулнора');
        $this->createClient('T0000002', 'Иванов Петр'); // без СПО

        $ids = $this->service->listClientIdsPendingRecompute();

        self::assertSame([$clientWithSpo->id], $ids);
    }

    public function test_recompute_one_returns_null_on_success(): void
    {
        $client = $this->createClientWithSpoRaw('T0000001', 'Файзуллоева Гулнора');
        $this->fakeSuccessfulClaudeResponse();

        $error = $this->service->recomputeOne($client->id);

        self::assertNull($error);
        self::assertSame(1, ClientCard::query()->where('client_id', $client->id)->count());
    }

    public function test_recompute_one_returns_error_message_on_failure_without_throwing(): void
    {
        $client = $this->createClientWithSpoRaw('T0000001', 'Файзуллоева Гулнора');
        $this->fakeFailingClaudeResponse();

        $error = $this->service->recomputeOne($client->id);

        self::assertNotNull($error);
        self::assertSame(0, ClientCard::query()->where('client_id', $client->id)->count());
    }

    public function test_recompute_all_dispatches_for_every_pending_client_and_collects_failures(): void
    {
        $okClient = $this->createClientWithSpoRaw('T0000001', 'Файзуллоева Гулнора');
        $failingClient = $this->createClientWithSpoRaw('T0000002', 'Иванов Петр');
        $this->createClient('T0000003', 'Без СПО'); // не должен попасть в выборку

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->successfulClaudeResponsePayload(), 200)
                ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'boom']], 400),
        ]);

        $summary = $this->service->recomputeAll();

        self::assertSame(2, $summary->dispatchedCount);
        self::assertCount(1, $summary->failures);
        self::assertSame(1, ClientCard::query()->where('client_id', $okClient->id)->count());
        self::assertSame(0, ClientCard::query()->where('client_id', $failingClient->id)->count());
    }

    private function createClientWithSpoRaw(string $docNumber, string $fullName): Client
    {
        $client = $this->createClient($docNumber, $fullName);

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

    private function fakeSuccessfulClaudeResponse(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->successfulClaudeResponsePayload(), 200),
        ]);
    }

    private function fakeFailingClaudeResponse(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => '`temperature` is deprecated for this model.'],
            ], 400),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulClaudeResponsePayload(): array
    {
        return [
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
        ];
    }
}
