<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lets managers add work to any room, alongside the turnovers that checkouts create. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('housekeeping_tasks', function (Blueprint $t) {
            $t->string('title', 120)->nullable()->after('type');
            $t->boolean('blocks_room')->default(false)->after('priority'); // room is not ready until this passes
            $t->boolean('requires_inspection')->default(true)->after('blocks_room');
            $t->foreignId('created_by')->nullable()->after('requires_inspection')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('housekeeping_tasks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn(['title', 'blocks_room', 'requires_inspection']);
        });
    }
};
