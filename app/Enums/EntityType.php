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
}
