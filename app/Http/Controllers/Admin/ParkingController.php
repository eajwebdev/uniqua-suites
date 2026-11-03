<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\RoomConflict;
use App\Domain\Bookings\BookingService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\ParkingAssignment;
use App\Models\ParkingSpace;
use App\Models\Property;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ParkingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $today = Local::today();
        $spaces = ParkingSpace::with(['property:id,name', 'assignments' => fn ($q) => $q->where('status', 'active')->where('end_date', '>', $today)->orderBy('start_date')])->orderBy('property_id')->get();
        $canManage = $user->can('parking.manage');

        $rows = collect();
        foreach ($spaces as $s) {
            $rows->push([
                'id' => 's'.$s->id,
                'space' => $s->code,
                'property' => $s->property->name,
                'vendor' => $s->vendor_name,
                'contact' => $canManage ? $s->vendor_contact : null,
                'rate_cents' => $s->rate_cents === null ? null : (int) $s->rate_cents,
                'rate_status' => $s->rate_status,
                'upcoming' => $s->assignments->map(fn ($a) => "{$a->start_date->toDateString()} to {$a->end_date->toDateString()}")->join('; ') ?: 'Free',
                '_actions' => $s->assignments->map(fn ($a) => ['label' => "Cancel {$a->start_date->format('M j')}", 'url' => "/admin/parking/assignments/{$a->id}/cancel", 'confirm' => 'Cancel this parking assignment?', 'variant' => 'danger'])->values()->all(),
            ]);
        }

        return Inertia::render('Admin/Resource', [
            'title' => 'Parking',
            'subtitle' => 'Separate inventory from rooms. Source rates (₱350 The Loop, ₱300 Mesaverte) are proposed until confirmed per space and vendor. Add the real stalls here; vendor contacts stay staff-only.',
            'columns' => [
                ['key' => 'space', 'label' => 'Space'], ['key' => 'property', 'label' => 'Property'], ['key' => 'vendor', 'label' => 'Vendor'], ['key' => 'contact', 'label' => 'Contact'],
                ['key' => 'rate_cents', 'label' => 'Rate', 'type' => 'money'], ['key' => 'rate_status', 'label' => 'Rate status', 'type' => 'status'], ['key' => 'upcoming', 'label' => 'Assigned'],
            ],
            'rows' => $rows->values(),
            'create' => $canManage ? ['label' => 'Assign parking', 'url' => '/admin/parking/assignments', 'fields' => [
                ['name' => 'parking_space_id', 'label' => 'Space', 'type' => 'select', 'required' => true, 'options' => $spaces->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->code} ({$s->property->name})"])->all()],
                ['name' => 'holder_name', 'label' => 'Guest or holder', 'type' => 'text', 'required' => true],
                ['name' => 'booking_reference', 'label' => 'Booking reference', 'type' => 'text'],
                ['name' => 'vehicle', 'label' => 'Vehicle', 'type' => 'text'],
                ['name' => 'start_date', 'label' => 'From', 'type' => 'date', 'required' => true, 'default' => $today],
                ['name' => 'end_date', 'label' => 'To (exclusive)', 'type' => 'date', 'required' => true],
                ['name' => 'add_charge', 'label' => 'Add the parking charge to the booking', 'type' => 'checkbox'],
            ]] : null,
            'secondaryCreate' => $canManage ? ['label' => 'Add space', 'url' => '/admin/parking/spaces', 'fields' => [
                ['name' => 'property_id', 'label' => 'Property', 'type' => 'select', 'required' => true, 'options' => Property::orderBy('id')->get()->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all()],
                ['name' => 'code', 'label' => 'Stall or space ID', 'type' => 'text', 'required' => true],
                ['name' => 'vendor_name', 'label' => 'Owner or vendor', 'type' => 'text'],
                ['name' => 'vendor_contact', 'label' => 'Contact (staff only)', 'type' => 'text'],
                ['name' => 'rate', 'label' => 'Rate per day (PHP)', 'type' => 'money'],
                ['name' => 'cost', 'label' => 'Cost (PHP)', 'type' => 'money'],
                ['name' => 'restrictions', 'label' => 'Restrictions', 'type' => 'text'],
            ]] : null,
        ]);
    }

    public function storeSpace(Request $request)
    {
        $data = $request->validate(['property_id' => 'required|exists:properties,id', 'code' => 'required|string|max:60', 'vendor_name' => 'nullable|string|max:120', 'vendor_contact' => 'nullable|string|max:60', 'rate' => ['nullable', Money::RULE], 'cost' => ['nullable', Money::RULE], 'restrictions' => 'nullable|string|max:255']);
        ParkingSpace::create([
            'property_id' => $data['property_id'], 'code' => $data['code'], 'vendor_name' => $data['vendor_name'] ?? null, 'vendor_contact' => $data['vendor_contact'] ?? null,
            'rate_cents' => Money::cents($data['rate'] ?? null), 'cost_cents' => Money::cents($data['cost'] ?? null), 'restrictions' => $data['restrictions'] ?? null,
        ]);

        return back()->with('success', 'Parking space added.');
    }

    /** Locks the space row first, so two staff cannot give the same stall to two guests. */
    public function storeAssignment(Request $request, BookingService $bookings)
    {
        $data = $request->validate([
            'parking_space_id' => 'required|exists:parking_spaces,id',
            'holder_name' => 'required|string|max:120',
            'booking_reference' => 'nullable|exists:bookings,reference',
            'vehicle' => 'nullable|string|max:80',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after:start_date',
            'add_charge' => 'nullable|boolean',
        ]);
        $booking = ! empty($data['booking_reference']) ? Booking::where('reference', $data['booking_reference'])->first() : null;
        if (! empty($data['add_charge']) && ! $booking) {
            throw new RoomConflict('Enter a booking reference to add a parking charge.');
        }

        DB::transaction(function () use ($data, $booking, $request, $bookings) {
            $space = ParkingSpace::lockForUpdate()->findOrFail($data['parking_space_id']);
            $clash = ParkingAssignment::where('parking_space_id', $space->id)->where('status', 'active')
                ->where('start_date', '<', $data['end_date'])->where('end_date', '>', $data['start_date'])->first();
            if ($clash) {
                throw new RoomConflict("{$space->code} is already assigned for {$clash->start_date->toDateString()} to {$clash->end_date->toDateString()}.");
            }

            $chargeId = null;
            if (! empty($data['add_charge'])) {
                if ($space->rate_cents === null) {
                    throw new RoomConflict("{$space->code} has no rate set.");
                }
                $days = (int) CarbonImmutable::parse($data['start_date'])->diffInDays(CarbonImmutable::parse($data['end_date']));
                $charge = BookingCharge::create([
                    'booking_id' => $booking->id, 'type' => 'parking', 'description' => "Parking {$space->code}, {$days} day(s)", 'quantity' => $days,
                    'unit_cents' => $space->rate_cents, 'amount_cents' => $days * (int) $space->rate_cents, 'charge_date' => $data['start_date'],
                    'pricing_version' => $booking->pricing_version, 'approved_by' => $request->user()->id, 'created_by' => $request->user()->id,
                ]);
                $bookings->syncTotal($booking);
                $chargeId = $charge->id;
            }
            $assignment = ParkingAssignment::create([
                'parking_space_id' => $space->id, 'booking_id' => $booking?->id, 'holder_name' => $data['holder_name'], 'vehicle' => $data['vehicle'] ?? null,
                'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'charge_id' => $chargeId, 'created_by' => $request->user()->id,
            ]);
            Audit::log('parking.assigned', $assignment);
        });

        return back()->with('success', 'Parking assigned.');
    }

    public function cancelAssignment(ParkingAssignment $assignment, BookingService $bookings)
    {
        DB::transaction(function () use ($assignment, $bookings) {
            $assignment->update(['status' => 'cancelled']);
            if ($assignment->charge_id) {
                BookingCharge::whereKey($assignment->charge_id)->update(['voided_at' => now(), 'void_reason' => 'Parking cancelled']);
                $bookings->syncTotal($assignment->booking);
            }
            Audit::log('parking.cancelled', $assignment);
        });

        return back()->with('success', 'Parking cancelled.');
    }
}
