<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('risk_label')->nullable();
            $table->text('summary')->nullable();
            $table->text('pattern_notes')->nullable();
            $table->text('network_signal')->nullable();
            $table->json('llm_raw_response')->nullable();
            $table->string('history_fingerprint', 64)->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_cards');
    }
};
