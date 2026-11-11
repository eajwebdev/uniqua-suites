<?php

namespace App\Http\Controllers;

use App\Domain\Availability\InventoryService;
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
                'unavailable' => $open->isEmpty() ? [] : $this->allTaken($open, $today, $until),
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
        $dates = $request->validate(['check_in' => 'required|date_format:Y-m-d', 'check_out' => 'required|date_format:Y-m-d|after:check_in']);

        $message = match (true) {
            $dates['check_in'] < Local::today() => 'Those dates have passed. Please choose new dates.',
            $units->every(fn ($r) => $this->isClosed($r)) => "{$type->name} is not available right now.",
            ! $units->contains(fn ($r) => ! $this->isClosed($r) && $this->inventory->isFree($r->id, $dates['check_in'], $dates['check_out'])) => "{$type->name} is fully booked for some of those days. Please choose other dates.",
            default => null,
        };

        // The real price for those days, from the rate set in force on the pickup date. Staff still confirm it.
        $estimate = null;
        if ($message === null) {
            $quote = $this->pricing->quote($units->first(), $dates['check_in'], $dates['check_out']);
            $paid = collect($quote['nights'])->where('cents', '>', 0);
            $estimate = ['days' => count($quote['nights']), 'estimate_cents' => $quote['total_cents'], 'daily_cents' => (int) ($paid->first()['cents'] ?? 0), 'free_day' => $paid->count() < count($quote['nights'])];
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

    /** Days on which every unit is taken, as [from, to) ranges like a single unit's taken dates. */
    private function allTaken(Collection $units, string $from, string $to): array
    {
        $common = null;
        foreach ($units as $unit) {
            $days = [];
            foreach ($this->inventory->takenRanges($unit->id, $from, $to) as $range) {
                for ($d = CarbonImmutable::parse($range['from']); $d->toDateString() < $range['to']; $d = $d->addDay()) {
                    $days[$d->toDateString()] = true;
                }
            }
            $common = $common === null ? $days : array_intersect_key($common, $days);
            if (! $common) {
                return [];
            }
        }

        ksort($common);
        $ranges = [];
        foreach (array_keys($common) as $day) {
            $next = CarbonImmutable::parse($day)->addDay()->toDateString();
            $last = count($ranges) - 1;
            if ($last >= 0 && $ranges[$last]['to'] === $day) {
                $ranges[$last]['to'] = $next;
            } else {
                $ranges[] = ['from' => $day, 'to' => $next];
            }
        }

        return $ranges;
    }
}
