<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Pricing\CameraPricing;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RentalRateTier;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Audit;
use App\Support\CameraUnits;
use App\Support\Local;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The Grab Cam rate card and catalog: one row per camera model with three length tiers and its
 * free-day rule. Every change takes effect from a date (today or later), so a rental keeps the
 * price of the rate set in force on its pickup date.
 */
class CameraRateController extends Controller
{
    /** Fixed by the rate card: 1–2 days, 3–5 days, 6 days or more. */
    private const TIERS = [['one_two', 1, 2], ['three_five', 3, 5], ['six_plus', 6, null]];

    public function __construct(private CameraPricing $pricing)
    {
    }

    public function index(Request $request)
    {
        $today = Local::today();
        $canEdit = $request->user()->can('rates.manage');
        $models = $this->models()->get();

        return Inertia::render('Admin/Resource', [
            'title' => 'Camera Rates',
            'subtitle' => 'Per-day rates by rental length, as on the Grab Cam rate card. Changes start on the date you choose (today or later); rentals picked up before then keep their price.',
            'columns' => [
                ['key' => 'model', 'label' => 'Camera'],
                ['key' => 'brand', 'label' => 'Brand'],
                ['key' => 'units', 'label' => 'Units'],
                ['key' => 'one_two', 'label' => '1–2 days', 'type' => 'money'],
                ['key' => 'three_five', 'label' => '3–5 days', 'type' => 'money'],
                ['key' => 'six_plus', 'label' => '6+ days', 'type' => 'money'],
                ['key' => 'free_day', 'label' => 'Free day'],
            ],
            'rows' => $models->map(function (RoomType $type) use ($today, $canEdit) {
                $card = collect($this->pricing->card($type->id, $today))->keyBy('min_days');
                $free = (int) ($card->first()['free_day_from'] ?? 0);
                $row = ['id' => $type->id, 'model' => $type->name, 'brand' => $type->brand, 'units' => $type->units, 'free_day' => $free > 0 ? "{$free}+ days: 1 free" : 'None'];
                foreach (self::TIERS as [$key, $min]) {
                    $row[$key] = $card[$min]['daily_cents'] ?? null;
                }
                $row['_actions'] = $canEdit ? [[
                    'label' => 'Change rates',
                    'url' => "/admin/camera-rates/{$type->id}",
                    'fields' => [...$this->rateFields(), ['name' => 'effective_from', 'label' => 'Starting', 'type' => 'date', 'required' => true]],
                    'data' => [
                        'one_two' => $this->pesos($row['one_two']), 'three_five' => $this->pesos($row['three_five']), 'six_plus' => $this->pesos($row['six_plus']),
                        'free_day_from' => $free, 'effective_from' => $today,
                    ],
                ]] : [];

                return $row;
            }),
            'create' => $canEdit ? ['label' => 'Add camera model', 'url' => '/admin/camera-models', 'fields' => [
                ['name' => 'name', 'label' => 'Model name', 'type' => 'text', 'required' => true, 'help' => 'For example Sony ZV-1. Shown on the public Grab Cam page.'],
                ['name' => 'brand', 'label' => 'Brand', 'type' => 'text', 'required' => true],
                ['name' => 'specs', 'label' => 'Kit or specs', 'type' => 'text', 'help' => 'Optional, for example 15-45mm kit lens.'],
                ...$this->rateFields(),
                ['name' => 'unit_code', 'label' => 'First unit code', 'type' => 'text', 'help' => 'Optional. Blank uses the model name.'],
            ]] : null,
            'secondaryCreate' => $canEdit ? ['label' => 'Free day rule', 'url' => '/admin/camera-rates/free-day', 'fields' => [
                ['name' => 'threshold', 'label' => 'Last day free from this many days', 'type' => 'number', 'required' => true, 'default' => 8, 'help' => 'Applies to every camera model. The rate card says 8 days and above get 1 day free. Enter 0 to turn it off.'],
                ['name' => 'effective_from', 'label' => 'Starting', 'type' => 'date', 'required' => true, 'default' => $today],
            ]] : null,
        ]);
    }

    public function update(Request $request, RoomType $type)
    {
        abort_unless($type->rooms()->cameras()->exists(), 404);
        $data = $request->validate($this->rateRules() + ['effective_from' => $this->startRule()]);

        $this->saveSet($type, $data, $data['effective_from']);
        Audit::log('camera_rates.changed', $type, $data);

        return back()->with('success', "{$type->name} rates saved from {$data['effective_from']}. Rentals picked up before then keep their price.");
    }

    /** Sets the free-day rule for every model from a date, carrying each model's rates on that date into the new set. */
    public function freeDay(Request $request)
    {
        $data = $request->validate(['threshold' => 'required|integer|min:0|max:365', 'effective_from' => $this->startRule()]);

        DB::transaction(function () use ($data) {
            foreach ($this->models()->get() as $type) {
                $card = collect($this->pricing->card($type->id, $data['effective_from']))->keyBy('min_days');
                if ($card->isEmpty()) {
                    continue;
                }
                $rates = [];
                foreach (self::TIERS as [$key, $min]) {
                    $rates[$key] = $this->pesos($card[$min]['daily_cents'] ?? 0);
                }
                $this->saveSet($type, $rates + ['free_day_from' => (int) $data['threshold']], $data['effective_from']);
            }
        });
        Audit::log('camera_rates.free_day', null, $data);

        return back()->with('success', (int) $data['threshold'] > 0
            ? "From {$data['effective_from']}, rentals of {$data['threshold']} days or more get 1 day free."
            : "From {$data['effective_from']}, there is no free day.");
    }

    /** A new model with its rate card and first unit, published on the Grab Cam page. */
    public function storeModel(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('room_types', 'name'), function ($attribute, $value, $fail) {
                if (RoomType::where('slug', Str::slug($value))->exists() || Room::where('slug', Str::slug($value))->exists()) {
                    $fail('A model with this name already exists.');
                }
            }],
            'brand' => 'required|string|max:40',
            'specs' => 'nullable|string|max:120',
            'unit_code' => 'nullable|string|max:30',
        ] + $this->rateRules());

        $type = DB::transaction(function () use ($data) {
            $type = RoomType::create(['name' => $data['name'], 'slug' => Str::slug($data['name']), 'brand' => $data['brand']]);
            $this->saveSet($type, $data, '2000-01-01');
            CameraUnits::create($type, $data['unit_code'] ?? null, $data['specs'] ?? null);

            return $type;
        });
        Audit::log('camera_model.added', $type, ['brand' => $data['brand']]);

        return back()->with('success', "{$type->name} added with one unit. It is now on the Grab Cam page.");
    }

    private function models()
    {
        return RoomType::whereHas('rooms', fn ($r) => $r->cameras())->withCount(['rooms as units' => fn ($r) => $r->cameras()])->orderBy('id');
    }

    private function saveSet(RoomType $type, array $data, string $from): void
    {
        DB::transaction(function () use ($type, $data, $from) {
            foreach (self::TIERS as [$key, $min, $max]) {
                RentalRateTier::updateOrCreate(
                    ['room_type_id' => $type->id, 'min_days' => $min, 'effective_from' => $from],
                    ['max_days' => $max, 'daily_cents' => Money::cents($data[$key]), 'free_day_from' => ((int) ($data['free_day_from'] ?? 0)) ?: null, 'effective_to' => null],
                );
            }
        });
    }

    private function rateFields(): array
    {
        return [
            ['name' => 'one_two', 'label' => '1–2 days (PHP per day)', 'type' => 'money', 'required' => true],
            ['name' => 'three_five', 'label' => '3–5 days (PHP per day)', 'type' => 'money', 'required' => true],
            ['name' => 'six_plus', 'label' => '6+ days (PHP per day)', 'type' => 'money', 'required' => true],
            ['name' => 'free_day_from', 'label' => 'Last day free from (days)', 'type' => 'number', 'required' => true, 'default' => 8, 'help' => '0 for no free day.'],
        ];
    }

    private function rateRules(): array
    {
        return [
            'one_two' => ['required', Money::RULE], 'three_five' => ['required', Money::RULE], 'six_plus' => ['required', Money::RULE],
            'free_day_from' => 'required|integer|min:0|max:365',
        ];
    }

    /** From today on only: a back-dated change would reprice rentals already agreed. */
    private function startRule(): string
    {
        return 'required|date_format:Y-m-d|after_or_equal:'.Local::today();
    }

    private function pesos(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}
