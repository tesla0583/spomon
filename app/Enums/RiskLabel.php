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
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
