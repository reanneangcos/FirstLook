<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_visits', function (Blueprint $table): void {
            $table->id();
            $table->string('stub_number')->nullable()->unique();
            $table->string('language');
            $table->string('status')->default('collecting')->index();
            $table->unsignedTinyInteger('question_index')->default(0);
            $table->json('answers');
            $table->timestamp('scope_confirmed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_visits');
    }
};
