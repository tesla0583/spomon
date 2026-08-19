<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RiskLabel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Текущий (неверсионируемый) результат работы LLM-агента по клиенту.
 *
 * Пересчитывается только когда history_fingerprint истории СПО клиента изменился —
 * см. CLAUDE.md, раздел "Логика вызова Claude API".
 */
class ClientCard extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'risk_label',
        'summary',
        'pattern_notes',
        'network_signal',
        'llm_raw_response',
        'history_fingerprint',
        'computed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'risk_label' => RiskLabel::class,
        'llm_raw_response' => 'array',
        'computed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Client, ClientCard>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
