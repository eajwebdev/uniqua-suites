<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FAQ answers also appear on the public website unless staff switch one off (for example, a Messenger-only reply). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faqs', function (Blueprint $t) {
            $t->boolean('show_on_website')->default(true)->after('answer');
        });
    }

    public function down(): void
    {
        Schema::table('faqs', function (Blueprint $t) {
            $t->dropColumn('show_on_website');
        });
    }
};
