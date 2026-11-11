<?php

use App\Models\OwnerDecision;
use App\Models\RateRule;
use App\Models\Room;
use App\Support\Local;
use App\Support\OwnerDefaults;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's booking calculator is the current source for these. Existing installs get the new
 * values only where nobody has already changed them. Fresh installs get them from the seeders.
 */
return new class extends Migration
{
    private const OLD_QUOTE_TEMPLATE = "Hi {{guest}}, here is your quotation for {{room}}:\n{{check_in}} to {{check_out}}, {{guests}} guests\nTotal: {{total}}\nThis quotation does not reserve the room until we confirm a hold or booking.";

    public function up(): void
    {
        // Kept per quote, so changing the setting later cannot rewrite what a guest was already sent.
        Schema::table('quotes', function (Blueprint $t) {
            $t->unsignedTinyInteger('downpayment_percent')->nullable()->after('total_cents');
        });

        $milan = Room::where('slug', 'milan')->first();
        if ($milan) {
            // A new rule, never an edit: earlier stays keep the rate they were priced at.
            RateRule::firstOrCreate(['room_id' => $milan->id, 'effective_from' => Local::today()], [
                'weekday_cents' => 260000,
                'weekend_cents' => 280000,
                'source_note' => 'Owner\'s booking calculator, November 2026',
            ]);
        }

        if (Settings::get('pricing.standard_check_out') === '12:00') {
            Settings::set('pricing.standard_check_out', '11:00');
        }

        foreach (['standard_check_out', 'flyer_differences'] as $key) {
            OwnerDecision::where('key', $key)->update(['resolution' => OwnerDefaults::RESOLUTIONS[$key]]);
        }

        // The table, not the model: later the model gains soft deletes, whose column does not exist yet here.
        DB::table('message_templates')->where('key', 'quote')->where('body', self::OLD_QUOTE_TEMPLATE)->update(['body' => OwnerDefaults::QUOTE_TEMPLATE]);
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn('downpayment_percent'));
    }
};
