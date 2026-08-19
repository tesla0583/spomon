<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spo_raw', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('source_file');
            $table->date('transaction_date')->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('amount_nc', 15, 2)->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('transaction_subtype')->nullable();
            $table->string('details')->nullable();
            $table->text('transaction_desc')->nullable();
            $table->text('ground_text')->nullable();
            $table->json('other_side')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spo_raw');
    }
};
