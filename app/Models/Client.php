<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PartyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Уникальный клиент банка — субъект карточки риска.
 *
 * Один Client объединяет все СПО, относящиеся к одному физическому/юридическому лицу
 * (сматченные по doc_number/tax_pay_number). См. CLAUDE.md, раздел "Схема БД (MVP)".
 */
class Client extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'party_type',
        'doc_number',
        'tax_pay_number',
        'first_name',
        'last_name',
        'middle_name',
        'dob',
        'full_name',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'party_type' => PartyType::class,
        'dob' => 'date',
    ];

    /**
     * @return HasMany<SpoRaw>
     */
    public function spoRaws(): HasMany
    {
        return $this->hasMany(SpoRaw::class);
    }

    /**
     * @return HasMany<EntityMention>
     */
    public function entityMentions(): HasMany
    {
        return $this->hasMany(EntityMention::class);
    }

    /**
     * @return HasOne<ClientCard>
     */
    public function card(): HasOne
    {
        return $this->hasOne(ClientCard::class);
    }
}
