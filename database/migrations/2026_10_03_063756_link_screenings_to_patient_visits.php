<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('patient_visit_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('screening_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('patient_visit_id');
        });
        // Keep nullable authors: patient-originated records must survive a rollback.
    }
};
