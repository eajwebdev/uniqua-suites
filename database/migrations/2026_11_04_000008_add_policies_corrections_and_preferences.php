<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $t) {
            $t->unsignedSmallInteger('min_nights')->nullable(); // null: property-wide setting applies
            $t->unsignedSmallInteger('max_advance_days')->nullable();
        });

        Schema::create('tax_rules', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->unsignedInteger('rate_basis_points'); // 12.00% = 1200, so no float reaches the money maths
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['effective_from', 'effective_to']);
        });

        // Unique: an entry can be reversed once. The reversal is a new row dated in an open month.
        Schema::table('expenses', function (Blueprint $t) {
            $t->foreignId('reverses_expense_id')->nullable()->unique()->constrained('expenses')->restrictOnDelete();
            $t->string('reversal_reason')->nullable();
        });
        Schema::table('receipts', function (Blueprint $t) {
            $t->foreignId('reverses_receipt_id')->nullable()->unique()->constrained('receipts')->restrictOnDelete();
        });

        Schema::create('incident_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 30); // internal | messenger | instagram | phone | email | in_person
            $t->text('body');
            $t->timestamp('created_at')->nullable();
            $t->index(['incident_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->text('muted_notifications')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('muted_notifications'));
        Schema::dropIfExists('incident_notes');
        Schema::table('receipts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reverses_receipt_id');
        });
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reverses_expense_id');
            $t->dropColumn('reversal_reason');
        });
        Schema::dropIfExists('tax_rules');
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn(['min_nights', 'max_advance_days']));
    }
};
