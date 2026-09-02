<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Исход обработки одного XML-файла СПО — см.
 * {@see \App\Services\Ingestion\SpoFileIngestionService::ingestFile()}.
 *
 * `Processed` — файл разобран и SpoRaw сохранён (независимо от того, удался ли последующий
 * пересчёт карточки клиента через Claude API — см. докблок класса сервиса про два независимых
 * failure domain); `Skipped` — та же пара (имя файла, хеш содержимого) уже была обработана
 * ранее; `Failed` — обработка прервалась ошибкой при парсинге/сохранении.
 */
enum SpoFileIngestOutcome: string
{
    case Processed = 'processed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
