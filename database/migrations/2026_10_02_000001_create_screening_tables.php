<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('dataset_case_id', 80)->nullable()->index();
            $table->string('variant_id', 80)->nullable();
            $table->string('language');
            $table->json('original_input');
            $table->json('patient_input');
            $table->string('processing_status')->default('processing')->index();
            $table->string('method_a_status')->nullable();
            $table->unsignedTinyInteger('method_a_priority')->nullable();
            $table->string('method_b_status')->default('not_implemented');
            $table->string('requested_model');
            $table->string('returned_model')->nullable();
            $table->string('prompt_version');
            $table->text('prompt_text');
            $table->json('request_settings');
            $table->longText('original_response')->nullable();
            $table->json('parsed_output')->nullable();
            $table->json('token_usage')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('is_fixture')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('screening_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('screening_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->string('status');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->longText('original_response')->nullable();
            $table->json('parsed_output')->nullable();
            $table->string('failure_code')->nullable();
            $table->unsignedInteger('latency_ms');
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->unique(['screening_session_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_attempts');
        Schema::dropIfExists('screening_sessions');
    }
};
