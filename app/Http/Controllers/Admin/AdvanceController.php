<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\StaffCashAdvance;
use App\Models\StaffRepayment;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Restricted records of advances and repayments. This is not payroll and computes no statutory amounts. */
class AdvanceController extends Controller
{
    public function index()
    {
        $employees = Employee::withSum('advances', 'amount_cents')->withSum('repayments', 'amount_cents')->orderBy('full_name')->get();
        $options = $employees->where('is_active', true)->map(fn ($e) => ['value' => $e->id, 'label' => $e->full_name])->values()->all();

        Audit::log('advances.viewed');

        return Inertia::render('Admin/Resource', [
            'title' => 'Staff Advances',
            'subtitle' => 'Restricted. Tracks advances and repayments only: not a payroll or statutory calculation. Nothing is imported from the SALARYC.A sheet automatically.',
            'columns' => [
                ['key' => 'name', 'label' => 'Employee'], ['key' => 'position', 'label' => 'Position'],
                ['key' => 'advanced', 'label' => 'Advanced', 'type' => 'money'], ['key' => 'repaid', 'label' => 'Repaid', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'],
            ],
            'rows' => $employees->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->full_name,
                'position' => $e->position,
                'advanced' => (int) $e->advances_sum_amount_cents,
                'repaid' => (int) $e->repayments_sum_amount_cents,
                'balance' => (int) $e->advances_sum_amount_cents - (int) $e->repayments_sum_amount_cents,
                '_actions' => [['label' => 'Record repayment', 'url' => '/admin/advances/repayments', 'data' => ['employee_id' => $e->id], 'fields' => [
                    ['name' => 'repaid_on', 'label' => 'Date', 'type' => 'date', 'required' => true, 'default' => Local::today()],
                    ['name' => 'amount', 'label' => 'Amount (PHP)', 'type' => 'money', 'required' => true],
                    ['name' => 'note', 'label' => 'Note', 'type' => 'text'],
                ]]],
            ]),
            'create' => ['label' => 'Record advance', 'url' => '/admin/advances', 'fields' => [
                ['name' => 'employee_id', 'label' => 'Employee', 'type' => 'select', 'required' => true, 'options' => $options],
                ['name' => 'advance_date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'default' => Local::today()],
                ['name' => 'amount', 'label' => 'Amount (PHP)', 'type' => 'money', 'required' => true],
                ['name' => 'note', 'label' => 'Note', 'type' => 'text'],
            ]],
            'secondaryCreate' => ['label' => 'Add employee', 'url' => '/admin/advances/employees', 'fields' => [
                ['name' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                ['name' => 'position', 'label' => 'Position', 'type' => 'text'],
            ]],
        ]);
    }

    public function storeEmployee(Request $request)
    {
        Employee::create($request->validate(['full_name' => 'required|string|max:120', 'position' => 'nullable|string|max:80']));

        return back()->with('success', 'Employee added.');
    }

    public function storeAdvance(Request $request)
    {
        $data = $request->validate(['employee_id' => 'required|exists:employees,id', 'advance_date' => 'required|date_format:Y-m-d', 'amount' => ['required', Money::RULE], 'note' => 'nullable|string|max:255']);
        $row = StaffCashAdvance::create(['employee_id' => $data['employee_id'], 'advance_date' => $data['advance_date'], 'amount_cents' => Money::cents($data['amount']), 'note' => $data['note'] ?? null, 'approved_by' => $request->user()->id, 'created_by' => $request->user()->id]);
        Audit::log('advance.recorded', $row);

        return back()->with('success', 'Advance recorded.');
    }

    public function storeRepayment(Request $request)
    {
        $data = $request->validate(['employee_id' => 'required|exists:employees,id', 'repaid_on' => 'required|date_format:Y-m-d', 'amount' => ['required', Money::RULE], 'note' => 'nullable|string|max:255']);
        $row = StaffRepayment::create(['employee_id' => $data['employee_id'], 'repaid_on' => $data['repaid_on'], 'amount_cents' => Money::cents($data['amount']), 'note' => $data['note'] ?? null, 'created_by' => $request->user()->id]);
        Audit::log('repayment.recorded', $row);

        return back()->with('success', 'Repayment recorded.');
    }
}
