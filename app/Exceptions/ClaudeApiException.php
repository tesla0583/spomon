<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Вызов Claude API завершился ошибкой — либо HTTP-ошибкой самого API, либо ответом,
 * который не удалось разобрать в ожидаемый tool-use формат (см. CLAUDE.md, раздел
 * "Логика вызова Claude API").
 */
final class ClaudeApiException extends RuntimeException
{
    public static function httpError(int $status, string $body): self
    {
        return new self("Claude API вернул ошибку HTTP {$status}: {$body}");
    }

    public static function unexpectedResponseFormat(string $reason): self
    {
        return new self("Неожиданный формат ответа Claude API: {$reason}");
    }
}
