<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('task_attachments', 'byte_size')) {
            Schema::table('task_attachments', function (Blueprint $table) {
                $table->unsignedInteger('byte_size')->nullable()->after('path');
                $table->string('mime_type', 50)->nullable()->after('byte_size');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_attachments', 'byte_size')) {
            Schema::table('task_attachments', function (Blueprint $table) {
                $table->dropColumn(['byte_size', 'mime_type']);
            });
        }
    }
};
