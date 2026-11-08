<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Incident;
use App\Models\Room;
use App\Support\AdminCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /** Filters that name a property or a unit: they mean nothing in the other business, so they are dropped on a switch. */
    private const RECORD_FILTERS = ['property', 'room', 'room_id'];

    /**
     * Switches business and stays on the same page, showing the other business. Three exceptions:
     * a page that belongs only to the other business goes to the dashboard, a single record of the
     * other business (a booking, a unit, a claim) goes to its list, and filters naming a property or
     * unit are dropped.
     */
    public function update(Request $request)
    {
        $data = $request->validate(['category' => ['required', Rule::in(AdminCategory::OPTIONS)]]);
        $request->session()->put('admin_category', $data['category']);

        $back = url()->previous();
        $path = parse_url($back, PHP_URL_PATH) ?: '/admin';
        if (parse_url($back, PHP_URL_HOST) !== $request->getHost() || ! str_starts_with($path, '/admin') || AdminCategory::hides($path)) {
            return redirect('/admin');
        }

        if (preg_match('#^/admin/(bookings|rooms|incidents)/(\d+)(/edit)?$#', $path, $m)) {
            $business = match ($m[1]) {
                'bookings' => Booking::whereKey($m[2])->first()?->property()->value('category'),
                'rooms' => Room::whereKey($m[2])->first()?->property()->value('category'),
                'incidents' => Incident::whereKey($m[2])->first()?->room?->property()->value('category'),
            };
            if ($business !== null && $business !== $data['category']) {
                return redirect("/admin/{$m[1]}");
            }
        }

        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
        $query = array_diff_key($query, array_flip(self::RECORD_FILTERS));

        return redirect($path.($query ? '?'.http_build_query($query) : ''));
    }
}
