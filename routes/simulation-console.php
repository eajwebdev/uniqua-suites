<?php

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\RoomConflict;
use App\Domain\Bookings\BookingService;
use App\Models\Booking;
use App\Models\BookingSource;
use App\Models\Guest;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Local;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

// Loaded only when the database name ends in _sim: see routes/console.php and scripts/simulation.ps1.

// The script calls this first. If the database is not the simulation one, this command does not exist and the script stops.
Artisan::command('uniqa:sim-guard', fn () => $this->line('Simulation database confirmed: '.config('database.connections.'.config('database.default').'.database')));

// One competitor in the race. Runs as its own process, with its own database connection.
Artisan::command('uniqa:sim-confirm {booking} {user} {startAt}', function (BookingService $bookings) {
    Auth::onceUsingId((int) $this->argument('user'));
    $booking = Booking::findOrFail((int) $this->argument('booking'));
    $wait = (float) $this->argument('startAt') - microtime(true);
    if ($wait > 0) {
        usleep((int) ($wait * 1_000_000));
    }

    try {
        $bookings->confirm($booking);
        $this->line('CONFIRMED');
    } catch (RoomConflict) {
        $this->line('CONFLICT');
    } catch (Throwable $e) {
        $this->line('ERROR '.class_basename($e).': '.$e->getMessage());

        return 1;
    }

    return 0;
});

Artisan::command('uniqa:simulate', function (BookingService $bookings, InventoryService $inventory) {
    $owner = User::where('email', 'owner@sim.test')->firstOrFail();
    Auth::login($owner);

    $results = [];
    $check = function (string $name, callable $test) use (&$results) {
        try {
            $detail = $test();
            $results[] = ['PASS', $name, is_string($detail) ? $detail : ''];
        } catch (Throwable $e) {
            $results[] = ['FAIL', $name, class_basename($e).': '.$e->getMessage()];
        }
    };
    $assert = function (bool $ok, string $why) {
        if (! $ok) {
            throw new RuntimeException($why);
        }
    };

    // Every scenario gets its own stretch of the calendar, well clear of the seeded stays.
    $window = 200;
    $base = function () use (&$window) {
        $window += 20;

        return $window;
    };
    $date = fn (int $offset) => Local::now()->addDays($offset)->toDateString();
    $room = fn (string $slug) => Room::where('slug', $slug)->firstOrFail();
    $source = fn (string $code = 'facebook_messenger') => BookingSource::where('code', $code)->value('id');
    $inquiry = fn (string|array $slugs, int $in, int $out, string $code = 'facebook_messenger', int $adults = 2) => $bookings->createInquiry([
        'guest_id' => Guest::create(['full_name' => 'SIM Scenario Guest'])->id,
        'room_ids' => Room::whereIn('slug', (array) $slugs)->pluck('id')->all(),
        'booking_source_id' => $source($code),
        'check_in' => $date($in),
        'check_out' => $date($out),
        'adults' => $adults,
    ]);
    $confirmed = fn (string|array $slugs, int $in, int $out, string $code = 'facebook_messenger') => $bookings->confirm($inquiry($slugs, $in, $out, $code));
    // Seeded guests are staying today, and a room's next guest has to wait for them to leave.
    $vacate = function (string $slug) use ($bookings, $room) {
        Booking::where('status', Booking::CHECKED_IN)->whereHas('activeRooms', fn ($stay) => $stay->where('room_id', $room($slug)->id))->get()
            ->each(fn ($stay) => $bookings->checkOut($stay));
    };
    $refused = function (callable $action, string $why) use ($assert) {
        try {
            $action();
        } catch (RoomConflict $e) {
            return $e->getMessage();
        }
        $assert(false, "Expected a refusal: {$why}");
    };

    // ---- Booking conflicts ----
    $check('Same room, same dates: second booking refused', function () use ($base, $confirmed, $refused) {
        $d = $base();
        $confirmed('venice', $d, $d + 3);

        return $refused(fn () => $confirmed('venice', $d, $d + 3), 'exact same dates');
    });
    foreach (['overlaps the start' => [-1, 1], 'overlaps the end' => [2, 4], 'sits inside the stay' => [1, 2], 'surrounds the stay' => [-1, 4]] as $shape => [$from, $to]) {
        $check("Partial overlap refused: new stay {$shape}", function () use ($base, $confirmed, $refused, $from, $to, $shape) {
            $d = $base();
            $confirmed('bali', $d, $d + 3);
            $refused(fn () => $confirmed('bali', $d + $from, $d + $to), $shape);
        });
    }
    $check('Back-to-back allowed: leave and arrive the same day', function () use ($base, $confirmed, $assert) {
        $d = $base();
        $confirmed('tokyo', $d, $d + 2);
        $assert($confirmed('tokyo', $d + 2, $d + 4)->status === Booking::CONFIRMED, 'next stay was not confirmed');
        $assert($confirmed('tokyo', $d - 1, $d)->status === Booking::CONFIRMED, 'earlier stay was not confirmed');
    });
    $check('Airbnb booking blocks a Facebook booking on the same dates', function () use ($base, $confirmed, $refused) {
        $d = $base();
        $confirmed('roma', $d, $d + 2, 'airbnb');
        $refused(fn () => $confirmed('roma', $d, $d + 2, 'facebook_messenger'), 'cross-channel');
    });
    $check('Different rooms on the same dates are fine', function () use ($base, $confirmed, $assert) {
        $d = $base();
        $confirmed('milan', $d, $d + 2);
        $assert($confirmed('berlin', $d, $d + 2)->status === Booking::CONFIRMED, 'second room refused');
    });

    // ---- Holds and inquiries ----
    $check('Active hold blocks another booking', function () use ($base, $inquiry, $confirmed, $bookings, $refused) {
        $d = $base();
        $bookings->hold($inquiry('paris', $d, $d + 2));
        $refused(fn () => $confirmed('paris', $d, $d + 2), 'room is on hold');
    });
    $check('Expired hold frees the dates', function () use ($base, $inquiry, $confirmed, $bookings, $assert) {
        $d = $base();
        $held = $bookings->hold($inquiry('paris', $d, $d + 2));
        Booking::whereKey($held->id)->update(['hold_expires_at' => now()->subMinute()]);
        $assert($confirmed('paris', $d, $d + 2)->status === Booking::CONFIRMED, 'dates stayed blocked');
    });
    $check('Plain inquiry does not reserve the room', function () use ($base, $inquiry, $confirmed, $assert) {
        $d = $base();
        $inquiry('new-york', $d, $d + 2);
        $assert($confirmed('new-york', $d, $d + 2)->status === Booking::CONFIRMED, 'inquiry blocked the room');
    });
    $check('Inquiry cannot be confirmed once its dates are taken', function () use ($base, $inquiry, $confirmed, $bookings, $refused, $assert) {
        $d = $base();
        $first = $inquiry('rio', $d, $d + 2);
        $confirmed('rio', $d, $d + 2);
        $refused(fn () => $bookings->confirm($first), 'dates taken since');
        $assert($first->fresh()->status === Booking::INQUIRY, 'inquiry changed status');
    });

    // ---- All or nothing ----
    $check('Group booking with one taken room saves nothing', function () use ($base, $confirmed, $inquiry, $bookings, $refused, $assert) {
        $d = $base();
        $confirmed('venice', $d, $d + 2);
        $group = $inquiry(['venice', 'bali'], $d, $d + 2);
        $refused(fn () => $bookings->confirm($group), 'one room is taken');
        $assert($group->fresh()->status === Booking::INQUIRY, 'group changed status');
        $assert(DB::table('room_night_claims')->where('booking_id', $group->id)->count() === 0, 'nights were held');
        $assert(DB::table('booking_charges')->where('booking_id', $group->id)->count() === 0, 'charges were written');
    });

    // ---- Changes after booking ----
    $check('Extending into the next stay is refused and nothing changes', function () use ($base, $confirmed, $bookings, $refused, $assert, $date) {
        $d = $base();
        $first = $confirmed('tokyo', $d, $d + 2);
        $confirmed('tokyo', $d + 3, $d + 5);
        $refused(fn () => $bookings->amend($first, ['check_out' => $date($d + 4)]), 'extends into next stay');
        $assert($first->fresh()->check_out->toDateString() === $date($d + 2), 'checkout moved');
    });
    $check('Moving to an occupied room is refused', function () use ($base, $confirmed, $bookings, $refused, $room) {
        $d = $base();
        $mine = $confirmed('roma', $d, $d + 2);
        $confirmed('milan', $d, $d + 2);
        $refused(fn () => $bookings->amend($mine, ['room_ids' => [$room('milan')->id]]), 'target room is taken');
    });
    $check('Moving a stay frees the old room', function () use ($base, $confirmed, $bookings, $room, $assert) {
        $d = $base();
        $mine = $confirmed('roma', $d, $d + 2);
        $bookings->amend($mine, ['room_ids' => [$room('berlin')->id]]);
        $assert($confirmed('roma', $d, $d + 2)->status === Booking::CONFIRMED, 'old room stayed blocked');
    });
    $check('Cancelling frees the dates', function () use ($base, $confirmed, $bookings, $assert) {
        $d = $base();
        $mine = $confirmed('berlin', $d, $d + 2);
        $bookings->cancel($mine, 'SIM');
        $assert($confirmed('berlin', $d, $d + 2)->status === Booking::CONFIRMED, 'dates stayed blocked');
    });

    // ---- Blocks and closures ----
    $check('Repair block stops bookings, and releasing it frees them', function () use ($base, $confirmed, $refused, $room, $date, $assert) {
        $d = $base();
        $block = RoomBlock::create(['room_id' => $room('venice')->id, 'start_date' => $date($d), 'end_date' => $date($d + 2), 'kind' => 'repair', 'reason' => 'SIM']);
        $refused(fn () => $confirmed('venice', $d, $d + 1), 'room is blocked');
        $block->update(['released_at' => now()]);
        $assert($confirmed('venice', $d, $d + 1)->status === Booking::CONFIRMED, 'release did not free it');
    });
    $check('Date closure stops bookings on every room, with the reason', function () use ($base, $confirmed, $refused, $date, $assert) {
        $d = $base();
        foreach (Room::all() as $r) {
            RoomBlock::create(['room_id' => $r->id, 'start_date' => $date($d), 'end_date' => $date($d + 1), 'kind' => RoomBlock::CLOSURE, 'reason' => 'SIM party', 'external_uid' => 'closure:sim-'.$d]);
        }
        foreach (['venice', 'bali', 'new-york'] as $slug) {
            $message = $refused(fn () => $confirmed($slug, $d, $d + 1), 'closed');
            $assert(str_contains($message, 'SIM party'), 'reason not given: '.$message);
        }
        $assert($confirmed('venice', $d + 1, $d + 2)->status === Booking::CONFIRMED, 'morning after should be open');
    });
    $check('Website shows blocked dates without the reason', function () use ($base, $inventory, $room, $date, $assert) {
        $d = $base();
        RoomBlock::create(['room_id' => $room('rio')->id, 'start_date' => $date($d), 'end_date' => $date($d + 2), 'kind' => RoomBlock::CLOSURE, 'reason' => 'SIM secret', 'external_uid' => 'closure:sim-web']);
        $ranges = $inventory->takenRanges($room('rio')->id, $date($d - 1), $date($d + 5));
        $assert($ranges === [['from' => $date($d), 'to' => $date($d + 2)]], 'wrong ranges: '.json_encode($ranges));
        $assert(! str_contains(json_encode($ranges), 'secret'), 'reason leaked');
    });
    $check('Safety work order stops bookings until resolved', function () use ($base, $confirmed, $refused, $room, $assert) {
        $d = $base();
        $order = WorkOrder::create(['room_id' => $room('milan')->id, 'title' => 'SIM exposed wiring', 'severity' => 'critical', 'safety_block' => true, 'status' => 'open']);
        $refused(fn () => $confirmed('milan', $d, $d + 1), 'safety issue is open');
        $order->update(['status' => 'resolved']);
        $assert($confirmed('milan', $d, $d + 1)->status === Booking::CONFIRMED, 'resolved issue still blocks');
    });

    // ---- Guests and rooms ----
    $check('Too many guests for the room is refused', function () use ($base, $inquiry, $refused) {
        $d = $base();
        $refused(fn () => $inquiry('rio', $d, $d + 1, 'facebook_messenger', 30), 'over capacity');
    });
    $check('Dirty room blocks check-in; override is logged', function () use ($base, $confirmed, $bookings, $room, $refused, $assert, $vacate) {
        $d = $base();
        $vacate('paris');
        $mine = $confirmed('paris', $d, $d + 1);
        $room('paris')->update(['housekeeping_status' => 'dirty']);
        $refused(fn () => $bookings->checkIn($mine), 'room is dirty');
        $bookings->checkIn($mine->fresh(), true, 'SIM manager approved');
        $assert($mine->fresh()->status === Booking::CHECKED_IN, 'override did not check in');
        $assert(DB::table('activity_logs')->where('action', 'booking.readiness_override')->exists(), 'override not logged');
        $room('paris')->update(['housekeeping_status' => 'ready']);
    });
    $check('Next guest cannot check in until the last guest has left, even with an override', function () use ($base, $confirmed, $bookings, $refused, $room, $assert, $vacate) {
        $d = $base();
        $vacate('rio');
        $first = $confirmed('rio', $d, $d + 2);
        $second = $confirmed('rio', $d + 2, $d + 4);
        $room('rio')->update(['housekeeping_status' => 'ready']);
        $bookings->checkIn($first);
        $message = $refused(fn () => $bookings->checkIn($second->fresh(), true, 'SIM override'), 'first guest still in');
        $assert($second->fresh()->status === Booking::CONFIRMED, 'second guest checked in');
        $bookings->checkOut($first->fresh());
        $bookings->checkIn($second->fresh(), true, 'SIM after cleaning');
        $assert($second->fresh()->status === Booking::CHECKED_IN, 'still refused after checkout');

        return $message;
    });
    $check('Checking out twice creates one cleaning only', function () use ($base, $confirmed, $bookings, $assert, $vacate) {
        $d = $base();
        $vacate('new-york');
        $mine = $confirmed('new-york', $d, $d + 1);
        $bookings->checkIn($mine, true, 'SIM');
        $bookings->checkOut($mine->fresh());
        $bookings->checkOut($mine->fresh());
        $assert(HousekeepingTask::where('booking_id', $mine->id)->count() === 1, 'duplicate cleaning');
    });

    // ---- The database's own safety net ----
    $check('Database itself refuses a double-held night', function () use ($base, $confirmed, $date, $room) {
        $d = $base();
        $mine = $confirmed('berlin', $d, $d + 1);
        try {
            DB::table('room_night_claims')->insert(['room_id' => $room('berlin')->id, 'stay_date' => $date($d), 'booking_id' => $mine->id]);
        } catch (QueryException) {
            return 'unique key held';
        }
        throw new RuntimeException('The database accepted a duplicate night.');
    });

    // ---- Real simultaneous requests, as separate processes ----
    $race = function (array $contenders) use ($owner) {
        $startAt = (string) (microtime(true) + 7);
        $workers = [];
        foreach ($contenders as $booking) {
            $process = new Process([PHP_BINARY, 'artisan', 'uniqa:sim-confirm', (string) $booking->id, (string) $owner->id, $startAt], base_path());
            $process->setTimeout(120);
            $process->start();
            $workers[] = $process;
        }
        $outcomes = [];
        foreach ($workers as $process) {
            $process->wait();
            $lines = array_filter(explode("\n", trim($process->getOutput())));
            $outcomes[] = trim((string) end($lines)) ?: 'NO OUTPUT: '.trim($process->getErrorOutput());
        }

        return $outcomes;
    };
    foreach ([1, 2, 3] as $round) {
        $check("Race {$round}: 8 staff confirm the same room at the same instant", function () use ($base, $inquiry, $race, $assert) {
            $d = $base();
            $contenders = array_map(fn () => $inquiry('venice', $d, $d + 2), range(1, 8));
            $outcomes = $race($contenders);
            $counts = array_count_values(array_map(fn ($o) => explode(' ', $o)[0], $outcomes));
            $assert(($counts['CONFIRMED'] ?? 0) === 1 && ($counts['CONFLICT'] ?? 0) === 7, 'outcomes: '.implode(' | ', array_unique($outcomes)));
            $ids = array_map(fn ($b) => $b->id, $contenders);
            $assert(Booking::whereIn('id', $ids)->where('status', Booking::CONFIRMED)->count() === 1, 'database shows more than one');

            return '1 confirmed, 7 refused';
        });
    }
    $check('Race 4: 16 overlapping date ranges on one room', function () use ($base, $inquiry, $race, $assert) {
        $d = $base();
        mt_srand(7);
        $contenders = [];
        foreach (range(1, 16) as $i) {
            $in = $d + mt_rand(0, 9);
            $contenders[] = $inquiry('bali', $in, $in + mt_rand(1, 4));
        }
        $outcomes = $race($contenders);
        $errors = array_filter($outcomes, fn ($o) => ! in_array($o, ['CONFIRMED', 'CONFLICT'], true));
        $assert($errors === [], 'unexpected: '.implode(' | ', array_unique($errors)));
        $won = Booking::whereIn('id', array_map(fn ($b) => $b->id, $contenders))->where('status', Booking::CONFIRMED)->orderBy('check_in')->get();
        $assert($won->isNotEmpty(), 'nobody won');
        foreach ($won->values() as $i => $stay) {
            if ($i > 0) {
                $assert($won[$i - 1]->check_out->toDateString() <= $stay->check_in->toDateString(), 'two winners overlap');
            }
        }

        return $won->count().' confirmed without overlap, '.(16 - $won->count()).' refused';
    });

    // ---- The whole database afterwards ----
    $live = [Booking::CONFIRMED, Booking::CHECKED_IN];
    $check('Whole database: no two live stays or blocks overlap', function () use ($inventory, $assert) {
        $found = $inventory->overlaps('2000-01-01');
        $real = $found->filter(fn ($o) => ! str_starts_with((string) $o->second, 'SIM'));
        $assert($real->isEmpty(), 'overlaps: '.$real->take(3)->toJson());

        return $found->count().' rows checked as overlaps';
    });
    $check('Whole database: every live stay holds exactly its nights', function () use ($live, $assert) {
        $wrong = 0;
        $stays = Booking::whereIn('status', $live)->with('activeRooms')->get();
        foreach ($stays as $b) {
            $nights = $b->activeRooms->sum(fn ($r) => (int) $r->check_in->diffInDays($r->check_out));
            if ($nights !== DB::table('room_night_claims')->where('booking_id', $b->id)->count()) {
                $wrong++;
            }
        }
        $assert($wrong === 0, "{$wrong} of {$stays->count()} stays disagree");

        return $stays->count().' live stays checked';
    });
    $check('Whole database: no night is held by a dead booking', function () use ($assert) {
        $dead = DB::table('room_night_claims as c')->join('bookings as b', 'b.id', '=', 'c.booking_id')
            ->whereNotIn('b.status', [Booking::CONFIRMED, Booking::CHECKED_IN, Booking::TENTATIVE])->count();
        $assert($dead === 0, "{$dead} nights held by cancelled or finished bookings");
    });
    $check('Whole database: each booking total equals its charges', function () use ($assert) {
        $rows = DB::table('bookings as b')->leftJoin('booking_charges as c', fn ($j) => $j->on('c.booking_id', '=', 'b.id')->whereNull('c.voided_at'))
            ->whereIn('b.status', [Booking::CONFIRMED, Booking::CHECKED_IN, Booking::CHECKED_OUT])
            ->groupBy('b.id', 'b.reference', 'b.total_cents')->selectRaw('b.reference, b.total_cents, COALESCE(SUM(c.amount_cents),0) as charges')->get();
        $wrong = $rows->filter(fn ($r) => (int) $r->total_cents !== (int) $r->charges);
        $assert($wrong->isEmpty(), $wrong->count().' disagree, e.g. '.$wrong->first()?->reference.': '.$wrong->first()?->total_cents.' vs '.$wrong->first()?->charges);

        return $rows->count().' bookings checked';
    });
    $check('Whole database: each confirmed booking has a locked price', function () use ($assert) {
        $missing = DB::table('bookings as b')->leftJoin('pricing_snapshots as p', 'p.booking_id', '=', 'b.id')
            ->whereIn('b.status', [Booking::CONFIRMED, Booking::CHECKED_IN, Booking::CHECKED_OUT])->whereNull('p.id')->count();
        $assert($missing === 0, "{$missing} without a price record");
    });
    $check('Whole database: each checkout has exactly one cleaning', function () use ($assert) {
        $rows = DB::table('booking_rooms as br')->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->leftJoin('housekeeping_tasks as t', fn ($j) => $j->on('t.booking_id', '=', 'b.id')->on('t.room_id', '=', 'br.room_id')->where('t.type', 'turnover'))
            ->where('b.status', Booking::CHECKED_OUT)->whereNull('br.released_at')
            ->groupBy('br.id')->selectRaw('br.id, COUNT(t.id) as tasks')->get();
        $wrong = $rows->where('tasks', '!=', 1)->count();
        $assert($wrong === 0, "{$wrong} of {$rows->count()} checkouts");

        return $rows->count().' checkouts checked';
    });
    $check('Whole database: no room has two guests in it at once', function () use ($assert) {
        $rooms = DB::table('booking_rooms as br')->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->where('b.status', Booking::CHECKED_IN)->whereNull('br.released_at')->groupBy('br.room_id')
            ->selectRaw('br.room_id, COUNT(*) as stays')->having('stays', '>', 1)->get();
        $assert($rooms->isEmpty(), $rooms->count().' rooms');
    });

    $this->table(['Result', 'Scenario', 'Detail'], array_map(fn ($r) => [$r[0], $r[1], mb_strimwidth($r[2], 0, 95, '...')], $results));
    $failed = count(array_filter($results, fn ($r) => $r[0] === 'FAIL'));
    $this->newLine();
    $this->line(count($results).' scenarios, '.$failed.' failed.');

    return $failed ? 1 : 0;
})->purpose('Try to break the booking rules on the simulation database');
