<?php

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\RoomConflict;
use App\Domain\Bookings\BookingService;
use App\Models\Booking;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

// Worker for the concurrency test. Loaded only under APP_ENV=testing.
// Pauses between the availability check and the write, so without the room lock both workers would succeed.
Artisan::command('uniqa:test-confirm {booking} {user} {startAt}', function () {
    app()->singleton(InventoryService::class, fn () => new class extends InventoryService {
        public function conflicts(int $roomId, string $start, string $end, ?int $ignoreBookingId = null): Collection
        {
            fwrite(STDOUT, "CHECKING\n");
            $found = parent::conflicts($roomId, $start, $end, $ignoreBookingId);
            usleep(400_000);

            return $found;
        }
    });

    Auth::onceUsingId((int) $this->argument('user'));
    $booking = Booking::findOrFail((int) $this->argument('booking'));
    $bookings = app(BookingService::class);

    // Both workers leave this barrier together despite PHP startup jitter.
    $wait = (float) $this->argument('startAt') - microtime(true);
    if ($wait > 0) {
        usleep((int) ($wait * 1_000_000));
    }

    try {
        $bookings->confirm($booking);
        $this->line('CONFIRMED');
    } catch (RoomConflict) {
        $this->line('CONFLICT');
    }
});
