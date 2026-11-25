<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\DomainTestCase;

class RoomPhotoUploadTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_several_photos_upload_under_one_title_in_order(): void
    {
        $room = $this->room('bali');
        $room->images()->create(['path' => 'rooms/old.jpg', 'alt_text' => 'Existing', 'position' => 0]);

        $this->post("/admin/rooms/{$room->id}/images", [
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.webp')],
            'alt_text' => 'Bedroom',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', '3 photos added.');

        $added = $room->images()->where('alt_text', 'Bedroom')->orderBy('position')->get();
        $this->assertSame([1, 2, 3], $added->pluck('position')->all());
        $added->each(fn ($image) => Storage::disk('public')->assertExists($image->path));
    }

    public function test_a_single_image_field_still_works(): void
    {
        $room = $this->room('bali');

        $this->post("/admin/rooms/{$room->id}/images", ['image' => UploadedFile::fake()->image('one.jpg'), 'alt_text' => 'Living area'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Photo added.');

        $this->assertSame(1, $room->images()->count());
    }

    public function test_a_bad_file_rejects_the_whole_upload(): void
    {
        $room = $this->room('bali');

        $this->post("/admin/rooms/{$room->id}/images", [
            'images' => [UploadedFile::fake()->image('ok.jpg'), UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            'alt_text' => 'Bathroom',
        ])->assertSessionHasErrors('images.1');

        $this->assertSame(0, $room->images()->count());
    }

    public function test_no_files_and_too_many_files_are_refused(): void
    {
        $room = $this->room('bali');

        $this->post("/admin/rooms/{$room->id}/images", ['alt_text' => 'Kitchen'])->assertSessionHasErrors('images');

        $files = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 10, 10), range(1, 21));
        $this->post("/admin/rooms/{$room->id}/images", ['images' => $files, 'alt_text' => 'Kitchen'])->assertSessionHasErrors('images');

        $this->assertSame(0, $room->images()->count());
    }
}
