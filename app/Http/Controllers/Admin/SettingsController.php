<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Bookings\MessageService;
use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\OwnerDecision;
use App\Models\TaxRule;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $s = Settings::all();

        return Inertia::render('Admin/Settings', [
            'settings' => [
                'weekend_days' => $s['pricing.weekend_days'],
                'extra_guest' => Money::pesos($s['pricing.extra_guest_cents']),
                'extra_guest_unit' => $s['pricing.extra_guest_unit'],
                'early_fee' => Money::pesos($s['pricing.early_fee_cents_per_hour']),
                'parking_rate' => Money::pesos($s['pricing.parking_night_cents']),
                'downpayment_percent' => $s['finance.downpayment_percent'],
                'standard_check_in' => $s['pricing.standard_check_in'],
                'standard_check_out' => $s['pricing.standard_check_out'],
                'late_fee' => Money::pesos($s['pricing.late_fee_cents_per_hour']),
                'hold_hours' => $s['holds.default_hours'],
                'laundry_auto' => $s['laundry.auto_create'],
                'require_verification' => $s['finance.require_verification_to_confirm'],
                'turnover_due_hours' => $s['housekeeping.turnover_due_hours'],
                'expense_threshold' => Money::pesos($s['finance.expense_approval_threshold_cents']),
                'second_reviewer' => $s['finance.second_reviewer'],
                'require_two_factor' => $s['security.require_two_factor'],
                'min_nights' => $s['stay.min_nights'],
                'max_advance_days' => $s['stay.max_advance_days'],
                'house_rules' => $s['policy.house_rules'],
                'cancellation' => $s['policy.cancellation'],
                'arrival_lead_days' => $s['alerts.arrival_lead_days'],
            ],
            'taxes' => TaxRule::orderByDesc('effective_from')->orderByDesc('id')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'percent' => number_format($t->rate_basis_points / 100, 2, '.', ''), 'from' => $t->effective_from->toDateString(), 'to' => $t->effective_to?->toDateString()]),
            // Setup notes are for the Administrator. Not sent at all to anyone else, including the Owner.
            'decisions' => $request->user()->hasRole('Administrator') ? OwnerDecision::orderBy('id')->get(['id', 'title', 'resolution']) : null,
            'templates' => MessageTemplate::orderBy('id')->get(['id', 'key', 'name', 'body']),
            'placeholders' => MessageService::PLACEHOLDERS,
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
            'parking_rate' => ['nullable', Money::RULE],
            'downpayment_percent' => 'nullable|integer|min:0|max:100',
            'standard_check_in' => 'required|date_format:H:i',
            'standard_check_out' => 'nullable|date_format:H:i',
            'late_fee' => ['nullable', Money::RULE],
            'hold_hours' => 'required|integer|min:1|max:336',
            'laundry_auto' => 'required|boolean',
            'require_verification' => 'required|boolean',
            'turnover_due_hours' => 'required|integer|min:1|max:72',
            'expense_threshold' => ['nullable', Money::RULE],
            'second_reviewer' => 'required|boolean',
            'require_two_factor' => 'required|boolean',
            'min_nights' => 'nullable|integer|min:1|max:365',
            'max_advance_days' => 'nullable|integer|min:1|max:1825',
            'house_rules' => 'nullable|string|max:4000',
            'cancellation' => 'nullable|string|max:4000',
            'arrival_lead_days' => 'nullable|integer|min:1|max:14',
        ]);

        // An empty list stays "pending": no weekend pricing is a decision the owner has to make explicitly.
        $weekend = empty($d['weekend_days']) ? null : array_values(array_map('intval', $d['weekend_days']));
        $values = [
            'pricing.parking_night_cents' => array_key_exists('parking_rate', $d) ? Money::cents($d['parking_rate']) : Settings::get('pricing.parking_night_cents'),
            'finance.downpayment_percent' => array_key_exists('downpayment_percent', $d) ? (int) $d['downpayment_percent'] : Settings::get('finance.downpayment_percent'),
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
            'finance.expense_approval_threshold_cents' => Money::cents($d['expense_threshold'] ?? null),
            'finance.second_reviewer' => (bool) $d['second_reviewer'],
            'security.require_two_factor' => (bool) $d['require_two_factor'],
            'stay.min_nights' => isset($d['min_nights']) ? (int) $d['min_nights'] : null,
            'stay.max_advance_days' => isset($d['max_advance_days']) ? (int) $d['max_advance_days'] : null,
            'policy.house_rules' => $d['house_rules'] ?? null,
            'policy.cancellation' => $d['cancellation'] ?? null,
            'alerts.arrival_lead_days' => (int) ($d['arrival_lead_days'] ?? 1),
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

    /** Added only by the owner. A rule is never edited or deleted, so past quotes stay explainable: it is ended and replaced. */
    public function storeTax(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:80', 'percent' => ['required', 'regex:/^\d{1,2}(\.\d{1,2})?$/'], 'effective_from' => 'required|date_format:Y-m-d']);
        $rule = TaxRule::create([
            'name' => $d['name'],
            'rate_basis_points' => Money::cents($d['percent']),
            'effective_from' => $d['effective_from'],
            'created_by' => $request->user()->id,
        ]);
        Audit::log('tax.added', $rule, ['name' => $d['name'], 'percent' => $d['percent'], 'from' => $d['effective_from']]);

        return back()->with('success', 'Tax added. It applies to new quotes and repriced stays; confirmed prices do not change.');
    }

    public function endTax(Request $request, TaxRule $rule)
    {
        $d = $request->validate(['effective_to' => 'required|date_format:Y-m-d|after_or_equal:'.$rule->effective_from->toDateString()]);
        $rule->update($d);
        Audit::log('tax.ended', $rule, $d);

        return back()->with('success', "{$rule->name} ends on {$d['effective_to']}.");
    }

    public function updateTemplate(Request $request, MessageTemplate $template)
    {
        $template->update($request->validate(['body' => 'required|string|max:4000']));
        Audit::log('template.updated', $template);

        return back()->with('success', 'Template saved.');
    }

    public function audit(Request $request)
    {
        $f = $request->validate(['action' => 'nullable|string|max:60']);
        $rows = DB::table('activity_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('a.action', 'like', "{$v}%"))
            ->orderByDesc('a.id')->select(['a.id', 'a.created_at', 'u.name as user', 'a.action', 'a.subject_type', 'a.subject_id', 'a.meta', 'a.ip'])
            ->paginate(25)->withQueryString();

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
