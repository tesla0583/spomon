<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PartyType;

/**
 * Общий контракт для стороны СПО — физлица ({@see IndividualPartyDto})
 * или юрлица ({@see LegalEntityPartyDto}).
 */
interface PartyDataInterface
{
    public function partyType(): PartyType;

    /**
     * Физический адрес стороны (`side_section.address`), если был указан. Используется
     * для регистрации сущности-адреса в графе связей — см.
     * {@see \App\Services\Entities\EntityRegistrationService::registerAddressMention()}.
     */
    public function address(): ?string;
}
