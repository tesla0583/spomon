<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Нормализация ФИО перед сравнением через similar_text() в fuzzy-матчинге клиентов
 * (см. App\Repositories\ClientRepository).
 *
 * Только регистр и пробелы — без транслитерации: в реальных данных ФИО всегда
 * в кириллице, транслитерация не нужна.
 */
final class NameNormalizer
{
    public static function normalize(string $name): string
    {
        $lower = mb_strtolower($name, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $lower) ?? $lower;

        return trim($collapsed);
    }
}
