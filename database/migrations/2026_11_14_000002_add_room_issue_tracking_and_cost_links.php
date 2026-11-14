<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room issues and room costs:
 * - a work order can come from a guest complaint and name the stay it came from;
 * - an expense can name the work order or damage incident it paid for, so a repair cost is
 *   recorded once and every room's monthly total adds up without double counting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $t) {
            $t->string('source')->default('staff')->after('category'); // staff | guest_complaint | inspection
            $t->foreignId('booking_id')->nullable()->after('property_id')->constrained()->nullOnDelete();
            $t->foreignId('completed_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $t->string('completion_note')->nullable()->after('completed_by');
            $t->index(['status', 'severity']);
        });

        Schema::table('expenses', function (Blueprint $t) {
            $t->foreignId('work_order_id')->nullable()->after('booking_id')->constrained()->nullOnDelete();
            $t->foreignId('incident_id')->nullable()->after('work_order_id')->constrained()->nullOnDelete();
        });

        foreach (['Damage & Replacement', 'Repairs & Maintenance'] as $name) {
            if (! DB::table('expense_categories')->where('name', $name)->exists()) {
                DB::table('expense_categories')->insert(['name' => $name]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('incident_id');
            $t->dropConstrainedForeignId('work_order_id');
        });
        Schema::table('work_orders', function (Blueprint $t) {
            $t->dropIndex(['status', 'severity']);
            $t->dropConstrainedForeignId('completed_by');
            $t->dropConstrainedForeignId('booking_id');
            $t->dropColumn(['source', 'completion_note']);
        });
    }
};
