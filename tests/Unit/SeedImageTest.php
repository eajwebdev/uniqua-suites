<?php

namespace Tests\Unit;

use App\Support\SeedImage;
use PHPUnit\Framework\TestCase;

/** The seeders' photo cutter must never stop a deploy, whatever the host's image library can or cannot do. */
class SeedImageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/seed-image-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
        parent::tearDown();
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 40, 80));
        imagepng($image, $path = $this->dir.'/card.png');

        return $path;
    }

    public function test_it_cuts_and_enlarges_a_box_into_a_jpeg(): void
    {
        $jpeg = SeedImage::crop($this->png(200, 200), [10, 20, 68, 82], 2.0);

        $this->assertNotNull($jpeg);
        $this->assertSame([136, 164, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($jpeg), 0, 3));
    }

    public function test_a_broken_or_missing_image_gives_null_instead_of_an_error(): void
    {
        file_put_contents($broken = $this->dir.'/broken.png', 'not an image');

        $this->assertNull(SeedImage::crop($broken, [0, 0, 10, 10]));
        $this->assertNull(SeedImage::crop($this->dir.'/missing.png', [0, 0, 10, 10]));
        $this->assertNull(SeedImage::crop($this->png(50, 50), [0, 0, 0, 10]), 'An empty box makes no image.');
    }

    public function test_a_box_past_the_edge_is_kept_inside_the_image(): void
    {
        $jpeg = SeedImage::crop($this->png(100, 80), [60, 50, 100, 100]);

        $this->assertSame([40, 30], array_slice(getimagesizefromstring($jpeg), 0, 2));
    }
}
