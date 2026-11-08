<?php

namespace App\Support;

use Inertia\Response;

/**
 * What search engines and link previews read, rendered on the server: title, description,
 * canonical address, share image and schema.org data. The public pages also set their title in
 * the browser; the server copy is there so crawlers and chat previews see it without running JS.
 */
final class Seo
{
    public const SITE = 'UNIQA SUITES';

    /** An absolute address on the live site (APP_URL, https://uniqasuites.com in production). */
    public static function url(string $path = '/'): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  string|null  $title  Page title without the site name; null for the home page.
     * @param  array<int, array<string, mixed>>  $schema  schema.org objects, each printed as JSON-LD.
     */
    public static function page(?string $title, string $description, string $path, ?string $image = null, array $schema = [], string $site = self::SITE, ?string $fullTitle = null): array
    {
        return [
            'title' => $fullTitle ?? ($title ? "{$title} | {$site}" : $site),
            'description' => $description,
            'url' => self::url($path),
            'image' => $image ? (str_starts_with($image, 'http') ? $image : self::url($image)) : null,
            'site' => $site,
            'schema' => $schema,
        ];
    }

    /** Gives the page its search data twice: in the server-rendered head for crawlers, and as a prop so the browser keeps the same title and description. */
    public static function attach(Response $page, array $seo): Response
    {
        return $page->with('seo', ['title' => $seo['title'], 'description' => $seo['description']])->withViewData('seo', $seo);
    }

    /** JSON-LD that is safe inside a <script> tag. */
    public static function json(array $schema): string
    {
        return json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
