<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Нормализация названий сущностей (контрагентов) перед регистрацией в реестре
 * `entities` — см. CLAUDE.md, таблица `entities` и раздел "Логика вызова Claude API"
 * (поиск связей — SQL по normalized_name, не LLM).
 *
 * Простой вариант нормализации, БЕЗ fuzzy-сравнения (см. App\Support\NameNormalizer
 * для fuzzy-матчинга клиентов — здесь сознательно другой подход): для AML-графа связей
 * ложное совпадение опаснее пропущенного. Шаги: регистр, пробелы, кавычки, отдельным
 * словом стоящие организационно-правовые формы (ООО/ОАО/ЗАО/... — список неисчерпывающий).
 *
 * Известное осознанное ограничение: различия внутри самого слова ("Алиф Бонк" vs
 * "Алиф Банк") этим не ловятся — если станет проблемой на реальных данных, добавим
 * точечный словарь синонимов позже, не сейчас.
 */
final class EntityNormalizer
{
    /**
     * Организационно-правовые формы, удаляемые как отдельные слова. Список
     * неисчерпывающий — пополняется по мере встречающихся в реальных данных форм.
     *
     * @var array<int, string>
     */
    private const LEGAL_FORMS = [
        'ооо', 'оао', 'зао', 'пао', 'ао', 'ип', 'нко', 'нпо',
        'чдмм', 'осоо', 'жшс', 'тоо', 'дп', 'гп', 'кфх',
    ];

    public static function normalize(string $name): string
    {
        $lower = mb_strtolower($name, 'UTF-8');
        $withoutQuotes = str_replace(['«', '»', '"', "'", '`'], '', $lower);
        $beforeFormStrip = trim(preg_replace('/\s+/u', ' ', $withoutQuotes) ?? $withoutQuotes);

        // Границы токена через (?<!\p{L})/(?!\p{L}), а не \b — \b в PCRE ненадёжен
        // на кириллице без явного unicode-свойства букв.
        $pattern = '/(?<!\p{L})('.implode('|', self::LEGAL_FORMS).')(?!\p{L})/u';
        $withoutForms = preg_replace($pattern, '', $beforeFormStrip) ?? $beforeFormStrip;
        $stripped = trim(preg_replace('/\s+/u', ' ', $withoutForms) ?? $withoutForms);

        // Само название — это и есть аббревиатура орг.-формы (например, просто "ООО"):
        // не теряем данные, возвращаем нормализованную (но не "ощипанную") строку.
        return $stripped !== '' ? $stripped : $beforeFormStrip;
    }
}
