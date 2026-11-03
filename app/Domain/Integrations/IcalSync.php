<?php

namespace App\Domain\Integrations;

use App\Domain\Availability\InventoryService;
use App\Models\ExternalCalendarFeed;
use App\Models\IntegrationHealth;
use App\Models\RoomBlock;
use App\Support\SystemNotice;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only ingestion of blocked dates. A feed can lag or omit detail, so a clash with an
 * existing booking is raised for staff to resolve; it never moves or cancels a stay.
 */
class IcalSync
{
    public function __construct(private InventoryService $inventory)
    {
    }

    public function syncAll(): int
    {
        $feeds = ExternalCalendarFeed::where('is_active', true)->get();
        if ($feeds->isEmpty()) {
            return 0;
        }

        $failures = 0;
        foreach ($feeds as $feed) {
            try {
                $response = Http::timeout(20)->get($feed->url);
                $response->throw();
                $this->apply($feed, $this->parse($response->body()));
                $feed->update(['last_synced_at' => now(), 'last_status' => 'ok', 'last_error' => null]);
            } catch (Throwable $e) {
                $failures++;
                // The message can contain the private URL, so it is not stored.
                $feed->update(['last_status' => 'failed', 'last_error' => class_basename($e)]);
            }
        }

        $health = IntegrationHealth::firstOrCreate(['provider' => 'Airbnb iCal']);
        $status = $failures === 0 ? 'connected' : ($failures < $feeds->count() ? 'degraded' : 'failed');
        $health->update([
            'status' => $status,
            'detail' => "{$feeds->count()} feed(s), {$failures} failed. Read-only and may lag behind the channel.",
            $failures ? 'last_failure_at' : 'last_success_at' => now(),
        ]);
        if ($failures) {
            SystemNotice::toPermission('settings.manage', "{$failures} calendar feed(s) failed to sync.", '/admin/settings');
        }

        return $feeds->count();
    }

    /** @return list<array{uid:string,start:string,end:string}> */
    public function parse(string $ics): array
    {
        $unfolded = preg_replace("/\r?\n[ \t]/", '', $ics);
        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $unfolded, $matches);

        $events = [];
        foreach ($matches[1] as $body) {
            if (preg_match('/UID[^:]*:(.+)/', $body, $uid)
                && preg_match('/DTSTART[^:]*:(\d{8})/', $body, $start)
                && preg_match('/DTEND[^:]*:(\d{8})/', $body, $end)) {
                $events[] = [
                    'uid' => substr(trim($uid[1]), 0, 180),
                    'start' => date('Y-m-d', strtotime($start[1])),
                    'end' => date('Y-m-d', strtotime($end[1])),
                ];
            }
        }

        return $events;
    }

    private function apply(ExternalCalendarFeed $feed, array $events): void
    {
        $this->inventory->withRoomLocks([$feed->room_id], function () use ($feed, $events) {
            $seen = [];
            foreach ($events as $e) {
                $seen[] = $e['uid'];
                $clash = $this->inventory->conflicts($feed->room_id, $e['start'], $e['end'])->where('type', '!=', 'block');
                if ($clash->isNotEmpty()) {
                    SystemNotice::toPermission('bookings.manage', "Calendar clash on room #{$feed->room_id}: external block {$e['start']} to {$e['end']} overlaps {$clash->first()['label']}.", '/admin/calendar');
                }
                RoomBlock::updateOrCreate(
                    ['external_calendar_feed_id' => $feed->id, 'external_uid' => $e['uid']],
                    ['room_id' => $feed->room_id, 'start_date' => $e['start'], 'end_date' => $e['end'], 'kind' => 'external_calendar', 'reason' => strtoupper($feed->provider).' calendar', 'released_at' => null],
                );
            }
            // Events removed upstream release their block.
            RoomBlock::where('external_calendar_feed_id', $feed->id)->whereNull('released_at')->whereNotIn('external_uid', $seen ?: [''])->update(['released_at' => now()]);
        });
    }
}
