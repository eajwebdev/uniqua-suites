<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One warehouse holds all supplies. A purchase adds to it (no property), a stock-out to a suite takes
 * from it and names the suite. Stock already recorded per property simply counts toward the warehouse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->unsignedBigInteger('property_id')->nullable()->change();
            $t->bigInteger('unit_cost_cents')->nullable()->after('quantity');
            $t->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropIndex(['room_id', 'created_at']);
            $t->dropColumn('unit_cost_cents');
        });
    }
};
