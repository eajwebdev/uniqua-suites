<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\InventoryService;
use App\Domain\Bookings\MessageService;
use App\Domain\Pricing\PricingService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSource;
use App\Models\MessageTemplate;
use App\Models\Quote;
use App\Models\Room;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/** A quotation never reserves inventory on its own. */
class QuoteController extends Controller
{
    public function __construct(private PricingService $pricing, private InventoryService $inventory, private MessageService $messages)
    {
    }

    public function index()
    {
        $rows = Quote::with('room:id,name')->orderByDesc('id')->paginate(30);

        return Inertia::render('Admin/Resource', [
            'title' => 'Quotes',
            'subtitle' => 'Each save creates a version. A quote does not reserve the room.',
            'headerLink' => ['label' => 'New quote', 'href' => '/admin/quotes/create'],
            'columns' => [
                ['key' => 'ref', 'label' => 'Reference', 'type' => 'link'],
                ['key' => 'version', 'label' => 'Ver.'],
                ['key' => 'guest', 'label' => 'Guest'],
                ['key' => 'room', 'label' => 'Room'],
                ['key' => 'check_in', 'label' => 'Check-in', 'type' => 'date'],
                ['key' => 'check_out', 'label' => 'Check-out', 'type' => 'date'],
                ['key' => 'total_cents', 'label' => 'Total', 'type' => 'money'],
            ],
            'rows' => $rows->through(fn ($q) => [
                'id' => $q->id,
                'ref' => ['label' => $q->reference, 'href' => "/admin/quotes/{$q->id}"],
                'version' => $q->version,
                'guest' => $q->guest_name,
                'room' => $q->room->name,
                'check_in' => $q->check_in->toDateString(),
                'check_out' => $q->check_out->toDateString(),
                'total_cents' => (int) $q->total_cents,
            ]),
        ]);
    }

    public function create(Request $request)
    {
        $f = $request->validate([
            'room_id' => 'nullable|integer|exists:rooms,id',
            'check_in' => 'nullable|date_format:Y-m-d',
            'check_out' => 'nullable|date_format:Y-m-d',
            'guests' => 'nullable|integer|min:1|max:30',
            'early_time' => 'nullable|date_format:H:i',
            'source_id' => 'nullable|integer',
            'discount' => ['nullable', Money::RULE],
            'parking' => ['nullable', Money::RULE],
        ]);

        $preview = null;
        if (! empty($f['room_id']) && ! empty($f['check_in']) && ! empty($f['check_out'])) {
            $room = Room::findOrFail($f['room_id']);
            try {
                $preview = $this->calculate($room, $f, $f['guests'] ?? 1);
                $preview['conflicts'] = $f['check_in'] < $f['check_out'] ? $this->inventory->conflicts($room->id, $f['check_in'], $f['check_out'])->pluck('label') : [];
            } catch (\App\Domain\Availability\RoomConflict $e) {
                $preview = ['error' => $e->getMessage()];
            }
        }

        return Inertia::render('Admin/QuoteForm', [
            'rooms' => Room::where('operational_status', '!=', 'retired')->orderBy('id')->get()->map(fn ($r) => ['id' => $r->id, 'label' => "{$r->name} ({$r->unit_code})", 'max' => $r->max_guests, 'included' => $r->included_guests]),
            'sources' => BookingSource::where('is_active', true)->orderBy('id')->get(['id', 'name']),
            'filters' => $f,
            'preview' => $preview,
            'bookingId' => $request->integer('booking_id') ?: null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d|after:check_in',
            'guests' => 'required|integer|min:1|max:30',
            'guest_name' => 'nullable|string|max:120',
            'early_time' => 'nullable|date_format:H:i',
            'source_id' => 'nullable|exists:booking_sources,id',
            'discount' => ['nullable', Money::RULE],
            'parking' => ['nullable', Money::RULE],
            'booking_id' => 'nullable|exists:bookings,id',
            'reference' => 'nullable|string|exists:quotes,reference',
        ]);
        if (! empty($data['discount']) && ! $request->user()->can('bookings.discount')) {
            abort(403, 'You are not authorized to apply discounts.');
        }

        $room = Room::findOrFail($data['room_id']);
        $calc = $this->calculate($room, $data, (int) $data['guests']);

        $quote = DB::transaction(function () use ($data, $calc, $request) {
            $reference = $data['reference'] ?? 'Q-'.Local::now()->format('ym').'-'.Str::upper(Str::random(5));
            $version = (int) Quote::where('reference', $reference)->lockForUpdate()->max('version') + 1;
            $quote = Quote::create([
                'reference' => $reference,
                'version' => $version,
                'booking_id' => $data['booking_id'] ?? null,
                'room_id' => $data['room_id'],
                'booking_source_id' => $data['source_id'] ?? null,
                'guest_name' => $data['guest_name'] ?? null,
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'guest_count' => $data['guests'],
                'early_time' => $data['early_time'] ?? null,
                'total_cents' => $calc['total_cents'],
                'warnings' => $calc['warnings'],
                'created_by' => $request->user()->id,
            ]);
            foreach ($calc['lines'] as $l) {
                $quote->items()->create(collect($l)->only(['type', 'description', 'quantity', 'unit_cents', 'amount_cents'])->all());
            }
            Audit::log('quote.saved', $quote, ['version' => $version]);

            return $quote;
        });

        return redirect("/admin/quotes/{$quote->id}")->with('success', 'Quote saved. Copy or download it below; it has not been sent.');
    }

    public function show(Quote $quote)
    {
        $quote->load(['items', 'room.property']);
        $history = Quote::where('reference', $quote->reference)->orderByDesc('version')->get(['id', 'version', 'total_cents', 'created_at']);

        return Inertia::render('Admin/QuoteShow', [
            'quote' => [
                'id' => $quote->id,
                'reference' => $quote->reference,
                'version' => $quote->version,
                'guest_name' => $quote->guest_name,
                'room' => $quote->room->name,
                'room_id' => $quote->room_id,
                'property' => $quote->room->property->location_label,
                'check_in' => $quote->check_in->toDateString(),
                'check_out' => $quote->check_out->toDateString(),
                'guest_count' => $quote->guest_count,
                'early_time' => $quote->early_time ? substr($quote->early_time, 0, 5) : null,
                'total_cents' => (int) $quote->total_cents,
                'warnings' => $quote->warnings ?? [],
                'items' => $quote->items,
            ],
            'history' => $history,
            'copyText' => $this->copyText($quote),
        ]);
    }

    public function pdf(Quote $quote)
    {
        $quote->load(['items', 'room.property']);

        return Pdf::loadView('pdf.quote', ['quote' => $quote, 'logo' => public_path('brand/uniqa-wordmark.png')])
            ->download("UNIQA-{$quote->reference}-v{$quote->version}.pdf");
    }

    private function calculate(Room $room, array $f, int $guests): array
    {
        return $this->pricing->quote($room, $f['check_in'], $f['check_out'], $guests, [
            'source_id' => $f['source_id'] ?? null,
            'early_time' => $f['early_time'] ?? null,
            'discount_cents' => Money::cents($f['discount'] ?? null) ?? 0,
            'discount_reason' => 'approved',
            'parking_cents' => Money::cents($f['parking'] ?? null) ?? 0,
        ]);
    }

    private function copyText(Quote $quote): string
    {
        $template = MessageTemplate::where('key', 'quote')->value('body') ?? '';
        $text = $this->messages->render($template, [
            'guest' => $quote->guest_name ?: 'there',
            'room' => $quote->room->name,
            'check_in' => $quote->check_in->format('M j, Y'),
            'check_out' => $quote->check_out->format('M j, Y'),
            'guests' => (string) $quote->guest_count,
            'total' => Money::format((int) $quote->total_cents),
        ]);
        $lines = $quote->items->map(fn ($i) => '- '.$i->description.': '.Money::format((int) $i->amount_cents))->join("\n");

        return $text."\n\nBreakdown:\n".$lines;
    }
}
