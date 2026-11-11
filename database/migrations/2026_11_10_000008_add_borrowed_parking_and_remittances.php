<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some stalls belong to other condo owners who lend them for a guest's stay. The guest pays for parking as usual,
 * but that money is owed to the stall's owner: it is not UNIQA's sale and is remitted to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parking_spaces', function (Blueprint $t) {
            $t->string('ownership', 20)->default('own')->after('code'); // own | borrowed
        });

        Schema::table('parking_assignments', function (Blueprint $t) {
            $t->bigInteger('remit_cents')->nullable()->after('charge_id'); // owed to the stall's owner; null when nothing is owed
            $t->string('remit_to', 120)->nullable()->after('remit_cents'); // the owner's name when assigned
            $t->date('remitted_on')->nullable()->after('remit_to');
            $t->bigInteger('remitted_cents')->nullable()->after('remitted_on');
            $t->string('remit_method', 30)->nullable()->after('remitted_cents');
            $t->string('remit_reference', 120)->nullable()->after('remit_method');
            $t->foreignId('remitted_by')->nullable()->after('remit_reference')->constrained('users')->nullOnDelete();
            $t->index('remitted_on');
        });
    }

    public function down(): void
    {
        Schema::table('parking_assignments', function (Blueprint $t) {
            $t->dropIndex(['remitted_on']);
            $t->dropConstrainedForeignId('remitted_by');
            $t->dropColumn(['remit_cents', 'remit_to', 'remitted_on', 'remitted_cents', 'remit_method', 'remit_reference']);
        });
        Schema::table('parking_spaces', function (Blueprint $t) {
            $t->dropColumn('ownership');
        });
    }
};
