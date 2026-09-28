<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            // What the customer submitted (kept verbatim for the audit trail).
            $table->string('submitted_email');
            $table->string('submitted_order_number');
            $table->text('message');

            // Outcome.
            $table->string('decision')->nullable()->index();   // approved | denied | escalated
            $table->string('status')->index();                 // processing | resolved | pending_review | reviewed | failed
            $table->string('reason_category')->nullable();
            $table->decimal('refund_amount', 10, 2)->default(0);
            $table->json('item_ids')->nullable();          // items refunded (approved) or proposed (escalated)
            $table->text('customer_reply')->nullable();

            // Decision support + explainability.
            $table->json('classification')->nullable();
            $table->json('policy_evaluation')->nullable();
            $table->json('risk_flags')->nullable();
            $table->string('ai_mode')->nullable();             // llm | fallback
            $table->string('ai_model')->nullable();

            // Human-in-the-loop.
            $table->string('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->text('resolution_message')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('refund_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_request_id')->constrained()->cascadeOnDelete();
            $table->string('stage');   // received, verification, injection_scan, classification, policy, reply, review, error
            $table->string('actor');   // system | ai | policy_engine | human
            $table->string('summary');
            $table->json('data')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_audit_events');
        Schema::dropIfExists('refund_requests');
    }
};
