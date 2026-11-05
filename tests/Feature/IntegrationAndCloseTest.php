<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Support\Facades\Artisan;
use Tests\DomainTestCase;

class IntegrationAndCloseTest extends DomainTestCase
{
    public function test_scheduler_commands_run_and_expire_holds(): void
    {
        $held = $this->bookings->hold($this->inquiry());
        Booking::whereKey($held->id)->update(['hold_expires_at' => now()->subMinute()]);

        $this->assertSame(0, Artisan::call('uniqa:expire-holds'));
        $this->assertSame(0, Artisan::call('uniqa:daily-alerts'));
        $this->assertSame(Booking::EXPIRED, $held->fresh()->status);
        $this->assertStringContainsString('hold(s) expired', $this->owner->notifications()->first()->data['title']);
    }

    public function test_csv_export_neutralizes_spreadsheet_formulas(): void
    {
        $guest = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $guest->guest->update(['full_name' => '=HYPERLINK("http://evil.test")']);

        $csv = $this->get('/admin/reports/export/aging/csv?from=2031-03-01&to=2031-03-31')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", str_replace('""', '"', $csv));
    }

    public function test_finance_exports_are_blocked_for_roles_without_finance_reports(): void
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo(['reports.view', 'reports.export']);
        $this->actingAs($user);

        $this->get('/admin/reports/export/collections/csv')->assertForbidden();
        $this->get('/admin/reports?type=aging')->assertForbidden();
        $this->get('/admin/reports/export/tasks/csv')->assertOk();
    }
}
