<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind'); // cash | gcash | bank | other
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamp('received_at');
            $t->date('receipt_date'); // Manila-local reporting date
            $t->bigInteger('amount_cents');
            $t->string('method'); // cash | gcash | bank_transfer | other. Channel payouts live in external_settlements.
            $t->foreignId('payment_account_id')->nullable()->constrained()->nullOnDelete();
            $t->string('external_reference')->nullable();
            $t->string('evidence_path')->nullable();
            $t->string('verification_status')->default('pending'); // pending | verified | rejected
            $t->string('review_note')->nullable();
            $t->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['verification_status', 'receipt_date']);
            $t->index(['booking_id', 'verification_status']);
            $t->index('external_reference');
        });

        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->restrictOnDelete();
            $t->foreignId('receipt_id')->nullable()->constrained()->nullOnDelete();
            $t->bigInteger('amount_cents');
            $t->string('reason');
            $t->string('status')->default('requested'); // requested | approved | executed | rejected
            $t->date('executed_on')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'executed_on']);
        });

        Schema::create('external_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('provider');
            $t->string('payout_reference')->nullable();
            $t->bigInteger('gross_cents');
            $t->bigInteger('fees_cents')->default(0);
            $t->bigInteger('adjustments_cents')->default(0);
            $t->bigInteger('expected_net_cents');
            $t->bigInteger('actual_payout_cents')->nullable();
            $t->date('payout_date')->nullable();
            $t->foreignId('payment_account_id')->nullable()->constrained()->nullOnDelete();
            $t->string('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['provider', 'payout_reference', 'booking_id'], 'settlement_dedupe');
            $t->index('payout_date');
        });

        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });

        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('contact')->nullable();
            $t->string('kind')->nullable();
            $t->timestamps();
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $t->foreignId('property_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->bigInteger('amount_cents');
            $t->date('expense_date');
            $t->string('status')->default('incurred'); // incurred | paid
            $t->date('paid_on')->nullable();
            $t->string('payment_method')->nullable();
            $t->string('proof_path')->nullable();
            $t->string('recurrence')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['expense_date', 'property_id']);
            $t->index(['room_id', 'expense_date']);
        });

        Schema::create('period_locks', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7)->unique(); // YYYY-MM
            $t->foreignId('locked_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('locked_at');
            $t->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['period_locks', 'expenses', 'vendors', 'expense_categories', 'external_settlements', 'refunds', 'receipts', 'payment_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
