<?php

namespace App\Domain\Bookings;

use App\Domain\Finance\FinanceService;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Support\Money;
use App\Support\Settings;

/** Renders copy-ready text for staff to send themselves. Nothing is delivered automatically. */
class MessageService
{
    /**
     * What staff can type into a template, shown under Settings. The Quotation template is filled by
     * QuoteController and every other template by variables() below, so each has its own set.
     */
    public const PLACEHOLDERS = [
        ['key' => 'guest', 'description' => 'Guest\'s name', 'where' => 'all'],
        ['key' => 'room', 'description' => 'Suite name', 'where' => 'all'],
        ['key' => 'check_in', 'description' => 'Check-in date. Quotations also show the time', 'where' => 'all'],
        ['key' => 'check_out', 'description' => 'Check-out date. Quotations also show the time', 'where' => 'all'],
        ['key' => 'guests', 'description' => 'Number of guests', 'where' => 'all'],
        ['key' => 'total', 'description' => 'Total amount', 'where' => 'all'],
        ['key' => 'reference', 'description' => 'Booking reference number', 'where' => 'booking'],
        ['key' => 'property', 'description' => 'Building or location', 'where' => 'booking'],
        ['key' => 'balance', 'description' => 'Balance still to pay', 'where' => 'booking'],
        ['key' => 'check_in_time', 'description' => 'Standard check-in time', 'where' => 'booking'],
        ['key' => 'nights', 'description' => 'Number of nights', 'where' => 'quote'],
        ['key' => 'breakdown', 'description' => 'Rates and charges, line by line', 'where' => 'quote'],
        ['key' => 'payment', 'description' => 'Downpayment and balance upon check-in', 'where' => 'quote'],
        ['key' => 'pickup_time', 'description' => 'Camera pickup time', 'where' => 'booking'],
        ['key' => 'return_time', 'description' => 'Camera return time', 'where' => 'booking'],
        ['key' => 'days', 'description' => 'Number of rental days', 'where' => 'booking'],
    ];

    /** Templates whose key starts with this are for Grab Cam rentals; the rest are for suites. */
    public const CAMERA_PREFIX = 'camera_';

    public function __construct(private FinanceService $finance)
    {
    }

    public function renderAll(Booking $booking): array
    {
        $vars = $this->variables($booking);
        $camera = $booking->property->category === \App\Models\Property::CAMERAS;

        // Templates for one property only show on that property's bookings.
        return MessageTemplate::where('key', $camera ? 'like' : 'not like', self::CAMERA_PREFIX.'%')
            ->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', $booking->property_id))
            ->orderBy('id')->get()->map(fn ($t) => [
            'key' => $t->key,
            'name' => $t->name,
            'body' => $this->render($t->body, $vars),
        ])->all();
    }

    public function render(string $body, array $vars): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $body);
    }

    public function variables(Booking $booking): array
    {
        $booking->loadMissing(['guest', 'property', 'activeRooms.room']);
        $balance = $this->finance->balance($booking);

        return [
            'guest' => $booking->guest->full_name,
            'reference' => $booking->reference,
            'room' => $booking->activeRooms->pluck('room.name')->join(', '),
            'property' => $booking->property->location_label,
            'check_in' => $booking->check_in->format('M j, Y'),
            'check_out' => $booking->check_out->format('M j, Y'),
            'guests' => (string) $booking->guest_count,
            'total' => Money::format((int) $booking->total_cents),
            'balance' => Money::format(max(0, $balance['balance_cents'])),
            'check_in_time' => (string) Settings::get('pricing.standard_check_in'),
            'pickup_time' => $booking->requested_arrival_time ? date('g:i A', strtotime($booking->requested_arrival_time)) : 'the agreed time',
            'return_time' => $booking->return_time ? date('g:i A', strtotime($booking->return_time)) : 'the agreed time',
            'days' => (string) $booking->check_in->diffInDays($booking->check_out),
        ];
    }
}
