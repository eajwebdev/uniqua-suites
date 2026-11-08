# Grab Cam Camera Rental Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run Grab Cam camera rentals on the existing Uniqa booking engine under a "Cameras" category, with tiered camera pricing, deposit/ID tracking, an admin category switch, and a separate iframe-able public page at `/grabcam`.

**Architecture:** The category lives on `properties.category`. "Grab Cam Rental" is a property, each camera model is a `room_types` row, and each physical camera is a `rooms` row. Bookings, conflicts, receipts and reports are reused unchanged. `PricingService::quote` delegates to a new `CameraPricing` for camera rooms. Suite-only behaviour (turnover, readiness, public suite pages, vacancy search) is skipped or scoped with `Room::suites()` / `Room::cameras()`.

**Tech Stack:** Laravel 12, Inertia + React (TSX), Tailwind, PHPUnit feature tests (`Tests\DomainTestCase`).

**Spec:** `docs/superpowers/specs/2026-11-07-grabcam-camera-rental-design.md`

## Global Constraints

- Money is integer centavos everywhere (`*_cents`).
- `properties.category` is `suites` | `cameras`, default `suites`. Existing rows must be untouched.
- Camera rooms: `bedrooms = ac_count = bathrooms = 0`, `included_guests = max_guests = 1`, `housekeeping_status = 'ready'`, `parking_eligible = false`.
- Rate tiers (pesos/day): G7X III 550/500/450 · G7X II 500/450/400 · EOS M100 450/400/350 · X-T30 II (15-45mm) 600/550/500 · Ricoh GR IIIx (40mm) 650/600/550 · iPhone 17 Pro Max (256GB) 800/700/600 · DJI Pocket 4 (Creator Combo) 550/500/450 · DJI Action 6 (Adventure Combo) 550/500/450 · DJI Nano 400/350/300. Tiers: 1–2 days, 3–5 days, 6+ days.
- Free day: rentals of 8 days or more get the last day at ₱0 (setting `cameras.free_day_threshold`, default 8).
- The deposit is never a booking charge and never changes `total_cents`.
- `/grabcam*` is the only route that may be framed by another site. All other routes keep `frame-ancestors 'self'` and `X-Frame-Options: SAMEORIGIN`.
- `GrabCamSeeder` runs from `DatabaseSeeder` only outside `testing` (same rule as `SuiteCatalogSeeder`). Tests seed it explicitly.
- Do not commit. The working tree already has the owner's uncommitted work, so leave commits to the owner.

## Review Focus

1. A camera unit must never appear on `/`, `/suites/{slug}` or its check-dates endpoint, and a suite must never appear on `/grabcam` → Task 5 tests both directions.
2. Check-out of a camera booking must not create a cleaning task or mark the unit not-ready → Task 3 test.
3. Existing suite pricing must give identical totals after the branch → Task 2 runs `PricingRulesTest` + `QuoteCalculatorTest` unchanged.
4. A model with 2 units shows a day as taken only when both units are booked → Task 5 test.
5. The public "no price" test for suites still holds. Only `/grabcam` shows rates → Task 5 test.

---

### Task 1: Schema, category plumbing, Grab Cam seeder

**Files:**
- Create: `database/migrations/2026_11_07_000001_add_camera_rental_support.php`
- Create: `app/Models/RentalRateTier.php`
- Create: `database/seeders/GrabCamSeeder.php`
- Modify: `app/Models/Property.php`, `app/Models/Room.php`, `app/Models/RoomType.php`, `app/Models/Booking.php` (casts), `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/GrabCamCatalogTest.php`

**Interfaces — Produces:**
- `properties.category` string default `'suites'`. `Property::CAMERAS = 'cameras'`, `Property::SUITES = 'suites'`.
- `rooms.specs` nullable string.
- `rental_rate_tiers(id, room_type_id FK cascade, min_days uint, max_days uint nullable, daily_cents ubigint, effective_from date, effective_to date nullable, timestamps)`.
- `room_types.brand` nullable string, used by the public brand filter.
- `bookings`: `return_time` time nullable, `deposit_cents` ubigint default 0, `deposit_status` string default `'none'`, `deposit_note` string nullable, `id_type` string nullable, `id_status` string default `'none'`.
- `Room::scopeSuites(Builder)`, `Room::scopeCameras(Builder)` (via `whereHas('property', category)`), `Room::isCamera(): bool`.
- `RoomType::rateTiers(): HasMany`.
- `GrabCamSeeder::run()`: idempotent (`updateOrCreate`/`firstOrCreate`). Property slug `grab-cam`, name `Grab Cam Rental`, location_label `Grab Cam Rental`. One room type per model, with slug from the name. Rooms: one per model, with slug `{model-slug}` and unit_code `1`, except G7X Mark II, which gets two rooms: slug `canon-g7x-mark-ii-n` / unit `N` and `canon-g7x-mark-ii-o` / unit `O`. Rooms are `catalog_status = approved`, `is_published = true`. Tiers start `effective_from = '2026-01-01'`. Sets `cameras.free_day_threshold = 8` if unset.

- [ ] **Step 1: Write failing tests** in `GrabCamCatalogTest extends DomainTestCase`. Each test calls `$this->seed(GrabCamSeeder::class)` first.
  - `test_seeder_creates_nine_models_and_ten_units`: `Room::cameras()->count() === 10`, `RoomType::has('rateTiers')->count() === 9`.
  - `test_seeder_is_idempotent`: seeding twice leaves the counts at 10 / 27 tiers.
  - `test_g7x_mark_ii_tiers_match_rate_card`: tiers for "Canon G7X Mark II" ordered by min_days give `[[1,2,50000],[3,5,45000],[6,null,40000]]`.
  - `test_suites_scope_excludes_cameras`: `Room::suites()->count() === 9`, and `Room::where('slug','venice')->first()->isCamera() === false`.
- [ ] **Step 2:** Run `php artisan test --filter=GrabCamCatalogTest`. Expect FAIL (class/table missing).
- [ ] **Step 3:** Implement the migration, models and seeder, and call `GrabCamSeeder` in `DatabaseSeeder` next to `SuiteCatalogSeeder` (outside `testing`).
- [ ] **Step 4:** Run `php artisan test --filter=GrabCamCatalogTest`. Expect PASS. Then `php artisan test` must show no new failures.

### Task 2: Camera pricing

**Files:**
- Create: `app/Domain/Pricing/CameraPricing.php`
- Modify: `app/Domain/Pricing/PricingService.php` (`quote()` early branch)
- Test: `tests/Feature/CameraPricingTest.php`

**Interfaces:**
- Consumes: `RentalRateTier`, `Room::isCamera()`.
- Produces: `CameraPricing::quote(Room $room, string $from, string $to): array`, which returns the same shape as `PricingService::quote` (`lines`, `nights`, `total_cents`, `warnings`). There is one `accommodation` line and one night per rental day. Each line has `description = "{date} · {tier label}"`, where the tier label is `"1–2 day rate"`, `"3–5 day rate"` or `"6+ day rate"`. On rentals ≥ threshold, the last day has `cents = 0` and the label `"Free day (8+ days)"`. Night `source = 'tier:{id}'`. It throws `RoomConflict` when no tier covers the day count. It applies no tax, extra guest, early check-in or parking.
- `PricingService::quote` returns `app(CameraPricing::class)->quote(...)` when `$room->isCamera()`, before the guest checks.

- [ ] **Step 1: Failing tests** (`DomainTestCase`, seed `GrabCamSeeder`). For G7X Mark II unit N from `2031-03-03`, the expected `total_cents` per day count is: 1→50000, 2→100000, 3→135000, 5→225000, 6→240000, 7→280000, 8→280000 (7 billed), 10→360000. Plus `test_free_day_is_the_last_day_at_zero` and `test_camera_quote_has_no_tax_line` (create a TaxRule; assert there is no `tax` line).
- [ ] **Step 2:** Run `php artisan test --filter=CameraPricingTest`. Expect FAIL.
- [ ] **Step 3:** Implement. The tier is picked by total day count `N` (`min_days <= N` and `max_days` null or `>= N`, effective on the start date).
- [ ] **Step 4:** Run `php artisan test --filter="CameraPricingTest|PricingRulesTest|QuoteCalculatorTest"`. Expect all PASS.

### Task 3: Booking flow for cameras, deposit and ID

**Files:**
- Modify: `app/Domain/Bookings/BookingService.php` (`checkIn` readiness check skipped for cameras; `checkOut` turnover skipped for cameras), `app/Domain/Tasks/TurnoverService.php` (`syncWaiting`, `plan`, `onCheckout` no-op/early-return for camera rooms, and `plannedTurnovers` excludes cameras)
- Modify: `app/Http/Controllers/Admin/BookingController.php` (new `rentalSecurity` action; `store` accepts `return_time`; `show` returns the deposit fields + `is_camera`), `routes/web.php`
- Test: `tests/Feature/CameraBookingFlowTest.php`

**Interfaces — Produces:**
- `POST /admin/bookings/{booking}/rental-security` (perm `bookings.manage`), which validates `deposit_cents` int ≥ 0, `deposit_status` in `none,held,returned,deducted`, `deposit_note` ≤ 255, `id_type` ≤ 80, `id_status` in `none,held,returned`. It returns 422 for a non-camera booking and writes `Audit::log('booking.rental_security', ...)`.
- The booking show payload adds `is_camera`, `return_time`, `deposit_cents`, `deposit_status`, `deposit_note`, `id_type` and `id_status`.

- [ ] **Step 1: Failing tests:**
  - `test_camera_booking_runs_pickup_to_return_without_cleaning_task`: inquiry → confirm → checkIn → checkOut on unit N. Then `HousekeepingTask::count()` is unchanged and the room `housekeeping_status === 'ready'`.
  - `test_same_camera_unit_cannot_be_double_booked`: a second confirmed booking overlapping unit N throws `RoomConflict`. Unit O on the same dates succeeds.
  - `test_deposit_and_id_are_recorded_without_changing_total`: POST rental-security `{deposit_cents: 200000, deposit_status: 'held', id_type: "Driver's license", id_status: 'held'}` → 302. The fields are saved and `total_cents` is unchanged.
  - `test_rental_security_rejected_for_suite_booking`: 422 on a venice booking.
- [ ] **Step 2:** Run `php artisan test --filter=CameraBookingFlowTest`. Expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run `php artisan test --filter="CameraBookingFlowTest|CleaningTest|AccessAndFlowTest"`. Expect PASS.

### Task 4: Admin category switch and camera wording

**Files:**
- Create: `app/Http/Controllers/Admin/CategoryController.php` (`POST /admin/category`, which stores `suites|cameras|all` in the session)
- Create: `app/Support/AdminCategory.php` with `current(): string` (session, default `'all'`) and `apply(Builder $roomQuery): Builder`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (share `category` for authed users), `CalendarController`, `BookingController@index`, `RoomController@index` (apply the filter), `VacancySearch` + `HousekeepingController` + `DashboardController` housekeeping counts (`Room::suites()`)
- Modify: `resources/js/layouts/AdminLayout.tsx` (Suites / Cameras / All segmented switch at the top of the sidebar; hide Cleaning and Parking nav when the category is `cameras`), `resources/js/pages/Admin/BookingShow.tsx` (for `is_camera`, show Pickup/Return, Days, and a "Deposit & ID" card that posts to rental-security), `resources/js/components/NewBooking.tsx` (rooms grouped by category; a camera unit hides the guest count and shows a Return time field)
- Test: `tests/Feature/AdminCategoryTest.php`

- [ ] **Step 1: Failing tests:**
  - `test_calendar_shows_only_cameras_when_category_is_cameras`: POST `/admin/category {category: cameras}`. The calendar room names are then all camera units.
  - `test_invalid_category_rejected`: POST `{category: 'x'}` returns a session error.
  - `test_vacancy_search_never_offers_a_camera`.
- [ ] **Step 2:** Run `php artisan test --filter=AdminCategoryTest`. Expect FAIL.
- [ ] **Step 3:** Implement the backend, then the TSX.
- [ ] **Step 4:** Run `php artisan test --filter=AdminCategoryTest` (PASS) and `npm run build` (no TypeScript errors).

### Task 5: Public `/grabcam` page and iframe headers

**Files:**
- Create: `app/Http/Controllers/GrabCamController.php` (`index`, `checkDates`)
- Create: `resources/js/pages/Public/GrabCam.tsx`, `resources/js/layouts/GrabCamLayout.tsx`
- Modify: `routes/web.php` (`GET /grabcam`, `GET /grabcam/{slug}/check-dates`, both throttle:public), `app/Http/Controllers/PublicSiteController.php` (`Room::published()->suites()` in home/show/checkDates), `app/Http/Middleware/SecurityHeaders.php`, `config/uniqa.php` (add `grabcam.messenger_url` env `GRABCAM_MESSENGER_URL` default `https://m.me/61582134053068`, `grabcam.facebook_url` default `https://www.facebook.com/profile.php?id=61582134053068`, `grabcam.frame_ancestors` env `GRABCAM_FRAME_ANCESTORS` default `'self' https:`), `.env.example`
- Test: `tests/Feature/GrabCamPublicTest.php`

**Interfaces:**
- The `/grabcam` props are `models: [{name, slug, brand, specs, images[], rates: [{label, daily_cents}], unavailable: [{from,to}], closed}]`, `free_day_threshold`, and `contact: {messenger_url, facebook_url}`. `unavailable` = days on which **every** published unit of the model is taken, computed as the intersection of each unit's `InventoryService::takenRanges` over 365 days. `slug` is the room type slug.
- `checkDates` checks whether **any** unit of the model is free. It returns `{available, message}` with `Cache-Control: no-store`.
- `SecurityHeaders`: when `$request->is('grabcam', 'grabcam/*')`, the policy uses `frame-ancestors {config}` and `X-Frame-Options` is removed.
- The page reuses `PAGE_WIDTH`, `Eyebrow`, `MonthGrid`, `DateFilter`, `useStayDates` and `datesAreFree` from `@/components/public`. It has Grab Cam branding (navy `#0f2847` / cream), a brand filter, and the CTA "Inquire on Facebook". When the link is `m.me`, the CTA prefills "Hi! I would like to rent {model}, pickup {date}, return {date}."

- [ ] **Step 1: Failing tests:**
  - `test_grabcam_lists_cameras_and_rates_but_no_suites`: the props contain 9 models, none with slug `venice`, and G7X II rates `[50000,45000,40000]`.
  - `test_suite_home_has_no_cameras`: the `/` props `rooms` contain no camera slug, and `/suites/canon-g7x-mark-ii-n` returns 404.
  - `test_model_taken_only_when_all_units_taken`: book unit N for 2031-03-03..05, and the model shows no unavailable range. Then book unit O too, and the range appears.
  - `test_only_grabcam_can_be_framed`: `/grabcam` CSP contains `frame-ancestors 'self' https:` and has no `X-Frame-Options`. `/` keeps `SAMEORIGIN`.
  - `test_grabcam_check_dates_reports_free_unit`.
- [ ] **Step 2:** Run `php artisan test --filter=GrabCamPublicTest`. Expect FAIL.
- [ ] **Step 3:** Implement the backend, then the TSX.
- [ ] **Step 4:** Run `php artisan test` (whole suite, no new failures) and `npm run build`.
- [ ] **Step 5:** Open `http://127.0.0.1:8000/grabcam` in the browser and confirm the page renders.
