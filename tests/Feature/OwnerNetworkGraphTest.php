<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Entity;
use App\Repositories\ClientRepository;
use App\Repositories\EntityRepository;
use App\Services\Entities\EntityRegistrationService;
use App\Services\Ingestion\SpoFileIngestionService;
use App\Services\Xml\Form101Parser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Сквозной сценарий: несколько разных клиентов, объединённых общим адресом или общим
 * именем в свободном тексте (`doubt_description`), должны находить друг друга в графе
 * связей после обычной загрузки XML — см. CLAUDE.md, "Логика вызова Claude API" и
 * App\Services\Entities\EntityRegistrationService::registerAddressMention().
 */
final class OwnerNetworkGraphTest extends TestCase
{
    use RefreshDatabase;

    private string $basePath;

    private string $incomingPath;

    private SpoFileIngestionService $service;

    private EntityRepository $entityRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = storage_path('framework/testing/spo_'.uniqid());
        $this->incomingPath = $this->basePath.'/incoming';

        File::makeDirectory($this->incomingPath, 0755, true);
        File::makeDirectory($this->basePath.'/processed', 0755, true);
        File::makeDirectory($this->basePath.'/failed', 0755, true);

        $this->service = new SpoFileIngestionService(new Form101Parser, new ClientRepository, new EntityRegistrationService);
        $this->entityRepository = new EntityRepository;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_clients_sharing_an_address_find_each_other_in_the_network_graph(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->emptyClaudeResponse(), 200)]);

        $this->copyFixtureToIncoming('owner-network-test-3-address-A.xml', 'address_a.xml');
        $this->copyFixtureToIncoming('owner-network-test-4-address-B.xml', 'address_b.xml');
        $this->copyFixtureToIncoming('owner-network-test-5-address-C.xml', 'address_c.xml');

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(3, $summary->processedCount);
        self::assertSame(0, $summary->failedCount);

        $clients = Client::query()->orderBy('id')->get();
        self::assertCount(3, $clients);

        // Три разных клиента, ни один структурно не связан с другим (у каждого файла
        // только одна side_section, без контрагента) — но все трое указали один и тот же
        // физический адрес, и это должно проявиться в графе связей у каждого из них.
        foreach ($clients as $client) {
            $references = $this->entityRepository->findKnownNetworkEntityReferences($client->id);

            self::assertCount(2, $references);

            foreach ($references as $reference) {
                self::assertStringContainsString('адрес "г хучанд, кучаи исмоили сомони 45, дом 9, кв 12"', $reference);
            }
        }
    }

    public function test_clients_sharing_a_person_named_in_free_text_find_each_other_via_ner(): void
    {
        // Один и тот же статический ответ применяется к обоим HTTP-вызовам (по одному на
        // файл — ComputeClientCardJob::dispatch() выполняется синхронно, QUEUE_CONNECTION=
        // sync). extracted_entities содержит ОБЕ реальные даты СПО фикстур — каждый клиент
        // подхватывает только свою (registerNerMentions() ищет SpoRaw по transaction_date
        // в рамках конкретного $client), чужая дата просто не находится и пропускается,
        // поэтому порядок обработки файлов не важен.
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'content' => [
                [
                    'type' => 'tool_use',
                    'name' => 'submit_client_analysis',
                    'input' => [
                        'summary' => 'Тестовая заглушка ответа Claude API.',
                        'pattern_notes' => null,
                        'extracted_entities' => [
                            ['spo_date' => '2025-04-10', 'entities' => ['Расулов Диловар']],
                            ['spo_date' => '2025-03-20', 'entities' => ['Расулов Диловар']],
                        ],
                        'network_signal' => ['found' => false, 'matched_client_reference' => null],
                    ],
                ],
            ],
        ], 200)]);

        $this->copyFixtureToIncoming('owner-network-test-6-ground-A.xml', 'ground_a.xml');
        $this->copyFixtureToIncoming('owner-network-test-7-ground-B.xml', 'ground_b.xml');

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(2, $summary->processedCount);
        self::assertSame(0, $summary->failedCount);

        $clients = Client::query()->orderBy('id')->get();
        self::assertCount(2, $clients);

        foreach ($clients as $client) {
            $references = $this->entityRepository->findKnownNetworkEntityReferences($client->id);

            self::assertCount(1, $references);
            self::assertStringContainsString('контрагент "расулов диловар"', $references[0]);
        }
    }

    public function test_clients_sharing_only_the_regulator_email_do_not_find_each_other(): void
    {
        // Один и тот же email почти в каждом СПО (аналитик упоминает уведомление
        // регулятора в свободном тексте) не должен считаться общим контрагентом —
        // см. App\Services\Entities\EntityRegistrationService::EXCLUDED_NORMALIZED_NAMES.
        // Разный регистр между файлами — заодно проверка, что фильтр применяется
        // после нормализации, а не по точной строке.
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'content' => [
                [
                    'type' => 'tool_use',
                    'name' => 'submit_client_analysis',
                    'input' => [
                        'summary' => 'Тестовая заглушка ответа Claude API.',
                        'pattern_notes' => null,
                        'extracted_entities' => [
                            ['spo_date' => '2025-04-10', 'entities' => ['fiu@nbt.tj']],
                            ['spo_date' => '2025-03-20', 'entities' => ['FIU@NBT.TJ']],
                        ],
                        'network_signal' => ['found' => false, 'matched_client_reference' => null],
                    ],
                ],
            ],
        ], 200)]);

        $this->copyFixtureToIncoming('owner-network-test-6-ground-A.xml', 'ground_a.xml');
        $this->copyFixtureToIncoming('owner-network-test-7-ground-B.xml', 'ground_b.xml');

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(2, $summary->processedCount);
        self::assertSame(0, $summary->failedCount);

        $clients = Client::query()->orderBy('id')->get();
        self::assertCount(2, $clients);

        self::assertSame(0, Entity::query()->where('normalized_name', 'fiu@nbt.tj')->count());

        foreach ($clients as $client) {
            self::assertSame([], $this->entityRepository->findKnownNetworkEntityReferences($client->id));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyClaudeResponse(): array
    {
        return [
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
                    ],
                ],
            ],
        ];
    }

    private function copyFixtureToIncoming(string $fixtureName, string $targetName): void
    {
        File::copy(base_path('tests/Fixtures/xml/'.$fixtureName), $this->incomingPath.'/'.$targetName);
    }
}
