<?php

namespace Tests\Feature;

use App\Domain\Tasks\TurnoverService;
use App\Models\TaskAttachment;
use App\Support\ImageCompressor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\DomainTestCase;

/** Photos pile up every day, so what is kept has to stay tiny, whatever the phone sends. */
class TaskPhotoSizeTest extends DomainTestCase
{
    /** Grainy on purpose: random detail is the hardest thing to compress, so this is the worst case. */
    private function busyPhoto(int $width = 2400, int $height = 1800): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        mt_srand(7);
        for ($y = 0; $y < $height; $y += 6) {
            for ($x = 0; $x < $width; $x += 6) {
                imagefilledrectangle($image, $x, $y, $x + 5, $y + 5, imagecolorallocate($image, mt_rand(60, 200), mt_rand(60, 200), mt_rand(60, 200)));
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'busy').'.jpg';
        imagejpeg($image, $path, 95);

        return new UploadedFile($path, 'camera.jpg', 'image/jpeg', null, true);
    }

    private function calmPhoto(): UploadedFile
    {
        return UploadedFile::fake()->image('room.jpg', 3000, 2000);
    }

    public function test_an_ordinary_photo_is_saved_under_the_target_at_full_width(): void
    {
        Storage::fake('local');

        $stored = Storage::disk('local')->path(ImageCompressor::store($this->calmPhoto(), 'evidence/tasks'));

        $this->assertLessThanOrEqual(ImageCompressor::TARGET_BYTES, filesize($stored));
        $this->assertSame(ImageCompressor::MAX_EDGE, getimagesize($stored)[0]);
    }

    public function test_even_the_hardest_photo_is_squeezed_far_below_what_was_sent(): void
    {
        Storage::fake('local');
        $photo = $this->busyPhoto();
        $sent = filesize($photo->getRealPath());

        $stored = Storage::disk('local')->path(ImageCompressor::store($photo, 'evidence/tasks'));

        $this->assertLessThan($sent / 10, filesize($stored));
        $this->assertLessThanOrEqual(ImageCompressor::TARGET_BYTES, filesize($stored), 'even the worst case reaches the 50 KB target');
        $this->assertLessThanOrEqual(640, getimagesize($stored)[0], 'it steps down in size only when needed');
    }

    public function test_a_small_photo_is_never_stretched(): void
    {
        Storage::fake('local');

        $stored = Storage::disk('local')->path(ImageCompressor::store(UploadedFile::fake()->image('small.jpg', 300, 200), 'evidence/tasks'));

        $this->assertSame([300, 200], array_slice(getimagesize($stored), 0, 2));
    }

    public function test_a_captured_photo_is_recorded_and_its_size_shown_without_the_path(): void
    {
        Storage::fake('local');
        $service = app(TurnoverService::class);
        $task = $service->createTask(['type' => 'cleaning', 'title' => 'Synthetic', 'room_id' => $this->room('rio')->id, 'due_at' => now()->addHours(3)], null);
        $service->start($task);

        $this->post("/admin/tasks/{$task->id}/photos", ['photos' => [$this->calmPhoto()]])->assertSessionHasNoErrors();

        $attachment = TaskAttachment::sole();
        $this->assertSame($task->id, $attachment->housekeeping_task_id);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame(Storage::disk('local')->size($attachment->path), $attachment->byte_size);
        $this->assertContains($attachment->mime_type, ['image/webp', 'image/jpeg']);

        $page = $this->get('/admin/housekeeping')->assertOk();
        $row = collect($page->original->getData()['page']['props']['tasks'])->firstWhere('id', $task->id);

        $this->assertSame([$attachment->byte_size], $row['photo_sizes']);
        $this->assertStringNotContainsString($attachment->path, $page->getContent());
    }
}
