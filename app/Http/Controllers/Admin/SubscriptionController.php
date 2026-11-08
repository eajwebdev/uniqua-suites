<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionBill;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use App\Support\Subscription;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The system's monthly subscription. The Administrator sets it and marks bills paid; the Owner can see it. */
class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        Subscription::ensureBill();

        return Inertia::render('Admin/Subscription', [
            'subscription' => Subscription::summary(),
            'canManage' => Subscription::canManage($request->user()),
            'today' => Local::today(),
            'bills' => SubscriptionBill::with('recorder:id,name')->orderByDesc('period_start')->get()->map(fn (SubscriptionBill $b) => [
                'id' => $b->id,
                'period_start' => $b->period_start->toDateString(),
                'period_end' => $b->period_end->toDateString(),
                'due_on' => $b->due_on->toDateString(),
                'amount_cents' => $b->amount_cents,
                'status' => $b->status,
                'paid_on' => $b->paid_on?->toDateString(),
                'paid_amount_cents' => $b->paid_amount_cents,
                'note' => $b->note,
                'recorded_by' => $b->recorder?->name,
            ]),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'monthly_fee' => ['required', Money::RULE],
            'trial_start' => 'nullable|date_format:Y-m-d',
            'trial_end' => 'nullable|date_format:Y-m-d|after_or_equal:trial_start|required_with:trial_start',
            'start' => 'nullable|date_format:Y-m-d',
            'end' => 'nullable|date_format:Y-m-d|after_or_equal:start',
            'grace_days' => 'required|integer|min:0|max:60',
        ], [
            'trial_end.required_with' => 'Enter when the trial ends.',
            'trial_end.after_or_equal' => 'The trial must end on or after it starts.',
            'end.after_or_equal' => 'The paid-through date cannot be before the subscription starts.',
        ]);

        $before = Subscription::status();
        $paidTo = SubscriptionBill::where('status', SubscriptionBill::PAID)->max('period_end');
        $paidTo = $paidTo ? substr((string) $paidTo, 0, 10) : null;
        if ($paidTo && (empty($data['end']) || $data['end'] < $paidTo)) {
            $data['end'] = $paidTo; // what has been paid stays paid
        }
        Subscription::save([
            'monthly_fee_cents' => Money::cents($data['monthly_fee']),
            'trial_start' => $data['trial_start'] ?? null,
            'trial_end' => $data['trial_end'] ?? null,
            'start' => $data['start'] ?? null,
            'end' => $data['end'] ?? null,
            'grace_days' => (int) $data['grace_days'],
        ]);

        // Until something is paid, the bills follow the dates and fee as they are now set.
        // After that, paid bills never change; only the open bill takes the new fee.
        if (! SubscriptionBill::where('status', SubscriptionBill::PAID)->exists()) {
            SubscriptionBill::where('status', SubscriptionBill::UNPAID)->delete();
        } else {
            Subscription::openBill()?->update(['amount_cents' => Money::cents($data['monthly_fee'])]);
        }
        Subscription::ensureBill();
        Subscription::clearReminders(); // old dates, old reminders
        Audit::log('subscription.updated', null, ['before' => $before, 'after' => Subscription::status(), 'paid_through' => Subscription::paidThrough()]);

        return back()->with('success', 'Subscription saved. Status: '.Subscription::label(Subscription::status()).'.');
    }

    /** Marking a bill paid moves the paid-through date to its end, unlocks at once, and creates the next month's bill. */
    public function pay(Request $request, SubscriptionBill $bill)
    {
        abort_if($bill->status === SubscriptionBill::PAID, 422, 'This bill is already paid.');
        $data = $request->validate([
            'paid_on' => 'required|date_format:Y-m-d',
            'amount' => ['required', Money::RULE],
            'note' => 'nullable|string|max:255',
        ]);

        $next = Subscription::markPaid($bill, $data['paid_on'], Money::cents($data['amount']), $data['note'] ?? null, $request->user()->id);
        Audit::log('subscription.bill_paid', $bill, ['paid_through' => Subscription::paidThrough(), 'next_bill' => $next?->period_start?->toDateString()]);

        return back()->with('success', 'Bill paid. The system is paid through '.Subscription::date(Subscription::paidThrough()).'.'
            .($next ? ' Next bill: '.Subscription::date($next->period_start->toDateString()).' to '.Subscription::date($next->period_end->toDateString()).', due '.Subscription::date($next->due_on->toDateString()).'.' : ''));
    }

    /** What everyone but the Administrator sees once the grace period has passed. */
    public function locked(Request $request)
    {
        if (! Subscription::isLocked() || Subscription::canManage($request->user())) {
            return redirect('/admin');
        }

        return Inertia::render('Admin/Locked', [
            'subscription' => array_merge(Subscription::summary(), ['fee_cents' => Subscription::openBill()?->amount_cents ?? Subscription::settings()['monthly_fee_cents']]),
            'contact' => config('uniqa.vendor'),
        ]);
    }
}
