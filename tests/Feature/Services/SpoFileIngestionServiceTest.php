<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\IngestionStatus;
use App\Models\Client;
use App\Models\SpoFileIngestion;
use App\Models\SpoRaw;
use App\Services\Ingestion\SpoFileIngestionService;
use App\Services\Xml\Form101Parser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

        $this->service = new SpoFileIngestionService(new Form101Parser);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_valid_file_is_ingested_creates_client_and_spo_raw_and_moves_to_processed(): void
    {
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

        self::assertFileDoesNotExist($this->incomingPath.'/spo_1.xml');
        self::assertFileExists($this->basePath.'/processed/spo_1.xml');

        $ingestion = SpoFileIngestion::query()->first();
        self::assertSame(IngestionStatus::Processed, $ingestion->status);
        self::assertNotNull($ingestion->processed_at);
    }

    public function test_reingesting_identical_file_content_is_skipped_and_does_not_duplicate_records(): void
    {
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

    public function test_ingesting_empty_directory_does_not_fail(): void
    {
        $summary = $this->service->ingestFromDirectory($this->incomingPath);

        self::assertSame(0, $summary->processedCount);
        self::assertSame(0, $summary->skippedCount);
        self::assertSame(0, $summary->failedCount);
    }

    private function copyFixtureToIncoming(string $fixtureName, string $targetName): void
    {
        File::copy(base_path('tests/Fixtures/xml/'.$fixtureName), $this->incomingPath.'/'.$targetName);
    }
}
