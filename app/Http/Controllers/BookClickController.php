<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomType;
use App\Support\Local;
use App\Support\SystemNotice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * A guest pressed Book Now on a suite, or a renter pressed Inquire on a camera, and Messenger is opening.
 * Front desk hears about it at once, so the chat is answered while the guest is still there.
 * Nothing is reserved: this is a heads-up only. It takes no names or contact details.
 *
 * The page sends it as the guest leaves for Messenger, from a page that may sit inside another
 * site's frame, so it carries no CSRF token. It can only cause an alert, and only for a published
 * suite or camera and future dates; repeats from one visitor within half an hour are dropped.
 */
class BookClickController extends Controller
{
    private const REPEAT_MINUTES = 30;

    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'kind' => 'required|in:suite,camera',
            'slug' => 'required|string|max:80',
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'pickup_time' => 'nullable|date_format:H:i',
            'return_time' => 'nullable|date_format:H:i',
            'guests' => 'nullable|integer|min:1|max:50',
        ]);
        if ($data['from'] < Local::today()) {
            return response()->noContent();
        }

        $notice = $data['kind'] === 'suite' ? $this->suite($data) : $this->camera($data);
        if ($notice === null) {
            return response()->noContent();
        }

        // One alert per visitor and choice, however many times the button is pressed.
        if (Cache::add('book-click:'.sha1($request->ip().'|'.json_encode($data)), true, now()->addMinutes(self::REPEAT_MINUTES))) {
            SystemNotice::toPermission('bookings.manage', $notice['title'], $notice['url'], 'inquiries', heading: $notice['heading']);
        }

        return response()->noContent();
    }

    private function suite(array $data): ?array
    {
        $room = Room::published()->suites()->where('slug', $data['slug'])->first();
        if (! $room || $data['to'] <= $data['from']) {
            return null;
        }
        $nights = CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to']));
        $guests = ! empty($data['guests']) ? ', '.$data['guests'].' guest'.($data['guests'] == 1 ? '' : 's') : '';

        return [
            'heading' => 'New booking request',
            'title' => "{$room->name}: a guest pressed Book Now for ".self::day($data['from']).' to '.self::day($data['to'])
                ." ({$nights} night".($nights == 1 ? '' : 's')."{$guests}). Watch Messenger for their message.",
            'url' => '/admin/calendar?business=suites&month='.substr($data['from'], 0, 7),
        ];
    }

    private function camera(array $data): ?array
    {
        $type = RoomType::where('slug', $data['slug'])->whereHas('rooms', fn ($r) => $r->published()->cameras())->first();
        if (! $type) {
            return null;
        }
        $from = self::day($data['from']).(! empty($data['pickup_time']) ? ' '.self::time($data['pickup_time']) : '');
        $to = self::day($data['to']).(! empty($data['return_time']) ? ' '.self::time($data['return_time']) : '');

        return [
            'heading' => 'Grab Cam rental request',
            'title' => "{$type->name}: a renter pressed Inquire for {$from} to {$to}. Watch the Grab Cam Messenger for their message.",
            'url' => '/admin/calendar?business=cameras&month='.substr($data['from'], 0, 7),
        ];
    }

    private static function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('D M j');
    }

    private static function time(string $time): string
    {
        return CarbonImmutable::parse("2000-01-01 {$time}")->format('g:i A');
    }
}
