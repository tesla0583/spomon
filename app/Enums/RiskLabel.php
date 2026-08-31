<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Риск-метка клиента, присваиваемая LLM-агентом на основе истории СПО.
 *
 * Хранится в ClientCard::$risk_label как текущий (неверсионируемый) результат последнего
 * вызова Claude API — см. CLAUDE.md, раздел "Логика вызова Claude API".
 */
enum RiskLabel: string
{
    case SingleCase = 'single_case';
    case NeedsAttention = 'needs_attention';
    case RepeatingPattern = 'repeating_pattern';
    case PartOfNetwork = 'part_of_network';

    /**
     * Русское отображаемое название метки для UI.
     *
     * Значения намеренно дублируют ключи {@see \App\Services\Llm\ClaudeApiClient::FINAL_LABEL_MAP}
     * (то сопоставление идёт в обратную сторону: строка ответа LLM → enum) —
     * это единственная точка, откуда UI читает русский текст метки.
     */
    public function label(): string
    {
        return match ($this) {
            self::SingleCase => 'единичный случай',
            self::NeedsAttention => 'требует внимания',
            self::RepeatingPattern => 'явный повторяющийся паттерн',
            self::PartOfNetwork => 'часть более широкой сети',
        };
    }

    /**
     * Tailwind-классы фона/текста для бейджа метки риска.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::SingleCase => 'bg-gray-200 text-gray-800',
            self::NeedsAttention => 'bg-yellow-200 text-yellow-900',
            self::RepeatingPattern => 'bg-orange-200 text-orange-900',
            self::PartOfNetwork => 'bg-red-200 text-red-900',
        };
    }
}
