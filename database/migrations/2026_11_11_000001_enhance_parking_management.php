<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parking as its own small ledger, still tied to the guest's booking:
 * - a stall has a name, an owner type (condo-owned or third-party) and a status (active, unavailable, inactive);
 * - a parking request can be recorded before a stall is confirmed ("to be assigned"), with its own fee;
 * - assignment status (requested, pending confirmation, assigned, cancelled) is separate from payment status,
 *   which comes from receipts tagged to the parking;
 * - staff can block a stall for dates (owner use, maintenance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parking_spaces', function (Blueprint $t) {
            $t->string('name', 120)->nullable()->after('code');
            $t->string('status', 20)->default('active')->after('ownership'); // active | unavailable | inactive
            $t->string('notes', 255)->nullable()->after('restrictions');
        });
        DB::table('parking_spaces')->where('is_active', false)->update(['status' => 'inactive']);
        Schema::table('parking_spaces', function (Blueprint $t) {
            $t->dropColumn('is_active');
        });

        Schema::table('parking_assignments', function (Blueprint $t) {
            $t->unsignedBigInteger('parking_space_id')->nullable()->change(); // null: to be assigned
            $t->unsignedInteger('nights')->default(0)->after('end_date');
            $t->bigInteger('rate_cents')->nullable()->after('nights');
            $t->bigInteger('fee_cents')->default(0)->after('rate_cents');
            $t->string('notes', 255)->nullable()->after('vehicle');
            $t->index(['status', 'start_date']);
        });
        // Assignments made before this change all held a stall.
        DB::table('parking_assignments')->where('status', 'active')->update(['status' => 'assigned']);
        foreach (DB::table('parking_assignments as a')->leftJoin('booking_charges as c', 'c.id', '=', 'a.charge_id')->get(['a.id', 'a.start_date', 'a.end_date', 'c.amount_cents', 'c.unit_cents', 'c.voided_at']) as $a) {
            $nights = (int) round((strtotime($a->end_date) - strtotime($a->start_date)) / 86400);
            $live = $a->amount_cents !== null && $a->voided_at === null;
            DB::table('parking_assignments')->where('id', $a->id)->update([
                'nights' => max(0, $nights),
                'rate_cents' => $live ? (int) $a->unit_cents : null,
                'fee_cents' => $live ? (int) $a->amount_cents : 0,
            ]);
        }

        Schema::table('receipts', function (Blueprint $t) {
            // A payment made for a parking fee, so parking is collected and tracked apart from the room.
            $t->foreignId('parking_assignment_id')->nullable()->after('booking_id')->constrained()->nullOnDelete();
        });

        Schema::create('parking_blocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parking_space_id')->constrained()->cascadeOnDelete();
            $t->date('start_date');
            $t->date('end_date'); // exclusive, like assignments
            $t->string('reason', 30); // owner_use | maintenance | unavailable | other
            $t->string('note', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['parking_space_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_blocks');
        Schema::table('receipts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parking_assignment_id');
        });
        DB::table('parking_assignments')->where('status', '!=', 'cancelled')->update(['status' => 'active']);
        DB::table('parking_assignments')->whereNull('parking_space_id')->delete();
        Schema::table('parking_assignments', function (Blueprint $t) {
            $t->dropIndex(['status', 'start_date']);
            $t->dropColumn(['nights', 'rate_cents', 'fee_cents', 'notes']);
            $t->unsignedBigInteger('parking_space_id')->nullable(false)->change();
        });
        Schema::table('parking_spaces', function (Blueprint $t) {
            $t->boolean('is_active')->default(true);
        });
        DB::table('parking_spaces')->where('status', 'inactive')->update(['is_active' => false]);
        Schema::table('parking_spaces', function (Blueprint $t) {
            $t->dropColumn(['name', 'status', 'notes']);
        });
    }
};
