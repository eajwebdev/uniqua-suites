<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Corrects register text already seeded on existing installs. Status and resolution are left alone. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('owner_decisions')->where('key', 'messenger_url')->update([
            'detail' => 'None were supplied. The website exists to send guests to Facebook Messenger, so its Message us button stays hidden until the link is set in the environment file.',
        ]);
    }

    public function down(): void
    {
    }
};
