<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заменяет unique-индекс на одном file_hash составным unique-индексом (file_name, file_hash).
 *
 * По ревью Этапа 2: одинаковое содержимое под РАЗНЫМИ именами файлов — это два разных файла
 * (например, СПО с одинаковыми полями за один день), а не дубль, поэтому идемпотентность
 * должна проверяться по паре (file_name, file_hash), а не по одному только хешу содержимого.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spo_file_ingestions', function (Blueprint $table) {
            $table->dropUnique('spo_file_ingestions_file_hash_unique');
            $table->unique(['file_name', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('spo_file_ingestions', function (Blueprint $table) {
            $table->dropUnique(['file_name', 'file_hash']);
            $table->unique('file_hash');
        });
    }
};
