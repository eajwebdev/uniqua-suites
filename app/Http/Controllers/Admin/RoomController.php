<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\RoomConflict;
use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\BookingSource;
use App\Models\RateOverride;
use App\Models\RateRule;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomImage;
use App\Models\RoomSecret;
use App\Models\RoomStatusEvent;
use App\Support\AdminCategory;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;


class RoomController extends Controller
{
    public function __construct(private InventoryService $inventory)
    {
    }

    /**
     * One card per suite or camera, made for a phone or tablet: what it is doing today, its status,
     * and the everyday actions (publish, block dates, change status) without opening the full editor.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $cameras = AdminCategory::isCameras();
        $today = Local::today();
        $rooms = AdminCategory::rooms(Room::query())
            ->with(['property:id,name', 'type:id,name', 'images:id,room_id,path', 'rateRules' => fn ($q) => $q->orderByDesc('effective_from')])
            ->orderBy('property_id')->orderBy('id')->get();
        $states = app(\App\Domain\Rooms\RoomBoard::class)->states($rooms, $today);

        return Inertia::render('Admin/Rooms', [
            'business' => $cameras ? 'cameras' : 'suites',
            'rooms' => $rooms->map(function (Room $r) use ($states, $today) {
                $rule = $r->rateRules->first(fn ($x) => $x->effective_from->toDateString() <= $today);
                $photo = $r->images->first();

                return [
                    'id' => $r->id,
                    'name' => $r->name,
                    'unit' => $r->unit_code,
                    'property' => $r->property->name,
                    'type' => $r->type->name,
                    'photo' => $photo ? '/storage/'.$photo->path : null,
                    'photos' => $r->images->count(),
                    'specs' => $r->specs,
                    'included_guests' => $r->included_guests,
                    'max_guests' => $r->max_guests,
                    'weekday_cents' => $rule ? (int) $rule->weekday_cents : null,
                    'weekend_cents' => $rule ? (int) $rule->weekend_cents : null,
                    'catalog' => $r->catalog_status,
                    'published' => (bool) $r->is_published,
                    'housekeeping' => $r->housekeeping_status,
                    'operational' => $r->operational_status,
                ] + ($states[$r->id] ?? ['state' => 'vacant', 'stay' => null, 'next' => null]);
            }),
            'can' => ['manage' => $user->can('rooms.manage'), 'block' => $user->can('bookings.manage'), 'bookings' => $user->can('bookings.view')],
            'checkOutTime' => \App\Support\Settings::get('pricing.standard_check_out'),
            'create' => $cameras && $user->can('rooms.manage') ? ['label' => 'Add camera unit', 'url' => '/admin/cameras', 'fields' => [
                ['name' => 'room_type_id', 'label' => 'Model', 'type' => 'select', 'required' => true, 'options' => \App\Models\RoomType::whereHas('rooms', fn ($r) => $r->cameras())->orderBy('name')->get()->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all()],
                ['name' => 'unit_code', 'label' => 'Unit code', 'type' => 'text', 'required' => true, 'help' => 'A short label staff use for this physical camera, such as N or O. For a new model with its own rates, use Add camera model on Camera Rates.'],
                ['name' => 'specs', 'label' => 'Kit or specs', 'type' => 'text'],
            ]] : null,
        ]);
    }

    /** The one-tap status change from a room's card. The full editor keeps the history the same way. */
    public function setStatus(Request $request, Room $room)
    {
        $data = $request->validate(['operational_status' => 'required|in:active,maintenance,retired']);
        if ($data['operational_status'] !== $room->operational_status) {
            RoomStatusEvent::create(['room_id' => $room->id, 'dimension' => 'operational', 'from_status' => $room->operational_status, 'to_status' => $data['operational_status'], 'user_id' => $request->user()->id, 'created_at' => now()]);
            $room->update($data);
            Audit::log($room->isCamera() ? 'camera.updated' : 'room.updated', $room, ['operational_status' => $data['operational_status']]);
        }

        return back()->with('success', match ($data['operational_status']) {
            'active' => "{$room->name} is back in service.",
            'maintenance' => "{$room->name} is marked as in maintenance.",
            default => "{$room->name} is retired.",
        });
    }

    public function edit(Request $request, Room $room)
    {
        if ($room->isCamera()) {
            return $this->editCamera($request, $room);
        }
        $room->load(['property:id,name', 'type:id,name', 'images', 'amenities:id', 'rateRules' => fn ($q) => $q->orderByDesc('effective_from'), 'blocks' => fn ($q) => $q->whereNull('released_at')->orderBy('start_date')]);
        $secret = RoomSecret::where('room_id', $room->id)->first();

        return Inertia::render('Admin/RoomEdit', [
            'room' => $room->only(['id', 'name', 'unit_code', 'floor', 'bedrooms', 'ac_count', 'bathrooms', 'has_balcony', 'included_guests', 'max_guests', 'min_nights', 'max_advance_days', 'description', 'inclusions', 'policies', 'operational_notes', 'operational_status', 'parking_eligible', 'active_from', 'active_to', 'catalog_status', 'is_published', 'housekeeping_status']) + [
                'property' => $room->property->name,
                'type' => $room->type->name,
                'amenity_ids' => $room->amenities->pluck('id'),
            ],
            'images' => $room->images->map(fn ($i) => ['id' => $i->id, 'url' => '/storage/'.$i->path, 'alt' => $i->alt_text]),
            'rates' => $room->rateRules->map(fn ($r) => ['id' => $r->id, 'from' => $r->effective_from->toDateString(), 'to' => $r->effective_to?->toDateString(), 'weekday_cents' => (int) $r->weekday_cents, 'weekend_cents' => (int) $r->weekend_cents, 'note' => $r->source_note]),
            'overrides' => RateOverride::where('room_id', $room->id)->orderByDesc('date_from')->with('source:id,name')->get()->map(fn ($o) => ['id' => $o->id, 'from' => $o->date_from->toDateString(), 'to' => $o->date_to->toDateString(), 'cents' => (int) $o->nightly_cents, 'label' => $o->label, 'source' => $o->source?->name]),
            'blocks' => $room->blocks->map(fn ($b) => ['id' => $b->id, 'start' => $b->start_date->toDateString(), 'end' => $b->end_date->toDateString(), 'kind' => $b->kind, 'reason' => $b->reason]),
            'amenities' => Amenity::orderBy('name')->get(['id', 'name']),
            'sources' => BookingSource::orderBy('name')->get(['id', 'name']),
            // Metadata only: the password is fetched through the audited reveal endpoint.
            'secret' => ['exists' => (bool) $secret, 'last_verified_on' => $secret?->last_verified_on?->toDateString()],
            'can' => ['secretsManage' => $request->user()->can('secrets.manage'), 'secretsReveal' => $request->user()->can('secrets.reveal'), 'rates' => $request->user()->can('rates.manage')],
        ]);
    }

    public function update(Request $request, Room $room)
    {
        if ($room->isCamera()) {
            return $this->updateCamera($request, $room);
        }
        $data = $request->validate([
            'floor' => 'nullable|string|max:20',
            'bedrooms' => 'required|integer|min:0|max:10',
            'ac_count' => 'required|integer|min:0|max:10',
            'bathrooms' => 'required|integer|min:0|max:10',
            'has_balcony' => 'required|boolean',
            'included_guests' => 'required|integer|min:1|max:30',
            'max_guests' => 'required|integer|min:1|max:30|gte:included_guests',
            'min_nights' => 'nullable|integer|min:1|max:365',
            'max_advance_days' => 'nullable|integer|min:1|max:1825',
            'description' => 'nullable|string|max:4000',
            'inclusions' => 'nullable|string|max:4000',
            'policies' => 'nullable|string|max:4000',
            'operational_notes' => 'nullable|string|max:4000',
            'operational_status' => 'required|in:active,maintenance,retired',
            'parking_eligible' => 'required|boolean',
            'amenities_text' => 'nullable|string|max:1000',
        ]);

        if ($data['operational_status'] !== $room->operational_status) {
            RoomStatusEvent::create(['room_id' => $room->id, 'dimension' => 'operational', 'from_status' => $room->operational_status, 'to_status' => $data['operational_status'], 'user_id' => $request->user()->id, 'created_at' => now()]);
        }
        $amenities = collect(explode(',', $data['amenities_text'] ?? ''))->map(fn ($n) => trim($n))->filter();
        unset($data['amenities_text']);
        $room->update($data);
        if ($request->has('amenities_text')) {
            $room->amenities()->sync($amenities->map(fn ($n) => Amenity::firstOrCreate(['name' => $n])->id));
        }
        Audit::log('room.updated', $room);

        return back()->with('success', 'Room saved.');
    }

    /** A camera unit: its own facts, photos, rate card (per model) and out-of-service dates. Suite fields stay fixed. */
    private function editCamera(Request $request, Room $room)
    {
        $room->load(['type', 'images', 'blocks' => fn ($q) => $q->whereNull('released_at')->orderBy('start_date')]);

        return Inertia::render('Admin/CameraEdit', [
            'room' => $room->only(['id', 'name', 'unit_code', 'specs', 'description', 'min_nights', 'max_advance_days', 'operational_status', 'operational_notes', 'is_published']) + ['model' => $room->type->name, 'brand' => $room->type->brand],
            'images' => $room->images->map(fn ($i) => ['id' => $i->id, 'url' => '/storage/'.$i->path, 'alt' => $i->alt_text]),
            'rates' => app(\App\Domain\Pricing\CameraPricing::class)->card($room->room_type_id, Local::today()),
            'blocks' => $room->blocks->map(fn ($b) => ['id' => $b->id, 'start' => $b->start_date->toDateString(), 'end' => $b->end_date->toDateString(), 'kind' => $b->kind, 'reason' => $b->reason]),
            'defaultImage' => \App\Http\Controllers\GrabCamController::DEFAULT_IMAGE,
            'can' => ['rates' => $request->user()->can('rates.manage')],
        ]);
    }

    private function updateCamera(Request $request, Room $room)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'unit_code' => ['required', 'string', 'max:30', \Illuminate\Validation\Rule::unique('rooms', 'unit_code')->where('property_id', $room->property_id)->ignore($room->id)],
            'specs' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:4000',
            'min_nights' => 'nullable|integer|min:1|max:365',
            'max_advance_days' => 'nullable|integer|min:1|max:1825',
            'operational_status' => 'required|in:active,maintenance,retired',
            'operational_notes' => 'nullable|string|max:4000',
        ]);

        if ($data['operational_status'] !== $room->operational_status) {
            RoomStatusEvent::create(['room_id' => $room->id, 'dimension' => 'operational', 'from_status' => $room->operational_status, 'to_status' => $data['operational_status'], 'user_id' => $request->user()->id, 'created_at' => now()]);
        }
        $room->update($data);
        Audit::log('camera.updated', $room);

        return back()->with('success', 'Camera saved.');
    }

    /** Another physical unit of a camera model already on the rate card. */
    public function storeCamera(Request $request)
    {
        $grabCam = \App\Models\Property::where('category', \App\Models\Property::CAMERAS)->value('id');
        $data = $request->validate([
            'room_type_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('rooms', 'room_type_id')->where('property_id', $grabCam)],
            'unit_code' => ['required', 'string', 'max:30', \Illuminate\Validation\Rule::unique('rooms', 'unit_code')->where('property_id', $grabCam)],
            'specs' => 'nullable|string|max:120',
        ]);

        $room = \App\Support\CameraUnits::create(\App\Models\RoomType::findOrFail($data['room_type_id']), $data['unit_code'], $data['specs'] ?? null);
        Audit::log('camera.added', $room);

        return back()->with('success', "{$room->name} added. It shares its model's rates and appears on the Grab Cam page.");
    }

    public function approve(Request $request, Room $room)
    {
        $room->update(['catalog_status' => 'approved', 'catalog_approved_by' => $request->user()->id, 'catalog_approved_at' => now()]);
        Audit::log('room.approved', $room);

        return back()->with('success', "{$room->name} approved.");
    }

    public function togglePublish(Room $room)
    {
        if (! $room->is_published && $room->catalog_status !== 'approved') {
            throw new RoomConflict("Approve {$room->name} before publishing it.");
        }
        $room->update(['is_published' => ! $room->is_published]);
        Audit::log($room->is_published ? 'room.published' : 'room.unpublished', $room);

        return back()->with('success', $room->is_published ? "{$room->name} is now public." : "{$room->name} is hidden from the public site.");
    }

    public function addImage(Request $request, Room $room)
    {
        // Several photos under one description; a single 'image' is still accepted.
        $files = $request->file('images') ?? ($request->hasFile('image') ? [$request->file('image')] : null);
        $data = validator(['images' => $files, 'alt_text' => $request->input('alt_text')], [
            'images' => 'required|array|min:1|max:20',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:8192',
            'alt_text' => 'required|string|max:160',
        ], [
            'images.required' => 'Choose at least one photo.',
            'images.max' => 'Upload up to 20 photos at a time.',
        ])->validate();

        $position = $room->images()->count();
        foreach ($data['images'] as $file) {
            $room->images()->create(['path' => $file->store("rooms/{$room->id}", 'public'), 'alt_text' => $data['alt_text'], 'position' => $position++]);
        }
        $count = count($data['images']);

        return back()->with('success', $count === 1 ? 'Photo added.' : "{$count} photos added.");
    }

    public function deleteImage(RoomImage $image)
    {
        Storage::disk('public')->delete($image->path);
        $image->delete();

        return back()->with('success', 'Photo removed.');
    }

    /** A new rule never edits an old one, so past stays keep their dated rate. */
    public function addRate(Request $request, Room $room)
    {
        $data = $request->validate(['effective_from' => 'required|date_format:Y-m-d', 'weekday' => ['required', Money::RULE], 'weekend' => ['required', Money::RULE], 'source_note' => 'nullable|string|max:255']);
        $rule = RateRule::create([
            'room_id' => $room->id,
            'effective_from' => $data['effective_from'],
            'weekday_cents' => Money::cents($data['weekday']),
            'weekend_cents' => Money::cents($data['weekend']),
            'source_note' => $data['source_note'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        Audit::log('rate.added', $rule, ['room' => $room->name]);

        return back()->with('success', 'New rate added. Existing confirmed bookings keep their price.');
    }

    public function addOverride(Request $request, Room $room)
    {
        $data = $request->validate(['date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from', 'nightly' => ['required', Money::RULE], 'label' => 'required|string|max:80', 'booking_source_id' => 'nullable|exists:booking_sources,id']);
        $row = RateOverride::create([
            'room_id' => $room->id,
            'booking_source_id' => $data['booking_source_id'] ?? null,
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'nightly_cents' => Money::cents($data['nightly']),
            'label' => $data['label'],
            'created_by' => $request->user()->id,
        ]);
        Audit::log('rate.override_added', $row, ['room' => $room->name]);

        return back()->with('success', 'Rate override added.');
    }

    public function addBlock(Request $request, Room $room)
    {
        $data = $request->validate(['start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after:start_date', 'kind' => 'required|in:repair,owner_use,technical,third_party', 'reason' => 'required|string|max:255']);

        $this->inventory->withRoomLocks([$room->id], function ($rooms) use ($data, $request) {
            $room = $rooms->first();
            $conflicts = $this->inventory->conflicts($room->id, $data['start_date'], $data['end_date']);
            if ($conflicts->isNotEmpty()) {
                throw new RoomConflict("Cannot block {$room->name}: {$conflicts->first()['label']} occupies {$conflicts->first()['start']} to {$conflicts->first()['end']}.");
            }
            $block = RoomBlock::create($data + ['room_id' => $room->id, 'created_by' => $request->user()->id]);
            Audit::log('room.blocked', $block, ['reason' => $data['reason']]);
        });

        return back()->with('success', 'Room blocked.');
    }

    public function releaseBlock(Request $request, RoomBlock $block)
    {
        // Whoever may close the property decides when it reopens, not everyone who takes bookings.
        abort_if($block->kind === RoomBlock::CLOSURE && ! $request->user()->can('settings.manage'), 403);
        $block->update(['released_at' => now(), 'released_by' => $request->user()->id]);
        Audit::log('room.block_released', $block);

        return back()->with('success', 'Block released.');
    }

    /** Every reveal is logged. Returned as JSON so it never sits in Inertia page props or history. */
    public function secret(Room $room)
    {
        $secret = RoomSecret::where('room_id', $room->id)->first();
        Audit::log('secret.revealed', $room);

        return response()->json($secret ? [
            'network_name' => $secret->network_name,
            'password' => $secret->password,
            'troubleshooting' => $secret->troubleshooting,
            'contact_person' => $secret->contact_person,
            'last_verified_on' => $secret->last_verified_on?->toDateString(),
        ] : null)->header('Cache-Control', 'no-store');
    }

    public function saveSecret(Request $request, Room $room)
    {
        $data = $request->validate(['network_name' => 'nullable|string|max:120', 'password' => 'nullable|string|max:120', 'troubleshooting' => 'nullable|string|max:1000', 'contact_person' => 'nullable|string|max:120', 'last_verified_on' => 'nullable|date_format:Y-m-d']);
        RoomSecret::updateOrCreate(['room_id' => $room->id], $data + ['updated_by' => $request->user()->id]);
        Audit::log('secret.updated', $room);

        return back()->with('success', 'Room access details saved.');
    }
}
