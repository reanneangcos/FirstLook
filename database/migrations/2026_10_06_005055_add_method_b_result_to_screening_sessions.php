<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('screening_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('method_b_priority')->nullable();
            $table->string('method_b_rule_version')->nullable();
            $table->json('method_b_input')->nullable();
            $table->json('method_b_result')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('screening_sessions', function (Blueprint $table) {
            $table->dropColumn(['method_b_priority', 'method_b_rule_version', 'method_b_input', 'method_b_result']);
        });
    }
};
