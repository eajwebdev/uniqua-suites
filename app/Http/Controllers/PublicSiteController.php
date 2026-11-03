<?php

namespace App\Http\Controllers;

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\RoomConflict;
use App\Domain\Pricing\PricingService;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Local;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Public, read-only. Serializes an explicit allow-list per room: no unit codes, operational
 * notes, secrets, guest data or booking references can reach a public page. Nothing here
 * writes anything, so a guest can only ever inquire.
 */
class PublicSiteController extends Controller
{
    public function __construct(private InventoryService $inventory, private PricingService $pricing)
    {
    }

    public function home()
    {
        $rooms = Room::published()->with(['property', 'type', 'images'])->orderBy('id')->get();

        return Inertia::render('Public/Home', [
            'rooms' => $rooms->map(fn ($r) => $this->card($r)),
            'properties' => Property::orderBy('id')->get(['name', 'slug', 'location_label']),
            'types' => RoomType::orderBy('id')->get(['name', 'slug']),
        ]);
    }

    public function suites(Request $request)
    {
        $f = $request->validate([
            'location' => 'nullable|string|max:40',
            'type' => 'nullable|string|max:40',
            'guests' => 'nullable|integer|min:1|max:20',
            'check_in' => 'nullable|date_format:Y-m-d',
            'check_out' => 'nullable|date_format:Y-m-d',
            'balcony' => 'nullable|boolean',
            'bathrooms' => 'nullable|integer|min:1|max:5',
            'max_price' => 'nullable|integer|min:0|max:1000000',
        ]);
        $hasDates = ! empty($f['check_in']) && ! empty($f['check_out']) && $f['check_in'] < $f['check_out'];

        $rooms = Room::published()->with(['property', 'type', 'images'])
            ->when($f['location'] ?? null, fn ($q, $v) => $q->whereHas('property', fn ($p) => $p->where('slug', $v)))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->whereHas('type', fn ($t) => $t->where('slug', $v)))
            ->when($f['guests'] ?? null, fn ($q, $v) => $q->where('max_guests', '>=', $v))
            ->when(! empty($f['balcony']), fn ($q) => $q->where('has_balcony', true))
            ->when($f['bathrooms'] ?? null, fn ($q, $v) => $q->where('bathrooms', '>=', $v))
            ->orderBy('id')->get();

        $cards = $rooms->map(function ($room) use ($f, $hasDates) {
            $card = $this->card($room);
            $card['availability'] = $hasDates
                ? ($this->inventory->isFree($room->id, $f['check_in'], $f['check_out']) && $room->operational_status === 'active' ? 'indicative' : 'unavailable')
                : 'unknown';
            $card['estimate_cents'] = $hasDates ? $this->estimate($room, $f['check_in'], $f['check_out'], (int) ($f['guests'] ?? 1)) : null;

            return $card;
        })->when(isset($f['max_price']), fn ($c) => $c->filter(fn ($r) => ($r['weekday_cents'] ?? 0) <= $f['max_price'] * 100)->values());

        return Inertia::render('Public/Suites', [
            'rooms' => $cards,
            'filters' => $f,
            'properties' => Property::orderBy('id')->get(['name', 'slug']),
            'types' => RoomType::orderBy('id')->get(['name', 'slug']),
        ]);
    }

    public function show(Request $request, string $slug)
    {
        $room = Room::published()->where('slug', $slug)->with(['property', 'type', 'images', 'amenities'])->firstOrFail();
        $f = $request->validate([
            'check_in' => 'nullable|date_format:Y-m-d',
            'check_out' => 'nullable|date_format:Y-m-d',
            'guests' => 'nullable|integer|min:1|max:20',
            'early' => 'nullable|date_format:H:i',
        ]);
        $hasDates = ! empty($f['check_in']) && ! empty($f['check_out']) && $f['check_in'] < $f['check_out'];
        $guests = (int) ($f['guests'] ?? 1);

        $estimate = null;
        if ($hasDates) {
            try {
                $quote = $this->pricing->quote($room, $f['check_in'], $f['check_out'], $guests, ['early_time' => $f['early'] ?? null]);
                $estimate = [
                    'lines' => array_map(fn ($l) => ['description' => $l['description'], 'amount_cents' => $l['amount_cents']], $quote['lines']),
                    'total_cents' => $quote['total_cents'],
                    'notes' => $quote['warnings'],
                ];
            } catch (RoomConflict $e) {
                $estimate = ['error' => $e->getMessage()];
            }
        }

        return Inertia::render('Public/Suite', [
            'room' => $this->card($room) + [
                'description' => $room->description,
                'inclusions' => $room->inclusions,
                'policies' => $room->policies,
                'amenities' => $room->amenities->pluck('name'),
            ],
            'filters' => $f,
            'availability' => $hasDates ? ($this->inventory->isFree($room->id, $f['check_in'], $f['check_out']) && $room->operational_status === 'active' ? 'indicative' : 'unavailable') : 'unknown',
            'estimate' => $estimate,
            'policy' => $this->policy(),
        ]);
    }

    public function policies()
    {
        return Inertia::render('Public/Policies', ['policy' => $this->policy()]);
    }

    private function policy(): array
    {
        return [
            'standard_check_in' => Settings::get('pricing.standard_check_in'),
            'standard_check_out' => Settings::get('pricing.standard_check_out'),
            'early_fee_cents_per_hour' => Settings::get('pricing.early_fee_cents_per_hour'),
        ];
    }

    private function estimate(Room $room, string $in, string $out, int $guests): ?int
    {
        try {
            return $this->pricing->quote($room, $in, $out, $guests)['total_cents'];
        } catch (RoomConflict) {
            return null;
        }
    }

    private function card(Room $room): array
    {
        $range = $this->pricing->currentRange($room, Local::today());

        return [
            'name' => $room->name,
            'slug' => $room->slug,
            'location' => $room->property->location_label,
            'location_slug' => $room->property->slug,
            'type' => $room->type->name,
            'bedrooms' => $room->bedrooms,
            'bathrooms' => $room->bathrooms,
            'ac_count' => $room->ac_count,
            'has_balcony' => $room->has_balcony,
            'included_guests' => $room->included_guests,
            'max_guests' => $room->max_guests,
            'weekday_cents' => $range['weekday_cents'] ?? null,
            'weekend_cents' => $range['weekend_cents'] ?? null,
            'images' => $room->images->map(fn ($i) => ['url' => '/storage/'.$i->path, 'alt' => $i->alt_text]),
        ];
    }
}
