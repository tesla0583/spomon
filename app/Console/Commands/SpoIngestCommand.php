<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ingestion\SpoFileIngestionService;
use Illuminate\Console\Command;

/**
 * Обрабатывает XML-файлы СПО (form_101) из storage/app/spo/incoming.
 *
 * См. README.md, раздел "Обработка СПО".
 */
class SpoIngestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'spo:ingest';

    /**
     * @var string
     */
    protected $description = 'Разобрать XML-файлы СПО (form_101) из storage/app/spo/incoming и загрузить их в БД';

    public function handle(SpoFileIngestionService $service): int
    {
        $summary = $service->ingestFromDirectory(storage_path('app/spo/incoming'));

        $this->info(sprintf(
            'Обработано: %d. Пропущено (уже обработаны ранее): %d. Ошибок: %d.',
            $summary->processedCount,
            $summary->skippedCount,
            $summary->failedCount,
        ));

        if ($summary->failures !== []) {
            $this->newLine();
            $this->error('Файлы, обработка которых завершилась ошибкой (перемещены в storage/app/spo/failed):');

            foreach ($summary->failures as $fileName => $errorMessage) {
                $this->line(sprintf('  - %s: %s', $fileName, $errorMessage));
            }
        }

        return $summary->failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
