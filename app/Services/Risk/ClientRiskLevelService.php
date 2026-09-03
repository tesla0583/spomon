<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\RiskLevel;
use App\Models\Client;
use App\Repositories\EntityRepository;

/**
 * Детерминированный (без LLM) уровень риска клиента — по чётким, объяснимым правилам,
 * а не по решению модели. Основной видимый статус клиента в реестре/карточке/графе
 * связей.
 *
 * Намеренно НЕ хранится в БД и не кэшируется — считается заново при каждом
 * отображении (реестр/карточка/граф). В отличие от бывшей LLM-метки, у этого
 * показателя нет проблемы "когда пересчитывать": запрос дешёвый и всегда должен быть
 * актуальным без отдельного триггера пересчёта.
 */
final class ClientRiskLevelService
{
    public function __construct(
        private readonly EntityRepository $entityRepository,
    ) {}

    public function calculate(Client $client): RiskLevel
    {
        $spoCount = $client->spoRaws()->count();
        $edges = $this->entityRepository->findNetworkGraphEdges($client->id);
        $distinctOtherClients = count(array_unique(array_column($edges, 'other_client_id')));
        $mentionedElsewhere = $this->entityRepository->isMentionedInAnotherClientsFreeText($client);

        return match (true) {
            $spoCount >= 4 || $mentionedElsewhere => RiskLevel::High,
            $spoCount === 1 && $distinctOtherClients === 0 => RiskLevel::Low,
            default => RiskLevel::Medium,
        };
    }
}
