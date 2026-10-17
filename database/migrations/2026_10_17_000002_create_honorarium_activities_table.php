<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('honorarium_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('defense_request_id')->nullable()->constrained('defense_requests')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finance_document_submission_id')->nullable()->constrained('finance_document_submissions')->nullOnDelete();
            $table->string('activity_type', 60);
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['occurred_at', 'activity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('honorarium_activities');
    }
};
