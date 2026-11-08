<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\InventoryService;
use App\Domain\Bookings\MessageService;
use App\Domain\Bookings\VacancySearch;
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
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/** A quotation never reserves inventory on its own. */
class QuoteController extends Controller
{
    public function __construct(private PricingService $pricing, private InventoryService $inventory, private MessageService $messages, private VacancySearch $vacancies)
    {
    }

    public function index()
    {
        $rows = Quote::with('room:id,name')->orderByDesc('id')->paginate(15);

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
            'reference' => 'nullable|string|exists:quotes,reference',
            'guest_name' => 'nullable|string|max:120',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'check_in' => 'nullable|date_format:Y-m-d',
            'check_out' => 'nullable|date_format:Y-m-d',
            'guests' => 'nullable|integer|min:1|max:30',
            'early_time' => 'nullable|date_format:H:i',
            'source_id' => 'nullable|integer|exists:booking_sources,id',
            'discount' => ['nullable', Money::RULE],
            'parking' => ['nullable', Money::RULE],
            'parking_nights' => 'nullable|integer|min:0|max:365',
            'parking_rate' => ['nullable', Money::RULE],
        ]);
        if (! empty($f['discount']) && ! $request->user()->can('bookings.discount')) {
            throw ValidationException::withMessages(['discount' => 'You are not authorized to apply discounts.']);
        }

        $preview = null;
        if (! empty($f['room_id']) && ! empty($f['check_in']) && ! empty($f['check_out'])) {
            $room = Room::findOrFail($f['room_id']);
            try {
                $problem = $this->cannotQuote($room, $f['check_in'], $f['check_out'], (int) ($f['guests'] ?? 1), $request->integer('booking_id') ?: null);
                if ($problem) {
                    throw new \App\Domain\Availability\RoomConflict($problem);
                }
                $percent = $this->downpaymentPercent($f['source_id'] ?? null);
                $preview = $this->withPayment($this->calculate($room, $f, $f['guests'] ?? 1), $percent);
                $preview['copyText'] = $this->message($room->name, $f['check_in'], $f['check_out'], (int) ($f['guests'] ?? 1), '{{guest}}', $preview['total_cents'], collect($preview['lines']), $percent);
            } catch (\App\Domain\Availability\RoomConflict $e) {
                $preview = ['error' => $e->getMessage()];
            }
        }

        $vacancy = ! empty($f['check_in']) && ! empty($f['check_out']) && $f['check_in'] < $f['check_out']
            ? $this->vacancies->find($f['check_in'], $f['check_out'], (int) ($f['guests'] ?? 1), $this->pricingOptions($f))
            : null;

        return Inertia::render('Admin/QuoteForm', [
            'rooms' => Room::where('operational_status', '!=', 'retired')->orderBy('id')->get()->map(fn ($r) => ['id' => $r->id, 'label' => "{$r->name} ({$r->unit_code})", 'max' => $r->max_guests, 'included' => $r->included_guests]),
            'sources' => BookingSource::where('is_active', true)->orderBy('id')->get(['id', 'name']),
            'filters' => $f,
            'preview' => $preview,
            'vacancy' => $vacancy,
            'bookingId' => $request->integer('booking_id') ?: null,
            'parkingRate' => Money::pesos((int) Settings::get('pricing.parking_night_cents')),
            'today' => Local::today(),
            'standardCheckIn' => (string) Settings::get('pricing.standard_check_in'),
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
            'parking_nights' => 'nullable|integer|min:0|max:365',
            'parking_rate' => ['nullable', Money::RULE],
            'booking_id' => 'nullable|exists:bookings,id',
            'reference' => 'nullable|string|exists:quotes,reference',
        ]);
        if (! empty($data['discount']) && ! $request->user()->can('bookings.discount')) {
            abort(403, 'You are not authorized to apply discounts.');
        }

        $room = Room::findOrFail($data['room_id']);
        $problem = $this->cannotQuote($room, $data['check_in'], $data['check_out'], (int) $data['guests'], $data['booking_id'] ?? null);
        if ($problem) {
            throw ValidationException::withMessages(['room_id' => $problem]);
        }
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
                'downpayment_percent' => $this->downpaymentPercent($data['source_id'] ?? null),
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

    /** Read just before copying, because the screen may have been open while someone else took the dates. */
    public function check(Request $request)
    {
        $data = $request->validate(['room_id' => 'required|exists:rooms,id', 'check_in' => 'required|date_format:Y-m-d', 'check_out' => 'required|date_format:Y-m-d|after:check_in', 'guests' => 'required|integer|min:1|max:30', 'booking_id' => 'nullable|exists:bookings,id']);
        $problem = $this->cannotQuote(Room::findOrFail($data['room_id']), $data['check_in'], $data['check_out'], (int) $data['guests'], $data['booking_id'] ?? null);

        return response()->json(['available' => $problem === null, 'message' => $problem])->header('Cache-Control', 'no-store');
    }

    /** A guest must never be quoted dates they cannot have. */
    private function cannotQuote(Room $room, string $checkIn, string $checkOut, int $guests, ?int $bookingId): ?string
    {
        if ($checkIn >= $checkOut) {
            return 'Check-out must be after check-in.';
        }
        if ($checkIn < Local::today()) {
            return 'Those dates have passed.';
        }
        $reason = $this->vacancies->reasonUnavailable($room, $checkIn, $checkOut, $guests, $bookingId);

        return $reason ? "{$room->name} is not available for these dates. {$reason} Please choose a vacant unit." : null;
    }

    public function show(Quote $quote)
    {
        $quote->load(['items', 'room.property']);
        $history = Quote::where('reference', $quote->reference)->orderByDesc('version')->get(['id', 'version', 'total_cents', 'created_at']);

        return Inertia::render('Admin/QuoteShow', [
            'quote' => [
                'id' => $quote->id,
                'booking_id' => $quote->booking_id,
                'reference' => $quote->reference,
                'version' => $quote->version,
                'guest_name' => $quote->guest_name,
                'room' => $quote->room->name,
                'room_id' => $quote->room_id,
                'property' => $quote->room->property->location_label,
                'check_in' => $quote->check_in->toDateString(),
                'check_out' => $quote->check_out->toDateString(),
                'guest_count' => $quote->guest_count,
                'source_id' => $quote->booking_source_id,
                'early_time' => $quote->early_time ? substr($quote->early_time, 0, 5) : null,
                'total_cents' => (int) $quote->total_cents,
                'downpayment_percent' => $quote->downpayment_percent ? (int) $quote->downpayment_percent : null,
                'downpayment_cents' => $quote->downpayment_percent ? $this->split((int) $quote->total_cents, (int) $quote->downpayment_percent)[0] : null,
                'balance_cents' => $quote->downpayment_percent ? $this->split((int) $quote->total_cents, (int) $quote->downpayment_percent)[1] : null,
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

        $percent = $quote->downpayment_percent ? (int) $quote->downpayment_percent : null;
        [$down, $balance] = $percent ? $this->split((int) $quote->total_cents, $percent) : [null, null];

        return Pdf::loadView('pdf.quote', ['quote' => $quote, 'logo' => public_path('brand/uniqa-wordmark.png'), 'percent' => $percent, 'downpayment' => $down, 'balance' => $balance])
            ->download("UNIQA-{$quote->reference}-v{$quote->version}.pdf");
    }

    private function calculate(Room $room, array $f, int $guests): array
    {
        return $this->pricing->quote($room, $f['check_in'], $f['check_out'], $guests, $this->pricingOptions($f));
    }

    private function pricingOptions(array $f): array
    {
        return [
            'source_id' => $f['source_id'] ?? null,
            'early_time' => $f['early_time'] ?? null,
            'discount_cents' => Money::cents($f['discount'] ?? null) ?? 0,
            'parking_cents' => Money::cents($f['parking'] ?? null) ?? 0,
            'parking_nights' => (int) ($f['parking_nights'] ?? 0),
            'parking_rate_cents' => Money::cents($f['parking_rate'] ?? null) ?? 0,
        ];
    }

    private function copyText(Quote $quote): string
    {
        return $this->message($quote->room->name, $quote->check_in->toDateString(), $quote->check_out->toDateString(), (int) $quote->guest_count, $quote->guest_name ?: 'there', (int) $quote->total_cents, $quote->items, $quote->downpayment_percent ? (int) $quote->downpayment_percent : null);
    }

    /** What the guest reads: the owner's template, filled in with the stay, the charges and what to pay now. */
    private function message(string $room, string $checkIn, string $checkOut, int $guests, string $guest, int $totalCents, $lines, ?int $percent): string
    {
        $times = fn (string $date, mixed $time) => CarbonImmutable::parse($date)->format('M j, Y').($time ? ' ('.CarbonImmutable::parse("2000-01-01 {$time}")->format('g:i A').')' : '');
        $breakdown = $this->breakdown($lines);
        $payment = '';
        if ($percent) {
            [$down, $balance] = $this->split($totalCents, $percent);
            $payment = "{$percent}% Downpayment: ".Money::plain($down)."\nBalance upon check-in: ".Money::plain($balance);
        }

        $template = MessageTemplate::where('key', 'quote')->value('body') ?? '';
        $text = $this->messages->render($template, [
            'guest' => $guest,
            'room' => $room,
            'check_in' => $times($checkIn, Settings::get('pricing.standard_check_in')),
            'check_out' => $times($checkOut, Settings::get('pricing.standard_check_out')),
            'nights' => (string) CarbonImmutable::parse($checkIn)->diffInDays($checkOut),
            'guests' => (string) $guests,
            'total' => Money::plain($totalCents),
            'breakdown' => $breakdown,
            'payment' => $payment,
        ]);

        // A template someone rewrote may not ask for these, and a quote must still show them.
        if (! str_contains($template, '{{breakdown}}')) {
            $text .= "\n\nRATE BREAKDOWN\n{$breakdown}";
        }
        if ($payment && ! str_contains($template, '{{payment}}')) {
            $text .= "\n\n{$payment}";
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** Nights at the same rate are one line, then each other charge in plain words. */
    private function breakdown($lines): string
    {
        $nights = [];
        $others = [];
        foreach ($lines as $line) {
            $description = (string) data_get($line, 'description');
            $amount = (int) data_get($line, 'amount_cents');

            if (data_get($line, 'type') === 'accommodation') {
                $label = str_replace(' rate', '', preg_replace('/^\d{4}-\d{2}-\d{2} · /u', '', $description));
                $key = $label.'|'.$amount;
                $nights[$key] = ['label' => $label, 'unit' => $amount, 'count' => ($nights[$key]['count'] ?? 0) + 1];
                continue;
            }
            $others[] = (data_get($line, 'type') === 'extra_guest' ? 'Additional guests' : $description).': '.Money::plain($amount);
        }

        return collect($nights)->map(fn ($n) => "{$n['label']} ({$n['count']} × ".Money::plain($n['unit']).'): '.Money::plain($n['count'] * $n['unit']))->values()->concat($others)->join("\n");
    }

    /** @return array{0: int, 1: int} Rounded half up in whole centavos. The two always add up to the total. */
    private function split(int $totalCents, int $percent): array
    {
        $down = intdiv($totalCents * $percent + 50, 100);

        return [$down, $totalCents - $down];
    }

    /** Booking source is informational and does not change the payment terms. */
    private function downpaymentPercent(mixed $sourceId): ?int
    {
        $percent = (int) Settings::get('finance.downpayment_percent');
        if ($percent <= 0) {
            return null;
        }

        return $percent;
    }

    private function withPayment(array $calc, ?int $percent): array
    {
        [$down, $balance] = $percent ? $this->split($calc['total_cents'], $percent) : [null, null];

        return $calc + ['downpayment_percent' => $percent, 'downpayment_cents' => $down, 'balance_cents' => $balance];
    }
}
