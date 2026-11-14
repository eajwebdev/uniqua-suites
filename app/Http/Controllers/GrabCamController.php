<?php

namespace App\Http\Controllers;

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\RoomConflict;
use App\Domain\Bookings\RentalPeriod;
use App\Domain\Pricing\CameraPricing;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Local;
use App\Support\Seo;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * Grab Cam's public page, kept apart from the Uniqa suites site and safe to embed in an iframe.
 * Renters browse camera models, see the rate card and the days a model is fully booked, then
 * message Grab Cam on Facebook. Staff assign the exact unit when they make the booking.
 * Serialized from an allow-list: no unit codes, notes or renter data.
 */
class GrabCamController extends Controller
{
    private const DAYS_SHOWN = 365;

    public const DEFAULT_IMAGE = '/brand/grabcam-default.svg';

    public function __construct(private InventoryService $inventory, private CameraPricing $pricing)
    {
    }

    public function index()
    {
        $today = Local::today();
        $until = Local::now()->addDays(self::DAYS_SHOWN)->toDateString();
        $units = $this->units()->get()->groupBy('room_type_id');

        $models = RoomType::whereIn('id', $units->keys())->orderBy('id')->get()->map(function (RoomType $type) use ($units, $today, $until) {
            $mine = $units[$type->id];
            $open = $mine->reject(fn ($r) => $this->isClosed($r));
            $first = $mine->first();

            return [
                'name' => $type->name,
                'slug' => $type->slug,
                'brand' => $type->brand,
                'specs' => $first->specs,
                'description' => $first->description,
                'units' => $mine->count(),
                'closed' => $open->isEmpty(),
                // Until staff upload photos (Cameras > open a unit), every camera shows the same stand-in.
                'images' => $mine->flatMap->images->map(fn ($i) => ['url' => '/storage/'.$i->path, 'alt' => $i->alt_text, 'default' => false])->values()
                    ->whenEmpty(fn ($none) => $none->push(['url' => self::DEFAULT_IMAGE, 'alt' => "{$type->name}: photo coming soon", 'default' => true])),
                'rates' => $rates = $this->pricing->card($type->id, $today),
                'free_day_from' => (int) ($rates[0]['free_day_from'] ?? 0),
                // When every unit is out, to the minute, so renters see the times they cannot book, not only whole days.
                'busy' => $busy = $open->isEmpty() ? [] : $this->allBusy($open, $today, $until),
                // Whole days with no free minute: only these are crossed out.
                'unavailable' => $open->isEmpty() ? [] : $this->fullDays($busy),
            ];
        })->values();

        return Seo::attach(Inertia::render('Public/GrabCam', [
            'models' => $models,
            'brands' => $models->pluck('brand')->filter()->unique()->values(),
            'grabcam' => ['messenger_url' => config('uniqa.grabcam.messenger_url'), 'facebook_url' => config('uniqa.grabcam.facebook_url')],
            'faqs' => \App\Models\Faq::forWebsite(\App\Models\Property::CAMERAS),
        ]), $this->seo($models));
    }

    /** Search and link-preview data: a camera rental business in Cagayan de Oro and its per-day prices. */
    private function seo(Collection $models): array
    {
        $lowest = $models->flatMap(fn ($m) => collect($m['rates'])->pluck('daily_cents'))->min();
        $names = $models->pluck('name')->take(4)->implode(', ');
        $description = "Rent a camera in Cagayan de Oro from Grab Cam Rental: {$names} and more"
            .($lowest ? ', from ₱'.number_format($lowest / 100).' a day' : '').'. See rates and free days, then inquire on Messenger.';
        $photo = $models->pluck('images')->flatten(1)->firstWhere('default', false);

        return Seo::page(null, $description, '/grabcam', $photo['url'] ?? '/brand/grabcam-logo.png', [array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Grab Cam Rental',
            'url' => Seo::url('/grabcam'),
            'description' => $description,
            'logo' => Seo::url('/brand/grabcam-logo.png'),
            'image' => Seo::url($photo['url'] ?? '/brand/grabcam-logo.png'),
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Cagayan de Oro', 'addressRegion' => 'Misamis Oriental', 'addressCountry' => 'PH'],
            'priceRange' => $lowest ? '₱'.number_format($lowest / 100).'+ per day' : null,
            'sameAs' => array_values(array_filter([config('uniqa.grabcam.facebook_url')])) ?: null,
            'makesOffer' => $models->map(fn ($m) => array_filter([
                '@type' => 'Offer',
                'itemOffered' => array_filter(['@type' => 'Product', 'name' => $m['name'], 'brand' => $m['brand'] ? ['@type' => 'Brand', 'name' => $m['brand']] : null]),
                'priceSpecification' => ($rate = collect($m['rates'])->min('daily_cents')) ? ['@type' => 'UnitPriceSpecification', 'price' => $rate / 100, 'priceCurrency' => 'PHP', 'unitCode' => 'DAY'] : null,
            ]))->values()->all(),
        ], fn ($v) => $v !== null)], site: 'Grab Cam Rental', fullTitle: 'Grab Cam Rental | Camera rental in Cagayan de Oro');
    }

    /** Is any unit of this model free for these days? Read live, just before the renter messages us. */
    public function checkDates(Request $request, string $slug)
    {
        $type = RoomType::where('slug', $slug)->firstOrFail();
        $units = $this->units()->where('room_type_id', $type->id)->get();
        abort_if($units->isEmpty(), 404);
        $dates = $request->validate([
            'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d|after_or_equal:check_in',
            'pickup_time' => 'nullable|date_format:H:i|required_with:return_time',
            'return_time' => 'nullable|date_format:H:i|required_with:pickup_time',
        ]);

        // With times, the exact moments are checked, as staff will; without, whole days as before.
        $period = null;
        if (! empty($dates['pickup_time'])) {
            try {
                $period = RentalPeriod::fromParts($dates['check_in'], $dates['pickup_time'], $dates['check_out'], $dates['return_time']);
            } catch (RoomConflict $e) {
                return response()->json(['available' => false, 'message' => 'The return time must be after the pickup time.'])->header('Cache-Control', 'no-store');
            }
        } elseif ($dates['check_out'] <= $dates['check_in']) {
            return response()->json(['available' => false, 'message' => 'Choose a return day after the pickup day.'])->header('Cache-Control', 'no-store');
        }
        $open = $units->reject(fn ($r) => $this->isClosed($r));
        $free = fn ($r) => $period
            ? $this->inventory->conflicts($r->id, $period->startsAt(), $period->endsAt())->isEmpty()
            : $this->inventory->isFree($r->id, $dates['check_in'], $dates['check_out']);

        $message = match (true) {
            $dates['check_in'] < Local::today() || ($period && $period->starts->lt(Local::now())) => 'That pickup time has passed. Please choose a later time.',
            $open->isEmpty() => "{$type->name} is not available right now.",
            ! $open->contains($free) => $period ? $this->takenMessage($type->name, $open, $period) : "{$type->name} is fully booked for some of those days. Please choose other dates.",
            default => null,
        };

        // The real price for those days, from the rate set in force on the pickup date. Staff still confirm it.
        $estimate = null;
        if ($message === null) {
            $quote = $this->pricing->quote($units->first(), $period?->checkIn() ?? $dates['check_in'], $period?->checkOut() ?? $dates['check_out'], $period ? ['days' => $period->days()] : []);
            $paid = collect($quote['nights'])->where('cents', '>', 0);
            $estimate = ['days' => count($quote['nights']), 'estimate_cents' => $quote['total_cents'], 'daily_cents' => (int) ($paid->first()['cents'] ?? 0), 'free_day' => $paid->count() < count($quote['nights'])]
                + ($period ? ['extra_minutes' => $period->extraMinutes()] : []);
        }

        return response()->json(['available' => $message === null, 'message' => $message] + ($estimate ?? []))->header('Cache-Control', 'no-store');
    }

    private function units()
    {
        return Room::published()->cameras()->with('images')->orderBy('id');
    }

    private function isClosed(Room $room): bool
    {
        return $room->operational_status !== 'active' || $room->hasOpenSafetyBlock();
    }

    /** Which booked times stand in the way, so the renter knows what to change. */
    private function takenMessage(string $name, Collection $units, RentalPeriod $period): string
    {
        $clash = collect($this->allBusy($units, $period->startsAt(), $period->endsAt()))
            ->map(fn ($w) => self::label($w['from']).' to '.self::label($w['to']))->take(3)->implode('; ');

        // With several units, each can be out at a different part of those times, so there is no one window to name.
        return $clash === ''
            ? "{$name} is not free for that whole time. Please choose shorter or other times."
            : "{$name} is booked at that time ({$clash}). Please choose other times.";
    }

    private static function label(string $at): string
    {
        return CarbonImmutable::parse($at)->format('M j, g:i A');
    }

    /**
     * When every unit is out at once, to the minute: the intersection of each unit's busy times.
     * A model with one free unit is never shown as busy.
     *
     * @return list<array{from: string, to: string}>
     */
    private function allBusy(Collection $units, string $from, string $to): array
    {
        $common = null;
        foreach ($units as $unit) {
            $mine = $this->inventory->busyWindows($unit->id, $from, $to);
            $common = $common === null ? $mine : self::intersect($common, $mine);
            if (! $common) {
                return [];
            }
        }

        return $common ?? [];
    }

    /** Both lists are sorted and merged; the result is too. */
    private static function intersect(array $a, array $b): array
    {
        $out = [];
        $i = $j = 0;
        while ($i < count($a) && $j < count($b)) {
            $from = max($a[$i]['from'], $b[$j]['from']);
            $to = min($a[$i]['to'], $b[$j]['to']);
            if ($from < $to) {
                $out[] = ['from' => $from, 'to' => $to];
            }
            $a[$i]['to'] < $b[$j]['to'] ? $i++ : $j++;
        }

        return $out;
    }

    /** Days covered from midnight to midnight by busy times, as [from, to) day ranges. */
    private function fullDays(array $busy): array
    {
        $ranges = [];
        foreach ($busy as $w) {
            $first = CarbonImmutable::parse($w['from']);
            $first = $first->format('H:i') === '00:00' ? $first->startOfDay() : $first->addDay()->startOfDay();
            $end = CarbonImmutable::parse($w['to'])->startOfDay(); // the day containing 'to' is not fully covered
            if ($first->lt($end)) {
                $ranges[] = ['from' => $first->toDateString(), 'to' => $end->toDateString()];
            }
        }

        return $ranges;
    }
}
