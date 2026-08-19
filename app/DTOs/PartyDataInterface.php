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
}
