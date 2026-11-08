<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The system's own monthly bills. Each covers one month from the subscription start; marking one
 * paid moves the paid-through date to its end and creates the next month's bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_bills', function (Blueprint $table) {
            $table->id();
            $table->date('period_start')->unique();
            $table->date('period_end');
            $table->date('due_on');
            $table->unsignedInteger('amount_cents');
            $table->string('status', 10)->default('unpaid'); // unpaid | paid
            $table->date('paid_on')->nullable();
            $table->unsignedInteger('paid_amount_cents')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_bills');
    }
};
