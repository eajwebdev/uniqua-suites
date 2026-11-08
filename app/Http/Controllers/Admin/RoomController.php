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

    public function index(Request $request)
    {
        $user = $request->user();
        $rooms = AdminCategory::rooms(Room::query())->with(['property:id,name', 'type:id,name', 'rateRules' => fn ($q) => $q->orderByDesc('effective_from')])->orderBy('id')->get();

        if (AdminCategory::isCameras()) {
            return Inertia::render('Admin/Resource', [
                'title' => 'Cameras',
                'subtitle' => 'Each physical camera is a unit. Open one to add photos or take it out of service. Rates are per model, under Camera Rates.',
                'headerLink' => ['label' => 'Camera Rates', 'href' => '/admin/camera-rates'],
                'create' => $user->can('rooms.manage') ? ['label' => 'Add camera unit', 'url' => '/admin/cameras', 'fields' => [
                    ['name' => 'room_type_id', 'label' => 'Model', 'type' => 'select', 'required' => true, 'options' => \App\Models\RoomType::whereHas('rooms', fn ($r) => $r->cameras())->orderBy('name')->get()->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all()],
                    ['name' => 'unit_code', 'label' => 'Unit code', 'type' => 'text', 'required' => true, 'help' => 'A short label staff use for this physical camera, such as N or O.'],
                    ['name' => 'specs', 'label' => 'Kit or specs', 'type' => 'text'],
                ], 'help' => 'For a new model with its own rates, use Add camera model on Camera Rates.'] : null,
                'columns' => [
                    ['key' => 'name', 'label' => 'Camera', 'type' => 'link'],
                    ['key' => 'unit', 'label' => 'Unit'],
                    ['key' => 'type', 'label' => 'Model'],
                    ['key' => 'specs', 'label' => 'Kit'],
                    ['key' => 'photos', 'label' => 'Photos'],
                    ['key' => 'published', 'label' => 'Public', 'type' => 'status'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                ],
                'rows' => $rooms->loadCount('images')->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $user->can('rooms.manage') ? ['label' => $r->name, 'href' => "/admin/rooms/{$r->id}/edit"] : ['label' => $r->name],
                    'unit' => $r->unit_code,
                    'type' => $r->type->name,
                    'specs' => $r->specs,
                    'photos' => $r->images_count ?: 'Default image',
                    'published' => $r->is_published ? 'published' : 'unpublished',
                    'status' => $r->operational_status,
                    '_actions' => $user->can('rooms.manage') ? [['label' => $r->is_published ? 'Unpublish' : 'Publish', 'url' => "/admin/rooms/{$r->id}/publish"]] : [],
                ]),
            ]);
        }

        return Inertia::render('Admin/Resource', [
            'title' => 'Rooms & Properties',
            'subtitle' => 'Proposed from the room list and suite flyers. Check each room, then approve it before publishing.',
            'columns' => [
                ['key' => 'name', 'label' => 'Suite', 'type' => 'link'],
                ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'property', 'label' => 'Location'],
                ['key' => 'type', 'label' => 'Type'],
                ['key' => 'capacity', 'label' => 'Incl. / max'],
                ['key' => 'weekday', 'label' => 'Weekday', 'type' => 'money'],
                ['key' => 'weekend', 'label' => 'Weekend', 'type' => 'money'],
                ['key' => 'catalog', 'label' => 'Catalog', 'type' => 'status'],
                ['key' => 'published', 'label' => 'Public', 'type' => 'status'],
                ['key' => 'housekeeping', 'label' => 'Housekeeping', 'type' => 'status'],
            ],
            'rows' => $rooms->map(function ($r) use ($user) {
                $rule = $r->rateRules->first(fn ($x) => $x->effective_from->toDateString() <= Local::today());
                $actions = [];
                if ($user->can('rooms.manage')) {
                    if ($r->catalog_status !== 'approved') {
                        $actions[] = ['label' => 'Approve', 'url' => "/admin/rooms/{$r->id}/approve", 'confirm' => "Approve {$r->name}'s details and rates as current?"];
                    }
                    $actions[] = ['label' => $r->is_published ? 'Unpublish' : 'Publish', 'url' => "/admin/rooms/{$r->id}/publish"];
                }

                return [
                    'id' => $r->id,
                    'name' => $user->can('rooms.manage') ? ['label' => $r->name, 'href' => "/admin/rooms/{$r->id}/edit"] : ['label' => $r->name],
                    'unit' => $r->unit_code,
                    'property' => $r->property->name,
                    'type' => $r->type->name,
                    'capacity' => "{$r->included_guests} / {$r->max_guests}",
                    'weekday' => $rule ? (int) $rule->weekday_cents : null,
                    'weekend' => $rule ? (int) $rule->weekend_cents : null,
                    'catalog' => $r->catalog_status,
                    'published' => $r->is_published ? 'published' : 'unpublished',
                    'housekeeping' => $r->housekeeping_status,
                    '_actions' => $actions,
                ];
            }),
        ]);
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
        $data = $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192', 'alt_text' => 'required|string|max:160']);
        $path = $request->file('image')->store("rooms/{$room->id}", 'public');
        $room->images()->create(['path' => $path, 'alt_text' => $data['alt_text'], 'position' => $room->images()->count()]);

        return back()->with('success', 'Photo added.');
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
