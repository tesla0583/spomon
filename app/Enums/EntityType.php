<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Тип нормализованной сущности в реестре контрагентов (для построения графа связей).
 *
 * См. CLAUDE.md, таблица `entities`.
 */
enum EntityType: string
{
    case Organization = 'organization';
    case Bank = 'bank';
    case Person = 'person';
    case Address = 'address';
    case Unknown = 'unknown';

    /**
     * Русское отображаемое название типа сущности для UI/текстовых сообщений о связях
     * — см. {@see \App\Repositories\EntityRepository::findKnownNetworkEntityReferences()}
     * и {@see \App\Livewire\ClientCardPage::networkReferences()}.
     */
    public function displayLabel(): string
    {
        return match ($this) {
            self::Address => 'адрес',
            self::Bank => 'банк',
            self::Organization, self::Person, self::Unknown => 'контрагент',
        };
    }
}
