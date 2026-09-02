<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\IngestionStatus;
use App\Enums\SpoFileIngestOutcome;
use App\Models\Client;
use App\Models\ClientCard;
use App\Models\Entity;
use App\Models\EntityMention;
use App\Models\SpoFileIngestion;
use App\Models\SpoRaw;
use App\Repositories\ClientRepository;
use App\Services\Entities\EntityRegistrationService;
use App\Services\Ingestion\SpoFileIngestionService;
use App\Services\Xml\Form101Parser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SpoFileIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $basePath;

    private string $incomingPath;

    private SpoFileIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = storage_path('framework/testing/spo_'.uniqid());
        $this->incomingPath = $this->basePath.'/incoming';

        File::makeDirectory($this->incomingPath, 0755, true);
        File::makeDirectory($this->basePath.'/processed', 0755, true);
        File::makeDirectory($this->basePath.'/failed', 0755, true);

        $this->service = new SpoFileIngestionService(new Form101Parser, new ClientRepository, new EntityRegistrationService);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);

        parent::tearDown();
    }

    /**
     * persist() дальше дёргает ComputeClientCardJob::dispatch(); с QUEUE_CONNECTION=sync
     * (дефолт проекта, см. phpunit.xml) джоб выполняется прямо здесь же, синхронно — без
     * фейкового ответа это был бы реальный вызов api.anthropic.com. Фейк заводится явно
     * в каждом тесте (а не в setUp()), т.к. Http::fake() при повторном вызове ДОБАВЛЯЕТ
     * стаб в общий список, а не заменяет — при совпадении побеждает первый
     * зарегистрированный, так что общий фейк в setUp() нельзя было бы переопределить
     * под конкретный тест (см. test_llm_card_computation_failure_...).
     */
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

    private function fakeFailingClaudeResponse(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => '`temperature` is deprecated for this model.'],
            ], 400),
        ]);
    }

    public function test_valid_file_is_ingested_creates_client_and_spo_raw_and_moves_to_processed(): void
    {
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(1, $summary->processedCount);
        self::assertSame(0, $summary->skippedCount);
        self::assertSame(0, $summary->failedCount);

        self::assertSame(1, Client::query()->count());
        self::assertSame(1, SpoRaw::query()->count());

        $spoRaw = SpoRaw::query()->first();
        self::assertSame('spo_1.xml', $spoRaw->source_file);
        self::assertNotNull($spoRaw->other_side);

        // Вторая сторона фикстуры — юрлицо "ООО Тестовая Компания" — регистрируется
        // в реестре сущностей как часть успешного сохранения XML.
        self::assertSame(1, Entity::query()->count());
        self::assertSame(1, EntityMention::query()->count());

        self::assertFileDoesNotExist($this->incomingPath.'/spo_1.xml');
        self::assertFileExists($this->basePath.'/processed/spo_1.xml');

        $ingestion = SpoFileIngestion::query()->first();
        self::assertSame(IngestionStatus::Processed, $ingestion->status);
        self::assertNotNull($ingestion->processed_at);
    }

    public function test_single_side_file_does_not_create_entity(): void
    {
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_single_side.xml', 'spo_1.xml');

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(1, $summary->processedCount);
        self::assertSame(1, SpoRaw::query()->count());
        self::assertSame(0, Entity::query()->count());
        self::assertSame(0, EntityMention::query()->count());
    }

    public function test_reingesting_identical_file_content_is_skipped_and_does_not_duplicate_records(): void
    {
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');
        $this->service->ingestFromDirectory($this->incomingPath);

        // Тот же файл (то же содержимое) повторно попадает в incoming — например, случайно
        // выгружен ещё раз. Повторный прогон не должен создавать дубликаты.
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');
        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(0, $summary->processedCount);
        self::assertSame(1, $summary->skippedCount);
        self::assertSame(0, $summary->failedCount);

        self::assertSame(1, Client::query()->count());
        self::assertSame(1, SpoRaw::query()->count());
        self::assertSame(1, SpoFileIngestion::query()->count());
    }

    public function test_retrying_a_previously_failed_file_reprocesses_it_and_can_succeed(): void
    {
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');
        $fileHash = hash('sha256', (string) file_get_contents($this->incomingPath.'/spo_1.xml'));

        // Симулируем, что эта же пара (имя файла + хеш содержимого) уже падала ранее —
        // например, из-за временной ошибки БД, впоследствии устранённой.
        SpoFileIngestion::create([
            'file_name' => 'spo_1.xml',
            'file_hash' => $fileHash,
            'status' => IngestionStatus::Failed,
            'error_message' => 'Симулированная ошибка предыдущего запуска.',
        ]);

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(1, $summary->processedCount);
        self::assertSame(0, $summary->skippedCount);
        self::assertSame(0, $summary->failedCount);

        // Запись переиспользована (retry), а не задублирована новой строкой.
        self::assertSame(1, SpoFileIngestion::query()->count());
        $ingestion = SpoFileIngestion::query()->first();
        self::assertSame(IngestionStatus::Processed, $ingestion->status);
        self::assertNull($ingestion->error_message);

        self::assertSame(1, SpoRaw::query()->count());
        self::assertFileExists($this->basePath.'/processed/spo_1.xml');
    }

    public function test_same_content_under_different_file_name_is_processed_as_separate_file(): void
    {
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');
        $this->service->ingestFromDirectory($this->incomingPath);

        // То же содержимое (тот же file_hash), но другое имя файла — это самостоятельный
        // файл, а не дубль (составной unique-индекс это разрешает).
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1_copy.xml');
        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(1, $summary->processedCount);
        self::assertSame(0, $summary->skippedCount);
        self::assertSame(0, $summary->failedCount);

        self::assertSame(2, SpoFileIngestion::query()->count());
        self::assertSame(2, SpoRaw::query()->count());
        // Тот же клиент (T0000001) — запись о клиенте не дублируется.
        self::assertSame(1, Client::query()->count());

        self::assertFileExists($this->basePath.'/processed/spo_1.xml');
        self::assertFileExists($this->basePath.'/processed/spo_1_copy.xml');
    }

    public function test_second_spo_for_same_client_is_attached_to_existing_client(): void
    {
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');
        $this->service->ingestFromDirectory($this->incomingPath);

        // Разное содержимое (другое имя файла и другая сумма), но тот же клиент (T0000001)
        // не должен матчиться повторно как новый.
        $secondXml = str_replace('15000.50', '20000.00', file_get_contents(
            base_path('tests/Fixtures/xml/form_101_valid.xml'),
        ));
        File::put($this->incomingPath.'/spo_2.xml', $secondXml);

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(1, $summary->processedCount);
        self::assertSame(1, Client::query()->count());
        self::assertSame(2, SpoRaw::query()->count());
    }

    public function test_unsupported_xml_format_goes_to_failed_with_error_message(): void
    {
        $this->copyFixtureToIncoming('unsupported_root.xml', 'bad.xml');

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(0, $summary->processedCount);
        self::assertSame(1, $summary->failedCount);
        self::assertArrayHasKey('bad.xml', $summary->failures);

        self::assertSame(0, SpoRaw::query()->count());
        self::assertFileDoesNotExist($this->incomingPath.'/bad.xml');
        self::assertFileExists($this->basePath.'/failed/bad.xml');

        $ingestion = SpoFileIngestion::query()->first();
        self::assertSame(IngestionStatus::Failed, $ingestion->status);
        self::assertNotEmpty($ingestion->error_message);
    }

    public function test_contra_party_with_homoglyph_doc_number_is_ingested_successfully(): void
    {
        // Регрессия: раньше этот реальный файл падал в failed, т.к. <doс_number>
        // контрагента (кириллическая "с") не распознавался как doc_number.
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_real_homoglyph_doc_number_1.xml', 'spo_1.xml');

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(1, $summary->processedCount);
        self::assertSame(0, $summary->failedCount);
        self::assertSame([], $summary->failures);

        self::assertSame(1, SpoRaw::query()->count());
        self::assertFileExists($this->basePath.'/processed/spo_1.xml');
        self::assertFileDoesNotExist($this->basePath.'/failed/spo_1.xml');
    }

    public function test_legal_entity_contra_party_without_tax_pay_number_is_ingested_successfully(): void
    {
        // Регрессия: раньше этот реальный файл падал в failed, т.к. юрлицо-контрагент без
        // tax_pay_number (иностранная компания без местного ИНН) не распознавалось.
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_real_legal_entity_no_tax_pay_number_1.xml', 'spo_1.xml');

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(1, $summary->processedCount);
        self::assertSame(0, $summary->failedCount);
        self::assertSame([], $summary->failures);

        self::assertSame(1, SpoRaw::query()->count());
        self::assertFileExists($this->basePath.'/processed/spo_1.xml');
        self::assertFileDoesNotExist($this->basePath.'/failed/spo_1.xml');
    }

    public function test_llm_card_computation_failure_does_not_fail_the_file_or_duplicate_spo_raw_on_retry(): void
    {
        // Регрессия на реальный инцидент: сбой вызова Claude API (например, невалидный
        // параметр запроса) НЕ должен переводить уже успешно распарсенный и сохранённый
        // XML в failed. Раньше сбой ComputeClientCardJob::dispatch() внутри persist()
        // ловился общим catch()'ем файла — файл уходил в failed, а при retry SpoRaw
        // сохранялся ПОВТОРНО (дубликат истории СПО у клиента).
        $this->fakeFailingClaudeResponse();

        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        // XML разобран и сохранён — это успех обработки файла, а не ошибка.
        self::assertSame(1, $summary->processedCount);
        self::assertSame(0, $summary->failedCount);
        self::assertSame([], $summary->failures);
        self::assertCount(1, $summary->cardFailures);

        self::assertSame(1, SpoRaw::query()->count());
        self::assertSame(0, ClientCard::query()->count());

        self::assertFileDoesNotExist($this->incomingPath.'/spo_1.xml');
        self::assertFileExists($this->basePath.'/processed/spo_1.xml');
        self::assertFileDoesNotExist($this->basePath.'/failed/spo_1.xml');

        $ingestion = SpoFileIngestion::query()->first();
        self::assertSame(IngestionStatus::Processed, $ingestion->status);
        self::assertNull($ingestion->error_message);

        // incoming пуст (файл уже в processed) — повторный прогон ничего не находит и
        // точно не создаёт вторую SpoRaw-запись для того же клиента.
        $summary2 = $this->service->ingestFromDirectory($this->incomingPath);
        self::assertSame(0, $summary2->processedCount);
        self::assertSame(0, $summary2->skippedCount);
        self::assertSame(1, SpoRaw::query()->count());
    }

    public function test_ingesting_empty_directory_does_not_fail(): void
    {
        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(0, $summary->processedCount);
        self::assertSame(0, $summary->skippedCount);
        self::assertSame(0, $summary->failedCount);
    }

    public function test_ingest_file_processes_a_single_valid_file_and_moves_it_to_processed(): void
    {
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');

        $result = $this->service->ingestFile($this->incomingPath.'/spo_1.xml');

        self::assertSame(SpoFileIngestOutcome::Processed, $result->outcome);
        self::assertSame('spo_1.xml', $result->fileName);
        self::assertNull($result->errorMessage);
        self::assertNull($result->cardFailureMessage);

        $client = Client::query()->first();
        self::assertNotNull($client);
        self::assertSame($client->id, $result->clientId);

        self::assertSame(1, SpoRaw::query()->count());
        self::assertFileDoesNotExist($this->incomingPath.'/spo_1.xml');
        self::assertFileExists($this->basePath.'/processed/spo_1.xml');
    }

    public function test_ingest_file_skips_an_already_processed_file(): void
    {
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');
        $this->service->ingestFile($this->incomingPath.'/spo_1.xml');

        // Тот же файл (то же имя+хеш) снова попадает в incoming.
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');

        $result = $this->service->ingestFile($this->incomingPath.'/spo_1.xml');

        self::assertSame(SpoFileIngestOutcome::Skipped, $result->outcome);
        self::assertSame(1, SpoRaw::query()->count());
    }

    public function test_ingest_file_returns_failed_outcome_with_error_message_and_moves_to_failed(): void
    {
        $this->copyFixtureToIncoming('unsupported_root.xml', 'bad.xml');

        $result = $this->service->ingestFile($this->incomingPath.'/bad.xml');

        self::assertSame(SpoFileIngestOutcome::Failed, $result->outcome);
        self::assertSame('bad.xml', $result->fileName);
        self::assertNotEmpty($result->errorMessage);

        self::assertSame(0, SpoRaw::query()->count());
        self::assertFileDoesNotExist($this->incomingPath.'/bad.xml');
        self::assertFileExists($this->basePath.'/failed/bad.xml');
    }

    public function test_ingest_file_reports_card_failure_without_failing_the_file(): void
    {
        $this->fakeFailingClaudeResponse();
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');

        $result = $this->service->ingestFile($this->incomingPath.'/spo_1.xml');

        self::assertSame(SpoFileIngestOutcome::Processed, $result->outcome);
        self::assertNotNull($result->clientId);
        self::assertNotEmpty($result->cardFailureMessage);

        self::assertSame(1, SpoRaw::query()->count());
        self::assertFileExists($this->basePath.'/processed/spo_1.xml');
    }

    /**
     * Регрессия на извлечение ingestFile(): ingestFromDirectory() должна по-прежнему давать
     * тот же результат, что и раньше — теперь это просто тонкий цикл вокруг ingestFile().
     */
    public function test_ingest_from_directory_still_aggregates_ingest_file_results_correctly(): void
    {
        $this->fakeSuccessfulClaudeResponse();
        $this->copyFixtureToIncoming('form_101_valid.xml', 'spo_1.xml');
        $this->copyFixtureToIncoming('unsupported_root.xml', 'bad.xml');

        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(1, $summary->processedCount);
        self::assertSame(0, $summary->skippedCount);
        self::assertSame(1, $summary->failedCount);
        self::assertArrayHasKey('bad.xml', $summary->failures);
    }

    private function copyFixtureToIncoming(string $fixtureName, string $targetName): void
    {
        File::copy(base_path('tests/Fixtures/xml/'.$fixtureName), $this->incomingPath.'/'.$targetName);
    }
}
