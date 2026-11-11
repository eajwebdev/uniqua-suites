<?php

namespace App\Http\Controllers;

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\StayRules;
use App\Domain\Pricing\PricingService;
use App\Models\Faq;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Local;
use App\Support\Seo;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Public, read-only. Guests browse suites, see which dates are taken, and open Facebook Messenger
 * with free dates they chose. Staff then send the quotation and make the booking. By owner decision
 * each suite shows its posted nightly rate (weekday and weekend) so guests know the price up front;
 * no stay estimate, tax, extra-guest fee or staff override reaches a public page. Each room is
 * serialized from an explicit allow-list: no unit codes, notes, secrets or guest data, and
 * taken dates carry no hint of who has them or why.
 */
class PublicSiteController extends Controller
{
    private const DAYS_SHOWN = 365;

    public function __construct(private InventoryService $inventory, private StayRules $stayRules, private PricingService $pricing)
    {
    }

    public function home()
    {
        $rooms = Room::published()->suites()->with(['property', 'type', 'images', 'amenities'])->orderBy('id')->get();
        $cards = $rooms->map(fn ($r) => $this->card($r));

        return Seo::attach(Inertia::render('Public/Home', [
            'rooms' => $cards,
            // Shown as "in every suite", so only what all of them really have.
            'amenities' => $rooms->isEmpty() ? [] : array_values(array_intersect(...$rooms->map(fn ($r) => $r->amenities->pluck('name')->all())->all())),
            'properties' => Property::where('category', Property::SUITES)->orderBy('id')->get(['name', 'slug', 'location_label']),
            'types' => RoomType::whereHas('rooms', fn ($r) => $r->suites())->orderBy('id')->get(['name', 'slug']),
            'policy' => $this->policy(),
            'faqs' => Faq::forWebsite(Property::SUITES),
        ]), Seo::page(
            null,
            self::HOME_DESCRIPTION,
            '/',
            $cards->pluck('images')->flatten(1)->first()['url'] ?? '/brand/uniqa-wordmark.png',
            [$this->business($cards), ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => Seo::SITE, 'url' => Seo::url('/')]],
            fullTitle: 'UNIQA SUITES | Condo suites for rent in Cagayan de Oro',
        ));
    }

    public const HOME_DESCRIPTION = 'UNIQA SUITES: studio, one-bedroom and two-bedroom condo suites for daily rent at The Loop, Limketkai and Mesaverte Residences in Cagayan de Oro. See rates and free dates, then book in Messenger.';

    /** Google's business card for the suites: what they are, where, the price range and where to message. */
    private function business($cards): array
    {
        $contact = config('uniqa.contact');
        $prices = $cards->pluck('rate')->filter()->flatMap(fn ($r) => [$r['weekday'], $r['weekend']]);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'LodgingBusiness',
            'name' => Seo::SITE,
            'url' => Seo::url('/'),
            'description' => self::HOME_DESCRIPTION,
            'logo' => Seo::url('/brand/uniqa-wordmark.png'),
            'image' => $cards->pluck('images')->flatten(1)->pluck('url')->take(5)->map(fn ($u) => Seo::url($u))->values()->all() ?: [Seo::url('/brand/uniqa-wordmark.png')],
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Cagayan de Oro', 'addressRegion' => 'Misamis Oriental', 'addressCountry' => 'PH'],
            'priceRange' => $prices->isEmpty() ? null : '₱'.number_format($prices->min() / 100).' - ₱'.number_format($prices->max() / 100).' per night',
            'checkinTime' => Settings::get('pricing.standard_check_in'),
            'checkoutTime' => Settings::get('pricing.standard_check_out'),
            'telephone' => $contact['phone'] ?? null,
            'email' => $contact['email'] ?? null,
            'sameAs' => array_values(array_filter([$contact['facebook_url'] ?? null, $contact['instagram_url'] ?? null])) ?: null,
            'containsPlace' => $cards->map(fn ($c) => ['@type' => 'Suite', 'name' => $c['name'], 'url' => Seo::url("/suites/{$c['slug']}"), 'occupancy' => ['@type' => 'QuantitativeValue', 'maxValue' => $c['max_guests']]])->values()->all(),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** Filters now run on the landing page. Old links and bookmarks arrive there with theirs applied. */
    public function suites(Request $request)
    {
        $query = http_build_query($request->only(['location', 'type', 'guests', 'bathrooms', 'balcony']));

        return redirect('/'.($query ? "?{$query}" : '').'#availability');
    }

    public function show(string $slug)
    {
        $room = Room::published()->suites()->where('slug', $slug)->with(['property', 'type', 'images', 'amenities'])->firstOrFail();

        $card = $this->card($room);

        return Seo::attach(Inertia::render('Public/Suite', [
            'room' => $card,
            'policy' => $this->policy(),
        ]), Seo::page(
            $room->name,
            "{$room->name} at {$card['location']}, Cagayan de Oro: ".strtolower($card['type'])." for up to {$room->max_guests} guests".($card['rate'] ? ', from ₱'.number_format(min($card['rate']) / 100).' a night' : '').'. '.($room->description ? rtrim($room->description, '.').'. ' : '').'Book in Messenger.',
            "/suites/{$room->slug}",
            $card['images'][0]['url'] ?? null,
            [array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Suite',
                'name' => "{$room->name}, UNIQA SUITES",
                'url' => Seo::url("/suites/{$room->slug}"),
                'description' => $room->description,
                'image' => collect($card['images'])->pluck('url')->map(fn ($u) => Seo::url($u))->all() ?: null,
                'numberOfRooms' => max(1, (int) $room->bedrooms),
                'numberOfBathroomsTotal' => $room->bathrooms,
                'occupancy' => ['@type' => 'QuantitativeValue', 'maxValue' => $room->max_guests],
                'amenityFeature' => $card['amenities']->map(fn ($a) => ['@type' => 'LocationFeatureSpecification', 'name' => $a, 'value' => true])->all() ?: null,
                'containedInPlace' => ['@type' => 'LodgingBusiness', 'name' => Seo::SITE, 'url' => Seo::url('/')],
            ], fn ($v) => $v !== null),
            ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => Seo::SITE, 'item' => Seo::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $room->name, 'item' => Seo::url("/suites/{$room->slug}")],
            ]]],
        ));
    }

    /** The final word before Book Now unlocks. The page's list could be minutes old; this reads the live inventory. */
    public function checkDates(Request $request, string $slug)
    {
        $room = Room::published()->suites()->where('slug', $slug)->firstOrFail();
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
        return Seo::attach(Inertia::render('Public/Policies', ['policy' => $this->policy(), 'faqs' => Faq::forWebsite(Property::SUITES)]), Seo::page('Policies & FAQ', 'How to book with UNIQA SUITES in Cagayan de Oro: check-in, early arrival, extra guests and parking, plus our privacy notice.', '/policies'));
    }

    /** Every public page, for search engines. Robots.txt points here. */
    public function sitemap()
    {
        $rooms = Room::published()->suites()->orderBy('id')->get(['id', 'slug', 'updated_at']);
        $pages = collect([
            ['loc' => Seo::url('/'), 'lastmod' => $rooms->max('updated_at'), 'priority' => '1.0'],
            ...$rooms->map(fn ($r) => ['loc' => Seo::url("/suites/{$r->slug}"), 'lastmod' => $r->updated_at, 'priority' => '0.8']),
            ['loc' => Seo::url('/grabcam'), 'lastmod' => Room::published()->cameras()->max('updated_at'), 'priority' => '0.8'],
            ['loc' => Seo::url('/policies'), 'lastmod' => null, 'priority' => '0.4'],
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($pages as $p) {
            $xml .= '  <url><loc>'.e($p['loc']).'</loc>'.($p['lastmod'] ? '<lastmod>'.Carbon::parse($p['lastmod'])->toDateString().'</lastmod>' : '').'<changefreq>daily</changefreq><priority>'.$p['priority'].'</priority></url>'."\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
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

    /** The posted nightly rate in force today, in centavos, or null when none is set. */
    private function rate(Room $room): ?array
    {
        $range = $this->pricing->currentRange($room, Local::today());

        return $range ? ['weekday' => $range['weekday_cents'], 'weekend' => $range['weekend_cents']] : null;
    }

    private function card(Room $room): array
    {
        return [
            'name' => $room->name,
            'slug' => $room->slug,
            'location' => $room->property->location_label,
            'location_slug' => $room->property->slug,
            'type' => $room->type->name,
            'type_slug' => $room->type->slug,
            'inclusions' => $room->inclusions,
            'policies' => $room->policies,
            'amenities' => $room->amenities->pluck('name'),
            'description' => $room->description,
            'closed' => $this->isClosed($room),
            'unavailable' => $this->inventory->takenRanges($room->id, Local::today(), Local::now()->addDays(self::DAYS_SHOWN)->toDateString()),
            'bedrooms' => $room->bedrooms,
            'bathrooms' => $room->bathrooms,
            'ac_count' => $room->ac_count,
            'has_balcony' => $room->has_balcony,
            'max_guests' => $room->max_guests,
            'rate' => $this->rate($room),
            'images' => $room->images->map(fn ($i) => ['url' => '/storage/'.$i->path, 'alt' => $i->alt_text]),
        ];
    }
}
