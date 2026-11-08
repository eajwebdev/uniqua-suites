<?php

use App\Domain\Bookings\BookingService;
use App\Domain\Finance\FinanceService;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\InventoryItem;
use App\Models\PeriodLock;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use App\Support\Local;
use App\Support\Settings;
use App\Support\Subscription;
use App\Support\SystemNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('uniqa:expire-holds', function (BookingService $bookings) {
    $count = $bookings->expireHolds();
    if ($count) {
        SystemNotice::toPermission('bookings.manage', "{$count} hold(s) expired and released their dates.", '/admin/bookings?status=expired_hold', 'holds');
    }
    Settings::set('system.scheduler_last_run', now()->toIso8601String());
    $this->info("Expired {$count} hold(s).");
})->purpose('Release lapsed booking holds');

Artisan::command('uniqa:daily-alerts', function () {
    $lead = (int) Settings::get('alerts.arrival_lead_days');
    $when = $lead === 1 ? 'tomorrow' : "in {$lead} days";
    $due = fn (string $category) => Booking::where('status', Booking::CONFIRMED)->where('check_in', Local::now()->addDays($lead)->toDateString())
        ->whereHas('property', fn ($p) => $p->where('category', $category))->count();
    if ($arrivals = $due(Property::SUITES)) {
        SystemNotice::toPermission('bookings.manage', "{$arrivals} arrival(s) {$when}. Check balances and special requests.", '/admin?business=suites', 'arrivals');
    }
    if ($pickups = $due(Property::CAMERAS)) {
        SystemNotice::toPermission('bookings.manage', "{$pickups} camera pickup(s) {$when}. Check balances and the renter's ID.", '/admin?business=cameras', 'arrivals');
    }

    $overdue = HousekeepingTask::whereIn('status', ['pending', 'assigned', 'in_progress'])->where('due_at', '<', now())->count();
    if ($overdue) {
        SystemNotice::toPermission('housekeeping.manage', "{$overdue} housekeeping task(s) are overdue.", '/admin/housekeeping', 'housekeeping');
    }

    // Raises a task per low item and property; never buys anything.
    $stock = DB::table('inventory_movements')->selectRaw('inventory_item_id, property_id, SUM(quantity) as qty')->groupBy('inventory_item_id', 'property_id')->get();
    foreach ($stock as $row) {
        $item = InventoryItem::find($row->inventory_item_id);
        if ($item && $item->reorder_level > 0 && $row->qty <= $item->reorder_level) {
            $room = Room::where('property_id', $row->property_id)->orderBy('id')->first();
            if ($room) {
                HousekeepingTask::firstOrCreate(
                    ['idempotency_key' => 'restock:'.$item->id.':'.$row->property_id.':'.Local::now()->format('Y-W')],
                    ['type' => 'restock', 'room_id' => $room->id, 'instructions' => "Restock {$item->name} at ".Property::find($row->property_id)?->name." (on hand: {$row->qty})."],
                );
            }
        }
    }
    $this->info('Daily alerts done.');
})->purpose('Prepare arrival, overdue-task and restock alerts');

// Prepares the owner's review only. It never locks a period: that stays a person's decision.
Artisan::command('uniqa:month-end {--period= : Month to prepare as YYYY-MM; defaults to last month}', function (FinanceService $finance) {
    $period = $this->option('period') ?: Local::now()->subMonthNoOverflow()->format('Y-m');
    if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        $this->error('Period must be YYYY-MM.');

        return 1;
    }
    if (PeriodLock::where('period', $period)->exists() || ! Cache::add("month-end:{$period}", true, now()->addDays(60))) {
        $this->info("{$period} is already locked or prepared.");

        return 0;
    }

    $from = CarbonImmutable::parse("{$period}-01");
    $count = $finance->reconciliationExceptions()->count();
    $query = http_build_query(['from' => $from->toDateString(), 'to' => $from->endOfMonth()->toDateString(), 'type' => 'room_grid']);
    SystemNotice::toPermission('periods.lock', "{$period} is ready for review: {$count} open reconciliation exception(s). Nothing has been locked.", '/admin/reconciliation', 'month_end');
    SystemNotice::toPermission('periods.lock', "{$period} monthly room grid and reports are ready.", "/admin/reports?{$query}", 'month_end');
    $this->info("Prepared {$period}: {$count} exception(s).");

    return 0;
})->purpose('Tell the owner a month is ready for review and period close');

Artisan::command('uniqa:doctor', function () {
    $checks = [];
    $check = function (string $label, bool $ok, string $fix = '', bool $required = true) use (&$checks) {
        $checks[] = [$ok ? 'PASS' : ($required ? 'FAIL' : 'WARN'), $label, $ok ? '' : $fix];
    };
    $production = app()->environment('production');
    $clashes = app(\App\Domain\Availability\InventoryService::class)->overlaps(\App\Support\Local::today())->count();
    $check('No schedule conflicts: no room has overlapping stays or blocks', $clashes === 0, "{$clashes} found. Open the Booking Calendar and resolve them");
    $database = config('database.connections.'.config('database.default'));

    $check('App key is set', (bool) config('app.key'), 'Run php artisan key:generate, and keep a copy of the key.');
    $check('Debug mode is off', ! config('app.debug'), 'Set APP_DEBUG=false.', $production);
    $check('Demo one-click sign-in is off', ! config('uniqa.demo'), 'Set DEMO=false.', $production);
    $check('Environment is production', $production, 'Set APP_ENV=production.', false);
    $check('App URL uses HTTPS', str_starts_with((string) config('app.url'), 'https://'), 'Set APP_URL to the https:// address.', $production);
    $check('Session cookie requires HTTPS', (bool) config('session.secure'), 'Set SESSION_SECURE_COOKIE=true.', $production);
    $check('Database account is not root', ($database['username'] ?? '') !== 'root', 'Create a limited database account.', $production);
    $check('Database account has a password', ($database['password'] ?? '') !== '', 'Set DB_PASSWORD.', $production);
    $check('Mail is really sent', ! in_array(config('mail.default'), ['log', 'array'], true), 'Set a real MAIL_MAILER: invitations and password resets currently go to the log file.', $production);
    $check('Frontend is built', is_file(public_path('build/manifest.json')), 'Run npm run build.');
    $check('Development server marker is absent', ! is_file(public_path('hot')), 'Delete public/hot.', $production);
    $check('Public storage is linked', file_exists(public_path('storage')), 'Run php artisan storage:link.');

    try {
        DB::connection()->getPdo();
        $check('Database connects', true);
        $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))->keys()->diff(DB::table('migrations')->pluck('migration'))->count();
        $check('Migrations are all applied', $pending === 0, "{$pending} pending: run php artisan migrate.");
        $last = Settings::get('system.scheduler_last_run');
        $check('Scheduler ran in the last 10 minutes', $last && now()->diffInMinutes($last, true) <= 10, 'Start the scheduler: see README.', $production);
        $check('An active Owner exists', User::role('Owner')->where('is_active', true)->exists(), 'Run php artisan db:seed.');
        $unprotected = User::permission('team.manage')->where('is_active', true)->whereNull('two_factor_confirmed_at')->count();
        $check('Owners use two-factor sign-in', $unprotected === 0, "{$unprotected} account(s) without it: set it up under Account.", $production);
    } catch (Throwable $e) {
        $check('Database connects', false, class_basename($e).': check the DB_ values in .env.');
    }

    $this->table(['Result', 'Check', 'What to do'], $checks);

    return collect($checks)->contains(fn ($c) => $c[0] === 'FAIL') ? 1 : 0;
})->purpose('Check this installation is safe and ready to run');

if (app()->environment('testing')) {
    require __DIR__.'/test-console.php';
}

// The simulation commands do not exist anywhere but the simulation database.
if (str_ends_with((string) config('database.connections.'.config('database.default').'.database'), '_sim')) {
    require __DIR__.'/simulation-console.php';
}

Artisan::command('uniqa:subscription-check', function () {
    Subscription::ensureBill(); // the next month's bill always exists before anyone is reminded
    $sent = Subscription::remind();
    $this->info($sent ? "Reminder sent: {$sent}" : 'No subscription reminder today.');
})->purpose('Remind the Owner and Administrator in the bell before and after the subscription ends');

Schedule::command('uniqa:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('uniqa:subscription-check')->dailyAt('07:05')->timezone(config('uniqa.display_timezone'));
Schedule::command('uniqa:daily-alerts')->dailyAt('07:00')->timezone(config('uniqa.display_timezone'));
Schedule::command('uniqa:month-end')->monthlyOn(1, '07:30')->timezone(config('uniqa.display_timezone'));
