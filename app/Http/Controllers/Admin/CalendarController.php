<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Support\Local;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'], 'property' => 'nullable|integer']);
        $start = CarbonImmutable::parse(($data['month'] ?? Local::now()->format('Y-m')).'-01');
        $end = $start->addMonth();

        $rooms = Room::where('operational_status', '!=', 'retired')
            ->when($data['property'] ?? null, fn ($q, $v) => $q->where('property_id', $v))
            ->with('property:id,name')->orderBy('property_id')->orderBy('id')->get();

        $events = DB::table('booking_rooms as br')->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->join('booking_sources as s', 's.id', '=', 'b.booking_source_id')->join('guests as g', 'g.id', '=', 'b.guest_id')
            ->whereNull('br.released_at')->whereIn('br.room_id', $rooms->pluck('id'))
            ->where('br.check_in', '<', $end->toDateString())->where('br.check_out', '>', $start->toDateString())
            ->whereIn('b.status', [Booking::INQUIRY, Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN, Booking::CHECKED_OUT])
            ->get(['b.id', 'b.reference', 'b.status', 'br.room_id', 'br.check_in', 'br.check_out', 's.name as source', 'g.full_name as guest']);

        $blocks = DB::table('room_blocks')->whereNull('released_at')->whereIn('room_id', $rooms->pluck('id'))
            ->where('start_date', '<', $end->toDateString())->where('end_date', '>', $start->toDateString())
            ->get(['id', 'room_id', 'start_date', 'end_date', 'kind', 'reason']);

        return Inertia::render('Admin/Calendar', [
            'month' => $start->format('Y-m'),
            'label' => $start->format('F Y'),
            'prev' => $start->subMonth()->format('Y-m'),
            'next' => $start->addMonth()->format('Y-m'),
            'days' => $start->daysInMonth,
            'today' => Local::today(),
            'weekend_days' => Settings::get('pricing.weekend_days') ?? [],
            'property' => $data['property'] ?? null,
            'properties' => Property::orderBy('id')->get(['id', 'name']),
            'rooms' => $rooms->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'unit' => $r->unit_code, 'property' => $r->property->name, 'housekeeping' => $r->housekeeping_status]),
            'events' => $events,
            'blocks' => $blocks,
        ]);
    }
}
