<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Одна распарсенная запись СПО из XML `form_101`, до записи в БД.
 *
 * `transactionDate` остаётся строкой на уровне DTO — приведение к дате (Carbon)
 * происходит в сервисе, который пишет запись в БД (см. SpoFileIngestionService).
 *
 * `sourceFile` парсеру неизвестен (он видит только содержимое XML), поэтому парсер
 * создаёт DTO с `sourceFile === null`, а вызывающий сервис проставляет имя файла
 * через {@see self::withSourceFile()} — DTO остаётся иммутабельным.
 */
final class SpoRecordDto
{
    public function __construct(
        public readonly ?string $sourceFile,
        public readonly ?string $transactionDate,
        public readonly ?string $currency,
        public readonly ?float $amount,
        public readonly ?float $amountNc,
        public readonly ?string $transactionType,
        public readonly ?string $transactionSubtype,
        public readonly ?string $details,
        public readonly ?string $transactionDesc,
        public readonly ?string $groundText,
        public readonly bool $isSuspicious,
        public readonly PartyDataInterface $client,
        public readonly ?PartyDataInterface $otherSide,
    ) {}

    public function withSourceFile(string $sourceFile): self
    {
        return new self(
            sourceFile: $sourceFile,
            transactionDate: $this->transactionDate,
            currency: $this->currency,
            amount: $this->amount,
            amountNc: $this->amountNc,
            transactionType: $this->transactionType,
            transactionSubtype: $this->transactionSubtype,
            details: $this->details,
            transactionDesc: $this->transactionDesc,
            groundText: $this->groundText,
            isSuspicious: $this->isSuspicious,
            client: $this->client,
            otherSide: $this->otherSide,
        );
    }
}
