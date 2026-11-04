<?php

namespace App\Domain\LegacyImport;

use App\Domain\Availability\InventoryService;
use App\Models\ImportAnomaly;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\LegacyRow;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Support\Audit;
use App\Support\Local;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Stages the sales workbook without creating any bookings or receipts. Each monthly tab's
 * room blocks are located from their own header labels, because columns shift between
 * January-June, July-August and September-December.
 *
 * Deliberately not imported: SALARYC.A (restricted, entered manually), WIFI DETAILS.
 */
class WorkbookImporter
{
    public const VERSION = 'sales-2026-v1';

    public const HOLD_KIND = 'workbook';

    private const MONTH_SHEETS = ['JAN. 2026' => 1, 'FEB 2026' => 2, 'MARCH 2026' => 3, 'APRIL 2026' => 4, 'MAY 2026' => 5, 'JUNE 2026' => 6, 'JULY 2026' => 7, 'AUGUST' => 8, 'SEPTEMBER' => 9, 'OCTOBER' => 10, 'NOVEMBER' => 11, 'DECEMBER' => 12];

    // Labels that stay unverified until the owner decides, with what the sheets actually show.
    private const CONFLICTED = [
        '2221' => 'headed STUDIO through June, then replaced by 2520 from July; the brief suggests NEW YORK 2224, which is a 2-bedroom',
        '2510' => 'parking calendar names it BALI; every other sheet uses 2520',
        '14Q' => 'headed LOOP in the sales sheets; the room list places BERLIN 14Q at Mesaverte',
    ];

    private ImportBatch $batch;

    public function __construct(private InventoryService $inventory)
    {
    }

    /** Accepts the clean rows as history, then protects the calendar from the workbook's future stays. */
    public function commit(ImportBatch $batch, ?int $userId): array
    {
        DB::transaction(function () use ($batch, $userId) {
            LegacyRow::where('import_batch_id', $batch->id)->where('review_status', 'pending')->whereNotNull('room_id')
                ->where('record_type', '!=', 'no_booking')
                ->whereDoesntHave('anomalies', fn ($q) => $q->whereIn('category', ['unknown_or_conflicting_room', 'historical_unlisted_room', 'future_or_unconfirmed_record']))
                ->update(['review_status' => 'approved', 'reviewed_by' => $userId]);
            $batch->update(['status' => 'committed', 'committed_by' => $userId, 'committed_at' => now()]);
            Audit::log('import.committed', $batch);
        });

        return $this->holdFutureDates($batch);
    }

    /**
     * Blocks the rooms for future stays written in the workbook, so guests and staff see them as taken.
     * A block, not a booking: the sheet has no check-out, payment proof or agreed price to build one from,
     * so staff still enter the real booking. Safe to repeat, because a night already taken is skipped.
     */
    public function holdFutureDates(ImportBatch $batch): array
    {
        $today = Local::today();
        $rows = LegacyRow::where('import_batch_id', $batch->id)->where('record_type', 'daily_sale')
            ->where('review_status', '!=', 'rejected')->where('record_date', '>=', $today)
            ->whereNotNull('guest_name')->where('guest_name', '!=', '');
        $result = ['held_nights' => 0, 'already_taken' => 0, 'unmatched_nights' => (clone $rows)->whereNull('room_id')->count()];

        foreach ((clone $rows)->whereNotNull('room_id')->orderBy('room_id')->orderBy('record_date')->get()->groupBy('room_id') as $roomId => $nights) {
            $this->inventory->withRoomLocks([$roomId], function () use ($batch, $roomId, $nights, &$result) {
                $open = null;
                $close = function () use (&$open, $batch, $roomId) {
                    if ($open) {
                        RoomBlock::create([
                            'room_id' => $roomId,
                            'start_date' => $open['start'],
                            'end_date' => $open['end'],
                            'kind' => self::HOLD_KIND,
                            'reason' => mb_substr("Sales workbook: {$open['guest']}", 0, 255),
                            'external_uid' => "workbook:{$batch->id}:{$roomId}:{$open['start']}",
                        ]);
                    }
                    $open = null;
                };

                foreach ($nights as $row) {
                    $night = $row->record_date->toDateString();
                    $next = $row->record_date->toImmutable()->addDay()->toDateString();
                    if ($this->inventory->conflicts($roomId, $night, $next)->isNotEmpty()) {
                        $result['already_taken']++;
                        $close();
                        continue;
                    }
                    $result['held_nights']++;
                    if ($open && $open['end'] === $night && mb_strtolower($open['guest']) === mb_strtolower(trim($row->guest_name))) {
                        $open['end'] = $next;
                    } else {
                        $close();
                        $open = ['start' => $night, 'end' => $next, 'guest' => trim($row->guest_name)];
                    }
                }
                $close();
            });
        }

        Audit::log('import.future_dates_held', $batch, $result);

        return $result;
    }

    public function releaseHolds(ImportBatch $batch): void
    {
        RoomBlock::where('kind', self::HOLD_KIND)->where('external_uid', 'like', "workbook:{$batch->id}:%")->whereNull('released_at')->update(['released_at' => now()]);
    }

    public function stage(string $absolutePath, string $originalName, string $storedPath, ?int $userId = null): ImportBatch
    {
        @ini_set('memory_limit', '1G');
        // A web request only: from the console this limit would also cut off whatever runs after the import.
        if (! app()->runningInConsole()) {
            @set_time_limit(300);
        }

        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $book = $reader->load($absolutePath);

        return DB::transaction(function () use ($book, $absolutePath, $originalName, $storedPath, $userId) {
            $this->batch = ImportBatch::create([
                'file_name' => $originalName,
                'stored_path' => $storedPath,
                'checksum' => hash_file('sha256', $absolutePath),
                'status' => 'staged',
                'transformation_version' => self::VERSION,
                'imported_by' => $userId,
            ]);

            $counts = ['sheets' => [], 'blocks' => 0];
            foreach ($book->getWorksheetIterator() as $sheet) {
                $title = trim($sheet->getTitle());
                if (isset(self::MONTH_SHEETS[$title])) {
                    $counts['blocks'] += $this->stageMonth($sheet, $title, self::MONTH_SHEETS[$title]);
                    $counts['sheets'][] = $title;
                } elseif (in_array($title, ['CLEANING-NEW', 'LAUNDRY'], true)) {
                    $this->stageMatrix($sheet, $title, $title === 'LAUNDRY' ? 'laundry' : 'cleaning');
                    $counts['sheets'][] = $title;
                } elseif ($title === 'DAMAGE REQUEST') {
                    $this->stageDamage($sheet, $title);
                    $counts['sheets'][] = $title;
                } elseif ($title === 'UTILITIES') {
                    $this->stageUtilities($sheet, $title);
                    $counts['sheets'][] = $title;
                }
            }

            $this->flagRepeatedGuests();
            $this->batch->update(['summary' => $counts + $this->summarize()]);
            Audit::log('import.staged', $this->batch, ['checksum' => $this->batch->checksum]);

            return $this->batch;
        });
    }

    /** Reads a formula's cached value rather than trusting a recalculation. */
    private function value(Worksheet $sheet, int $col, int $row): mixed
    {
        $coord = Coordinate::stringFromColumnIndex($col).$row;
        if (! $sheet->cellExists($coord)) {
            return null;
        }
        $cell = $sheet->getCell($coord);
        $raw = $cell->getValue();

        if (is_string($raw) && str_starts_with($raw, '=')) {
            return $cell->getOldCalculatedValue();
        }

        return $raw;
    }

    private function stageMonth(Worksheet $sheet, string $title, int $month): int
    {
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $maxRow = min($sheet->getHighestDataRow(), 400);

        // The header row is whichever row holds the NAME labels.
        $headerRow = null;
        for ($r = 1; $r <= 60 && ! $headerRow; $r++) {
            for ($c = 1; $c <= $maxCol; $c++) {
                if ($this->text($sheet, $c, $r) === 'NAME') {
                    $headerRow = $r;
                    break;
                }
            }
        }
        if (! $headerRow) {
            ImportAnomaly::create(['import_batch_id' => $this->batch->id, 'category' => 'ambiguous_date_or_room', 'detail' => "{$title}: no NAME header row found; sheet skipped."]);

            return 0;
        }

        $nameCols = [];
        $dateCol = null;
        for ($c = 1; $c <= $maxCol; $c++) {
            $label = $this->text($sheet, $c, $headerRow);
            if ($label === 'NAME') {
                $nameCols[] = $c;
            }
            if ($dateCol === null && str_contains($label, 'DATE')) {
                $dateCol = $c;
            }
        }

        $blocks = 0;
        foreach ($nameCols as $i => $nameCol) {
            $nextName = $nameCols[$i + 1] ?? $maxCol + 3;
            $map = [];
            for ($c = $nameCol; $c < $nextName - 1 || ($c < $nextName && ! isset($nameCols[$i + 1])); $c++) {
                $label = $this->text($sheet, $c, $headerRow);
                if ($label !== '' && ! str_contains($label, 'DATE')) {
                    $map[$label] = $map[$label] ?? $c;
                }
            }

            // A block carries its own date column when the label sits just before NAME; otherwise it shares the first.
            $ownDate = null;
            for ($c = max(1, $nameCol - 2); $c < $nameCol; $c++) {
                if (str_contains($this->text($sheet, $c, $headerRow), 'DATE')) {
                    $ownDate = $c;
                }
            }
            $blockDate = $ownDate ?? $dateCol ?? 1;

            $roomLabel = $this->blockHeading($sheet, $nameCol, $nextName);
            $blocks++;
            $this->stageBlock($sheet, $title, $month, $roomLabel, $headerRow, $maxRow, $blockDate, $nameCol, $map);
        }

        return $blocks;
    }

    private function blockHeading(Worksheet $sheet, int $from, int $to): string
    {
        for ($c = max(1, $from - 2); $c < $to; $c++) {
            $v = $this->text($sheet, $c, 1);
            if ($v !== '' && (str_contains($v, 'LOOP') || str_contains($v, 'MESAVERTE'))) {
                return $v;
            }
        }

        return 'UNKNOWN';
    }

    private function stageBlock(Worksheet $sheet, string $title, int $month, string $roomLabel, int $headerRow, int $maxRow, int $dateCol, int $nameCol, array $map): void
    {
        $find = function (array $needles) use ($map): ?int {
            foreach ($map as $label => $col) {
                foreach ($needles as $n) {
                    if (str_contains($label, $n)) {
                        return $col;
                    }
                }
            }

            return null;
        };
        $viaCol = $find(['BOOKING VIA']);
        $rateCol = $find(['NIGHT RATE']);
        $extraCountCol = $find(['EXTRA PAX']);
        $paxTotalCol = $find(['PAX TOTAL AMOUNT']);
        $payCol = $find(['PAYMENT SOURCE']);
        $totalCol = $find(['TOTAL AMOUNT']);
        if ($totalCol === $paxTotalCol) {
            $totalCol = null;
            foreach ($map as $label => $col) {
                if ($label === 'TOTAL AMOUNT' || $label === 'TOTAL') {
                    $totalCol = $col;
                }
            }
        }

        [$roomId, $conflict] = $this->resolveRoom($roomLabel);
        $this->flagCapacity($title, $roomLabel, $roomId);
        $computed = 0;

        for ($r = $headerRow + 1; $r <= $maxRow; $r++) {
            $marker = $this->text($sheet, $nameCol, $r);
            if ($marker === 'STANDARD RATE AMOUNT') {
                $this->compareBlockTotal($sheet, $title, $roomLabel, $r, $nameCol, $computed);
                break;
            }

            $date = $this->toDate($this->value($sheet, $dateCol, $r));
            if (! $date) {
                continue;
            }

            $name = trim((string) $this->value($sheet, $nameCol, $r));
            $via = $viaCol ? trim((string) $this->value($sheet, $viaCol, $r)) : '';
            $rate = $rateCol ? $this->toCents($this->value($sheet, $rateCol, $r)) : null;
            $extra = $paxTotalCol ? $this->toCents($this->value($sheet, $paxTotalCol, $r)) : null;
            $pay = $payCol ? trim((string) $this->value($sheet, $payCol, $r)) : '';
            $total = $totalCol ? $this->toCents($this->value($sheet, $totalCol, $r)) : null;

            // A date with no entry is not evidence of vacancy or revenue.
            if ($name === '' && $via === '' && ! $rate && ! $extra) {
                continue;
            }

            $isNoBooking = strtoupper($name) === 'NO BOOKING' || strtoupper($via) === 'NO BOOKING';
            $raw = [];
            foreach ($map as $label => $col) {
                $raw[$label] = $this->value($sheet, $col, $r);
            }
            $raw['_date_serial'] = $this->value($sheet, $dateCol, $r);

            $row = $this->upsert([
                'source_key' => "sales2026|{$title}|{$roomLabel}|{$r}",
                'record_type' => $isNoBooking ? 'no_booking' : 'daily_sale',
                'sheet' => $title,
                'row_number' => $r,
                'cell_range' => Coordinate::stringFromColumnIndex($nameCol).$r,
                'raw' => $raw,
                'room_label' => $roomLabel,
                'room_id' => $roomId,
                'record_date' => $date->toDateString(),
                'guest_name' => $isNoBooking ? null : ($name ?: null),
                'source_label' => $via ?: null,
                'payment_label' => $pay ?: null,
                // NO BOOKING is an occupancy state and never carries money.
                'amount_cents' => $isNoBooking ? null : $rate,
                'extra_cents' => $isNoBooking ? null : $extra,
                'confidence' => $roomId && ! $conflict ? ($name ? 70 : 40) : 20,
            ]);
            if (! $row) {
                continue;
            }

            if (! $isNoBooking) {
                $computed += (int) $rate + (int) $extra;
            }

            if ($conflict) {
                $this->anomaly($row, 'unknown_or_conflicting_room', "{$roomLabel}: {$conflict}");
            }
            if (! $roomId) {
                $this->anomaly($row, 'historical_unlisted_room', "{$roomLabel} does not match a current room.");
            }
            if ($date->month !== $month) {
                $this->anomaly($row, 'ambiguous_date_or_room', "Date {$date->toDateString()} sits on the {$title} sheet.");
            }
            if ($date->isFuture() && ! $isNoBooking) {
                $this->anomaly($row, 'future_or_unconfirmed_record', 'Entry is dated in the future; not a realized collection.');
            }
            if (! $isNoBooking) {
                $this->anomaly($row, 'missing_checkout_date', 'Sheet holds one row per night; no checkout date is recorded.');
                if (in_array(strtoupper($pay), ['GCASH', 'CASH', 'BANK'], true) && strtoupper($via) === 'AIRBNB') {
                    $this->anomaly($row, 'booking_origin_vs_payment_source_conflict', 'Airbnb origin with direct payment label: review whether this is the extra-guest payment only.');
                }
                if ($rate || $extra) {
                    $this->anomaly($row, 'unverified_payout_or_cash_receipt', 'Sheet amount has no proof of receipt.');
                } else {
                    $this->anomaly($row, 'legacy_or_placeholder_value', 'Entry has a name or channel but no amount.');
                }
                if ($total !== null && $total !== (int) $rate + (int) $extra) {
                    $this->anomaly($row, 'formula_vs_manual_total_mismatch', 'Row total differs from rate plus extra-guest amount.');
                }
            }
        }
    }

    private function compareBlockTotal(Worksheet $sheet, string $title, string $roomLabel, int $row, int $nameCol, int $computed): void
    {
        $maxCol = min($nameCol + 11, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
        $legacy = null;
        for ($c = $nameCol; $c <= $maxCol; $c++) {
            if ($this->text($sheet, $c, $row) === 'TOTAL AMOUNT') {
                $legacy = $this->toCents($this->value($sheet, $c + 1, $row));
                break;
            }
        }
        $summary = $this->batch->summary ?? [];
        $summary['block_totals'][] = ['sheet' => $title, 'room' => $roomLabel, 'legacy_cents' => $legacy, 'imported_cents' => $computed, 'difference_cents' => $legacy === null ? null : $computed - $legacy];
        $this->batch->summary = $summary;

        if ($legacy !== null && $legacy !== $computed) {
            ImportAnomaly::create([
                'import_batch_id' => $this->batch->id,
                'category' => 'formula_vs_manual_total_mismatch',
                'detail' => sprintf('%s / %s: sheet total %.2f, rows sum to %.2f.', $title, $roomLabel, $legacy / 100, $computed / 100),
            ]);
        }
    }

    /** Daily room-by-column cost matrices. Empty and zero cells are skipped. */
    private function stageMatrix(Worksheet $sheet, string $title, string $type): void
    {
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $labels = [];
        for ($c = 2; $c <= $maxCol; $c++) {
            $label = $this->text($sheet, $c, 1);
            if (str_starts_with($label, 'ROOM') || str_contains($label, 'T3')) {
                $labels[$c] = $label;
            }
        }
        $maxRow = min($sheet->getHighestDataRow(), 1000);

        for ($r = 3; $r <= $maxRow; $r++) {
            $date = $this->toDate($this->value($sheet, 1, $r));
            if (! $date) {
                continue;
            }
            foreach ($labels as $c => $label) {
                $cell = $this->value($sheet, $c, $r);
                $cents = $this->toCents($cell);
                $isPlaceholder = is_string($cell) && trim($cell) !== '';
                if (! $cents && ! $isPlaceholder) {
                    continue;
                }
                [$roomId, $conflict] = $this->resolveRoom($label);
                $row = $this->upsert([
                    'source_key' => "sales2026|{$title}|{$label}|{$r}",
                    'record_type' => $type,
                    'sheet' => $title,
                    'row_number' => $r,
                    'cell_range' => Coordinate::stringFromColumnIndex($c).$r,
                    'raw' => ['value' => $this->value($sheet, $c, $r), '_date_serial' => $this->value($sheet, 1, $r)],
                    'room_label' => $label,
                    'room_id' => $roomId,
                    'record_date' => $date->toDateString(),
                    'amount_cents' => $cents,
                    'confidence' => $roomId && ! $conflict ? 60 : 20,
                ]);
                if ($row && $conflict) {
                    $this->anomaly($row, 'unknown_or_conflicting_room', "{$label}: {$conflict}");
                }
                if ($row && $isPlaceholder) {
                    $this->anomaly($row, 'legacy_or_placeholder_value', 'Text marker in a cost column; no amount recorded.');
                }
            }
        }
    }

    /** Month headings followed by ELECTRICITY and WATER rows, one column per room. */
    private function stageUtilities(Worksheet $sheet, string $title): void
    {
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $labels = [];
        for ($c = 2; $c <= $maxCol; $c++) {
            $label = $this->text($sheet, $c, 1);
            if ($label !== '') {
                $labels[$c] = $label;
            }
        }

        $month = null;
        for ($r = 3; $r <= $sheet->getHighestDataRow(); $r++) {
            $a = $this->text($sheet, 1, $r);
            if (! in_array($a, ['ELECTRICITY', 'WATER'], true)) {
                $month = $a !== '' ? $a : $month;
                continue;
            }
            foreach ($labels as $c => $label) {
                $cents = $this->toCents($this->value($sheet, $c, $r));
                if (! $cents) {
                    continue;
                }
                [$roomId] = $this->resolveRoom($label);
                $this->upsert([
                    'source_key' => "sales2026|{$title}|{$label}|{$r}",
                    'record_type' => 'utility',
                    'sheet' => $title,
                    'row_number' => $r,
                    'cell_range' => Coordinate::stringFromColumnIndex($c).$r,
                    'raw' => ['month' => $month, 'kind' => $a, 'value' => $this->value($sheet, $c, $r)],
                    'room_label' => $label,
                    'room_id' => $roomId,
                    'source_label' => $a,
                    'amount_cents' => $cents,
                    'confidence' => 40,
                ]);
            }
        }
    }

    /** A heading such as "(7 PAX MAX)" that disagrees with the current room list. */
    private function flagCapacity(string $title, string $roomLabel, ?int $roomId): void
    {
        if (! $roomId || ! preg_match('/\((\d+)\s*PAX MAX\)/', $roomLabel, $m)) {
            return;
        }
        $current = Room::find($roomId)?->max_guests;
        if ($current !== null && (int) $m[1] !== $current) {
            ImportAnomaly::create([
                'import_batch_id' => $this->batch->id,
                'category' => 'capacity_or_rate_conflict',
                'detail' => "{$title} / {$roomLabel}: heading says {$m[1]} max, current room list says {$current}.",
            ]);
        }
    }

    private function stageDamage(Worksheet $sheet, string $title): void
    {
        $maxRow = $sheet->getHighestDataRow();
        for ($r = 3; $r <= $maxRow; $r++) {
            $unit = trim((string) $this->value($sheet, 2, $r));
            $item = trim((string) $this->value($sheet, 4, $r));
            if ($unit === '' && $item === '') {
                continue;
            }
            [$roomId] = $this->resolveRoom('ROOM '.$unit);
            $row = $this->upsert([
                'source_key' => "sales2026|{$title}|{$r}",
                'record_type' => 'damage',
                'sheet' => $title,
                'row_number' => $r,
                'cell_range' => "A{$r}:H{$r}",
                'raw' => ['unit' => $unit, 'item' => $item, 'amount' => $this->value($sheet, 5, $r), 'resolution' => $this->value($sheet, 7, $r), 'remarks' => $this->value($sheet, 8, $r)],
                'room_label' => $unit,
                'room_id' => $roomId,
                'record_date' => $this->toDate($this->value($sheet, 1, $r))?->toDateString(),
                'guest_name' => trim((string) $this->value($sheet, 3, $r)) ?: null,
                'amount_cents' => $this->toCents($this->value($sheet, 5, $r)),
                'confidence' => 30,
            ]);
            if ($row) {
                $this->anomaly($row, 'unmatched_damage_or_cleaning_entry', 'Not linked to a stay; needs review before any incident is created.');
            }
        }
    }

    /** Same guest and room on adjacent dates is one probable stay, not several bookings. */
    private function flagRepeatedGuests(): void
    {
        $rows = LegacyRow::where('import_batch_id', $this->batch->id)->where('record_type', 'daily_sale')->whereNotNull('guest_name')
            ->orderBy('room_label')->orderBy('record_date')->get(['id', 'room_label', 'guest_name', 'record_date']);

        $previous = null;
        foreach ($rows as $row) {
            if ($previous && $previous->room_label === $row->room_label
                && mb_strtolower($previous->guest_name) === mb_strtolower($row->guest_name)
                && $previous->record_date->diffInDays($row->record_date) == 1) {
                $this->anomaly($row, 'possible_multi_day_duplicate', 'Same guest on the previous night: likely one stay.');
            }
            $previous = $row;
        }
    }

    /** Re-running the same workbook updates nothing: stable keys already exist. */
    private function upsert(array $data): ?LegacyRow
    {
        $existing = LegacyRow::where('source_key', $data['source_key'])->whereHas('batch', fn ($q) => $q->where('status', '!=', 'rolled_back'))->first();
        if ($existing) {
            $summary = $this->batch->summary ?? [];
            $summary['duplicates'] = ($summary['duplicates'] ?? 0) + 1;
            $this->batch->summary = $summary;

            return null;
        }
        LegacyRow::where('source_key', $data['source_key'])->delete();

        return LegacyRow::create($data + ['import_batch_id' => $this->batch->id]);
    }

    private function anomaly(LegacyRow $row, string $category, string $detail): void
    {
        ImportAnomaly::create(['import_batch_id' => $this->batch->id, 'legacy_row_id' => $row->id, 'category' => $category, 'detail' => $detail]);
    }

    /** @return array{0: ?int, 1: ?string} room id and a conflict note */
    private function resolveRoom(string $label): array
    {
        $key = strtoupper(trim($label));
        ImportMapping::firstOrCreate(['label' => $key]);
        $mapping = ImportMapping::where('label', $key)->first();
        if ($mapping->status === 'confirmed' && $mapping->room_id) {
            return [$mapping->room_id, null];
        }
        if ($mapping->status === 'rejected') {
            return [null, 'Owner rejected this mapping.'];
        }

        foreach (self::CONFLICTED as $code => $evidence) {
            if (str_contains($key, $code)) {
                return [null, "Unverified identifier {$code}: {$evidence}. Awaiting owner decision."];
            }
        }

        foreach (Room::all() as $room) {
            $code = strtoupper($room->unit_code);
            $short = str_replace('T3, ', '', $code);
            if (str_contains($key, $code) || str_contains($key, $short)) {
                return [$room->id, null];
            }
        }

        return [null, null];
    }

    private function summarize(): array
    {
        return [
            'rows' => LegacyRow::where('import_batch_id', $this->batch->id)->count(),
            'by_type' => LegacyRow::where('import_batch_id', $this->batch->id)->selectRaw('record_type, COUNT(*) n')->groupBy('record_type')->pluck('n', 'record_type')->all(),
            'anomalies' => ImportAnomaly::where('import_batch_id', $this->batch->id)->selectRaw('category, COUNT(*) n')->groupBy('category')->pluck('n', 'category')->all(),
            'duplicates' => $this->batch->summary['duplicates'] ?? 0,
            'block_totals' => $this->batch->summary['block_totals'] ?? [],
        ];
    }

    private function text(Worksheet $sheet, int $col, int $row): string
    {
        $v = $this->value($sheet, $col, $row);

        return is_string($v) ? strtoupper(trim($v)) : '';
    }

    private function toDate(mixed $v): ?CarbonImmutable
    {
        if ($v instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($v)->startOfDay();
        }
        // Excel serials for plausible 2020-2035 dates only; zero placeholders are not dates.
        if (is_numeric($v) && $v > 43800 && $v < 49700) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $v))->startOfDay();
        }
        // CLEANING-NEW switches to typed strings such as "Sept 1 2026" from September.
        if (is_string($v) && preg_match('/^([A-Za-z]{3})[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})$/', trim($v), $m)) {
            $parsed = CarbonImmutable::createFromFormat('!M j Y', ucfirst(strtolower($m[1]))." {$m[2]} {$m[3]}");

            return $parsed ?: null;
        }

        return null;
    }

    private function toCents(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round(((float) $v) * 100) : null;
    }
}
