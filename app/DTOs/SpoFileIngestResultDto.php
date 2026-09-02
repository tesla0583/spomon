<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\SpoFileIngestOutcome;

/**
 * Результат обработки одного файла {@see \App\Services\Ingestion\SpoFileIngestionService::ingestFile()}.
 */
final class SpoFileIngestResultDto
{
    public function __construct(
        public readonly string $fileName,
        public readonly SpoFileIngestOutcome $outcome,
        public readonly ?int $clientId = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $cardFailureMessage = null,
    ) {}
}
