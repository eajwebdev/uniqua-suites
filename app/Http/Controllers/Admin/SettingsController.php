<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\RoomConflict;
use App\Http\Controllers\Controller;
use App\Models\ExternalCalendarFeed;
use App\Models\IntegrationHealth;
use App\Models\MessageTemplate;
use App\Models\OwnerDecision;
use App\Models\Room;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
    {
        $s = Settings::all();

        return Inertia::render('Admin/Settings', [
            'settings' => [
                'weekend_days' => $s['pricing.weekend_days'],
                'extra_guest' => Money::pesos($s['pricing.extra_guest_cents']),
                'extra_guest_unit' => $s['pricing.extra_guest_unit'],
                'early_fee' => Money::pesos($s['pricing.early_fee_cents_per_hour']),
                'standard_check_in' => $s['pricing.standard_check_in'],
                'standard_check_out' => $s['pricing.standard_check_out'],
                'late_fee' => Money::pesos($s['pricing.late_fee_cents_per_hour']),
                'hold_hours' => $s['holds.default_hours'],
                'laundry_auto' => $s['laundry.auto_create'],
                'require_verification' => $s['finance.require_verification_to_confirm'],
                'turnover_due_hours' => $s['housekeeping.turnover_due_hours'],
            ],
            'decisions' => OwnerDecision::orderByRaw("FIELD(status,'pending','approved','rejected')")->orderBy('id')->get(['id', 'title', 'detail', 'kind', 'status', 'resolution', 'decided_at']),
            'templates' => MessageTemplate::orderBy('id')->get(['id', 'key', 'name', 'body']),
            'integrations' => IntegrationHealth::orderBy('id')->get(['provider', 'status', 'detail', 'last_success_at', 'last_failure_at']),
            // The private feed URL is write-only: never sent back to the browser.
            'feeds' => ExternalCalendarFeed::with('room:id,name')->orderBy('id')->get()->map(fn ($f) => ['id' => $f->id, 'room' => $f->room->name, 'provider' => $f->provider, 'last_synced_at' => $f->last_synced_at, 'last_status' => $f->last_status]),
            'rooms' => Room::orderBy('id')->get(['id', 'name']),
            'contact' => array_map(fn ($v) => (bool) $v, config('uniqa.contact')),
        ]);
    }

    public function update(Request $request)
    {
        $d = $request->validate([
            'weekend_days' => 'nullable|array',
            'weekend_days.*' => 'integer|between:1,7',
            'extra_guest' => ['required', Money::RULE],
            'extra_guest_unit' => 'nullable|in:per_night,per_stay',
            'early_fee' => ['required', Money::RULE],
            'standard_check_in' => 'required|date_format:H:i',
            'standard_check_out' => 'nullable|date_format:H:i',
            'late_fee' => ['nullable', Money::RULE],
            'hold_hours' => 'required|integer|min:1|max:336',
            'laundry_auto' => 'required|boolean',
            'require_verification' => 'required|boolean',
            'turnover_due_hours' => 'required|integer|min:1|max:72',
        ]);

        // An empty list stays "pending": no weekend pricing is a decision the owner has to make explicitly.
        $weekend = empty($d['weekend_days']) ? null : array_values(array_map('intval', $d['weekend_days']));
        $values = [
            'pricing.weekend_days' => $weekend,
            'pricing.extra_guest_cents' => Money::cents($d['extra_guest']),
            'pricing.extra_guest_unit' => $d['extra_guest_unit'] ?? null,
            'pricing.early_fee_cents_per_hour' => Money::cents($d['early_fee']),
            'pricing.standard_check_in' => $d['standard_check_in'],
            'pricing.standard_check_out' => $d['standard_check_out'] ?? null,
            'pricing.late_fee_cents_per_hour' => Money::cents($d['late_fee'] ?? null),
            'holds.default_hours' => (int) $d['hold_hours'],
            'laundry.auto_create' => (bool) $d['laundry_auto'],
            'finance.require_verification_to_confirm' => (bool) $d['require_verification'],
            'housekeeping.turnover_due_hours' => (int) $d['turnover_due_hours'],
        ];
        $before = Settings::all();
        $changed = [];
        foreach ($values as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
                Settings::set($key, $value);
            }
        }
        if ($changed) {
            Audit::log('settings.updated', null, $changed);
        }

        return back()->with('success', 'Settings saved.');
    }

    public function decide(Request $request, OwnerDecision $decision)
    {
        $d = $request->validate(['status' => 'required|in:approved,rejected,pending', 'resolution' => 'nullable|string|max:2000']);
        $decision->update($d + ['decided_by' => $request->user()->id, 'decided_at' => now()]);
        Audit::log('decision.recorded', $decision, $d);

        return back()->with('success', 'Decision recorded.');
    }

    public function updateTemplate(Request $request, MessageTemplate $template)
    {
        $template->update($request->validate(['body' => 'required|string|max:4000']));
        Audit::log('template.updated', $template);

        return back()->with('success', 'Template saved.');
    }

    public function storeFeed(Request $request)
    {
        $d = $request->validate(['room_id' => 'required|exists:rooms,id', 'provider' => 'required|string|max:40', 'url' => 'required|url:https|max:1000']);
        $host = parse_url($d['url'], PHP_URL_HOST);
        if (! $host || ! filter_var(gethostbyname($host), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RoomConflict('The calendar URL must point to a public internet host.');
        }
        $feed = ExternalCalendarFeed::create($d);
        Audit::log('feed.added', $feed, ['room_id' => $d['room_id'], 'provider' => $d['provider']]);

        return back()->with('success', 'Calendar feed saved. It syncs hourly while the scheduler is running.');
    }

    public function audit(Request $request)
    {
        $f = $request->validate(['action' => 'nullable|string|max:60']);
        $rows = DB::table('activity_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('a.action', 'like', "{$v}%"))
            ->orderByDesc('a.id')->select(['a.id', 'a.created_at', 'u.name as user', 'a.action', 'a.subject_type', 'a.subject_id', 'a.meta', 'a.ip'])
            ->paginate(50)->withQueryString();

        return Inertia::render('Admin/Resource', [
            'title' => 'Audit Log',
            'subtitle' => 'Append-only record of sensitive actions.',
            'search' => ['value' => $f['action'] ?? null, 'placeholder' => 'Filter by action, e.g. booking', 'param' => 'action'],
            'columns' => [
                ['key' => 'created_at', 'label' => 'When (UTC)'], ['key' => 'user', 'label' => 'User'], ['key' => 'action', 'label' => 'Action'],
                ['key' => 'subject', 'label' => 'Subject'], ['key' => 'meta', 'label' => 'Details'], ['key' => 'ip', 'label' => 'IP'],
            ],
            'rows' => $rows->through(fn ($r) => [
                'id' => $r->id,
                'created_at' => $r->created_at,
                'user' => $r->user ?? 'System',
                'action' => $r->action,
                'subject' => $r->subject_type ? class_basename($r->subject_type).' #'.$r->subject_id : null,
                'meta' => $r->meta,
                'ip' => $r->ip,
            ]),
        ]);
    }
}
