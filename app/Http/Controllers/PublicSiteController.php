<?php

namespace App\Http\Controllers;

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\StayRules;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Local;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Public, read-only. Guests browse suites, see which dates are taken, and open Facebook Messenger
 * with free dates they chose. Staff then send the quotation and make the booking. By owner decision
 * no price or estimate reaches a public page, so no pricing service is used here. Each room is
 * serialized from an explicit allow-list: no rates, unit codes, notes, secrets or guest data, and
 * taken dates carry no hint of who has them or why.
 */
class PublicSiteController extends Controller
{
    private const DAYS_SHOWN = 365;

    public function __construct(private InventoryService $inventory, private StayRules $stayRules)
    {
    }

    public function home()
    {
        $rooms = Room::published()->with(['property', 'type', 'images', 'amenities'])->orderBy('id')->get();

        return Inertia::render('Public/Home', [
            'rooms' => $rooms->map(fn ($r) => $this->card($r)),
            // Shown as "in every suite", so only what all of them really have.
            'amenities' => $rooms->isEmpty() ? [] : array_values(array_intersect(...$rooms->map(fn ($r) => $r->amenities->pluck('name')->all())->all())),
            'properties' => Property::orderBy('id')->get(['name', 'slug', 'location_label']),
        ]);
    }

    public function suites(Request $request)
    {
        $f = $request->validate([
            'location' => 'nullable|string|max:40',
            'type' => 'nullable|string|max:40',
            'guests' => 'nullable|integer|min:1|max:20',
            'balcony' => 'nullable|boolean',
            'bathrooms' => 'nullable|integer|min:1|max:5',
        ]);

        $rooms = Room::published()->with(['property', 'type', 'images'])
            ->when($f['location'] ?? null, fn ($q, $v) => $q->whereHas('property', fn ($p) => $p->where('slug', $v)))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->whereHas('type', fn ($t) => $t->where('slug', $v)))
            ->when($f['guests'] ?? null, fn ($q, $v) => $q->where('max_guests', '>=', $v))
            ->when(! empty($f['balcony']), fn ($q) => $q->where('has_balcony', true))
            ->when($f['bathrooms'] ?? null, fn ($q, $v) => $q->where('bathrooms', '>=', $v))
            ->orderBy('id')->get();

        return Inertia::render('Public/Suites', [
            'rooms' => $rooms->map(fn ($r) => $this->card($r)),
            'filters' => $f,
            'properties' => Property::orderBy('id')->get(['name', 'slug']),
            'types' => RoomType::orderBy('id')->get(['name', 'slug']),
        ]);
    }

    public function show(string $slug)
    {
        $room = Room::published()->where('slug', $slug)->with(['property', 'type', 'images', 'amenities'])->firstOrFail();

        return Inertia::render('Public/Suite', [
            'room' => $this->card($room) + [
                'inclusions' => $room->inclusions,
                'policies' => $room->policies,
                'amenities' => $room->amenities->pluck('name'),
            ],
            'policy' => $this->policy(),
        ]);
    }

    /** The final word before Book Now unlocks. The page's list could be minutes old; this reads the live inventory. */
    public function checkDates(Request $request, string $slug)
    {
        $room = Room::published()->where('slug', $slug)->firstOrFail();
        $dates = $request->validate(['check_in' => 'required|date_format:Y-m-d', 'check_out' => 'required|date_format:Y-m-d|after:check_in']);

        $problem = $this->datesProblem($room, $dates['check_in'], $dates['check_out']);

        return response()->json(['available' => $problem === null, 'message' => $problem])->header('Cache-Control', 'no-store');
    }

    private function datesProblem(Room $room, string $checkIn, string $checkOut): ?string
    {
        return match (true) {
            $checkIn < Local::today() => 'Those dates have passed. Please choose new dates.',
            $this->isClosed($room) => "{$room->name} is not available right now.",
            ! $this->inventory->isFree($room->id, $checkIn, $checkOut) => "{$room->name} was just taken for some of those dates. Please choose other dates.",
            default => $this->stayRules->violation($room, $checkIn, $checkOut),
        };
    }

    private function isClosed(Room $room): bool
    {
        return $room->operational_status !== 'active' || $room->hasOpenSafetyBlock();
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
            'house_rules' => Settings::get('policy.house_rules'),
            'cancellation' => Settings::get('policy.cancellation'),
        ];
    }

    private function card(Room $room): array
    {
        return [
            'name' => $room->name,
            'slug' => $room->slug,
            'location' => $room->property->location_label,
            'type' => $room->type->name,
            'description' => $room->description,
            'closed' => $this->isClosed($room),
            'unavailable' => $this->inventory->takenRanges($room->id, Local::today(), Local::now()->addDays(self::DAYS_SHOWN)->toDateString()),
            'bedrooms' => $room->bedrooms,
            'bathrooms' => $room->bathrooms,
            'ac_count' => $room->ac_count,
            'has_balcony' => $room->has_balcony,
            'max_guests' => $room->max_guests,
            'images' => $room->images->map(fn ($i) => ['url' => '/storage/'.$i->path, 'alt' => $i->alt_text]),
        ];
    }
}
