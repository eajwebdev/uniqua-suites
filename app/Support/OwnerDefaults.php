<?php

namespace App\Support;

use App\Models\OwnerDecision;
use App\Models\Room;

/**
 * The setup decisions, settled from the room list, flyers and sales workbooks so nobody has to approve
 * them one by one. Everything stays changeable under Settings. Safe to repeat: a value someone has
 * already chosen, or a decision already recorded, is left alone.
 */
class OwnerDefaults
{
    public const QUOTE_TEMPLATE = "Hi {{guest}}, here is your quotation.\n\nUNIQA SUITES CDO BOOKING QUOTATION\n\nRoom: {{room}}\nCheck-in: {{check_in}}\nCheck-out: {{check_out}}\nDuration: {{nights}} night(s)\nGuests: {{guests}}\n\nRATE BREAKDOWN\n{{breakdown}}\n\nTOTAL: {{total}}\n{{payment}}\n\nThis quotation does not reserve the room until we confirm a hold or booking.";

    public const RESOLUTIONS = [
        'weekend_definition' => 'Friday and Saturday nights use the weekend rate, as the suite flyers state.',
        'extra_guest_unit' => '₱200 per extra guest, per night, as the suite flyers state.',
        'standard_check_out' => 'Check-out is 11:00 AM, as the owner\'s booking calculator states, three hours before the 2:00 PM check-in. No late check-out fee is charged automatically: staff add it as a charge when they agree one with a guest.',
        'early_fee' => '₱200 per started hour before 2:00 PM, as the project brief states.',
        'hold_expiry' => 'Holds last 24 hours, then release their dates automatically.',
        'laundry_policy' => 'A laundry job is suggested at every checkout. Quantity and cost are entered per job.',
        'payout_handling' => 'Airbnb bookings are entered here by hand. Payouts are recorded under Channel payouts when they reach the BPI account, because the workbooks show BPI as the payout route.',
        'room_2221_identity' => 'Treated as a former separate unit. Its 2026 sales stay in the workbook history and are not merged into BALI or NEW YORK, because a wrong merge would misstate those rooms.',
        'room_2510_2520' => 'The same unit: BALI 2520. Every other sheet uses 2520, so 2510 is treated as a typing error on the parking calendar.',
        'room_14q_location' => 'BERLIN 14Q is at Mesaverte, as the room list and flyers state.',
        'capacity_history' => 'New bookings use the flyer capacities. Past sales keep the guest counts they were recorded with.',
        'messenger_url' => 'The official Facebook page and Messenger link are in use. Instagram, phone and email stay hidden until they are added.',
        'room_photos' => 'The supplied photos are in use. RIO and VENICE keep their flyer photos until HD photos are added.',
        'address_details' => 'Only the building names are published until a street address and map link are added.',
        'cutover_date' => 'The go-live day. Before that day, enter every future Airbnb booking here so that all rooms show correctly.',
        'tax_rules' => 'No tax or fee is added to stays. If one applies, add it under Settings with its start date.',
        'stay_rules' => 'No minimum stay or booking window, because none of the source documents set one.',
        'guest_policies' => 'Nothing is published until the wording is written under Settings, because none of the source documents state these terms.',
        'ical_future' => 'Not used. Airbnb bookings are transferred by hand, as new bookings with the source set to Airbnb.',
        'public_no_prices' => 'In use: guests see taken dates only, and staff send prices in Messenger.',
        'source_room_catalog' => 'In use: nine rooms from the room list, with current details from the flyers.',
        'flyer_differences' => 'The flyers are used as the newer source. NEW YORK is shown on the 22nd floor, because its unit is 2224 and every other numbered unit\'s first digits are its floor. Its flyer\'s 5th floor matches PARIS, so it looks copied. MILAN is ₱2,600 weekday and ₱2,800 weekend, as the owner\'s booking calculator states.',
    ];

    public static function apply(): void
    {
        foreach (['pricing.weekend_days' => [5, 6], 'pricing.extra_guest_unit' => 'per_night', 'pricing.standard_check_out' => '11:00'] as $key => $value) {
            if (Settings::get($key) === null) {
                Settings::set($key, $value);
            }
        }

        foreach (self::RESOLUTIONS as $key => $resolution) {
            OwnerDecision::where('key', $key)->where('status', 'pending')->update(['status' => 'approved', 'resolution' => $resolution, 'decided_at' => now()]);
        }

        // Only while it still shows the flyer's value, so a later correction by staff is kept.
        $newYork = Room::where('slug', 'new-york')->where('floor', '5')->first();
        $newYork?->update(['floor' => '22', 'description' => str_replace('5th floor', '22nd floor', (string) $newYork->description)]);
    }
}
