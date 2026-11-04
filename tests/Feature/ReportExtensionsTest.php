<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceService;
use App\Domain\Reporting\ReportService;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ParkingAssignment;
use App\Models\ParkingSpace;
use App\Models\Property;
use App\Models\RoomBlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DomainTestCase;

class ReportExtensionsTest extends DomainTestCase
{
    private function report(string $type, string $from = '2031-03-01', string $to = '2031-03-07', ?int $property = null, string $basis = 'stay'): array
    {
        return app(ReportService::class)->table($type, $from, $to, $property, $basis);
    }

    private function expense(string $category, string $date, int $cents, array $extra = []): Expense
    {
        return Expense::create($extra + [
            'expense_category_id' => ExpenseCategory::where('name', $category)->value('id'),
            'description' => 'Synthetic',
            'amount_cents' => $cents,
            'expense_date' => $date,
        ]);
    }

    private function column(array $headers, string $prefix): int
    {
        foreach ($headers as $i => $header) {
            if (str_starts_with($header, $prefix)) {
                return $i;
            }
        }
        $this->fail("No column starting {$prefix}");
    }

    public function test_room_grid_has_a_cell_per_room_night_and_channel_tallies(): void
    {
        $this->confirmed('venice', '2031-03-03', '2031-03-05', 'airbnb');
        RoomBlock::create(['room_id' => $this->room('roma')->id, 'start_date' => '2031-03-06', 'end_date' => '2031-03-09', 'kind' => 'repair', 'reason' => 'Synthetic']);

        [$headers, $rows] = $this->report('room_grid');
        $venice = $this->column($headers, 'VENICE');
        $roma = $this->column($headers, 'ROMA');
        $byLabel = collect($rows)->keyBy(0);

        $this->assertSame('VENICE (1410)', $headers[$venice]);
        $this->assertSame('1700.00', $byLabel['2031-03-03'][$venice]);
        $this->assertSame('1700.00', $byLabel['2031-03-04'][$venice]);
        $this->assertSame('', $byLabel['2031-03-05'][$venice], 'Check-out day is not an occupied night.');
        $this->assertSame('Blocked', $byLabel['2031-03-06'][$roma]);
        $this->assertSame('Blocked', $byLabel['2031-03-07'][$roma]);
        $this->assertSame('1700.00', $byLabel['2031-03-03'][count($headers) - 1], 'Day total');
        $this->assertSame('', $byLabel['2031-03-01'][count($headers) - 1], 'A vacant day stays blank, not zero sales.');

        $this->assertSame('3400.00', $byLabel['Total (PHP)'][$venice]);
        $this->assertSame(2, $byLabel['Occupied nights'][$venice]);
        $this->assertSame(5, $byLabel['Vacant nights'][$venice]);
        $this->assertSame(2, $byLabel['Blocked nights'][$roma]);
        $this->assertSame(5, $byLabel['Vacant nights'][$roma]);
        $this->assertSame(2, $byLabel['Airbnb nights'][$venice]);
        $this->assertFalse($byLabel->has('Facebook Messenger nights'), 'A channel with no nights adds no row.');
    }

    public function test_cancelled_and_unconfirmed_bookings_stay_out_of_the_grid(): void
    {
        $this->inquiry('venice', '2031-03-03', '2031-03-05');
        $this->bookings->cancel($this->confirmed('bali', '2031-03-03', '2031-03-05'), 'Synthetic');

        [$headers, $rows] = $this->report('room_grid');
        $total = collect($rows)->firstWhere(0, 'Total (PHP)');

        $this->assertSame('0.00', $total[count($headers) - 1]);
    }

    public function test_property_filter_applies_to_every_financial_table(): void
    {
        $loop = Property::where('slug', 'the-loop')->value('id');
        $mesa = Property::where('slug', 'mesaverte')->value('id');
        $this->confirmed('venice', '2031-03-03', '2031-03-05'); // The Loop
        $this->confirmed('rio', '2031-03-03', '2031-03-05'); // Mesaverte

        [$headers] = $this->report('room_grid', property: $mesa);
        $this->assertContains('RIO (T3, 6H)', $headers);
        $this->assertNotContains('VENICE (1410)', $headers);

        $ledgerRooms = fn ($p) => collect($this->report('room_ledger', property: $p)[1])->pluck(1)->unique()->values()->all();
        $this->assertSame(['VENICE'], $ledgerRooms($loop));
        $this->assertSame(['RIO'], $ledgerRooms($mesa));

        $services = app(ReportService::class);
        $this->assertSame(['VENICE'], array_column($services->topRooms('2031-03-01', '2031-03-07', $loop), 'name'));
        $earned = fn ($p) => array_sum(array_column($services->channelMix('2031-03-01', '2031-03-07', $p), 'earned_cents'));
        $this->assertSame(340000, $earned($loop));
        $this->assertSame(320000, $earned($mesa));
    }

    public function test_exports_honour_the_property_filter_and_state_the_scope(): void
    {
        $mesa = Property::where('slug', 'mesaverte')->first();
        $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $this->confirmed('rio', '2031-03-03', '2031-03-05');

        $csv = $this->get("/admin/reports/export/room_ledger/csv?from=2031-03-01&to=2031-03-07&property={$mesa->id}")->assertOk()->streamedContent();

        $this->assertStringContainsString("Scope: {$mesa->name}", $csv);
        $this->assertStringContainsString('RIO', $csv);
        $this->assertStringNotContainsString('VENICE', $csv);
    }

    public function test_date_bases_are_selectable_and_differ_when_money_moves_in_another_month(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $finance = app(FinanceService::class);
        $receipt = $finance->recordReceipt(['booking_id' => $booking->id, 'received_at' => '2031-04-02 10:00:00', 'amount_cents' => 100000, 'method' => 'gcash']);
        $finance->review($receipt, true);
        $finance->recordSettlement(['provider' => 'Airbnb', 'booking_id' => $booking->id, 'payout_reference' => 'SYN-1', 'gross_cents' => 340000, 'fees_cents' => 10000, 'actual_payout_cents' => 330000, 'payout_date' => '2031-05-05']);
        $this->expense('Water', '2031-06-01', 50000);

        $total = fn ($basis, $from, $to) => collect($this->report('by_basis', $from, $to, null, $basis)[1])->firstWhere(0, 'Total')[2] ?? null;

        $this->assertSame('3400.00', $total('stay', '2031-03-01', '2031-03-31'));
        $this->assertNull($total('receipt', '2031-03-01', '2031-03-31'));
        $this->assertSame('1000.00', $total('receipt', '2031-04-01', '2031-04-30'));
        $this->assertSame('3300.00', $total('payout', '2031-05-01', '2031-05-31'));
        $this->assertSame('500.00', $total('expense', '2031-06-01', '2031-06-30'));

        [$headers, , $definition] = $this->report('by_basis', '2031-05-01', '2031-05-31', null, 'payout');
        $this->assertSame('Platform payout date', $headers[0]);
        $this->assertStringContainsString('not expected to reconcile', $definition);

        $this->get('/admin/reports?type=by_basis&basis=receipt&from=2031-04-01&to=2031-04-30')->assertOk()->assertInertia(fn ($p) => $p->where('basis', 'receipt')->where('table.headers.0', 'Receipt date'));
        $this->get('/admin/reports?type=by_basis&basis=nonsense')->assertSessionHasErrors('basis');
    }

    public function test_created_date_uses_the_local_day_not_utc(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        // 20:00 UTC on 31 March is already 04:00 on 1 April in Manila.
        DB::table('bookings')->where('id', $booking->id)->update(['created_at' => '2031-03-31 20:00:00']);
        $service = app(ReportService::class);

        $this->assertSame(0, $service->kpis('2031-03-01', '2031-03-31')['booked_cents']);
        $this->assertSame(340000, $service->kpis('2031-04-01', '2031-04-30')['booked_cents']);
        $this->assertSame('2031-04-01', $this->report('by_basis', '2031-04-01', '2031-04-30', null, 'created')[1][0][0]);
    }

    public function test_utilities_compare_by_month_and_room_without_inventing_zero_months(): void
    {
        $venice = $this->room('venice');
        $this->expense('Electricity', '2031-03-10', 150000, ['room_id' => $venice->id]);
        $this->expense('Water', '2031-03-11', 40000, ['room_id' => $venice->id, 'status' => 'paid', 'paid_on' => '2031-03-11']);
        $this->expense('Wi-Fi', '2031-04-01', 170000, ['property_id' => $venice->property_id]);
        $this->expense('Cleaning', '2031-03-12', 99900, ['room_id' => $venice->id]);

        [$headers, $rows] = $this->report('utilities', '2031-03-01', '2031-04-30');

        $this->assertSame(['Month', 'Allocated to', 'Water (PHP)', 'Electricity (PHP)', 'Wi-Fi (PHP)', 'Total (PHP)', 'Unpaid (PHP)'], $headers);
        $this->assertCount(2, $rows);
        $this->assertSame(['2031-03', 'VENICE', '400.00', '1500.00', '0.00', '1900.00', '1500.00'], $rows[0]);
        $this->assertSame(['2031-04', 'The Loop', '0.00', '0.00', '1700.00', '1700.00', '1700.00'], $rows[1]);
    }

    public function test_parking_report_counts_nights_inside_the_period_only(): void
    {
        $space = ParkingSpace::where('code', 'T3, LG')->firstOrFail();
        ParkingAssignment::create(['parking_space_id' => $space->id, 'holder_name' => 'Synthetic Holder', 'start_date' => '2031-02-27', 'end_date' => '2031-03-03', 'status' => 'active']);
        ParkingAssignment::create(['parking_space_id' => $space->id, 'holder_name' => 'Synthetic Cancelled', 'start_date' => '2031-03-04', 'end_date' => '2031-03-06', 'status' => 'cancelled']);
        ParkingAssignment::create(['parking_space_id' => $space->id, 'holder_name' => 'Synthetic Later', 'start_date' => '2031-04-01', 'end_date' => '2031-04-02', 'status' => 'active']);

        [$headers, $rows] = $this->report('parking');
        $nights = array_search('Nights in period', $headers, true);

        $this->assertCount(2, $rows);
        $this->assertSame('Synthetic Holder', $rows[0][2]);
        $this->assertSame(2, $rows[0][$nights], '1 and 2 March; the 3rd is the exclusive end.');
        $this->assertSame(0, $rows[1][$nights]);
    }

    public function test_cleaning_and_laundry_keep_job_cost_and_expenses_apart(): void
    {
        $venice = $this->room('venice');
        DB::table('laundry_jobs')->insert(['room_id' => $venice->id, 'status' => 'delivered', 'quantity' => 3, 'cost_cents' => 45000, 'created_at' => '2031-03-02 04:00:00', 'updated_at' => '2031-03-02 04:00:00']);
        DB::table('laundry_jobs')->insert(['room_id' => $venice->id, 'status' => 'cancelled', 'quantity' => 9, 'cost_cents' => 99900, 'created_at' => '2031-03-02 04:00:00', 'updated_at' => '2031-03-02 04:00:00']);
        $this->expense('Laundry', '2031-03-03', 45000, ['room_id' => $venice->id]);
        $this->expense('Cleaning', '2031-03-03', 30000);

        [, $rows, $definition] = $this->report('cleaning_laundry');
        $row = collect($rows)->firstWhere(0, 'VENICE');

        $this->assertSame(['VENICE', 0, 1, 3, '450.00', '0.00', '450.00'], $row);
        $this->assertSame(['Not room-specific', 0, 0, 0, '0.00', '300.00', '0.00'], collect($rows)->firstWhere(0, 'Not room-specific'));
        $this->assertStringContainsString('do not add them', $definition);
    }

    public function test_financial_reports_stay_closed_to_roles_without_finance_access(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Operations Manager');
        $this->actingAs($manager);

        foreach (['room_grid', 'by_basis', 'utilities', 'cleaning_laundry', 'parking'] as $type) {
            $this->get("/admin/reports?type={$type}")->assertForbidden();
            $this->get("/admin/reports/export/{$type}/csv")->assertForbidden();
        }
        $this->get('/admin/reports')->assertOk()->assertInertia(fn ($p) => $p->where('type', 'tasks'));

        $auditor = User::factory()->create();
        $auditor->assignRole('Auditor');
        $this->actingAs($auditor)->get('/admin/reports/export/room_grid/csv')->assertOk();
    }

    public function test_every_new_report_exports_in_every_format(): void
    {
        $this->confirmed('venice', '2031-03-03', '2031-03-05');

        foreach (['room_grid', 'by_basis', 'utilities', 'cleaning_laundry', 'parking'] as $type) {
            foreach (['csv', 'xlsx', 'pdf'] as $format) {
                $response = $this->get("/admin/reports/export/{$type}/{$format}?from=2031-03-01&to=2031-03-07")->assertOk();
                $this->assertNotEmpty($response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $response->streamedContent() : $response->getContent(), "{$type} as {$format}");
            }
        }
        $this->assertSame(15, DB::table('activity_logs')->where('action', 'report.exported')->count());
    }

    public function test_reversed_date_range_is_rejected(): void
    {
        $this->get('/admin/reports?from=2031-03-07&to=2031-03-01')->assertSessionHasErrors('to');
        $this->assertSame(0, Booking::count());
    }
}
