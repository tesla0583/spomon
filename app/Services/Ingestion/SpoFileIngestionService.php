<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\DTOs\IndividualPartyDto;
use App\DTOs\IngestionSummaryDto;
use App\DTOs\LegalEntityPartyDto;
use App\DTOs\PartyDataInterface;
use App\DTOs\SpoRecordDto;
use App\Enums\IngestionStatus;
use App\Enums\PartyType;
use App\Jobs\ComputeClientCardJob;
use App\Models\Client;
use App\Models\SpoFileIngestion;
use App\Models\SpoRaw;
use App\Repositories\ClientRepository;
use App\Services\Entities\EntityRegistrationService;
use App\Services\Xml\Form101Parser;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use LogicException;
use Throwable;

/**
 * Идемпотентная обработка XML-файлов СПО из папки `storage/app/spo/incoming`.
 *
 * См. CLAUDE.md, раздел "Идемпотентность обработки файлов": файл проверяется по паре
 * (имя файла, sha256-хеш содержимого) — уже обработанные файлы не парсятся повторно, ранее
 * упавшие файлы с тем же именем и содержимым переобрабатываются (retry), а то же содержимое
 * под другим именем считается самостоятельным файлом. Успешные/неуспешные файлы
 * перемещаются в соседние папки `processed`/`failed`.
 *
 * Матчинг клиента делегирован в App\Repositories\ClientRepository (точное совпадение
 * по doc_number/tax_pay_number, с fuzzy-fallback по ФИО+ДОБ для физлиц — см. CLAUDE.md,
 * раздел "Матчинг клиентов" в README.md).
 *
 * Сохранение XML (parse + persist SpoRaw) и пересчёт карточки клиента через Claude API —
 * два независимых failure domain. Файл считается успешно обработанным, если он разобран
 * и SpoRaw сохранён; сбой последующего вызова LLM (например, временная недоступность
 * api.anthropic.com) НЕ должен переводить файл в failed и НЕ должен приводить к его
 * повторной обработке — иначе при retry SpoRaw::create() выполнится повторно и создаст
 * дубликат записи для того же клиента (то же имя+хеш файла лишь сбрасывает
 * SpoFileIngestion в pending, но сам XML был бы распарсен и сохранён заново). Ошибки
 * пересчёта карточки собираются отдельно в {@see IngestionSummaryDto::$cardFailures};
 * повторный пересчёт без повторной загрузки файла — `php artisan spo:recompute-cards`.
 */
final class SpoFileIngestionService
{
    public function __construct(
        private readonly Form101Parser $parser,
        private readonly ClientRepository $clientRepository,
        private readonly EntityRegistrationService $entityRegistrationService,
    ) {}

    public function ingestFromDirectory(string $incomingPath): IngestionSummaryDto
    {
        $incomingPath = rtrim($incomingPath, '/\\');

        $processedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $failures = [];
        $cardFailures = [];

        foreach ($this->listXmlFiles($incomingPath) as $filePath) {
            $fileName = basename($filePath);
            $content = File::get($filePath);
            $fileHash = hash('sha256', $content);

            $ingestion = SpoFileIngestion::query()
                ->where('file_name', $fileName)
                ->where('file_hash', $fileHash)
                ->first();

            if ($ingestion?->status === IngestionStatus::Processed) {
                // Та же пара (имя файла, хеш содержимого) уже успешно обработана ранее —
                // пропускаем, файл остаётся в incoming нетронутым (перемещение описано
                // только для случаев успеха/ошибки обработки, см. CLAUDE.md).
                $skippedCount++;

                continue;
            }

            if ($ingestion === null) {
                // Записи с такой парой (имя, хеш) нет: либо файл совсем новый, либо это
                // то же содержимое под другим именем — составной unique-индекс
                // (file_name, file_hash) на этот случай (см. миграцию
                // replace_spo_file_ingestions_file_hash_unique_with_composite) считает
                // это самостоятельным файлом, а не дублем.
                $ingestion = SpoFileIngestion::create([
                    'file_name' => $fileName,
                    'file_hash' => $fileHash,
                    'status' => IngestionStatus::Pending,
                ]);
            } else {
                // Эта же пара (имя, хеш) ранее уже была обработана с ошибкой (failed) —
                // сбрасываем в pending и переобрабатываем (retry).
                $ingestion->fill([
                    'status' => IngestionStatus::Pending,
                    'error_message' => null,
                ])->save();
            }

            try {
                $record = $this->parser->parse($content)->withSourceFile($fileName);
                $client = $this->persist($record);

                $ingestion->fill([
                    'status' => IngestionStatus::Processed,
                    'processed_at' => now(),
                    'error_message' => null,
                ])->save();

                $this->moveFile($filePath, $incomingPath, 'processed', $fileName);
                $processedCount++;
            } catch (Throwable $e) {
                $ingestion->fill([
                    'status' => IngestionStatus::Failed,
                    'error_message' => $e->getMessage(),
                ])->save();

                $this->moveFile($filePath, $incomingPath, 'failed', $fileName);
                $failures[$fileName] = $e->getMessage();
                $failedCount++;

                continue;
            }

            // Отдельный try — см. докблок класса: сбой здесь не должен откатывать статус
            // файла на Failed и не должен вызывать повторный парсинг XML при retry.
            try {
                ComputeClientCardJob::dispatch($client->id);
            } catch (Throwable $e) {
                report($e);
                $cardFailures[$client->id] = $e->getMessage();
            }
        }

        return new IngestionSummaryDto(
            processedCount: $processedCount,
            skippedCount: $skippedCount,
            failedCount: $failedCount,
            failures: $failures,
            cardFailures: $cardFailures,
        );
    }

    /**
     * @return array<int, string>
     */
    private function listXmlFiles(string $incomingPath): array
    {
        if (! File::isDirectory($incomingPath)) {
            return [];
        }

        $files = File::glob($incomingPath.DIRECTORY_SEPARATOR.'*.xml');

        return $files === false ? [] : $files;
    }

    private function persist(SpoRecordDto $record): Client
    {
        $client = $this->findOrCreateClient($record->client);

        $spoRaw = SpoRaw::create([
            'client_id' => $client->id,
            'source_file' => $record->sourceFile,
            'transaction_date' => $record->transactionDate !== null ? Carbon::parse($record->transactionDate) : null,
            'currency' => $record->currency,
            'amount' => $record->amount,
            'amount_nc' => $record->amountNc,
            'transaction_type' => $record->transactionType,
            'transaction_subtype' => $record->transactionSubtype,
            'details' => $record->details,
            'transaction_desc' => $record->transactionDesc,
            'ground_text' => $record->groundText,
            'other_side' => $this->otherSideToArray($record->otherSide),
        ]);

        // Регистрация контрагента из структурных полей — часть домена "успешное
        // сохранение XML" (не LLM), см. докблок класса.
        $this->entityRegistrationService->registerStructuredMention($spoRaw, $record->otherSide);

        return $client;
    }

    private function findOrCreateClient(PartyDataInterface $party): Client
    {
        if ($party instanceof IndividualPartyDto) {
            return $this->clientRepository->findOrCreateIndividual($party);
        }

        if ($party instanceof LegalEntityPartyDto) {
            return $this->clientRepository->findOrCreateLegalEntity($party);
        }

        throw new LogicException('Неизвестный тип стороны: '.get_class($party));
    }

    /**
     * @return array<string, string|null>|null
     */
    private function otherSideToArray(?PartyDataInterface $otherSide): ?array
    {
        return match (true) {
            $otherSide instanceof IndividualPartyDto => [
                'party_type' => PartyType::Individual->value,
                'doc_number' => $otherSide->docNumber,
                'first_name' => $otherSide->firstName,
                'last_name' => $otherSide->lastName,
                'middle_name' => $otherSide->middleName,
                'dob' => $otherSide->dob,
            ],
            $otherSide instanceof LegalEntityPartyDto => [
                'party_type' => PartyType::LegalEntity->value,
                'tax_pay_number' => $otherSide->taxPayNumber,
                'name' => $otherSide->name,
                'leg_org_form' => $otherSide->legOrgForm,
            ],
            default => null,
        };
    }

    private function moveFile(string $filePath, string $incomingPath, string $targetSubdir, string $fileName): void
    {
        $targetDir = dirname($incomingPath).DIRECTORY_SEPARATOR.$targetSubdir;

        if (! File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        File::move($filePath, $targetDir.DIRECTORY_SEPARATOR.$fileName);
    }
}
