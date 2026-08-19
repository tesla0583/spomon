<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Нормализованная сущность (контрагент/банк/адрес/лицо) для построения графа связей клиентов.
 *
 * См. CLAUDE.md, таблица `entities`.
 */
class Entity extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'normalized_name',
        'raw_name',
        'entity_type',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'entity_type' => EntityType::class,
    ];

    /**
     * @return HasMany<EntityMention>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(EntityMention::class);
    }
}
