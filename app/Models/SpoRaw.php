<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Распарсенная запись одного СПО (одна транзакция/сообщение о подозрительной операции).
 *
 * Источник — XML-файл form_101. См. CLAUDE.md, раздел "Структура XML СПО — важные решения".
 */
class SpoRaw extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'spo_raw';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'source_file',
        'transaction_date',
        'currency',
        'amount',
        'amount_nc',
        'transaction_type',
        'transaction_subtype',
        'details',
        'transaction_desc',
        'ground_text',
        'other_side',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'amount_nc' => 'decimal:2',
        'other_side' => 'array',
    ];

    /**
     * @return BelongsTo<Client, SpoRaw>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<EntityMention>
     */
    public function entityMentions(): HasMany
    {
        return $this->hasMany(EntityMention::class);
    }
}
