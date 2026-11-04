<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\RoomConflict;
use App\Domain\LegacyImport\WorkbookImporter;
use App\Http\Controllers\Controller;
use App\Models\ImportAnomaly;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\LegacyRow;
use App\Models\Room;
use App\Support\Audit;
use App\Models\RoomBlock;
use App\Support\Local;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Staged rows stay in legacy_rows as "Unverified Historical". Committing never creates
 * bookings, receipts or expenses, so provisional totals cannot mix with live reporting.
 */
class ImportController extends Controller
{
    public function index()
    {
        $rows = ImportBatch::withCount(['rows', 'anomalies'])->orderByDesc('id')->get();

        return Inertia::render('Admin/Resource', [
            'title' => 'Import & Data Quality',
            'subtitle' => 'Upload the sales workbook to stage it for review. Past rows appear in the Sales recording report, and future stays are held on the calendar when you commit. No bookings or receipts are created. The original file is kept privately with its checksum.',
            'columns' => [
                ['key' => 'file', 'label' => 'File', 'type' => 'link'], ['key' => 'status', 'label' => 'Status', 'type' => 'status'], ['key' => 'rows_count', 'label' => 'Rows'],
                ['key' => 'anomalies_count', 'label' => 'Flags'], ['key' => 'checksum', 'label' => 'Checksum'], ['key' => 'created_at', 'label' => 'Uploaded', 'type' => 'datetime'],
            ],
            'rows' => $rows->map(fn ($b) => [
                'id' => $b->id,
                'file' => ['label' => $b->file_name, 'href' => "/admin/imports/{$b->id}"],
                'status' => $b->status,
                'rows_count' => $b->rows_count,
                'anomalies_count' => $b->anomalies_count,
                'checksum' => substr($b->checksum, 0, 12).'…',
                'created_at' => $b->created_at,
            ]),
            'create' => ['label' => 'Upload workbook', 'url' => '/admin/imports', 'fields' => [
                ['name' => 'workbook', 'label' => 'UNIQA SALES RECORDING (.xlsx)', 'type' => 'file', 'required' => true, 'accept' => '.xlsx'],
            ]],
        ]);
    }

    public function store(Request $request, WorkbookImporter $importer)
    {
        $file = $request->validate(['workbook' => 'required|file|mimes:xlsx|max:51200'])['workbook'];
        $path = $file->store('imports', 'local');
        $batch = $importer->stage(Storage::disk('local')->path($path), $file->getClientOriginalName(), $path, $request->user()->id);

        return redirect("/admin/imports/{$batch->id}")->with('success', 'Workbook staged. Review the flags before committing.');
    }

    public function show(Request $request, ImportBatch $batch)
    {
        $f = $request->validate(['category' => 'nullable|string|max:60', 'type' => 'nullable|string|max:30', 'sheet' => 'nullable|string|max:40']);

        $rows = LegacyRow::where('import_batch_id', $batch->id)->with('room:id,name')
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('record_type', $v))
            ->when($f['sheet'] ?? null, fn ($q, $v) => $q->where('sheet', $v))
            ->when($f['category'] ?? null, fn ($q, $v) => $q->whereHas('anomalies', fn ($a) => $a->where('category', $v)))
            ->withCount('anomalies')->orderBy('id')->paginate(50)->withQueryString();

        $mappingLabels = LegacyRow::where('import_batch_id', $batch->id)->whereNotNull('room_label')->distinct()->pluck('room_label')->map(fn ($l) => strtoupper(trim($l)));

        return Inertia::render('Admin/ImportShow', [
            'batch' => $batch->only(['id', 'file_name', 'status', 'checksum', 'transformation_version', 'created_at', 'committed_at']),
            'summary' => $batch->summary,
            'future' => [
                'nights' => $this->futureRows($batch)->count(),
                'unmatched' => $this->futureRows($batch)->whereNull('room_id')->count(),
                'held' => RoomBlock::where('kind', WorkbookImporter::HOLD_KIND)->where('external_uid', 'like', "workbook:{$batch->id}:%")->whereNull('released_at')->where('end_date', '>', Local::today())->count(),
            ],
            'counts' => [
                'pending' => LegacyRow::where('import_batch_id', $batch->id)->where('review_status', 'pending')->count(),
                'approved' => LegacyRow::where('import_batch_id', $batch->id)->where('review_status', 'approved')->count(),
                'rejected' => LegacyRow::where('import_batch_id', $batch->id)->where('review_status', 'rejected')->count(),
                'unlinked' => LegacyRow::where('import_batch_id', $batch->id)->whereNull('room_id')->count(),
            ],
            'categories' => ImportAnomaly::where('import_batch_id', $batch->id)->selectRaw('category, COUNT(*) as n')->groupBy('category')->orderByDesc('n')->get(),
            'blockAnomalies' => ImportAnomaly::where('import_batch_id', $batch->id)->whereNull('legacy_row_id')->orderBy('id')->limit(100)->get(['id', 'category', 'detail']),
            'monthly' => LegacyRow::where('import_batch_id', $batch->id)->where('record_type', 'daily_sale')
                ->selectRaw("sheet, COALESCE(SUM(amount_cents),0) as rate_cents, COALESCE(SUM(extra_cents),0) as extra_cents, COUNT(*) as n")
                ->groupBy('sheet')->orderByRaw('MIN(id)')->get(),
            'mappings' => ImportMapping::whereIn('label', $mappingLabels)->with('room:id,name')->orderBy('label')->get()->map(fn ($m) => ['id' => $m->id, 'label' => $m->label, 'status' => $m->status, 'room' => $m->room?->name, 'room_id' => $m->room_id]),
            'rooms' => Room::orderBy('id')->get(['id', 'name', 'unit_code']),
            'rows' => $rows->through(fn ($r) => [
                'id' => $r->id,
                'sheet' => $r->sheet,
                'cell' => $r->cell_range,
                'type' => $r->record_type,
                'room_label' => $r->room_label,
                'room' => $r->room?->name,
                'date' => $r->record_date?->toDateString(),
                'guest' => $r->guest_name,
                'source' => $r->source_label,
                'payment' => $r->payment_label,
                'amount_cents' => $r->amount_cents === null ? null : (int) $r->amount_cents,
                'extra_cents' => $r->extra_cents === null ? null : (int) $r->extra_cents,
                'confidence' => $r->confidence,
                'flags' => $r->anomalies_count,
                'review' => $r->review_status,
            ]),
            'filters' => $f,
            'sheets' => LegacyRow::where('import_batch_id', $batch->id)->distinct()->pluck('sheet'),
        ]);
    }

    private function futureRows(ImportBatch $batch)
    {
        return LegacyRow::where('import_batch_id', $batch->id)->where('record_type', 'daily_sale')->where('record_date', '>=', Local::today())->whereNotNull('guest_name')->where('guest_name', '!=', '');
    }

    /** Marks reviewed rows as accepted history. Still provisional and still outside live reporting. */
    public function commit(Request $request, ImportBatch $batch, WorkbookImporter $importer)
    {
        if ($batch->status !== 'staged') {
            throw new RoomConflict("This batch is already {$batch->status}.");
        }
        $held = $importer->commit($batch, $request->user()->id);

        return back()->with('success', "Clean rows accepted as Unverified Historical. {$this->heldMessage($held)}");
    }

    public function holdFutureDates(ImportBatch $batch, WorkbookImporter $importer)
    {
        if ($batch->status === 'rolled_back') {
            throw new RoomConflict('This import was rolled back.');
        }

        return back()->with('success', $this->heldMessage($importer->holdFutureDates($batch)));
    }

    private function heldMessage(array $held): string
    {
        $message = "{$held['held_nights']} future night(s) from the workbook are now held on the calendar, and {$held['already_taken']} were already taken.";

        return $held['unmatched_nights'] ? "{$message} {$held['unmatched_nights']} are NOT held because their room label is not matched yet: match it, then press Hold future dates." : $message;
    }

    /** Removes the batch's rows so a corrected workbook can be staged again. Keeps the batch record and file. */
    public function rollback(ImportBatch $batch, WorkbookImporter $importer)
    {
        DB::transaction(function () use ($batch, $importer) {
            $importer->releaseHolds($batch);
            ImportAnomaly::where('import_batch_id', $batch->id)->delete();
            LegacyRow::where('import_batch_id', $batch->id)->delete();
            $batch->update(['status' => 'rolled_back']);
            Audit::log('import.rolled_back', $batch);
        });

        return back()->with('success', 'Import rolled back, and its holds on the calendar were released. Bookings were never affected.');
    }

    public function decideMapping(Request $request, ImportMapping $mapping)
    {
        $data = $request->validate(['decision' => 'required|in:confirmed,rejected', 'room_id' => 'required_if:decision,confirmed|nullable|exists:rooms,id']);

        DB::transaction(function () use ($data, $mapping, $request) {
            $mapping->update(['status' => $data['decision'], 'room_id' => $data['decision'] === 'confirmed' ? $data['room_id'] : null, 'decided_by' => $request->user()->id, 'decided_at' => now()]);

            $rowIds = LegacyRow::whereRaw('UPPER(TRIM(room_label)) = ?', [$mapping->label])->pluck('id');
            LegacyRow::whereIn('id', $rowIds)->update(['room_id' => $mapping->room_id]);
            if ($data['decision'] === 'confirmed') {
                ImportAnomaly::whereIn('legacy_row_id', $rowIds)->whereIn('category', ['unknown_or_conflicting_room', 'historical_unlisted_room'])->update(['status' => 'resolved', 'resolved_by' => $request->user()->id]);
            }
            Audit::log('import.mapping_decided', $mapping, $data);
        });

        return back()->with('success', 'Mapping saved for all rows using this label.');
    }

    public function reviewRow(Request $request, LegacyRow $row)
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected']);
        $row->update(['review_status' => $data['decision'], 'reviewed_by' => $request->user()->id]);
        ImportAnomaly::where('legacy_row_id', $row->id)->update(['status' => $data['decision'] === 'approved' ? 'resolved' : 'dismissed', 'resolved_by' => $request->user()->id]);
        Audit::log('import.row_reviewed', $row, $data);

        return back();
    }
}
