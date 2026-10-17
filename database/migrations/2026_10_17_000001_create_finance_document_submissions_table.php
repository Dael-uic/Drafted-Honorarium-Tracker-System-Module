<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_document_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('defense_request_id')->constrained('defense_requests')->cascadeOnDelete();
            $table->enum('document_type', ['payment_receipt', 'panel_breakdown']);
            $table->enum('status', ['locked', 'ready', 'submitted', 'verified', 'rejected'])->default('ready');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->text('finance_notes')->nullable();
            $table->date('ready_on')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['defense_request_id', 'document_type'], 'finance_doc_request_type_unique');
            $table->index(['document_type', 'status', 'ready_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_document_submissions');
    }
};
