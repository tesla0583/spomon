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
     * Русское отображаемое название типа сущности для текстовых сообщений о связях,
     * уходящих в промпт Claude API — см.
     * {@see \App\Repositories\EntityRepository::findKnownNetworkEntityReferences()}.
     * Для отображения связи на карточке клиента и в графе используется другая,
     * более содержательная подпись — см.
     * {@see \App\Repositories\EntityRepository::findNetworkGraphEdges()}.
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
