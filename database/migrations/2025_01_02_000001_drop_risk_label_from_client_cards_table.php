<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Старая 4-значная LLM-метка риска (`risk_label`, App\Enums\RiskLabel) удалена
 * полностью — заменена детерминированным App\Enums\RiskLevel, который не хранится
 * в БД (считается на лету, см. App\Services\Risk\ClientRiskLevelService). Текущие
 * значения в dev-БД теряются осознанно — ценности они больше не несут.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_cards', function (Blueprint $table) {
            $table->dropColumn('risk_label');
        });
    }

    public function down(): void
    {
        Schema::table('client_cards', function (Blueprint $table) {
            $table->string('risk_label')->nullable();
        });
    }
};
