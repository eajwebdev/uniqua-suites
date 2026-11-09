<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ready answers to questions guests ask on Messenger, kept per business (suites or cameras). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $t) {
            $t->id();
            $t->string('category', 20)->default('suites')->index();
            $t->string('topic', 60)->nullable();
            $t->string('question', 255);
            $t->text('answer');
            $t->unsignedInteger('times_copied')->default(0);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
