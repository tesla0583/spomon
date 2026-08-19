<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статус обработки XML-файла СПО из папки входящих файлов.
 *
 * См. CLAUDE.md, таблица `spo_file_ingestions` и раздел "Идемпотентность обработки файлов":
 * `Pending` — файл обнаружен, но обработка не завершена; `Processed` — успешно разнесён по
 * таблицам и перемещён в `processed/`; `Failed` — обработка прервалась ошибкой, файл перемещён
 * в `failed/`, причина — в `error_message`.
 */
enum IngestionStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
}
