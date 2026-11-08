# Grab Cam camera rental inside Uniqa — design

Date: 2026-11-07 · Status: approved by owner 2026-11-07

## Why

The owner runs two rental businesses with the same daily routine: a customer picks free days,
staff confirm in Messenger, payment is logged with a reference number, and the status goes
Pending → Paid. Grab Cam currently tracks this in a Google Sheet (one block per camera unit,
one row per day of the month: name/IG, time, balance, payment, reference no., status, remarks).
Running both in one system avoids double bookkeeping and gives cameras the same calendar,
conflict checks, payments and reports the suites already have.

## Goals

1. **Categories.** Every rentable unit belongs to a category: **Suites** or **Cameras**. Admin
   pages (calendar, bookings, dashboard, reports, payments) get a category switch/filter.
2. **Same booking flow as suites.** For cameras, too: pick a unit → pick dates → hold → confirm →
   record receipt (reference no.) → mark paid → release on return. Conflict checks prevent
   double-booking a unit.
3. **Separate public camera page at `/grabcam`.** It has the same structure and look as the suites
   landing page, uses Grab Cam branding, and can be embedded in an iframe on another site. Each
   camera shows its photo, its specs and the days already taken. "Book / Inquire" opens the Grab Cam Facebook
   page (https://www.facebook.com/profile.php?id=61582134053068) carrying the chosen camera and dates, just as the
   suites page hands off to Uniqa's Messenger.
4. **Camera pricing** follows the Grab Cam rate card (see below), so staff quotes and bookings
   compute it automatically.

## Non-goals

- No online payment or self-checkout on either public page. Booking stays staff-confirmed.
- No changes to how suites work today.
- No separate login or admin for Grab Cam. It is one admin, with categories.
- Importing the old Google Sheet is a later step, not part of this build.

## Anti-goals (would count as failure even if it "works")

- Suite-only features leaking into cameras: housekeeping turnover tasks, parking, bedrooms/AC/guest
  counts, check-in/out wording on a camera booking.
- A camera booking shown on the Uniqa suites site, or a suite on the Grab Cam page.
- The `/grabcam` page breaking the strict security headers of the rest of the site. Only that page
  may be framed.

## How it fits the current code (my call — the owner can override)

**Reuse the booking engine and add a category, instead of building a second system.**
The current schema already works per unit and per date (`rooms`, `bookings`, `booking_rooms`,
`booking_nights`, `room_night_claims`, receipts). A camera unit for a date range is the same
problem as a suite for a date range.

- **Category lives on the property.** Add `properties.category` (`suites` | `cameras`) and create
  a property called "Grab Cam Rental". Every room under it is a camera unit. Rooms inherit the
  category through the property, so existing queries can filter with one join.
- **Camera model = room type; camera unit = room.** For example, room type "Canon G7X Mark II" has
  units with `unit_code` "N M2" and "O M2", which matches the sheet's per-unit blocks. (My guess: N/O
  are two physical units of the same model. Confirm.)
- **Suite-only columns stay as they are.** Camera units store `bedrooms/ac_count/bathrooms = 0` and
  `included_guests = max_guests = 1`, so the existing capacity checks keep passing untouched, and the UI
  hides these fields for cameras. Cameras get an optional `specs` text field (for example "15-45mm kit
  lens", "Creator Combo", "256GB").
- **Booking days.** `check_in` = pickup day, `check_out` = return day (exclusive, like nights).
  The sheet's TIME column maps to `requested_arrival_time` (shown as "Pickup time"). "R-1PM"
  maps to a new nullable `bookings.return_time` (shown as "Return time"). My guess is that "R-" means return.
- **Wording switches by category** in the UI: Guest → Customer, Check-in/out → Pickup/Return,
  Nights → Days, Suite → Camera.
- **Turned off for cameras:** housekeeping turnover tasks (`TurnoverService`), parking, guest
  counts, and the suite stay rules (`StayRules`), replaced by a 1-day minimum.

## Deposit and ID (owner: yes, both are taken)

Each camera booking records: `deposit_cents`, `deposit_status` (`none` | `held` | `returned` |
`deducted`), `deposit_note`, `id_type` (free text such as "Driver's license"), and `id_status`
(`none` | `held` | `returned`). The deposit is **not** a charge and does not change the booking
total or balance. It is money held and handed back. Staff update these on the booking page.
Damage that is taken out of a deposit is entered as an ordinary booking charge, as it is today.

## Camera pricing (from the rate card)

Tiered per-day rate per camera model, picked by the total rental length:

| Model | 1–2 days | 3–5 days | 6+ days |
|---|---|---|---|
| Canon G7X Mark III | 550 | 500 | 450 |
| Canon G7X Mark II | 500 | 450 | 400 |
| Canon EOS M100 | 450 | 400 | 350 |
| Fujifilm X-T30 II (15–45mm) | 600 | 550 | 500 |
| Ricoh GR IIIx (40mm) | 650 | 600 | 550 |
| iPhone 17 Pro Max (256GB) | 800 | 700 | 600 |
| DJI Pocket 4 (Creator Combo) | 550 | 500 | 450 |
| DJI Action 6 (Adventure Combo) | 550 | 500 | 450 |
| DJI Nano | 400 | 350 | 300 |

Rule: **8 days or more → 1 day free.** My reading: a rental of N ≥ 8 days is charged for N−1 days
at the 6+ rate. Confirm.

- A new table `rental_rate_tiers` (room_type_id, min_days, max_days nullable, daily_cents,
  effective_from/to) plus a setting `cameras.free_day_threshold = 8`. Storing tiers per model means
  every unit of a model shares one price.
- `PricingService` branches on category. Cameras use the tier lookup and the free-day line item, with
  no weekday/weekend split, no parking and no early check-in. The output is the same line-item and
  snapshot structure, so receipts, balances and reports keep working unchanged.
- Admin can edit the tiers on the camera model's edit page.

## Public `/grabcam` page

- New routes: `GET /grabcam` and `GET /grabcam/{slug}/check-dates`, built on the `PublicSiteController`
  pattern but reading only published camera-category units.
- Layout mirrors `Public/Home.tsx` (hero, filters, cards, date picker showing taken days,
  policies). Grab Cam logo and colours. Filters cover model and brand (Canon, Fujifilm, Ricoh, Apple, DJI).
- When several units of one model exist, the card shows the **model** and a day counts as taken only
  when **every** unit is booked. Staff assign the specific unit when they make the booking.
- CTA: "Inquire on Facebook". It opens the Grab Cam page/Messenger with a prefilled message
  (model + dates). The URL is configurable via `GRABCAM_MESSENGER_URL`.
- **Iframe:** `SecurityHeaders` adds a per-route exception for `/grabcam*` only. That exception sets
  `frame-ancestors` to an allow-list from `GRABCAM_FRAME_ANCESTORS`, and drops `X-Frame-Options` on
  that route. Every other route keeps `frame-ancestors 'self'`.
- **Price display (needs the owner's answer):** the suites site hides prices by owner decision, but
  the Grab Cam rate card is already public. The default plan **shows the rate tiers** on `/grabcam`.

## Admin changes

- A top-level **category switcher** (Suites / Cameras) in `AdminLayout`. It is remembered per user and
  filters Calendar, Bookings, Dashboard, Quotes, Reports and Payments. An "All" view is available
  for finance.
- The Rooms list becomes "Units" with a category tab. Camera units are edited with the camera fields.
- New Booking / Quote form: picking a unit sets the category, and the form hides suite-only fields.
- The sidebar hides Housekeeping and Parking while the Cameras category is selected.
- Roles and permissions are unchanged (the same `bookings.*` and `payments.*` permissions cover both).

## Data and seeding

- Migration: `properties.category` (default `suites`, so existing data is untouched), nullable
  suite columns, `rooms.specs`, `bookings.return_time`, `rental_rate_tiers`.
- A seeder for the Grab Cam property, the 9 camera models with their rate tiers, and the units seen in the
  sheet (G7X M2 ×2: N, O; EOS M100; X-T30 II; and others to be confirmed). Unit counts per model
  come from the owner.
- Tests: tier pricing (1, 2, 3, 5, 6, 7, 8, 10 days; the free day at 8+); a camera conflict blocks
  double booking; camera units never appear on `/`; suites never appear on `/grabcam`; `/grabcam`
  sends the frame-ancestors exception and `/` does not; existing suite tests still pass.

## Build order

1. Schema and category plumbing, plus seed (no UI change for suites).
2. Camera pricing tiers and tests.
3. Admin category switcher, labels, and hiding suite-only modules.
4. `/grabcam` public page and iframe headers.
5. (Later) Import the Grab Cam Google Sheet.

## Owner answers (2026-11-07)

- The rate card lists every camera and device. A seeder creates all 9 models with their rates:
  one unit each, and two for the Canon G7X Mark II (unit codes "N" and "O", as in the sheet).
  More units can be added in admin.
- Deposit and ID: yes, both are taken and tracked (section above).
- The rest were left as my calls: "R-" = return time; prices are shown on `/grabcam`; the 8+ day
  rental has 1 free day. The iframe allow-list comes from `GRABCAM_FRAME_ANCESTORS` (default:
  any https site, which can be tightened once the embedding site is known).

## v1 scope trims (my call)

- The admin category switch filters the Calendar, Reservations and Rooms. Dashboard, Payments and
  Reports show both businesses combined in v1.
- The Quotes "find vacant units" search stays suites-only. Camera bookings are made from New Booking.
