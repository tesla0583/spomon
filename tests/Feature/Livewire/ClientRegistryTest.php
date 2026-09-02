<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\PartyType;
use App\Livewire\ClientRegistry;
use App\Models\Client;
use App\Models\ClientCard;
use App\Models\SpoRaw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class ClientRegistryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * startIngest() жёстко берёт storage_path('app/spo/incoming') (как и SpoIngestCommand
     * сейчас) — на время теста реальная папка spo/ отодвигается в сторону и восстанавливается
     * в tearDown(), чтобы тест не тронул настоящие входящие/обработанные файлы на диске.
     */
    private string $realSpoPath;

    private string $backupSpoPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realSpoPath = storage_path('app/spo');
        $this->backupSpoPath = storage_path('app/spo_test_backup_'.uniqid());

        if (File::isDirectory($this->realSpoPath)) {
            File::moveDirectory($this->realSpoPath, $this->backupSpoPath);
        }

        File::makeDirectory($this->realSpoPath.'/incoming', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->realSpoPath);

        if (File::isDirectory($this->backupSpoPath)) {
            File::moveDirectory($this->backupSpoPath, $this->realSpoPath);
        }

        parent::tearDown();
    }

    public function test_renders_successfully_with_clients(): void
    {
        $this->createClient('T0000001', 'Клиент Один');
        $this->createClient('T0000002', 'Клиент Два');

        Livewire::test(ClientRegistry::class)->assertOk();
    }

    public function test_search_by_doc_number_narrows_results(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент Один');
        $clientB = $this->createClient('T0000002', 'Клиент Два');

        Livewire::test(ClientRegistry::class)
            ->set('search', 'T0000001')
            ->assertSee($clientA->full_name)
            ->assertDontSee($clientB->full_name);
    }

    public function test_client_full_name_links_to_the_card_page(): void
    {
        $client = $this->createClient('T0000001', 'Клиент Один');

        Livewire::test(ClientRegistry::class)
            ->assertSeeHtml('href="'.route('clients.show', $client).'"');
    }

    /**
     * startIngest() + processNextIngestItem() вызванный total раз подряд (эмуляция того, что
     * в браузере сделает wire:poll) должны дать тот же итог, что и старый
     * SpoFileIngestionService::ingestFromDirectory() на той же папке.
     */
    public function test_start_ingest_then_polling_all_items_matches_ingest_from_directory_result(): void
    {
        $this->fakeSuccessfulClaudeResponse();
        File::copy(base_path('tests/Fixtures/xml/form_101_valid.xml'), storage_path('app/spo/incoming/spo_1.xml'));
        File::copy(base_path('tests/Fixtures/xml/unsupported_root.xml'), storage_path('app/spo/incoming/bad.xml'));

        $component = Livewire::test(ClientRegistry::class)->call('startIngest');

        $total = $component->get('total');
        self::assertSame(2, $total);
        self::assertSame(0, $component->get('done'));
        self::assertNull($component->get('lastSummary'));

        for ($i = 0; $i < $total; $i++) {
            $component->call('processNextIngestItem');
        }

        self::assertSame([], $component->get('queue'));
        self::assertSame($total, $component->get('done'));

        $lastSummary = $component->get('lastSummary');
        self::assertSame(1, $lastSummary['processedCount']);
        self::assertSame(0, $lastSummary['skippedCount']);
        self::assertSame(1, $lastSummary['failedCount']);
        self::assertArrayHasKey('bad.xml', $lastSummary['failures']);
        self::assertSame([], $lastSummary['cardFailures']);

        self::assertSame(1, Client::query()->count());
        self::assertSame(1, SpoRaw::query()->count());
    }

    public function test_process_next_ingest_item_is_a_noop_when_queue_is_empty(): void
    {
        Livewire::test(ClientRegistry::class)
            ->call('processNextIngestItem')
            ->assertSet('done', 0)
            ->assertSet('lastSummary', null);
    }

    /**
     * startRecompute() + processNextRecomputeItem() вызванный total раз подряд должны дать
     * тот же итог, что и ClientCardRecomputeService::recomputeAll().
     */
    public function test_start_recompute_then_polling_all_items_dispatches_for_every_pending_client(): void
    {
        $client = $this->createClientWithSpoRaw('T0000001', 'Клиент Один');
        $this->createClient('T0000002', 'Клиент Два'); // без СПО — не должен попасть в очередь
        $this->fakeSuccessfulClaudeResponse();

        $component = Livewire::test(ClientRegistry::class)->call('startRecompute');

        $total = $component->get('total');
        self::assertSame(1, $total);

        for ($i = 0; $i < $total; $i++) {
            $component->call('processNextRecomputeItem');
        }

        self::assertSame([], $component->get('queue'));
        self::assertSame($total, $component->get('done'));

        $lastSummary = $component->get('lastSummary');
        self::assertSame(1, $lastSummary['dispatchedCount']);
        self::assertSame([], $lastSummary['failures']);

        self::assertSame(1, ClientCard::query()->where('client_id', $client->id)->count());
    }

    public function test_process_next_recompute_item_is_a_noop_when_queue_is_empty(): void
    {
        Livewire::test(ClientRegistry::class)
            ->call('processNextRecomputeItem')
            ->assertSet('done', 0)
            ->assertSet('lastSummary', null);
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
                            'summary' => 'Тестовая заглушка ответа Claude API.',
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
