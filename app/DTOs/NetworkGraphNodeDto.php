<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Один узел графа связей клиента — либо сам клиент, чью карточку сейчас смотрят
 * (`isFocus = true`), либо другой клиент, с которым найдена общая сущность
 * (см. {@see \App\Services\Entities\ClientNetworkGraphService::buildGraph()}).
 *
 * `riskLevel` — строковое значение App\Enums\RiskLevel, всегда определено (в отличие
 * от прежней LLM-метки, детерминированный уровень риска не зависит от наличия
 * ClientCard) — namespaced enum здесь не используется, чтобы DTO без проблем
 * разворачивался в плоский JSON-массив для ECharts на фронтенде.
 */
final class NetworkGraphNodeDto
{
    public function __construct(
        public readonly int $clientId,
        public readonly string $label,
        public readonly string $riskLevel,
        public readonly bool $isFocus,
    ) {}
}
