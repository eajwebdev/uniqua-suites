<?php

namespace Tests\Feature;

use App\Domain\Availability\RoomConflict;
use App\Domain\LegacyImport\WorkbookImporter;
use App\Domain\Reporting\ReportService;
use App\Models\Booking;
use App\Models\ImportBatch;
use App\Models\LegacyRow;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Support\Local;
use Tests\DomainTestCase;

/** Going off the spreadsheet: its future stays must protect the calendar, and its history must still print. */
class WorkbookHandoverTest extends DomainTestCase
{
    private ImportBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->batch = ImportBatch::create(['file_name' => 'synthetic.xlsx', 'stored_path' => 'x', 'checksum' => str_repeat('a', 64), 'status' => 'staged', 'transformation_version' => 'test']);
    }

    private function day(int $offset): string
    {
        return Local::now()->addDays($offset)->toDateString();
    }

    private function sale(?string $slug, int $offset, ?string $guest = 'Synthetic Sheet Guest', int $cents = 200000, array $extra = []): LegacyRow
    {
        static $n = 0;
        $n++;

        return LegacyRow::create($extra + [
            'import_batch_id' => $this->batch->id,
            'source_key' => "test|{$n}",
            'record_type' => 'daily_sale',
            'sheet' => 'SYNTHETIC',
            'row_number' => $n,
            'raw' => ['REMARKS' => 'Synthetic remark'],
            'room_label' => $slug ? strtoupper($slug) : 'UNKNOWN LABEL',
            'room_id' => $slug ? $this->room($slug)->id : null,
            'record_date' => $this->day($offset),
            'guest_name' => $guest,
            'source_label' => 'AIRBNB',
            'payment_label' => 'BPI',
            'amount_cents' => $cents,
            'extra_cents' => 0,
        ]);
    }

    private function hold(): array
    {
        return app(WorkbookImporter::class)->holdFutureDates($this->batch);
    }

    private function blocks(string $slug)
    {
        return RoomBlock::where('room_id', $this->room($slug)->id)->whereNull('released_at')->orderBy('start_date')->get();
    }

    private function report(string $from, string $to, ?int $property = null): array
    {
        return app(ReportService::class)->table('sales_recording', $from, $to, $property);
    }

    public function test_future_workbook_stays_become_merged_holds_and_past_ones_do_not(): void
    {
        $this->sale('venice', -3);
        $this->sale('venice', 2);
        $this->sale('venice', 3);
        $this->sale('venice', 4, 'Another Sheet Guest');
        $this->sale('venice', 8);

        $this->assertSame(['held_nights' => 4, 'already_taken' => 0, 'unmatched_nights' => 0], $this->hold());

        $this->assertSame(
            [[$this->day(2), $this->day(4)], [$this->day(4), $this->day(5)], [$this->day(8), $this->day(9)]],
            $this->blocks('venice')->map(fn ($b) => [$b->start_date->toDateString(), $b->end_date->toDateString()])->all(),
        );
    }

    public function test_held_dates_show_as_taken_on_the_website_without_the_guest_name(): void
    {
        $this->sale('venice', 2);
        $this->hold();
        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved']);
        auth()->logout();

        $this->assertFalse($this->getJson("/suites/venice/check-dates?check_in={$this->day(2)}&check_out={$this->day(3)}")->json('available'));
        $body = $this->get('/suites/venice')->getContent();
        $this->assertStringContainsString($this->day(2), $body);
        $this->assertStringNotContainsString('Synthetic Sheet Guest', $body);
        $this->assertStringNotContainsString('workbook', $body);
    }

    public function test_staff_cannot_book_over_a_hold_until_it_is_released(): void
    {
        $this->sale('venice', 2);
        $this->hold();

        try {
            $this->confirmed('venice', $this->day(2), $this->day(3));
            $this->fail('A held night must refuse a booking.');
        } catch (RoomConflict) {
            $this->assertSame(0, Booking::where('status', Booking::CONFIRMED)->count());
        }

        $this->post('/admin/blocks/'.$this->blocks('venice')->first()->id.'/release')->assertSessionHasNoErrors();
        $this->assertSame(Booking::CONFIRMED, $this->confirmed('venice', $this->day(2), $this->day(3))->status);
    }

    public function test_holding_again_adds_nothing_and_skips_nights_already_taken(): void
    {
        $this->sale('venice', 2);
        $this->sale('venice', 3);
        $this->confirmed('venice', $this->day(3), $this->day(4));

        $this->assertSame(['held_nights' => 1, 'already_taken' => 1, 'unmatched_nights' => 0], $this->hold());
        $this->assertSame(['held_nights' => 0, 'already_taken' => 2, 'unmatched_nights' => 0], $this->hold());
        $this->assertCount(1, $this->blocks('venice'));
    }

    public function test_an_unmatched_room_label_is_reported_not_silently_dropped(): void
    {
        $this->sale(null, 2);

        $held = $this->hold();

        $this->assertSame(1, $held['unmatched_nights']);
        $this->assertSame(0, RoomBlock::count());
        $props = $this->get("/admin/imports/{$this->batch->id}")->assertOk()->original->getData()['page']['props'];
        $this->assertSame(['nights' => 1, 'unmatched' => 1, 'held' => 0], $props['future']);
    }

    public function test_matching_the_label_then_holding_protects_those_nights(): void
    {
        $row = $this->sale(null, 2);
        $row->update(['room_label' => 'LOOP 14Q']);
        $mapping = \App\Models\ImportMapping::firstOrCreate(['label' => 'LOOP 14Q'], ['status' => 'pending']);

        $this->post("/admin/imports/mappings/{$mapping->id}", ['decision' => 'confirmed', 'room_id' => $this->room('berlin')->id])->assertSessionHasNoErrors();
        $this->post("/admin/imports/{$this->batch->id}/hold-future")->assertSessionHas('success');

        $this->assertCount(1, $this->blocks('berlin'));
    }

    public function test_committing_holds_the_dates_and_rolling_back_releases_them(): void
    {
        $this->sale('venice', 2);

        $this->post("/admin/imports/{$this->batch->id}/commit")->assertSessionHas('success');
        $this->assertCount(1, $this->blocks('venice'));

        $this->post("/admin/imports/{$this->batch->id}/rollback");
        $this->assertCount(0, $this->blocks('venice'));
    }

    public function test_rejected_rows_and_blank_names_hold_nothing(): void
    {
        $this->sale('venice', 2, 'Rejected Guest', 200000, ['review_status' => 'rejected']);
        $this->sale('venice', 3, null);
        $this->sale('venice', 4, '');

        $this->assertSame(0, $this->hold()['held_nights']);
    }

    public function test_the_dashboard_reminds_staff_to_turn_holds_into_real_bookings(): void
    {
        $action = fn () => collect($this->get('/admin')->original->getData()['page']['props']['actions'])->contains(fn ($a) => str_contains($a['label'], 'held from the sales workbook'));
        $this->assertFalse($action());

        $this->sale('venice', 2);
        $this->hold();

        $this->assertTrue($action());
    }

    // The printable report

    public function test_the_report_prints_workbook_history_and_live_stays_room_by_room_with_totals(): void
    {
        $this->sale('venice', -5, 'Past Sheet Guest', 150000, ['extra_cents' => 20000]);
        $this->sale(null, -4, 'Old Unit Guest', 100000);
        $this->confirmed('venice', $this->day(1), $this->day(2));

        [$headers, $rows] = $this->report($this->day(-10), $this->day(5));

        $this->assertSame('Name', $headers[2]);
        $byGuest = collect($rows)->keyBy(2);
        $past = $byGuest['Past Sheet Guest'];
        $this->assertSame([$this->day(-5), 'VENICE', 'AIRBNB', 'BPI', '1500.00', '200.00', '1700.00', 'Synthetic remark', 'Workbook (unverified)'], [$past[0], $past[1], $past[3], $past[4], $past[5], $past[6], $past[7], $past[8], $past[9]]);
        $this->assertSame('Workbook (unverified)', $byGuest['Old Unit Guest'][9]);
        $this->assertSame('UNKNOWN LABEL', $byGuest['Old Unit Guest'][1], 'An unmatched room still prints under its own label.');

        $live = collect($rows)->firstWhere(9, 'System');
        $this->assertSame('VENICE', $live[1]);

        $venice = collect($rows)->firstWhere(1, 'VENICE total');
        $this->assertSame((float) $past[7] + (float) $live[7], (float) $venice[7]);
        $all = collect($rows)->last();
        $this->assertSame('ALL ROOMS TOTAL', $all[1]);
        $this->assertSame((float) $venice[7] + 1000.0, (float) $all[7]);
    }

    public function test_a_live_booking_replaces_the_workbook_row_for_the_same_night_so_nothing_counts_twice(): void
    {
        $this->sale('venice', 1, 'Sheet Copy', 999900);
        $this->confirmed('venice', $this->day(1), $this->day(2));

        [, $rows] = $this->report($this->day(0), $this->day(3));

        $this->assertNull(collect($rows)->firstWhere(2, 'Sheet Copy'));
        $this->assertCount(1, collect($rows)->where(9, 'System'));
    }

    public function test_the_report_leaves_out_other_rooms_when_filtered_and_rejected_rows_always(): void
    {
        $this->sale('venice', -2, 'Loop Guest');
        $this->sale('rio', -2, 'Mesaverte Guest');
        $this->sale('venice', -1, 'Rejected Guest', 100000, ['review_status' => 'rejected']);

        [, $rows] = $this->report($this->day(-5), $this->day(0), $this->room('rio')->property_id);

        $names = collect($rows)->pluck(2)->filter()->all();
        $this->assertContains('Mesaverte Guest', $names);
        $this->assertNotContains('Loop Guest', $names);
        $this->assertNotContains('Rejected Guest', $names);
    }

    public function test_workbook_history_stays_out_of_verified_figures(): void
    {
        $this->sale('venice', -2, 'Sheet Guest', 500000);

        $this->assertSame(0, app(ReportService::class)->kpis($this->day(-5), $this->day(0))['earned_cents']);
    }

    public function test_the_report_exports_and_is_for_finance_staff_only(): void
    {
        $this->sale('venice', -2);
        $query = "from={$this->day(-5)}&to={$this->day(0)}";

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->get("/admin/reports/export/sales_recording/{$format}?{$query}")->assertOk();
        }

        $housekeeping = User::factory()->create();
        $housekeeping->assignRole('Housekeeping / Laundry');
        $this->actingAs($housekeeping)->get("/admin/reports/export/sales_recording/csv?{$query}")->assertForbidden();
    }
}
