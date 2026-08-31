<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\EntityNormalizer;
use PHPUnit\Framework\TestCase;

final class EntityNormalizerTest extends TestCase
{
    public function test_case_and_whitespace_differences_normalize_to_same_result(): void
    {
        self::assertSame(
            'алиф банк',
            EntityNormalizer::normalize('  АЛИФ    Банк  '),
        );
    }

    public function test_all_kinds_of_quotes_are_removed(): void
    {
        $expected = 'компания';

        self::assertSame($expected, EntityNormalizer::normalize('«Компания»'));
        self::assertSame($expected, EntityNormalizer::normalize('"Компания"'));
        self::assertSame($expected, EntityNormalizer::normalize("'Компания'"));
        self::assertSame($expected, EntityNormalizer::normalize('`Компания`'));
    }

    public function test_different_legal_org_forms_normalize_to_the_same_name(): void
    {
        // ЧДММ (таджикская/киргизская форма ООО) и ООО с кавычками — после нормализации
        // должны схлопнуться в одно и то же название, иначе граф связей не найдёт
        // пересечение по одному и тому же контрагенту, записанному в разных СПО по-разному.
        self::assertSame(
            EntityNormalizer::normalize('ЧДММ Компания'),
            EntityNormalizer::normalize('ООО «Компания»'),
        );
    }

    public function test_name_that_is_only_a_legal_form_is_not_lost(): void
    {
        // Само название — и есть аббревиатура орг.-формы: удалять всё нельзя, теряем данные.
        self::assertSame('ооо', EntityNormalizer::normalize('ООО'));
    }

    public function test_empty_string_normalizes_to_empty_string(): void
    {
        self::assertSame('', EntityNormalizer::normalize(''));
    }

    public function test_alif_bonk_and_alif_bank_remain_different(): void
    {
        // Осознанное ограничение: нормализация без fuzzy-сравнения не ловит различия
        // внутри самого слова ("Бонк" vs "Банк"). Для AML-графа ложное совпадение
        // опаснее пропущенного — если станет проблемой на реальных данных, добавим
        // точечный словарь синонимов позже.
        self::assertNotSame(
            EntityNormalizer::normalize('Алиф Бонк'),
            EntityNormalizer::normalize('Алиф Банк'),
        );
    }
}
