<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Способ, которым сущность была извлечена из СПО.
 *
 * `Structured` — из структурированных полей XML (side_section и т.п.), `Ner` — распознана
 * LLM-агентом (Named Entity Recognition) из свободного текста doubt_description.
 * См. CLAUDE.md, таблица `entity_mentions` и раздел "Логика вызова Claude API".
 */
enum EntityMentionSource: string
{
    case Structured = 'structured';
    case Ner = 'ner';
}
