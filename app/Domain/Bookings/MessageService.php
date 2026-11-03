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
    public function __construct(private FinanceService $finance)
    {
    }

    public function renderAll(Booking $booking): array
    {
        $vars = $this->variables($booking);

        return MessageTemplate::orderBy('id')->get()->map(fn ($t) => [
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
        ];
    }
}
