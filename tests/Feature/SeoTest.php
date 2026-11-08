<?php

namespace Tests\Feature;

use App\Models\Room;
use Database\Seeders\GrabCamSeeder;
use Tests\DomainTestCase;

/** What Google reads: robots.txt, the sitemap, and the server-rendered title, description, canonical and schema.org data. */
class SeoTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://uniqasuites.com']);
        (new GrabCamSeeder)->run();
        Room::query()->update(['is_published' => true]);
        Room::where('slug', 'venice')->update(['is_published' => false]);
        auth()->logout();
    }

    public function test_robots_txt_opens_the_public_site_and_points_to_the_sitemap(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString("User-agent: *\nAllow: /", $robots);
        $this->assertStringContainsString('Sitemap: https://uniqasuites.com/sitemap.xml', $robots);
        foreach (['/admin', '/login', '/suites/*/check-dates', '/grabcam/*/check-dates'] as $private) {
            $this->assertStringContainsString("Disallow: {$private}\n", $robots);
        }
        $this->assertStringNotContainsString("Disallow: /\n", $robots, 'The whole site must never be blocked.');
    }

    public function test_the_sitemap_lists_every_public_page_and_only_those(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $urls = [];
        foreach ($xml->url as $url) {
            $urls[] = (string) $url->loc;
        }

        $this->assertContains('https://uniqasuites.com/', $urls);
        $this->assertContains('https://uniqasuites.com/suites/new-york', $urls);
        $this->assertContains('https://uniqasuites.com/grabcam', $urls);
        $this->assertContains('https://uniqasuites.com/policies', $urls);
        $this->assertNotContains('https://uniqasuites.com/suites/venice', $urls, 'Unpublished suites stay out.');
        $this->assertEmpty(array_filter($urls, fn ($u) => str_contains($u, 'dji') || str_contains($u, '/admin')));
    }

    public function test_the_home_page_head_is_readable_without_javascript(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title inertia>UNIQA SUITES | Condo suites for rent in Cagayan de Oro</title>', $html);
        $this->assertStringContainsString('<meta inertia name="description" content="UNIQA SUITES: studio', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://uniqasuites.com/">', $html);
        $this->assertStringNotContainsString('noindex', $html);

        $business = $this->schema($html, 'LodgingBusiness');
        $this->assertSame('UNIQA SUITES', $business['name']);
        $this->assertSame('Cagayan de Oro', $business['address']['addressLocality']);
        $this->assertMatchesRegularExpression('/^₱[\d,]+ - ₱[\d,]+ per night$/', $business['priceRange']);
        $this->assertNotContains('VENICE', array_column($business['containsPlace'], 'name'));
    }

    public function test_a_suite_page_has_its_own_title_canonical_and_breadcrumb(): void
    {
        $html = $this->get('/suites/new-york')->assertOk()->getContent();

        $this->assertStringContainsString('<title inertia>NEW YORK | UNIQA SUITES</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://uniqasuites.com/suites/new-york">', $html);
        $this->assertStringContainsString('a night', $html);
        $this->assertSame('NEW YORK, UNIQA SUITES', $this->schema($html, 'Suite')['name']);
        $this->assertCount(2, $this->schema($html, 'BreadcrumbList')['itemListElement']);
        $this->assertSame('NEW YORK | UNIQA SUITES', $this->get('/suites/new-york')->original->getData()['page']['props']['seo']['title'], 'The browser keeps the same title.');
    }

    public function test_grab_cam_is_described_as_a_camera_rental_with_per_day_prices(): void
    {
        $html = $this->get('/grabcam')->assertOk()->getContent();

        $this->assertStringContainsString('<title inertia>Grab Cam Rental | Camera rental in Cagayan de Oro</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://uniqasuites.com/grabcam">', $html);
        $business = $this->schema($html, 'LocalBusiness');
        $this->assertSame('Grab Cam Rental', $business['name']);
        $nano = collect($business['makesOffer'])->firstWhere('itemOffered.name', 'DJI Nano');
        $this->assertEquals(['@type' => 'UnitPriceSpecification', 'price' => 300, 'priceCurrency' => 'PHP', 'unitCode' => 'DAY'], $nano['priceSpecification']);
    }

    public function test_staff_pages_ask_not_to_be_indexed(): void
    {
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $this->get('/login')->getContent());
    }

    private function schema(string $html, string $type): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $found = collect($m[1])->map(fn ($j) => json_decode($j, true))->firstWhere('@type', $type);
        $this->assertNotNull($found, "No {$type} schema on the page.");

        return $found;
    }
}
