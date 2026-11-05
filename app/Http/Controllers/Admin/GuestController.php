<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Finance\FinanceService;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GuestController extends Controller
{
    private const FIELDS = [
        ['name' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'text'],
        ['name' => 'email', 'label' => 'Email', 'type' => 'text'],
        ['name' => 'messenger_handle', 'label' => 'Messenger / Instagram', 'type' => 'text'],
        ['name' => 'company', 'label' => 'Company', 'type' => 'text'],
        ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
    ];

    public function index(Request $request)
    {
        $q = $request->validate(['q' => 'nullable|string|max:80'])['q'] ?? null;
        $rows = Guest::withCount('bookings')
            ->when($q, fn ($b, $v) => $b->where(fn ($w) => $w->where('full_name', 'like', "%{$v}%")->orWhere('phone', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->orderByDesc('id')->paginate(15)->withQueryString();

        return Inertia::render('Admin/Resource', [
            'title' => 'Guests',
            'search' => ['value' => $q, 'placeholder' => 'Search name, phone or email'],
            'columns' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'link'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'bookings_count', 'label' => 'Bookings'],
            ],
            'rows' => $rows->through(fn ($g) => [
                'id' => $g->id,
                'name' => ['label' => $g->full_name, 'href' => "/admin/guests/{$g->id}"],
                'phone' => $g->phone,
                'email' => $g->email,
                'bookings_count' => $g->bookings_count,
            ]),
            'create' => $request->user()->can('guests.manage') ? ['label' => 'Add guest', 'url' => '/admin/guests', 'fields' => self::FIELDS] : null,
        ]);
    }

    public function show(Request $request, Guest $guest, FinanceService $finance)
    {
        $canPay = $request->user()->can('payments.view');
        $bookings = $guest->bookings()->with(['source:id,name', 'activeRooms.room:id,name'])->orderByDesc('check_in')->get();

        return Inertia::render('Admin/Resource', [
            'title' => $guest->full_name,
            'subtitle' => collect([$guest->phone, $guest->email, $guest->messenger_handle])->filter()->join(' · ') ?: 'No contact details on file',
            'note' => $guest->notes,
            'backHref' => '/admin/guests',
            'columns' => array_values(array_filter([
                ['key' => 'ref', 'label' => 'Booking', 'type' => 'link'],
                ['key' => 'rooms', 'label' => 'Rooms'],
                ['key' => 'source', 'label' => 'Source'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                ['key' => 'check_in', 'label' => 'Check-in', 'type' => 'date'],
                ['key' => 'check_out', 'label' => 'Check-out', 'type' => 'date'],
                ['key' => 'total_cents', 'label' => 'Total', 'type' => 'money'],
                $canPay ? ['key' => 'balance_cents', 'label' => 'Balance', 'type' => 'money'] : null,
            ])),
            'rows' => $bookings->map(fn ($b) => [
                'id' => $b->id,
                'ref' => ['label' => $b->reference, 'href' => "/admin/bookings/{$b->id}"],
                'rooms' => $b->activeRooms->pluck('room.name')->join(', '),
                'source' => $b->source->name,
                'status' => $b->status,
                'check_in' => $b->check_in->toDateString(),
                'check_out' => $b->check_out->toDateString(),
                'total_cents' => (int) $b->total_cents,
                'balance_cents' => $canPay ? $finance->balance($b)['balance_cents'] : null,
            ]),
            'create' => $request->user()->can('guests.manage') ? [
                'label' => 'Edit guest',
                'url' => "/admin/guests/{$guest->id}",
                'fields' => array_map(fn ($f) => $f + ['default' => $guest->{$f['name']}], self::FIELDS),
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        Guest::create($this->validated($request));

        return back()->with('success', 'Guest added.');
    }

    public function update(Request $request, Guest $guest)
    {
        $guest->update($this->validated($request));

        return back()->with('success', 'Guest updated.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'full_name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120',
            'messenger_handle' => 'nullable|string|max:120',
            'company' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:2000',
        ]);
    }
}
