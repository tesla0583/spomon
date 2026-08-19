<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Тип стороны СПО — физическое лицо или юридическое лицо.
 *
 * Определяется один раз при создании клиента (см. Client::$party_type) и не пересчитывается
 * заново по каждому XML-файлу — см. CLAUDE.md, раздел "Схема БД (MVP)".
 */
enum PartyType: string
{
    case Individual = 'individual';
    case LegalEntity = 'legal_entity';
}
