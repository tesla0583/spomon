<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Детерминированный (без участия LLM) уровень риска клиента — считается кодом по
 * чётким правилам, см. {@see \App\Services\Risk\ClientRiskLevelService}. Основной
 * видимый статус клиента в реестре/карточке/графе связей.
 */
enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Русское отображаемое название уровня для UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Low => 'Низкий',
            self::Medium => 'Средний',
            self::High => 'Высокий',
        };
    }

    /**
     * Tailwind-классы фона/текста для бейджа уровня риска.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Low => 'bg-green-200 text-green-900',
            self::Medium => 'bg-yellow-200 text-yellow-900',
            self::High => 'bg-red-200 text-red-900',
        };
    }
}
