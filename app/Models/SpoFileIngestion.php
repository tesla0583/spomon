<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngestionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Учёт обработки XML-файлов СПО — идемпотентность (файл не парсится повторно).
 *
 * См. CLAUDE.md, раздел "Идемпотентность обработки файлов".
 */
class SpoFileIngestion extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'file_name',
        'file_hash',
        'status',
        'error_message',
        'processed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => IngestionStatus::class,
        'processed_at' => 'datetime',
    ];
}
