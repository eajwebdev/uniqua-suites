<?php

namespace Tests\Feature;

use App\Domain\Bookings\MessageService;
use App\Models\MessageTemplate;
use App\Models\RateRule;
use Tests\DomainTestCase;

/** The list under Settings is a promise: everything on it must really be filled in, where it says. */
class TemplatePlaceholdersTest extends DomainTestCase
{
    private function keys(string $scope): array
    {
        return array_column(array_filter(MessageService::PLACEHOLDERS, fn ($p) => $p['where'] === 'all' || $p['where'] === $scope), 'key');
    }

    private function allOf(string $scope): string
    {
        return implode(' ', array_map(fn ($k) => "{{{$k}}}", $this->keys($scope)));
    }

    public function test_settings_lists_the_placeholders(): void
    {
        $props = $this->get('/admin/settings')->assertOk()->original->getData()['page']['props'];

        $this->assertSame(MessageService::PLACEHOLDERS, $props['placeholders']);
    }

    public function test_every_booking_placeholder_on_the_list_is_filled_in(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $service = app(MessageService::class);

        $this->assertEqualsCanonicalizing($this->keys('booking'), array_keys($service->variables($booking)), 'The list and the real values must not drift apart.');

        MessageTemplate::where('key', 'thank_you')->update(['body' => $this->allOf('booking')]);
        $body = collect($service->renderAll($booking))->firstWhere('key', 'thank_you')['body'];

        $this->assertStringNotContainsString('{{', $body);
    }

    public function test_every_quotation_placeholder_on_the_list_is_filled_in(): void
    {
        $room = $this->room('milan');
        RateRule::create(['room_id' => $room->id, 'effective_from' => '2031-01-01', 'weekday_cents' => 260000, 'weekend_cents' => 280000]);
        MessageTemplate::where('key', 'quote')->update(['body' => $this->allOf('quote')]);

        $query = ['room_id' => $room->id, 'check_in' => '2031-03-06', 'check_out' => '2031-03-10', 'guests' => 6];
        $this->post('/admin/quotes', $query + ['guest_name' => 'Synthetic'])->assertSessionHasNoErrors();
        $quote = \App\Models\Quote::sole();

        $text = $this->get("/admin/quotes/{$quote->id}")->assertOk()->original->getData()['page']['props']['copyText'];

        $this->assertStringNotContainsString('{{', $text);
    }

    public function test_every_template_as_shipped_only_uses_placeholders_that_work_in_it(): void
    {
        foreach (MessageTemplate::all() as $template) {
            preg_match_all('/\{\{(\w+)\}\}/', $template->body, $used);
            $allowed = $this->keys($template->key === 'quote' ? 'quote' : 'booking');

            $this->assertSame([], array_values(array_diff($used[1], $allowed)), "{$template->key} uses a placeholder that would reach the guest unfilled.");
        }
    }
}
