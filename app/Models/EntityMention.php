<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntityMentionSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Упоминание сущности в конкретном СПО конкретного клиента.
 *
 * client_id денормализован относительно spo_raw_id для быстрых джойнов при поиске пересечений
 * в графе связей — см. CLAUDE.md, раздел "Логика вызова Claude API" (поиск связей — SQL, не LLM).
 */
class EntityMention extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'entity_id',
        'client_id',
        'spo_raw_id',
        'source',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'source' => EntityMentionSource::class,
    ];

    /**
     * @return BelongsTo<Entity, EntityMention>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * @return BelongsTo<Client, EntityMention>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<SpoRaw, EntityMention>
     */
    public function spoRaw(): BelongsTo
    {
        return $this->belongsTo(SpoRaw::class);
    }
}
