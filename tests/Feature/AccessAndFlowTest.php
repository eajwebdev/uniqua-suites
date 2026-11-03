<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceService;
use App\Domain\LegacyImport\WorkbookImporter;
use App\Domain\Tasks\TurnoverService;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Guest;
use App\Models\HousekeepingTask;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\LegacyRow;
use App\Models\ParkingAssignment;
use App\Models\ParkingSpace;
use App\Models\Receipt;
use App\Models\Room;
use App\Models\RoomSecret;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\DomainTestCase;

class AccessAndFlowTest extends DomainTestCase
{
    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_every_admin_page_renders_for_the_owner(): void
    {
        $booking = $this->confirmed();
        foreach (['/admin', '/admin/calendar', '/admin/bookings', '/admin/bookings/create', "/admin/bookings/{$booking->id}", '/admin/guests', "/admin/guests/{$booking->guest_id}", '/admin/quotes', '/admin/quotes/create', '/admin/rooms', '/admin/rooms/'.$this->room('venice')->id.'/edit', '/admin/payments', '/admin/settlements', '/admin/reconciliation', '/admin/expenses', '/admin/cashbook', '/admin/reports', '/admin/housekeeping', '/admin/laundry', '/admin/work-orders', '/admin/incidents', '/admin/inventory', '/admin/parking', '/admin/advances', '/admin/imports', '/admin/team', '/admin/settings', '/admin/audit', '/admin/account'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_public_pages_render_and_expose_no_private_fields(): void
    {
        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved', 'operational_notes' => 'INTERNAL-NOTE-MARKER']);
        $venice = $this->room('venice');
        RoomSecret::create(['room_id' => $venice->id, 'network_name' => 'NET-MARKER', 'password' => 'WIFI-PASSWORD-MARKER']);
        $this->confirmed('venice', '2031-03-03', '2031-03-05');
        auth()->logout();

        foreach (['/', '/suites', '/suites?check_in=2031-03-03&check_out=2031-03-05&guests=2', '/suites/venice?check_in=2031-03-03&check_out=2031-03-05&guests=2', '/policies'] as $url) {
            $body = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('WIFI-PASSWORD-MARKER', $body);
            $this->assertStringNotContainsString('NET-MARKER', $body);
            $this->assertStringNotContainsString('INTERNAL-NOTE-MARKER', $body);
            $this->assertStringNotContainsString('unit_code', $body);
            $this->assertStringNotContainsString('Synthetic Guest', $body);
        }
    }

    public function test_confirmed_room_is_not_shown_as_available_publicly(): void
    {
        Room::query()->update(['is_published' => true]);
        $this->confirmed('venice', '2031-03-03', '2031-03-05');
        auth()->logout();

        $this->get('/suites/venice?check_in=2031-03-04&check_out=2031-03-06')->assertInertia(fn ($p) => $p->where('availability', 'unavailable'));
        $this->get('/suites/venice?check_in=2031-03-05&check_out=2031-03-06')->assertInertia(fn ($p) => $p->where('availability', 'indicative'));
    }

    public function test_the_public_has_no_way_to_write(): void
    {
        auth()->logout();
        $this->post('/admin/bookings', [])->assertRedirect('/login');
        $this->post('/admin/receipts', [])->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');

        $publicWrites = collect(app('router')->getRoutes())->filter(fn ($r) => ! in_array('GET', $r->methods()) && ! str_starts_with($r->uri(), 'admin') && ! in_array($r->uri(), ['login', 'forgot-password', 'reset-password'], true));
        $this->assertCount(0, $publicWrites, 'A public write route exists: '.$publicWrites->map->uri()->join(', '));
    }

    public function test_unpublished_room_is_hidden(): void
    {
        auth()->logout();
        $this->get('/suites/venice')->assertNotFound();
    }

    public function test_housekeeping_cannot_reach_money_salaries_guests_or_secrets(): void
    {
        $this->actingAs($this->staff('Housekeeping / Laundry'));
        $room = $this->room('venice');

        foreach (['/admin/payments', '/admin/settlements', '/admin/expenses', '/admin/advances', '/admin/guests', '/admin/bookings', '/admin/reports', '/admin/imports', '/admin/settings', '/admin/audit', "/admin/rooms/{$room->id}/secret"] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/receipts', [])->assertForbidden();
        $this->get('/admin/housekeeping')->assertOk();
    }

    public function test_housekeeping_board_carries_no_guest_or_payment_data_and_hides_others_tasks(): void
    {
        $booking = $this->confirmed();
        $guestName = $booking->guest->full_name;
        $this->bookings->checkIn($booking);
        $this->bookings->checkOut($booking);
        $task = HousekeepingTask::firstOrFail();
        $other = $this->staff('Housekeeping / Laundry');
        $me = $this->staff('Housekeeping / Laundry');
        $task->update(['assigned_to' => $other->id]);

        $this->actingAs($me);
        $this->post("/admin/tasks/{$task->id}/start")->assertForbidden();
        $this->assertStringNotContainsString($guestName, $this->get('/admin/housekeeping')->getContent());

        $task->update(['assigned_to' => $me->id]);
        $this->post("/admin/tasks/{$task->id}/start")->assertRedirect();
        $this->assertStringNotContainsString($guestName, $this->get('/admin/housekeeping')->getContent());
    }

    public function test_reception_records_but_cannot_verify_or_discount(): void
    {
        $booking = $this->confirmed();
        $receipt = app(FinanceService::class)->recordReceipt(['booking_id' => $booking->id, 'received_at' => '2031-03-01 10:00', 'amount_cents' => 100, 'method' => 'cash']);
        $inquiry = $this->inquiry('bali');

        $this->actingAs($this->staff('Booking / Reception'));
        $this->post("/admin/receipts/{$receipt->id}/review", ['decision' => 'verified'])->assertForbidden();
        $this->post("/admin/bookings/{$inquiry->id}/confirm", ['discount' => '100', 'discount_reason' => 'x'])->assertForbidden();
        $this->assertSame('pending', $receipt->fresh()->verification_status);
        $this->assertSame(Booking::INQUIRY, $inquiry->fresh()->status);
    }

    public function test_finance_cannot_reassign_rooms(): void
    {
        $booking = $this->confirmed();
        $this->actingAs($this->staff('Finance / Bookkeeper'));
        $this->post("/admin/bookings/{$booking->id}/amend", ['reason' => 'x', 'room_id_primary' => $this->room('roma')->id])->assertForbidden();
    }

    public function test_secret_is_encrypted_at_rest_and_each_reveal_is_audited(): void
    {
        $room = $this->room('venice');
        $this->post("/admin/rooms/{$room->id}/secret", ['network_name' => 'NET', 'password' => 'plain-text-password'])->assertRedirect();

        $this->assertStringNotContainsString('plain-text-password', (string) DB::table('room_secrets')->where('room_id', $room->id)->value('password'));
        $this->assertStringNotContainsString('plain-text-password', $this->get("/admin/rooms/{$room->id}/edit")->getContent());

        $this->get("/admin/rooms/{$room->id}/secret")->assertOk()->assertJsonPath('password', 'plain-text-password');
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'secret.revealed')->count());
    }

    public function test_conflict_returns_a_readable_message_and_commits_nothing(): void
    {
        $this->confirmed('venice');
        $second = $this->inquiry('venice');
        $charges = BookingCharge::count();

        $this->post("/admin/bookings/{$second->id}/confirm")->assertRedirect()->assertSessionHas('error');
        $this->assertSame(Booking::INQUIRY, $second->fresh()->status);
        $this->assertSame($charges, BookingCharge::count());
    }

    public function test_full_journey_inquiry_to_report(): void
    {
        $this->post('/admin/quotes', ['room_id' => $this->room('venice')->id, 'check_in' => '2031-03-03', 'check_out' => '2031-03-05', 'guests' => 2])->assertRedirect();
        $this->assertSame(0, DB::table('booking_rooms')->count(), 'a quote reserves nothing');

        $this->post('/admin/bookings', ['guest_name' => 'Synthetic Journey', 'room_ids' => [$this->room('venice')->id], 'booking_source_id' => $this->source(), 'check_in' => '2031-03-03', 'check_out' => '2031-03-05', 'adults' => 2, 'then' => 'hold'])->assertRedirect();
        $booking = Booking::firstOrFail();
        $this->assertSame(Booking::TENTATIVE, $booking->status);

        $this->post('/admin/receipts', ['booking_reference' => $booking->reference, 'received_at' => '2031-03-01T10:00', 'amount' => '3400.00', 'method' => 'gcash', 'external_reference' => 'GC-JOURNEY'])->assertRedirect();
        $receipt = Receipt::firstOrFail();
        $this->post("/admin/receipts/{$receipt->id}/review", ['decision' => 'verified'])->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/confirm")->assertRedirect()->assertSessionHas('success');
        $this->assertSame(340000, (int) $booking->fresh()->total_cents);

        $this->post("/admin/bookings/{$booking->id}/check-in")->assertRedirect()->assertSessionHas('success');
        $this->post("/admin/bookings/{$booking->id}/check-out")->assertRedirect()->assertSessionHas('success');
        $venice = $this->room('venice');
        $this->assertSame('dirty', $venice->housekeeping_status);

        $task = HousekeepingTask::firstOrFail();
        $this->post("/admin/tasks/{$task->id}/start")->assertRedirect();
        $this->post("/admin/tasks/{$task->id}/complete", ['checklist' => [['label' => 'Clean', 'done' => true]], 'notes' => 'Done'])->assertRedirect();
        $this->assertSame('for_inspection', $venice->fresh()->housekeeping_status);
        $this->post("/admin/tasks/{$task->id}/inspect", ['accepted' => true])->assertRedirect();
        $this->assertSame('ready', $venice->fresh()->housekeeping_status);

        $this->get('/admin/reports?from=2031-03-01&to=2031-03-31')->assertOk()->assertInertia(fn ($p) => $p
            ->where('kpis.earned_cents', 340000)
            ->where('kpis.collected_cents', 340000)
            ->where('kpis.occupied_room_nights', 2));
        $this->assertSame('Paid', app(FinanceService::class)->balance($booking->fresh())['status']);

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->get("/admin/reports/export/room_ledger/{$format}?from=2031-03-01&to=2031-03-31")->assertOk();
        }
        $this->get('/admin/quotes/1/pdf')->assertOk();
    }

    public function test_inventory_moves_only_when_use_is_recorded_and_seed_has_no_stock(): void
    {
        $this->assertSame(0, InventoryMovement::count());
        $this->assertGreaterThan(0, InventoryItem::count());

        $booking = $this->confirmed();
        $this->bookings->checkIn($booking);
        $this->bookings->checkOut($booking);
        $this->assertSame(0, InventoryMovement::count(), 'turnover alone consumes nothing');

        $this->post('/admin/inventory/movements', ['inventory_item_id' => InventoryItem::first()->id, 'property_id' => $this->room('venice')->property_id, 'type' => 'issue', 'quantity' => 2])->assertRedirect();
        $this->assertSame(-2, (int) InventoryMovement::sum('quantity'));
    }

    public function test_damage_report_creates_no_charge_until_approved_and_can_be_reversed(): void
    {
        $booking = $this->confirmed();
        $before = (int) $booking->fresh()->total_cents;

        $this->post('/admin/incidents', ['room_id' => $this->room('venice')->id, 'booking_reference' => $booking->reference, 'item' => 'Broken lamp', 'discovered_on' => '2031-03-05', 'estimated' => '500.00'])->assertRedirect();
        $incident = Incident::firstOrFail();
        $this->assertSame($before, (int) $booking->fresh()->total_cents);
        $this->assertSame(0, BookingCharge::where('type', 'damage')->count());

        $this->post("/admin/incidents/{$incident->id}/decide", ['decision' => 'approve', 'approved' => '400.00', 'owner_resolution' => 'Guest agreed'])->assertRedirect();
        $this->assertSame($before + 40000, (int) $booking->fresh()->total_cents);

        $this->post("/admin/incidents/{$incident->id}/decide", ['decision' => 'reverse', 'owner_resolution' => 'Mistaken'])->assertRedirect();
        $this->assertSame($before, (int) $booking->fresh()->total_cents);
        $this->assertSame(1, BookingCharge::where('type', 'damage')->whereNotNull('voided_at')->count(), 'history is kept');
    }

    public function test_reception_cannot_approve_a_damage_charge(): void
    {
        $booking = $this->confirmed();
        $incident = Incident::create(['room_id' => $this->room('venice')->id, 'booking_id' => $booking->id, 'item' => 'x', 'discovered_on' => '2031-03-05']);
        $this->actingAs($this->staff('Booking / Reception'));
        $this->post("/admin/incidents/{$incident->id}/decide", ['decision' => 'approve', 'approved' => '1', 'owner_resolution' => 'x'])->assertForbidden();
    }

    public function test_parking_cannot_be_given_to_two_guests_at_once(): void
    {
        $space = ParkingSpace::firstOrFail();
        $this->post('/admin/parking/assignments', ['parking_space_id' => $space->id, 'holder_name' => 'A', 'start_date' => '2031-03-03', 'end_date' => '2031-03-05'])->assertSessionHas('success');
        $this->post('/admin/parking/assignments', ['parking_space_id' => $space->id, 'holder_name' => 'B', 'start_date' => '2031-03-04', 'end_date' => '2031-03-06'])->assertSessionHas('error');
        $this->post('/admin/parking/assignments', ['parking_space_id' => $space->id, 'holder_name' => 'C', 'start_date' => '2031-03-05', 'end_date' => '2031-03-06'])->assertSessionHas('success');
        $this->assertSame(2, ParkingAssignment::count());
    }

    public function test_safety_work_order_stops_a_room_being_marked_ready(): void
    {
        $booking = $this->confirmed();
        $this->bookings->checkIn($booking);
        $this->bookings->checkOut($booking);
        $task = HousekeepingTask::firstOrFail();
        $turnover = app(TurnoverService::class);
        $turnover->start($task);
        $turnover->complete($task->fresh(), [], null);
        $this->post('/admin/work-orders', ['title' => 'Gas leak', 'room_id' => $task->room_id, 'severity' => 'critical', 'safety_block' => true])->assertRedirect();

        $this->post("/admin/tasks/{$task->id}/inspect", ['accepted' => true])->assertSessionHas('error');
        $this->assertSame('for_inspection', $this->room('venice')->housekeeping_status);
    }

    /** Synthetic workbook covering the shifting layouts, so no real guest data enters the test suite. */
    private function syntheticWorkbook(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('JAN. 2026');
        $sheet->setCellValue('D1', 'LOOP 2221 -STUDIO (5 PAX MAX)');
        foreach (['A' => 'DATE(2026)', 'B' => 'NAME', 'C' => 'BOOKING VIA', 'D' => 'NIGHT RATE(3 PAX)', 'F' => 'EXTRA PAX', 'G' => 'PER PAX RATE', 'H' => 'PAX TOTAL AMOUNT', 'I' => 'PAYMENT SOURCE', 'K' => 'TOTAL AMOUNT'] as $col => $label) {
            $sheet->setCellValue("{$col}18", $label);
        }
        $rows = [
            [46023, 'Synthetic One', 'AIRBNB', 1000, 2, 200, 400, 'GCASH', 1400],
            [46024, 'Synthetic One', 'AIRBNB', 1000, 0, 200, 0, null, 1000],
            [46025, 'NO BOOKING', 'NO BOOKING', 0, 0, 200, 0, null, 0],
            [46026, null, null, null, null, 200, 0, null, 0],
        ];
        $r = 19;
        foreach ($rows as $row) {
            foreach (['A', 'B', 'C', 'D', 'F', 'G', 'H', 'I', 'K'] as $i => $col) {
                $sheet->setCellValue("{$col}{$r}", $row[$i]);
            }
            $r++;
        }
        $sheet->setCellValue("B{$r}", 'STANDARD RATE AMOUNT');
        $sheet->setCellValue("J{$r}", 'TOTAL AMOUNT');
        $sheet->setCellValue("K{$r}", 9999);

        $path = tempnam(sys_get_temp_dir(), 'uq').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function test_import_flags_conflicts_and_creates_no_bookings_or_receipts(): void
    {
        $batch = app(WorkbookImporter::class)->stage($this->syntheticWorkbook(), 'x.xlsx', 'x');
        $summary = $batch->fresh()->summary;

        $this->assertSame(2, $summary['by_type']['daily_sale']);
        $this->assertSame(1, $summary['by_type']['no_booking']);
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Receipt::count());
        $this->assertSame(0, Guest::count());

        $noBooking = LegacyRow::where('record_type', 'no_booking')->firstOrFail();
        $this->assertNull($noBooking->amount_cents, 'NO BOOKING is never a sale');
        $this->assertNull($noBooking->guest_name);

        foreach (['unknown_or_conflicting_room', 'missing_checkout_date', 'possible_multi_day_duplicate', 'booking_origin_vs_payment_source_conflict', 'formula_vs_manual_total_mismatch', 'unverified_payout_or_cash_receipt'] as $category) {
            $this->assertArrayHasKey($category, $summary['anomalies'], $category);
        }
        $this->assertNull(LegacyRow::where('record_type', 'daily_sale')->first()->room_id, '2221 stays unmapped until the owner decides');
        $first = LegacyRow::where('record_type', 'daily_sale')->orderBy('row_number')->first();
        $this->assertSame('2026-01-01', $first->record_date->toDateString(), 'Excel serial decoded');
    }

    public function test_import_rerun_does_not_double_and_rollback_is_clean(): void
    {
        $importer = app(WorkbookImporter::class);
        $path = $this->syntheticWorkbook();
        $first = $importer->stage($path, 'x.xlsx', 'x');
        $count = LegacyRow::count();
        $this->assertGreaterThan(0, $count);

        $second = $importer->stage($path, 'x.xlsx', 'x');
        $this->assertSame($count, LegacyRow::count());
        $this->assertSame(0, $second->fresh()->summary['rows']);

        $this->post("/admin/imports/{$first->id}/rollback")->assertRedirect();
        $this->assertSame(0, LegacyRow::count());
        $third = $importer->stage($path, 'x.xlsx', 'x');
        $this->assertSame($count, LegacyRow::count(), 'can re-import after rollback');
        $this->assertSame($count, $third->fresh()->summary['rows']);
    }

    public function test_owner_can_map_a_conflicted_label_once(): void
    {
        $batch = app(WorkbookImporter::class)->stage($this->syntheticWorkbook(), 'x.xlsx', 'x');
        $mapping = \App\Models\ImportMapping::where('label', 'like', '%2221%')->firstOrFail();

        $this->post("/admin/imports/mappings/{$mapping->id}", ['decision' => 'confirmed', 'room_id' => $this->room('new-york')->id])->assertRedirect();

        $this->assertSame(0, LegacyRow::where('import_batch_id', $batch->id)->whereNull('room_id')->count());
        $this->assertSame(0, $batch->anomalies()->where('category', 'unknown_or_conflicting_room')->where('status', 'open')->count());
    }

    public function test_seed_contains_no_wifi_or_staff_financial_data(): void
    {
        $this->assertSame(0, RoomSecret::count());
        $this->assertSame(0, DB::table('employees')->count());
        $this->assertSame(0, DB::table('staff_cash_advances')->count());
        $this->assertSame(0, Guest::count());
    }

    public function test_only_owner_guard_prevents_lockout(): void
    {
        $this->post("/admin/team/{$this->owner->id}", ['role' => 'Auditor', 'is_active' => true])->assertSessionHas('error');
        $this->assertTrue($this->owner->fresh()->hasRole('Owner'));
    }

    public function test_deactivated_user_loses_access_immediately(): void
    {
        $user = $this->staff('Finance / Bookkeeper');
        $this->actingAs($user)->get('/admin/payments')->assertOk();
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh())->get('/admin/payments')->assertRedirect('/login');
    }
}
