<?php

namespace Tests\Feature;

use App\Domain\Tasks\TurnoverService;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Support\ImageCompressor;
use App\Support\Local;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\DomainTestCase;

class CleaningTest extends DomainTestCase
{
    private function cleaner(string $name = 'Synthetic Cleaner'): User
    {
        $user = User::factory()->create(['name' => $name]);
        Permissions::apply($user, 'Staff', ['housekeeping_own']);

        return $user->fresh();
    }

    private function day(int $offset = 0): string
    {
        return Local::now()->addDays($offset)->toDateString();
    }

    private function dueOutToday(string $slug = 'venice'): Booking
    {
        $booking = $this->confirmed($slug, $this->day(-1), $this->day());
        $this->bookings->checkIn($booking, true, 'test');

        return $booking->fresh();
    }

    private function props(string $url = '/admin/housekeeping'): array
    {
        return $this->get($url)->assertOk()->original->getData()['page']['props'];
    }

    private function bigPhoto(int $width = 4000, int $height = 3000): UploadedFile
    {
        // Noise compresses badly, so this behaves like a real full-size photo rather than a flat colour.
        $image = imagecreatetruecolor($width, $height);
        for ($i = 0; $i < 60000; $i++) {
            imagefilledrectangle($image, $x = random_int(0, $width), $y = random_int(0, $height), $x + 12, $y + 12, random_int(0, 0xFFFFFF));
        }
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($image, $path, 95);

        return new UploadedFile($path, 'IMG_0001.jpg', 'image/jpeg', null, true);
    }

    /** A JPEG as a phone writes it: the camera's turn and a location, in an EXIF block before the pixels. */
    private function phonePhoto(string $marker): UploadedFile
    {
        $image = imagecreatetruecolor(800, 600);
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($image, $path, 90);

        // Little-endian TIFF: one tag, Orientation (0x0112) = 6, meaning "turn 90 degrees to view".
        $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, 6, 0).pack('V', 0);
        $exif = "Exif\0\0".$tiff.$marker;
        $jpeg = file_get_contents($path);
        file_put_contents($path, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));

        return new UploadedFile($path, 'phone.jpg', 'image/jpeg', null, true);
    }

    public function test_a_phone_photo_loses_its_hidden_data_and_stays_the_right_way_up(): void
    {
        Storage::fake('local');
        $photo = $this->phonePhoto('GPS-14.5995N-120.9842E');
        $this->assertStringContainsString('GPS-14.5995N', file_get_contents($photo->getRealPath()), 'the marker is really in the upload');

        $path = ImageCompressor::store($photo, 'evidence/tasks');

        $stored = Storage::disk('local')->path($path);
        $this->assertStringNotContainsString('GPS-14.5995N', file_get_contents($stored));
        [$width, $height] = getimagesize($stored);
        $this->assertSame([600, 800], [$width, $height], 'a sideways landscape file is saved upright');
    }

    public function test_a_guest_due_out_appears_as_waiting_without_their_name(): void
    {
        $booking = $this->dueOutToday();

        $props = $this->props();
        $this->assertCount(1, $props['planned']);
        $this->assertSame('waiting', $props['planned'][0]['bucket']);
        $this->assertSame('VENICE', $props['planned'][0]['room']);
        $this->assertStringNotContainsString($booking->guest->full_name, $this->get('/admin/housekeeping')->getContent());
        $this->assertSame(0, HousekeepingTask::count(), 'looking at the page creates nothing');
    }

    public function test_a_cleaner_lined_up_in_advance_is_told_and_cannot_start_until_the_guest_leaves(): void
    {
        $booking = $this->dueOutToday();
        $cleaner = $this->cleaner();
        $room = $this->room('venice');
        $before = $room->housekeeping_status;

        $this->post('/admin/tasks/plan', ['booking_id' => $booking->id, 'room_id' => $room->id, 'user_id' => $cleaner->id])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $task = HousekeepingTask::sole();
        $this->assertSame('waiting', $task->status);
        $this->assertSame($before, $room->fresh()->housekeeping_status, 'the guest is still in the room');
        $this->assertStringContainsString('New cleaning for you: VENICE', $cleaner->notifications()->sole()->data['title']);
        $this->assertCount(0, $this->props()['planned'], 'not listed twice');

        $this->actingAs($cleaner)->post("/admin/tasks/{$task->id}/start")->assertSessionHas('error');
        $this->assertSame('waiting', $task->fresh()->status);

        $this->actingAs($this->owner);
        $this->bookings->checkOut($booking);

        $task = HousekeepingTask::sole();
        $this->assertSame('assigned', $task->status);
        $this->assertSame($cleaner->id, $task->assigned_to);
        $this->assertSame(2, $cleaner->notifications()->count());
        $this->assertStringContainsString('The guest has left VENICE', $cleaner->notifications()->latest()->get()->pluck('data.title')->join(' '));
        $this->actingAs($cleaner)->post("/admin/tasks/{$task->id}/start")->assertSessionMissing('error');
        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_cancelling_a_booking_withdraws_the_planned_task_and_tells_the_cleaner(): void
    {
        $booking = $this->confirmed('venice', $this->day(1), $this->day(3));
        $cleaner = $this->cleaner();
        $this->post('/admin/tasks/plan', ['booking_id' => $booking->id, 'room_id' => $this->room('venice')->id, 'user_id' => $cleaner->id])->assertSessionMissing('error');

        $this->bookings->cancel($booking->fresh(), 'Guest cancelled');

        $this->assertSame('cancelled', HousekeepingTask::sole()->status);
        $this->assertNull(HousekeepingTask::sole()->assigned_to);
        $this->assertStringContainsString('Cleaning cancelled: VENICE', $cleaner->notifications()->latest('id')->get()->pluck('data.title')->join(' '));
        $this->actingAs($cleaner);
        $this->assertCount(0, $this->props('/admin/housekeeping?date='.$this->day(3))['tasks']);
    }

    public function test_moving_a_stay_withdraws_the_old_plan_and_the_new_date_can_be_planned(): void
    {
        $booking = $this->confirmed('venice', $this->day(1), $this->day(3));
        $cleaner = $this->cleaner();
        $room = $this->room('venice');
        $this->post('/admin/tasks/plan', ['booking_id' => $booking->id, 'room_id' => $room->id, 'user_id' => $cleaner->id])->assertSessionMissing('error');

        $this->bookings->amend($booking->fresh(), ['check_out' => $this->day(4)]);
        $this->assertSame('cancelled', HousekeepingTask::sole()->status);

        $planned = $this->props('/admin/housekeeping?date='.$this->day(4))['planned'];
        $this->assertCount(1, $planned, 'offered again on the new date');

        $this->post('/admin/tasks/plan', ['booking_id' => $booking->id, 'room_id' => $room->id, 'user_id' => $cleaner->id])->assertSessionMissing('error');
        $task = HousekeepingTask::sole();
        $this->assertSame('waiting', $task->status, 'the same record is reused, not duplicated');
        $this->assertSame($this->day(4), $task->due_at->copy()->setTimezone(Local::tz())->toDateString());
    }

    public function test_changing_a_party_size_keeps_the_planned_cleaner(): void
    {
        $booking = $this->confirmed('venice', $this->day(1), $this->day(3));
        $cleaner = $this->cleaner();
        $this->post('/admin/tasks/plan', ['booking_id' => $booking->id, 'room_id' => $this->room('venice')->id, 'user_id' => $cleaner->id])->assertSessionMissing('error');

        $this->bookings->amend($booking->fresh(), ['adults' => 1]);

        $this->assertSame('waiting', HousekeepingTask::sole()->status);
        $this->assertSame($cleaner->id, HousekeepingTask::sole()->assigned_to);
    }

    public function test_auto_assign_notifies_each_person_and_includes_guests_due_out(): void
    {
        $this->dueOutToday('venice');
        $this->dueOutToday('bali');
        $first = $this->cleaner('First');
        $second = $this->cleaner('Second');

        $this->post('/admin/tasks/auto-assign', ['date' => $this->day()])->assertSessionMissing('error');

        $this->assertSame(2, HousekeepingTask::where('status', 'waiting')->whereNotNull('assigned_to')->count());
        $this->assertSame(1, $first->notifications()->count());
        $this->assertSame(1, $second->notifications()->count());
    }

    public function test_reassigning_tells_both_people_and_repeating_it_tells_nobody(): void
    {
        $task = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('venice')->id, 'status' => 'pending']);
        $first = $this->cleaner('First');
        $second = $this->cleaner('Second');

        $this->post("/admin/tasks/{$task->id}/assign", ['user_id' => $first->id]);
        $this->post("/admin/tasks/{$task->id}/assign", ['user_id' => $first->id]);
        $this->assertSame(1, $first->notifications()->count());

        $this->post("/admin/tasks/{$task->id}/assign", ['user_id' => $second->id]);
        $this->assertSame(2, $first->notifications()->count());
        $this->assertSame(1, $second->notifications()->count());
    }

    public function test_work_cannot_go_to_someone_who_cannot_clean_or_has_left(): void
    {
        $task = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('venice')->id, 'status' => 'pending']);
        $bookkeeper = User::factory()->create();
        Permissions::apply($bookkeeper, 'Staff', ['reports']);
        $former = $this->cleaner();
        $former->update(['is_active' => false]);

        $this->post("/admin/tasks/{$task->id}/assign", ['user_id' => $bookkeeper->id])->assertSessionHas('error');
        $this->post("/admin/tasks/{$task->id}/assign", ['user_id' => $former->id])->assertSessionHas('error');
        $this->assertNull($task->fresh()->assigned_to);
    }

    public function test_a_failed_inspection_tells_the_cleaner_what_to_redo(): void
    {
        $cleaner = $this->cleaner();
        $task = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('venice')->id, 'status' => 'for_inspection', 'assigned_to' => $cleaner->id]);

        $this->post("/admin/tasks/{$task->id}/inspect", ['accepted' => false, 'note' => 'Bathroom mirror'])->assertSessionMissing('error');

        $this->assertStringContainsString('Bathroom mirror', $cleaner->notifications()->sole()->data['title']);
        $this->assertSame('Bathroom mirror', collect($this->actingAs($cleaner)->props()['tasks'])->sole()['redo_note']);
    }

    public function test_a_finished_photo_is_saved_small(): void
    {
        Storage::fake('local');
        $cleaner = $this->cleaner();
        $task = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('venice')->id, 'status' => 'in_progress', 'assigned_to' => $cleaner->id]);
        $photo = $this->bigPhoto();
        $original = $photo->getSize();
        $this->assertGreaterThan(1024 * 1024, $original, 'the test photo really is large');

        $this->actingAs($cleaner)->post("/admin/tasks/{$task->id}/complete", [
            'checklist' => [['label' => 'Floors cleaned', 'done' => true]],
            'photos' => [$photo],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame('for_inspection', $task->fresh()->status);
        $path = TaskAttachment::sole()->path;
        $stored = Storage::disk('local')->path($path);
        $size = filesize($stored);
        $this->assertLessThanOrEqual(ImageCompressor::TARGET_BYTES, $size);
        $this->assertLessThan($original / 4, $size);
        [$width, $height] = getimagesize($stored);
        $this->assertLessThanOrEqual(ImageCompressor::MAX_EDGE, max($width, $height));
        $this->assertEqualsWithDelta(4 / 3, $width / $height, 0.01, 'compression keeps the photo proportions');
        $this->assertStringEndsWith('.webp', $path);
        $this->assertStringNotContainsString('IMG_0001', $path, 'the original file name is not kept');
    }

    public function test_a_file_that_is_not_a_photo_stops_finishing_and_changes_nothing(): void
    {
        Storage::fake('local');
        $cleaner = $this->cleaner();
        $task = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('venice')->id, 'status' => 'in_progress', 'assigned_to' => $cleaner->id]);

        $this->actingAs($cleaner)->post("/admin/tasks/{$task->id}/complete", [
            'checklist' => [['label' => 'Floors cleaned', 'done' => true]],
            'photos' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')],
        ])->assertSessionHasErrors();

        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertSame(0, TaskAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_cleaner_can_add_a_photo_after_finishing_but_not_after_it_passes(): void
    {
        Storage::fake('local');
        $cleaner = $this->cleaner();
        $task = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('venice')->id, 'status' => 'for_inspection', 'assigned_to' => $cleaner->id]);

        $this->actingAs($cleaner)->post("/admin/tasks/{$task->id}/photos", ['photos' => [UploadedFile::fake()->image('a.jpg', 800, 600)]])->assertSessionMissing('error')->assertSessionHasNoErrors();
        $this->assertSame(1, TaskAttachment::count());

        $task->update(['status' => 'accepted']);
        $this->post("/admin/tasks/{$task->id}/photos", ['photos' => [UploadedFile::fake()->image('b.jpg', 800, 600)]])->assertSessionHas('error');
        $this->assertSame(1, TaskAttachment::count());
    }

    public function test_only_the_right_people_can_open_a_cleaning_photo(): void
    {
        Storage::fake('local');
        $cleaner = $this->cleaner();
        $other = $this->cleaner('Other');
        $inspector = User::factory()->create();
        Permissions::apply($inspector, 'Staff', ['inspect']);
        $task = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('venice')->id, 'status' => 'for_inspection', 'assigned_to' => $cleaner->id]);
        Storage::disk('local')->put('evidence/tasks/x.webp', 'bytes');
        $photo = TaskAttachment::create(['housekeeping_task_id' => $task->id, 'path' => 'evidence/tasks/x.webp']);

        $this->actingAs($cleaner)->get("/admin/files/task/{$photo->id}")->assertOk();
        $this->actingAs($inspector->fresh())->get("/admin/files/task/{$photo->id}")->assertOk();
        $this->actingAs($this->owner)->get("/admin/files/task/{$photo->id}")->assertOk();
        $this->actingAs($other)->get("/admin/files/task/{$photo->id}")->assertForbidden();
    }

    public function test_the_day_view_shows_that_day_and_keeps_late_work_on_today(): void
    {
        $late = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('venice')->id, 'status' => 'pending', 'due_at' => now()->subDays(3)]);
        $done = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('bali')->id, 'status' => 'accepted', 'due_at' => now()->subDays(3), 'inspected_at' => now()->subDays(3)]);

        $today = collect($this->props()['tasks']);
        $this->assertTrue($today->contains('id', $late->id), 'late work stays on today');
        $this->assertFalse($today->contains('id', $done->id), 'old finished work does not');
        $this->assertSame('at_risk', $today->firstWhere('id', $late->id)['bucket']);

        $past = collect($this->props('/admin/housekeeping?date='.$this->day(-3))['tasks']);
        $this->assertTrue($past->contains('id', $done->id));

        $this->assertSame($this->day(), $this->props('/admin/housekeeping?date=not-a-date')['date']);
        $this->assertSame($this->day(), $this->props('/admin/housekeeping?date=2031-02-31')['date']);
    }

    public function test_a_room_with_a_same_day_arrival_must_be_ready_by_check_in(): void
    {
        $this->dueOutToday('venice');
        $this->confirmed('venice', $this->day(), $this->day(2));

        $row = $this->props()['planned'][0];
        $this->assertTrue($row['has_arrival']);
        $this->assertStringContainsString('14:00', $row['ready_by']);
    }

    public function test_a_cleaner_sees_only_their_own_work_and_no_booking_details(): void
    {
        $booking = $this->dueOutToday();
        $mine = $this->cleaner('Mine');
        $other = $this->cleaner('Other');
        $this->post('/admin/tasks/plan', ['booking_id' => $booking->id, 'room_id' => $this->room('venice')->id, 'user_id' => $other->id]);

        $this->actingAs($mine);
        $props = $this->props();
        $this->assertCount(0, $props['tasks']);
        $this->assertSame([], $props['planned']);
        $this->assertSame([], $props['staff']);
        $this->assertFalse($props['can']['manage']);
        $this->post('/admin/tasks/plan', ['booking_id' => $booking->id, 'room_id' => $this->room('venice')->id, 'user_id' => $mine->id])->assertForbidden();
    }

    public function test_a_temporary_password_lets_staff_sign_in_and_is_never_stored_or_logged(): void
    {
        $cleaner = $this->cleaner();

        $response = $this->post("/admin/team/{$cleaner->id}/temporary-password")->assertSessionHas('credential');
        $password = session('credential')['password'];

        $this->assertTrue(Hash::check($password, $cleaner->fresh()->password));
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'team.temporary_password')->count());
        $this->assertSame(0, DB::table('activity_logs')->where('meta', 'like', "%{$password}%")->count());

        auth()->logout();
        $this->post('/login', ['email' => $cleaner->email, 'password' => $password]);
        $this->assertAuthenticatedAs($cleaner);
        $this->get('/admin/housekeeping')->assertOk();
    }

    public function test_temporary_passwords_respect_who_may_change_whom(): void
    {
        $manager = User::factory()->create();
        Permissions::apply($manager, 'Operations Manager', ['team']);
        $hash = $this->owner->password;

        $this->actingAs($manager->fresh())->post("/admin/team/{$this->owner->id}/temporary-password")->assertForbidden();
        $this->assertSame($hash, $this->owner->fresh()->password);

        $this->actingAs($this->owner)->post("/admin/team/{$this->owner->id}/temporary-password")->assertForbidden();

        $this->actingAs($this->cleaner())->post("/admin/team/{$manager->id}/temporary-password")->assertForbidden();
    }

    public function test_inviting_while_mail_goes_to_the_log_hands_over_a_password(): void
    {
        config(['mail.default' => 'log']);

        $this->post('/admin/team', ['name' => 'New Cleaner', 'email' => 'new@example.test', 'role' => 'Staff', 'pages' => ['housekeeping_own']])->assertSessionHas('credential');

        $this->assertTrue(Hash::check(session('credential')['password'], User::where('email', 'new@example.test')->sole()->password));
    }

    public function test_new_notices_are_shared_with_the_page(): void
    {
        $cleaner = $this->cleaner();
        $task = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('venice')->id, 'status' => 'pending']);
        app(TurnoverService::class)->assign($task, $cleaner);

        $user = $this->actingAs($cleaner)->props()['auth']['user'];
        $this->assertSame(1, $user['unread']);
        $this->assertStringContainsString('New cleaning for you: VENICE', $user['latest_notice']['title']);
        $this->assertSame('/admin/housekeeping', $user['latest_notice']['url']);
    }
}
