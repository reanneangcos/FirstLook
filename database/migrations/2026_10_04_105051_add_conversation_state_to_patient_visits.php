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
        Schema::table('patient_visits', function (Blueprint $table): void {
            $table->json('interview_state')->nullable();
        });
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->json('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
        Schema::table('patient_visits', function (Blueprint $table): void {
            $table->dropColumn('interview_state');
        });
    }
};
