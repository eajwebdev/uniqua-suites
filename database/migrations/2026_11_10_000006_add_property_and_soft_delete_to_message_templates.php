<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message template can be for one property only (The Loop or Mesaverte); none means every property of its business.
 * Deleted templates are kept out of sight rather than erased, so re-running the seeders does not bring them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $t) {
            $t->foreignId('property_id')->nullable()->after('body')->constrained()->nullOnDelete();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $t) {
            $t->dropConstrainedForeignId('property_id');
            $t->dropSoftDeletes();
        });
    }
};
