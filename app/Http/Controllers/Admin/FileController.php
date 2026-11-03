<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\IncidentEvidence;
use App\Models\Receipt;
use App\Models\TaskAttachment;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Private evidence is only served here, per record type and permission, and every view is logged. */
class FileController extends Controller
{
    public function show(Request $request, string $kind, int $id)
    {
        [$model, $column, $permission] = match ($kind) {
            'receipt' => [Receipt::class, 'evidence_path', 'payments.view'],
            'expense' => [Expense::class, 'proof_path', 'expenses.view'],
            'incident' => [IncidentEvidence::class, 'path', 'incidents.view'],
            'task' => [TaskAttachment::class, 'path', 'housekeeping.manage'],
            default => abort(404),
        };
        abort_unless($request->user()->can($permission), 403);

        $record = $model::findOrFail($id);
        $path = $record->{$column};
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        Audit::log('file.viewed', $record, ['kind' => $kind]);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
        ]);
    }
}
