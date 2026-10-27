<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Method C is a separate LLM-only request; its attempts share the attempts table under their own method tag.
     */
    public function up(): void
    {
        Schema::table('screening_sessions', function (Blueprint $table): void {
            $table->string('method_c_status')->default('not_evaluated');
            $table->unsignedTinyInteger('method_c_priority')->nullable();
            $table->json('method_c_result')->nullable();
        });
        Schema::table('screening_attempts', function (Blueprint $table): void {
            $table->string('method', 1)->default('a');
            $table->dropUnique(['screening_session_id', 'number']);
            $table->unique(['screening_session_id', 'method', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('screening_attempts', function (Blueprint $table): void {
            $table->dropUnique(['screening_session_id', 'method', 'number']);
            $table->unique(['screening_session_id', 'number']);
            $table->dropColumn('method');
        });
        Schema::table('screening_sessions', function (Blueprint $table): void {
            $table->dropColumn(['method_c_status', 'method_c_priority', 'method_c_result']);
        });
    }
};
