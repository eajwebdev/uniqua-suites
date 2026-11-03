# MASTER BUILD PROMPT — UNIQA SUITES
## Premium Booking, Sales, and Property Operations Management System
**Version:** 2.1 — Excel-grounded requirements; MySQL + XAMPP / PHP 8.2 | **Date:** 4 November 2026

> **How to use:** Provide this entire prompt, the two original Excel workbooks, and the official UNIQA brand assets to the AI development agent. The workbooks contain real guest, staff, contact, and Wi-Fi information: keep them private; use redacted/synthetic data for demonstrations and tests. Build the software, not merely a prototype or marketing mockup.

## 0. Role and non-negotiable mission

Act as a senior product manager, hospitality operations consultant, UX/UI designer, Laravel architect, React engineer, database engineer, data-migration specialist, QA lead, and security reviewer. Design and implement a **production-capable, single-property hospitality operations system for UNIQA SUITES in Cagayan de Oro, Philippines**. The primary purpose is to replace disconnected, manually maintained Excel sheets with one reliable source of truth that reduces double entry, avoids booking conflicts, improves collections visibility, automatically prepares cleaning and laundry work, and lets the owner understand income, expenses, profitability, and staff performance.

**Two integrated experiences:**
1. **Public premium website:** customers browse real units, check indicative availability, see transparent estimates, and inquire through the officially configured Facebook Messenger/contact channels.
2. **Private operations application:** owner, admin, reception/booking staff, finance staff, housekeeping staff, and authorized managers manage bookings, money, tasks, rooms, parking, incidents, inventory, and reporting.

**Hard scope boundary:** This is **not a self-service confirmed online booking platform**. The public website must not allow a guest to create a confirmed booking, perform checkout, or pay through a public payment gateway. Authorized staff must approve/confirm bookings and verify off-platform payments. Public availability is indicative where external channels have not been synchronized. Do not invent integration credentials, API availability, the property's live Messenger URL, tax rules, missing rates, or booking records.

**Primary operating principle:** Each operational event should be recorded once and drive all appropriate downstream changes: e.g., confirming a reservation blocks physical room inventory; recording payment updates the guest balance and collection reports; checkout triggers cleaning, laundry, and readiness checks; actual expenses feed profitability; damages create an incident and optional charge; date/rate changes generate revised quotations and history.

## 1. Evidence, precedence, and known limits

Analyze all original source files before implementation. Do not treat the Excel records as database-ready or perfectly consistent.

### Supplied sources
- `UNIQA_ROOM LIST.xlsx` — sheets `ROOM DETAILS`, `WIFI DETAILS`, `PARKING DETAILS`, and `2026 Parking Calendar`. The room list is the proposed **current** master reference for named units and published base rates, subject to owner approval.
- `UNIQA SALES RECORDING 2026.xlsx` — `JAN. 2026`, `FEB 2026`, `MARCH 2026`, `APRIL 2026`, `MAY 2026`, `JUNE 2026`, `JULY 2026`, `AUGUST`, `SEPTEMBER`, `OCTOBER`, `NOVEMBER`, `DECEMBER`, plus `CLEANING-NEW`, `LAUNDRY`, `UTILITIES`, `DAMAGE REQUEST`, `RENOVATION`, `SALARYC.A`, `SAMPLE`, and empty `REFILLS` / `REFILLS INVENTORY`. These reveal historical workflows, field names, legacy prices, and potential operational records. Some December data may be future-dated or incomplete. Do not label tentative/future records as realized collections.
- Official brand assets: supplied UNIQA SUITES monogram, wide wordmark, and management-system artwork. Use the source logos without alteration or re-creation.
- The original functional prompt is the starting specification. Retain its public-inquiry-only rule, chosen stack, luxury visual direction, Messenger quotation functionality, 2:00 PM standard check-in, and default ₱200/hour early-check-in example.

### Source-precedence and approval rules
1. Use **named accommodation plus physical room/unit code and property** as a controlled identity, not name alone or room number alone.
2. The `ROOM DETAILS` sheet is the **initial proposed current catalog**, while month-by-month sales layouts are **historical records**, not overrides of present-day room rates, capacities, or physical unit identities.
3. Historical pricing must be preserved with effective dates, rather than overwritten with current rates. Historical manual totals and platform payouts may differ from public price lists; do not back-calculate or rewrite them without explanation.
4. If any workbook conflicts with another, create an **owner review / mapping conflict** rather than automatically choosing one. Do not fabricate missing booking check-out dates, proof of payment, official references, guest contacts, or expense categories.
5. Excel formulas and their cached values are useful evidence but not guaranteed correct or authoritative. Recalculate only with validated business definitions.
6. Do not expose private Wi-Fi passwords, phone numbers, guest names, salaries, or payment proofs in the public site, screenshots, seed files, logs, or sample output.
7. Distinguish **source-derived facts**, **system defaults that the owner must approve**, and **future/optional functionality**. Provide a visible assumptions-and-decisions register in the admin setup flow.

## 2. Initial room catalog — create all nine records

Create a seeding/import review screen populated with these **proposed** records from `UNIQA_ROOM LIST.xlsx` > `ROOM DETAILS`. Rates are **PHP per night** as expressed in the source's weekday/weekend columns, but the exact definition of “weekend” must remain owner-configurable. “Included” and “max” are separate values.

| Suite | Unit / Floor | Property / location | Type | Bedrooms | AC | Bathrooms | Balcony | Included guests | Maximum guests | Weekday base | Weekend base |
|---|---|---|---|---:|---:|---:|---|---:|---:|---:|---:|
| RIO | T3, 6H | Mesaverte Residences | Studio | 0 | 1 | 1 | No | 2 | 2 | ₱1,600 | ₱1,800 |
| VENICE | 1410 | The Loop, Limketkai | Studio | 0 | 1 | 1 | No | 3 | 4 | ₱1,700 | ₱1,900 |
| BALI | 2520 | The Loop, Limketkai | Studio | 0 | 1 | 1 | Yes | 4 | 4 | ₱2,000 | ₱2,200 |
| TOKYO | 1725 | The Loop, Limketkai | 1 bedroom | 1 | 1 | 1 | Yes | 4 | 5 | ₱2,200 | ₱2,400 |
| ROMA | 1916 | The Loop, Limketkai | 1 bedroom | 1 | 1 | 1 | Yes | 4 | 4 | ₱2,100 | ₱2,300 |
| MILAN | 0918 | The Loop, Limketkai | 1 bedroom | 1 | 2 | 1 | Yes | 4 | 6 | ₱2,500 | ₱2,700 |
| BERLIN | T3, 14Q | Mesaverte Residences | 1 bedroom | 1 | 2 | 1 | Yes | 4 | 6 | ₱2,500 | ₱2,700 |
| PARIS | 0524 | The Loop, Limketkai | 2 bedrooms | 2 | 2 | 2 | Yes | 6 | 8 | ₱3,500 | ₱4,000 |
| NEW YORK | 2224 | The Loop, Limketkai | 2 bedrooms | 2 | 3 | 2 | Yes | 6 | 10 | ₱4,000 | ₱4,500 |

**Important catalog/data conflicts to flag during import:**
- Older sales sheets refer to `2221`, while the current room list and cleaning register use `2224` for New York. Treat these as **unverified separate identifiers** until the owner confirms whether they are the same unit.
- A parking calendar heading references `2510`, while the master room list and cleaning/laundry records reference `2520` for Bali. Flag; never silently merge.
- Historical capacity/rate descriptions differ: Tokyo 1725 appears as up to 7 guests in sales sheets but has max 5 in the current room list; Venice 1410 appears historically as up to 5 but has max 4 in the current list; RIO has a historic description up to 4 while the current list says max 2. Preserve original historical data, use current rules only after approval, and allow explicit dated exceptions with authorization.
- Some historical headings place `14Q` under “LOOP”, while the current room list locates Berlin at Mesaverte. Require owner reconciliation.
- The 2026 sales sheets do not consistently list all nine current named rooms. Absence of a room from a historical sheet must **not** mean its revenue was zero, it did not exist, or it was vacant.

For each room also support: immutable room ID, public display name, location/building, floor, room/unit number (as string with leading zero), type, capacity, inclusions, property-specific policies, amenities, photos/gallery, current availability state, housekeeping state, operational notes, active-from/to dates, facilities, time restrictions, parking eligibility, and external platform listing IDs. Enable owner-controlled publish/unpublish and temporarily retired/maintenance states.

## 3. Why the current workbook is difficult — workflows to replace

Build a clear migration from spreadsheet workflows to normalized application records:

| Excel source or pattern | Existing manual workflow | New system responsibility |
|---|---|---|
| 2026 monthly sales sheets | Repeated room blocks with dates, names, booking channel, nightly price, extra guests, per-guest fee, payment source, comments, daily totals, platform summaries, expenses, gross/net profit | One reservation record, associated room nights/charges, guest, booking source, payments, settlement/collection entries, expenses, and automatically calculated reporting |
| Monthly `FACEBOOK`, `AIRBNB`, `OUTSIDE BOOKING`, `NO BOOKING` summaries | Manual channel tally and day-status computation | Normalized channel taxonomy; channel and occupancy analytics with explicit metric denominators; no fictitious bookings from `NO BOOKING` cells |
| Payment-source columns (`GCASH`, Airbnb, etc.) | Booking origin and money receipt may be mixed | Separate booking **origin channel**, **payment method**, **payout channel**, and **receiving account** |
| Cleaning-NEW | Daily room-wise cleaning matrix and monthly totals | Automatic turnover task creation, assignments, completion/inspection evidence, supply use, monthly cleaning cost/report |
| LAUNDRY | Daily per-room laundry activity and totals | Automatic laundry requests after checkout, vendor/employee assignment, cost/quantity/status tracking |
| UTILITIES | Room/building electricity and water records | Monthly utility bill/reading import; room/property expense allocation and cost comparison |
| DAMAGE REQUEST | Check-in date, unit, guest, damaged item, amount, submission proof, resolution, remarks | Incident register linked to booking and room; evidence, review, approved charge, refund/claim status, repair work |
| RENOVATION | Refill checklist, improvements, replacements, cost notes | Inventory, restock alerts, maintenance requests, capital/repair classification proposed for owner review |
| SALARYC.A | Salary-related and staff cash advance tracking | Restricted employee advances, repayments, wage/payment summary and approval flow; **do not infer full statutory payroll from this sheet** |
| Room-list parking details + parking calendar | Parking contacts, prices, stall locations and manual calendar | Parking inventory, separate reservations/assignments, pricing, third-party vendor links, availability conflicts |
| Room-list Wi-Fi details | Per-room network details, passwords, troubleshoot contacts | Restricted room operations notes and credentials vault; never publish private information by default |
| REFILLS / REFILLS INVENTORY | Empty templates | Create inventory schema and editable screen, but import **zero actual stock quantities** without owner-provided counts |

Do not clone Excel layout literally as the core database or interface. Preserve row-level source provenance and offer a familiar **daily room-grid view** for the owner without forcing a spreadsheet-like data architecture.

## 4. End-to-end journeys — automate the work, not only its screens

### Journey A — guest inquiry to confirmed reservation
1. Guest visits the public website, selects property/type, dates and party size, reviews **indicative** room availability and estimated rates.
2. Guest clicks **Inquire via Messenger**; where prefilled messages are unsupported by the platform, provide a working copyable inquiry summary and then open the confirmed official Messenger URL. Do not claim automated sending happened.
3. Staff creates an `Inquiry` or `Tentative Hold`, chooses exact room(s), guest, dates/times, booking origin, actual price override reason (if any), and hold expiry.
4. Availability engine validates the dates and current conflicting holds, confirmed bookings, room blocks, and maintenance windows. Prevent simultaneous double-booking at transaction/database level.
5. Staff generates a quotation and copies it to chat or downloads branded PDF. A quotation alone does not reserve inventory unless staff explicitly creates an authorized time-limited hold.
6. Staff records externally received deposit/payment with evidence and review status; authorized staff **verifies** receipt. A screenshot/upload is not proof of cleared funds.
7. Staff approves and confirms reservation. System saves the final price snapshot, blocks physical room inventory, sends **internal** notifications, updates the calendar, and creates a confirmation message for human-controlled copying/sending.
8. Staff sees upcoming arrival, balance due, required guest details, and special requests. Room assignments, changed rates, payment events, and source changes are audit-logged.

### Journey B — arrival, departure, turnover
1. Daily agenda flags arrivals, pending balances, early-arrival requests and current housekeeping readiness.
2. Staff marks guest checked in after appropriate verification and hands over approved property information (Wi-Fi only to authorized guest/staff).
3. Check-in and check-out record actual timestamps separately from reserved times.
4. Checkout automatically creates a turnover cleaning task and, when applicable, laundry/restock/inspection tasks. Set housekeeping to `Dirty`; do not assume immediately bookable/ready.
5. Cleaner updates checklist, optional supply use, and before/after photos; manager/reception verifies room, marks `Inspected / Ready`.
6. Damage or missing inventory creates an incident; owner approves charges/refunds separately.
7. Financial, occupancy and task dashboards update from the underlying events without another spreadsheet entry.

### Journey C — nightly/monthly close
1. Staff enters actual cash/GCash/bank receipts, external-platform payouts, charges, refunds and categorized expenses.
2. Reconciliation panel pairs receipts/settlement rows to bookings and flags missing supporting proof, unmatched amounts, duplicate payout references, pending payment verification and outstanding balances.
3. Reports automatically compute by stay date, transaction date, payout date, property, room and source, with clearly defined measures.
4. Owner reviews the report and locks a period if desired; later changes are adjustments with history, not silent edits to a closed month.

### Journey D — customer issues and operations
Incident, cleaning failure, broken appliance, low inventory or missed maintenance deadline creates a task/alert linked to the room, accountable employee, cost and resolution evidence. Owner dashboard immediately shows what needs attention.

## 5. Booking and inventory engine

Implement these statuses and separate their meanings:
- Inquiry (not inventory-blocking)
- Pending / Tentative (inventory-blocking only if an active explicitly configured hold exists)
- Confirmed (inventory-blocking)
- Checked In (inventory-blocking)
- Checked Out (historical/nonfuture inventory-blocking)
- Cancelled (released, subject to block/history rules)
- No Show (history and charge rules; future inventory handled by explicit policy)
- Expired Hold (automatically released)

**Booking model:** unique human-friendly reference; guest; optional company/referrer; property; 1+ assigned rooms; local check-in/check-out dates, approved early time, actual arrival/departure, number of adults/children/total persons, special requests, source and source reference, guest messages, note permissions, operational status, quotation version, charge lines, amounts, payment status, cancellation/no-show policy, created/modified/confirmed by, immutable history.

**Critical availability logic:**
- Apply physical-room and maintenance blocks regardless of sales channel.
- Use half-open occupied date/time ranges `[start, end)` with turnover buffer logic; a checkout day is normally available for another arrival **only if cleaning/turnover and check-in rules allow it**.
- Make booking concurrency safe: MySQL/InnoDB transaction and serialized room-row locking (`SELECT ... FOR UPDATE` / Eloquent `lockForUpdate()`) before checking overlapping holds, bookings, and room blocks. Do not rely on application-only overlap checks or unsupported exclusion constraints. Prevent two transactions from confirming the same room at once.
- Holds have UTC expiry timestamps; property display uses Asia/Manila. Scheduler expires holds idempotently, with reservation history and notifications.
- Support room moves, date changes, split/group reservations and multiple rooms under one booking; use the same validation across create, update, confirm, import and room reassignment.
- Availability status and housekeeping readiness are separate dimensions; explain when `vacant but dirty` blocks check-in.
- Prevent confirming a room as ready when an unresolved safety-critical maintenance issue exists.
- Allow room blocks for repairs, owner use, technical downtime and unavailable third-party inventory; include reasons and operator identity.
- Support configurable minimum stay, max advance booking, house rules, cancellation policies and room-specific cutoffs, but don't enable unsupported policies without approval.
- Provide desktop monthly resource timeline and mobile-friendly daily/weekly list with sticky room labels, property/type filters, visual source icon, event status and accessible legend.

## 6. Rate, occupancy and charge-calculation engine

The `ROOM DETAILS` reference supplies weekday and weekend base prices. Store these as effective-dated pricing rules, not hardcoded on the frontend. Support property/room-level overrides, seasons/holidays/promo windows, channel-specific price overrides, manual approved discount, taxes/fees (only if configured), parking, deposits, early/late charges and incident adjustments.

**Calculation requirements:**
1. Calculate each **night** from its actual date, selected room and effective pricing rule. The owner sets the exact weekend-day definition and rates; do not silently assume Friday, Saturday or Sunday.
2. Select only an eligible room with `guest_count <= max_capacity`; distinguish included party size from max capacity.
3. Extra guests = `max(0, guest_count - included_capacity)`, but whether the ₱200/pax historically shown is **per night or per stay**, and any exceptions, **must be owner-configurable and explicitly approved before activation**. Never treat the historical workbook's `200/PAX` as proof of a per-night policy.
4. Total = sum of nightly base rates + configured guest charges + approved early/late fees + parking/services + configured taxes or charges − authorized discounts, using decimal-safe money types.
5. Store an immutable rate/charge snapshot at confirmation and any replacement version after amendments. Future rate changes must not rewrite prior confirmed prices.
6. Record expected gross booking amount separately from amount received, channel settlement/payout, platform fees, refunds, and realized service revenue.
7. Prevent ambiguous “total profit” labels: gross booking value, net booking revenue, collections, payout, expenses, and operating contribution must have explicit definitions.

**Early check-in:** standard check-in **2:00 PM**. Default **₱200 per started hour before 2:00 PM**, changeable in Settings. Examples: 1 PM ₱200; 12 PM ₱400; 11 AM ₱600; 10 AM ₱800. Frontend modal shows standard/requested time, hours early, fee, revised estimate and **subject to availability, room readiness and staff approval**. Never automatically move/block another guest's confirmed stay. Late check-out fee and scheduled standard check-out time are owner-configurable: the source does not establish a default.

**Quotation test cases:** (a) Venice one weekday + one weekend night for included guest capacity = ₱1,700 + ₱1,900 = ₱3,600 before other charges, *once dates/weekend mapping are selected*; (b) approved noon check-in adds ₱400 under default early policy; (c) total beyond 4 guests for Venice is rejected; (d) booking at old 2026 historical rate must not be recalculated at new posted rate.

## 7. Sales recording, payment and financial management

This must be a full **operational sales ledger**, not simply a “paid/unpaid” checkbox. The Excel shows nightly amounts, extra guest amounts, receipt method, totals, expenses, collection via GCash/cash, and an Airbnb BPI payout context. Keep these accounting ideas separate.

### Required financial entities and interfaces
- **Charges:** nightly accommodation, extra persons, early check-in, late checkout, parking, other services, damage, discount, manual adjustments; each with source, quantity, tax configuration if applicable, approved_by, currency PHP and timestamp.
- **Payments/receipts:** reference, booking, date/time, amount, method (cash, GCash, bank transfer, platform remittance, etc.), receiving account, evidence, entry source, reviewer, verification status, refund/chargeback linkage, and optional bank/payout reconciliation reference.
- **External channel settlements:** platform gross booking, commissions/host fees, adjustments, expected net payout, actual payout, payout date, receiving bank, booking linkage, fees and differences. A paid Airbnb guest is not automatically equivalent to cash received by UNIQA.
- **Expense entries:** room/property/general allocation, booking/incident/task link, category, vendor, amount, date, payment method, proof, incurred-vs-paid status, approver and recurrence.
- **Guest balances:** booking total less valid applied receipts plus refunds/adjustments, with statuses Unpaid, Partially Paid, Paid, Overpaid, Refunded, Payment Under Review. Use verification rules to determine recognized valid receipts.
- **Cashbook and reconciliation:** daily collections, GCash, bank and platform settlements, petty cash movements, expense reimbursements, unmatched transactions and exceptions. Prevent double counting when recording both platform booking gross and its subsequent payout.
- **Refund/deposit handling:** owner-controlled policies and approval, amount calculation and manual execution status; no API payout/reversal without explicit supported integration and permission.
- **Salary/cash advances (restricted):** employee advances, repayments, salary payouts, running balance, supporting approvals, separate permission/audit. Do **not** promise legally compliant payroll calculations or infer salary amounts from unrelated bank/cash entries.
- **Period close:** preview/review/lock, immutable adjustments, reconciliation reports, CSV/XLSX/PDF exports and printable summaries.

### KPI definitions must be unambiguous
- **Booked revenue:** contracted accommodation/charge total for reservations in the selected booking cohort, excluding cancelled/no-show amounts unless policy specifies retained fees.
- **Earned accommodation revenue:** allocate each paid/accrued stay to the corresponding occupied nights, with adjustments properly allocated and transparent.
- **Collected cash:** verified receipts by receipt date/method, net of actual outbound refunds; no duplicate counting of booking total and payout.
- **Outstanding balance:** confirmed charge obligations less verified applied payments, with disputed balances clearly shown.
- **Gross operating revenue:** defined recognized accommodation + ancillary income, based on selected reporting basis.
- **Operating expenses:** recorded and approved expenses under defined categories; distinguish incurred/paid.
- **Operating profit / contribution:** recognized operating revenue minus relevant expenses, excluding incomplete/unallocated costs where required; **not represented as audited net income**.
- **Occupancy:** occupied **room nights** / sellable available room nights over the selected period, excluding approved room-out-of-service nights under a documented policy.
- **ADR:** room accommodation revenue / occupied room nights, not total booking count.
- **RevPAR:** room accommodation revenue / available room nights, with exclusions consistently described.
- **Channel mix:** unique confirmed bookings, recognized revenue or verified collections **as separately selected**; `NO BOOKING` is occupancy state, not a source or a booking.
- **Cancellation/no-show rates, average length of stay, collection aging, extra-pax income, fees, room profitability, property profitability and booking trend** as long as the underlying data is reliable.

Provide report basis selectors: **by booking-created date**, **stay/room-night date**, **receipt date**, **external-platform payout date**, and **expense date**. Never imply these totals necessarily reconcile directly across different bases. Monthly room-by-room historical exports should resemble the owner's previous grid for familiarity while using normalized transactions.

## 8. Booking source, acquisition, and communication

**Booking source options:** Facebook Messenger, Instagram, Airbnb, Walk-in, Phone Call, Direct Referral, Other / custom, plus explicit `Outside Booking / Unclassified` for historical entries that lack sufficient channel detail. Keep `Facebook` historical alias mapped to `Facebook Messenger` only if confirmed. Allow channel metadata, external reservation ID, original message reference, campaign/referrer and the operator who entered it.

**Never confuse:**
- `booking_source` = where a customer/reservation originated;
- `payment_method` = cash, GCash, transfer, etc.;
- `settlement_source` = Airbnb or other external payout, if applicable;
- `import_source` = manual, legacy spreadsheet, iCal feed, approved API.

Channel analytics should show bookings, source share, confirmed/cancelled, gross room-night revenue, collections, commission/fees, conversion of logged inquiries, and monthly trends. Permit admin filter, pivot and export by property, room, date and status. Show a source label/icon on booking cards and room grid; colors must also have accessible text labels.

**Messenger/Instagram:** Implement polished one-click **Copy Message** templates for inquiry response, quote, confirmation, payment instructions, balance reminder, early-check-in notice, directions, arrival instructions, check-out reminder, post-stay thank-you, and damage/incident communication. Opening an official social URL is not equivalent to delivering a message. Use only official platform integrations if credentials, approval, capabilities and applicable policies are actually available. Do not scrape, auto-DM, impersonate a user or promise unrestricted two-way Messenger/Instagram automation.

## 9. Public luxury marketing website

Build a responsive, credible hospitality site that increases room inquiries. Use the official UNIQA SUITES wordmark and original property photography; do not invent room photos, properties, reviews or address details.

**Pages/sections:** premium hero with value proposition and clear inquiry CTA; property/location switcher for The Loop and Mesaverte; “Find your suite” check-in/check-out, guest count, room type and location; available room tiles and detail pages; galleries and amenities; capacities and rate explanations; facilities; check-in and early-arrival policy; directions/map links if supplied; FAQs; social/contact links; privacy notice; footer. Publish only approved location/contact details.

**Filters:** Studio, 1-bedroom, 2-bedroom; The Loop vs Mesaverte; balcony, bathrooms, guest capacity, cost range, dates. Rooms should be visually presented by suite name (`RIO`, `VENICE`, etc.), not just numeric unit.

**Availability display:** “Indicatively available” or “Please confirm with us” when external calendars aren't up-to-date. If a room is already confirmed internally, do not show it available for the same stay. No guest-entered reservation confirmation or online payment. Guest-generated inquiry summary can be copied before Messenger redirect; log only the inquiry that can actually be captured lawfully and reliably (avoid inventing inquiry counts).

**SEO/accessibility:** meaningful metadata, property and room pages, optimized responsive images, skeleton/loading states, mobile navigation, keyboard and screen-reader behavior, WCAG-aware contrast, alt text, suitable privacy disclosures and practical performance budgets.

## 10. Staff housekeeping, laundry, maintenance and inspections

### Turnover automation
- On actual checkout, create a linked cleaning task if not already created; avoid duplicates on retried events.
- Set housekeeping state `Dirty`, then `Assigned`, `Cleaning`, `For Inspection`, `Ready`, or `Under Maintenance` as appropriate, without changing booking status.
- Assign by property, staff availability/shift, workload or manager decision; allow reassignment.
- Task has room, booking link, due-by time, priority, instructions, photo checklist, materials consumed, clock-in/out and completion notes.
- Notify assigned worker; overdue tasks appear as alerts. An authorized reviewer performs inspection/acceptance before the room becomes ready.

### Laundry
Automatically suggest/create laundry work for departures under a property policy; quantity (sets/items), processing status, provider/person, pickup/delivery, cost, proof and stock turnover. Do not assume all stays automatically incur identical laundry cost/quantity.

### Maintenance/renovation
Work orders for damaged furniture, plumbing, AC, appliances, supplies, preventive checks and renovation items; set severity, safety block, quote/cost, labor/vendor, evidence, date and inspection status. Distinguish routine upkeep from potential capital expenditure as an **owner/accountant review classification**, not an automatic accounting assertion.

### Consumables and restocking
Use `RENOVATION` entries such as tissue, hand soap, shampoo, body wash, dishwashing liquid, room freshener, bathroom freshener and batteries as **example inventory SKUs**, not confirmed current stock. Add central inventory, per-property stock, min/reorder level, movement ledger, supplier, unit cost, issue-to-room, adjustments, replenishment alerts and assigned restock tasks. Empty `REFILLS` sheets must not seed invented stock balances.

## 11. Guest damage, refunds, and issue resolution

Create a secure incident module matching `DAMAGE REQUEST` fields: related check-in / stay, room/unit, guest, item/description, date discovered, evidence, photos, estimated/approved amount, claim/charge status, deposit deduction or refund, owner resolution, vendor/repair cost, closure notes and chronological communications.

**Statuses:** Reported, Evidence Pending, Under Review, Approved for Charge, Disputed, Resolved, Closed. Owner decides liability and reimbursement; **never auto-charge or auto-refund** because someone submitted a photo. Support a separate guest-protective audit of disputes, cancellation of mistaken charges, and correct accounting/reporting impact.

## 12. Parking availability and reservations

Use `PARKING DETAILS` and `2026 Parking Calendar` as evidence that parking requires its own inventory/calendar, not as proof that a particular parking spot is always available.

- Parking location The Loop or Mesaverte, stall/space ID, owner/partner/vendor, public internal rate, cost/rate history, contact (staff only), lease rules, accessibility/size restrictions, active dates and availability.
- Source shows examples of **₱350** for The Loop and **₱300** for Mesaverte parking; treat as **proposed amounts subject to date/space/vendor confirmation**, never a universal fixed rate.
- Create parking-specific holds/assignments linked optionally to room booking and guest, with conflict detection and cancellation.
- Quote and charge parking as a separate configurable line item. Parking capacity is independent of room inventory.
- Include parking in check-in messages only when the guest has an approved assigned space. Restrict vendor phone numbers to authorized staff.

## 13. Wi-Fi, room instructions and sensitive property information

Import `WIFI DETAILS` only with appropriate admin authorization, encrypted at rest if feasible and strictly access-controlled. Credentials must **never** be embedded in public HTML, frontend bundles, static JSON, exports intended for the public, or debug logs. Create a per-room internal knowledge card for network name, authorized password reveal, troubleshooting instruction, contact person and last verification date. Record access where operationally useful and allow secure password rotation. Guest access instructions are shared only through an authorized staff-generated message or controlled guest-specific delivery mechanism, not through open pages.

## 14. Dashboard and reporting screens

### Owner dashboard, prioritized top-to-bottom
1. **Action needed:** booking conflict/missing channel import, arrivals with unpaid balances, dirty rooms due for check-in, failed calendar feed, incident requiring approval, unreconciled receipt, expired hold and pending task.
2. **Today:** occupied vs vacant vs blocked rooms, arrivals, departures, room readiness, confirmed check-ins, housekeeping completion, parking usage.
3. **Money:** verified collected today/month, outstanding receivables, Airbnb expected vs received payouts, monthly recognized accommodation revenue, expenses and operating contribution, comparison against prior month.
4. **Performance:** occupancy, ADR, RevPAR, top rooms, top booking sources, monthly trend.
5. **Team:** task workload, late cleaning, laundry/maintenance status and inventory alerts.

### Navigation
- Dashboard / Today
- Booking Calendar
- Reservations & Inquiries
- Guests
- Quotes & Messages
- Rooms & Properties
- Payments & Reconciliation
- Expenses & Cashbook
- Reports & Analytics
- Housekeeping & Laundry
- Maintenance & Damage Claims
- Inventory & Refills
- Parking
- Staff Advances & Payroll Records (restricted / optional)
- Import & Data Quality
- Team & Permissions
- Settings & Integrations
- Audit Log

### Reporting, filters and export
Support date range, property, unit, room type, booking source, payment method, stay status, actual-vs-forecast, gross-vs-net and review status. Include nightly/monthly room ledger, channel report, guest balance aging, daily collection, monthly expenses by category, receipt-to-bank reconciliation, occupancy and rate reports, staff task completion, incidents/refunds, cleaning/laundry expense, utilities and parking. Export clean PDF, Excel and CSV with selected filters, timestamp, report definition and generated-by user. Exports must obey role-based access and protect PII.

## 15. Notifications, scheduler and safe automation rules

Automations must be idempotent, observable and **permission-aware**. Implement scheduler/queue-driven workflows with event IDs, retries, failure notices, administrative override and dry-run when useful.

| Trigger | Automatic action | Human approval/limitation |
|---|---|---|
| Quotation saved/updated | Recalculate, version, generate copy-ready reply/PDF | Staff controls sending |
| Hold created | Temporarily reserve eligible inventory, set expiry/reminder | Availability check and hold policy |
| Hold expired | Free inventory, mark expired, notify staff | Do not expire already confirmed booking |
| Booking confirmed | Lock room dates, update dashboard, generate checklist/templates | Staff approval and payment verification policy |
| Payment recorded | Update draft balance and reconciliation queue | Only verified payments count as cleared |
| Date/room changed | Revalidate overlaps, reprice proposed changes, update tasks if needed | Staff approval on material adjustments |
| Day before arrival | Show prep reminders, outstanding balances and special requests | No unsolicited social messages without approved integration |
| Checkout completed | Mark dirty, issue cleaning and laundry tasks, alert manager | Do not automatically declare room ready |
| Cleaner marks done | Queue inspection, record labor/supply consumption | Manager inspection for Ready state |
| Stock below threshold | Generate purchase/restock task | No automatic purchasing/payment |
| Expense saved | Allocate to selected cost center, update reporting | Approval when configured |
| Incident submitted | Create review action, optionally maintenance block | No automatic guest penalty/refund |
| External calendar sync | Stage updates, detect clashes/duplicates, alert admins | Third-party changes can be delayed/incomplete |
| Month-end scheduler | Prepare reports and reconciliation exceptions | Owner closes/locks period |

Support timezone-aware notifications, quiet hours, user subscription preferences, configurable lead times and all relevant audit logs.

## 16. Third-party availability and future integration readiness

Start with **manual Airbnb booking entry and import**, as the current spec assumes. If available and authorized, support an official iCalendar (.ics) subscription for **read-only external blocked-date ingestion**, with last-sync time, refresh cadence, failure alerts, duplicate detection, external reference mapping and uncertainty indicators. iCal feeds may have delays and limited guest/financial detail; never claim real-time guaranteed bidirectional Airbnb sync. Build adapters/interfaces for future officially permitted Airbnb / other OTA channels, Meta messaging and payment/account feeds only where verified documentation and authorization permit. Integrations must never bypass backend inventory constraints.

Admin integrations page must show each provider as `Not connected`, `Connected`, `Degraded` or `Failed`; clearly distinguish verified live feeds from manually maintained records. If a live channel cannot be accessed, preserve a reliable manual workflow with import review and visible staleness warning.

## 17. Legacy Excel ingestion and reconciliation — a core deliverable

**Do not skip this module.** The client wants the manual work replaced and historical data preserved. Implement a secure import wizard with preview, mapping, validation, dry run, exception queue, approvals and rollback. Preserve original files as restricted source documents with checksum, upload time, importer and version.

### Stage 1: identify layouts and extract
- Parse all monthly sales tabs, even though their repeated room blocks shift between January–June, July–August, and September–December. Build **per-sheet/per-block mappings** rather than assuming one universal column layout.
- The date column sometimes contains Excel serials; some rows contain formatted strings/ranges, merged cells, blank/zero placeholders, repeated guest names, formulas and monthly summaries. Decode correctly, **do not treat every filled row as a new reservation**.
- Extract potential records for named guest, room identity, nightly date, booking source, base nightly amount, notes, extra guests, extra-guest charge, payment source, extra fees and source total; retain exact workbook/sheet/cell/row provenance.
- Identify historical values such as `AIRBNB`, `FACEBOOK`, `OUTSIDE BOOKING`, `NO BOOKING`, `GCASH`, `NO EXTRA PAX`, “early check in”, “late check out” and fee notes. Normalize only with explicit rules; `NO BOOKING` is not a guest or reservation.
- Import cleaning, laundry, utilities, damage, renovation, parking and staff-advance information as **separate** source types with independent review/permissions.
- Never import Wi-Fi passwords or employee financial information as a public/general seed or general report field.

### Stage 2: mapping and data quality
For each proposed booking/stay: confidence score, source file/tab/range, inferred identity, date evidence, money evidence, conflict flags and reviewer decisions. Explicit anomaly categories:
- `unknown_or_conflicting_room` (`2221`/`2224`, `2510`/`2520`, `14Q` location);
- `capacity_or_rate_conflict` (historical vs current sheet);
- `missing_checkout_date`;
- `possible_multi_day_duplicate` (same guest repeated each occupied day);
- `booking_origin_vs_payment_source_conflict`;
- `formula_vs_manual_total_mismatch`;
- `unverified_payout_or_cash_receipt`;
- `unmatched_damage_or_cleaning_entry`;
- `ambiguous_date_or_room`;
- `historical_unlisted_room`;
- `future_or_unconfirmed_record`;
- `legacy_or_placeholder_value`.

**Do not infer occupancy, completed stays, actual payments or realized profits from a cell without enough evidence.** Prefer manual review to false precision.

### Stage 3: approval and commit
- Show counts of imported / skipped / flagged / duplicate / unlinked rows and cross-sheet consistency, with sample raw source.
- Owner/admin resolves room mappings once where appropriate, can reject incorrect suggestions and can attach evidence.
- Import in transactions; use stable source keys and idempotency to prevent duplicates on rerun.
- Store immutable `source_file`, `sheet`, `row`, original cell values, transformation version, operator and import batch IDs.
- Historical sales records that cannot reliably reconstruct whole stays may be held as **legacy daily revenue lines** or marked `Unverified Historical`, not forced into fake bookings with fabricated checkout dates.
- Produce reconciliation by month and room comparing **legacy summary totals** vs **imported data** where meaningful, with difference explanation and unsupported formulas clearly noted.
- Expose historical financial totals as `Provisional / Unreconciled` until reviewed; do not intermingle with verified new production activity.

### Stage 4: system cutover
Establish a cutover date confirmed by owner; identify the authoritative room calendar immediately before live use; reconcile future Airbnb bookings and manual holds; freeze/backup source spreadsheets; allow historical corrections only with a new audit entry. Provide a rollback plan and training for all staff.

## 18. Roles, permissions and review workflows

Implement policies/permissions on **every endpoint, action, import, export and file download**, not only navigation visibility.

- **Owner / Super Admin:** all properties, rooms, settings, roles, financials, approvals, import reviews, month locks, audits.
- **Operations Manager:** room allocation, team scheduling, housekeeping review, maintenance and incident operations; configurable financial visibility.
- **Booking / Reception:** inquiries, quotations, eligible room reservations, check-in/out, appropriate guest details, **record** payments; discount/refund/override capabilities only where authorized.
- **Finance / Bookkeeper:** collections, reconciliation, expenses, payouts, approved reports, bank evidence; cannot unilaterally modify room assignments unless granted.
- **Housekeeping / Laundry Staff:** own assignments and permitted room notes, status, inventory used, photos; no guest payment info or salary data.
- **Maintenance Staff:** designated work orders, room access instructions and evidence; no broad financial/guest access.
- **Staff Advances Administrator / Payroll:** restricted private employee financial information.
- **Auditor / Read-only:** permission-limited historical reports and audit logs.

Requirements: MFA for privileged users where possible; strong sessions, policies, reset flows, revocable access, immutable approval records, action/audit timestamps and secure invitation/offboarding. Finance approval thresholds and second reviewer can be configured. Separate sensitive fields using policies/API serializers, not hidden frontend components alone.

## 19. Core data model

Use normalized, Laravel migration-managed **MySQL-compatible InnoDB** tables (suggested; consolidate when practical):

- `properties`, `room_types`, `rooms`, `room_images`, `amenities`, `room_amenities`, `room_blocks`, `room_status_events`;
- `guests`, `inquiries`, `booking_sources`, `bookings`, `booking_rooms`, `room_stays`/`booking_nights`, `booking_status_events`, `holds`, `external_reservation_links`, `external_calendar_feeds`;
- `rate_plans`, `rate_rules`, `rate_overrides`, `quotes`, `quote_items`, `booking_charges`, `booking_adjustments`, `pricing_snapshots`, `message_templates`;
- `receipts`, `receipt_allocations`, `refunds`, `external_settlements`, `settlement_items`, `payment_accounts`, `reconciliation_matches`;
- `expenses`, `expense_categories`, `cost_centers`, `vendors`, `monthly_periods`, `period_locks`;
- `housekeeping_tasks`, `task_checklists`, `task_assignments`, `task_events`, `task_attachments`, `laundry_jobs`, `work_orders`, `incidents`, `incident_evidence`;
- `inventory_items`, `inventory_locations`, `inventory_movements`, `inventory_reorder_rules`;
- `parking_spaces`, `parking_assignments`, `parking_rates`, `parking_vendors`;
- `room_secrets` (encrypted/strictly authorized), `room_instructions`;
- `employees`, `staff_cash_advances`, `staff_repayments` (restricted);
- `import_batches`, `import_files`, `legacy_rows`, `import_suggestions`, `import_anomalies`, `import_mappings`, `import_decisions`;
- `users`, `roles`, `permissions`, `model_has_roles` or equivalent, `notifications`, `activity_logs`, `integration_health`, `application_settings`.

**Constraints and types:** UUID/ULID or safe ID strategy; physical room codes stored as **strings** (preserve leading zero); Philippine peso amounts `DECIMAL(14,2)` or integer centavos; no float money arithmetic; local date fields separate from UTC timestamps; external identifiers unique per provider/account where applicable; MySQL-safe lookup/status tables; foreign keys and cascading rules that do not erase finance or audit history; composite indexes on property/room, reservation dates/status, source, payment state, and external references. Use `utf8mb4` and `InnoDB` for transactional integrity. Avoid PostgreSQL-only constructs (e.g., exclusion constraints, range types, `ILIKE`, PostgreSQL JSON operators and partial indexes), and do not assume the XAMPP "MySQL" control always runs Oracle MySQL rather than MariaDB. Keep schema, migrations, queries, and indexes compatible with the installed database engine/version. Soft delete only where compatible with audit and financial integrity.

**MySQL overlap/conflict invariant:** In one `DB::transaction`, lock the relevant physical `rooms` rows with `lockForUpdate()` **before** querying overlaps; for multi-room bookings lock rows in ascending room-ID order. Then check conflicting confirmed/checked-in bookings, active holds, room blocks and relevant time buffers with half-open interval logic (`existing_start < requested_end AND existing_end > requested_start`), update all affected records, and commit atomically. Every inventory-writing path (new booking, confirmation, edit, reallocation, external import, hold, cancellation/release, maintenance and parking allocation where applicable) must use the same locking strategy. Add collision-safe idempotency keys, guarded state changes, deadlock retry policies and MySQL-backed concurrent tests. Avoid using a booking-row-only lock that fails when no booking exists yet.

## 20. Application architecture and technology stack — REQUIRED: XAMPP + PHP 8.2 + MySQL

**Non-negotiable stack change:** Develop and demonstrate this application on **Windows XAMPP with PHP 8.2**, using the **MySQL database service managed through XAMPP/phpMyAdmin**, Laravel 12, React and Inertia.js. Do **not** use PostgreSQL or require PHP 8.3+. Laravel 12 must be installed with versions compatible with PHP 8.2. Do not silently upgrade the required local PHP runtime.

### 20.1 Backend and database
- **Web/runtime:** XAMPP Apache + PHP **8.2.x** on Windows; configure `mod_rewrite`, OpenSSL, PDO MySQL, mbstring, fileinfo, XML, tokenizer, ctype, curl, intl, zip and GD as needed by chosen packages. Confirm compatibility with `php -v`, `php -m`, `composer check-platform-reqs` and the project’s actual dependencies.
- **Framework:** Laravel **12** with Eloquent, migrations, Form Requests, Policies/Gates, modular Services/Actions, Events/Listeners, Notifications, jobs, Scheduler, filesystem and Pest/PHPUnit.
- **Database:** XAMPP's **MySQL-compatible** database running with **InnoDB** storage, `utf8mb4` charset, foreign keys and strict SQL mode. Prefer **MySQL 8.0+** where a genuine Oracle MySQL server is installed. **Compatibility note:** many XAMPP builds label their database service “MySQL” while bundling **MariaDB**; inspect `SELECT VERSION();` and explicitly confirm whether it is MySQL 8.x or MariaDB before relying on dialect/version-specific behavior. Do not call MariaDB "MySQL 8". The implementation must use Laravel-supported SQL compatible with the locally installed engine, or clearly document any required real-MySQL upgrade. Never assume a PostgreSQL server.
- **Database administration:** phpMyAdmin in XAMPP for local setup and inspection; run schema creation and all repeatable schema changes through **Laravel migrations**. Do not make manual phpMyAdmin table edits a dependency for future deployment.
- **Local database:** `uniqa_suites` (configurable). Create a least-privilege development account where practical; do not hardcode credentials. Use the default local `root`/empty password only as an explicitly marked **local XAMPP example**; never use it in production.
- **Data structure:** relational InnoDB schema; decimal/integer-centavo money; indexes for room/date range checks, lookups and reconciliation; referential integrity; durable audit and transaction histories; UTC timestamps and `Asia/Manila` application display timezone.

### 20.2 Frontend and application integration
- React with TypeScript; **Inertia.js** integrated into Laravel (do not create a separately deployed API service unless actually justified); Tailwind CSS, shadcn/ui, Lucide React, TanStack Table, React Hook Form + Zod, Recharts, and FullCalendar or another proven room/resource calendar component.
- Node.js and npm for Vite builds. Provide compatible, reproducible dependency versions, including composer lockfile and package lockfile. Run `npm run dev` during development and `npm run build` for distribution.
- Preserve responsive premium branding and all original room, booking, quotation, finance, housekeeping, source, parking and Excel-migration requirements.

### 20.3 Exact Windows/XAMPP local setup deliverable
Provide a working README with commands and troubleshooting, using example project folder `C:\xampp\htdocs\uniqa-suites` (allow another location). Account for Windows PowerShell vs Command Prompt differences.

1. Install/verify **XAMPP PHP 8.2**, **Composer**, **Node.js/npm** and required PHP extensions. Show actual compatibility checks; if the bundled database is MariaDB, identify its installed version and test migrations on it.
2. In XAMPP Control Panel, start **Apache** and **MySQL**. Verify the DB service and that phpMyAdmin connects; document resolution for Apache port 80/443 and database port 3306 conflicts without changing ports silently.
3. Create database using phpMyAdmin or compatible SQL: `CREATE DATABASE uniqa_suites CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`. Provide a secure account setup option. Do not put real credentials in the README.
4. Set the Laravel `.env` variables to a local example (update credentials to match the installed DB):

```dotenv
APP_NAME="UNIQA SUITES"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://uniqa-suites.test
APP_TIMEZONE=Asia/Manila
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=uniqa_suites
DB_USERNAME=root
DB_PASSWORD=
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
MAIL_MAILER=log
```

   `DB_USERNAME=root` with blank `DB_PASSWORD` is an example **only for an isolated default local XAMPP setup**, never a production recommendation. Use `APP_DEBUG=false`, real secrets, HTTPS, restricted database accounts and environment-specific config for deployment. Confirm Laravel's actual timezone config reads the chosen setting, rather than assuming `APP_TIMEZONE` alone changes runtime behavior.
5. Include dependency/install and first-run commands that work for the generated repository (typically `composer install`, `copy .env.example .env` on Windows CMD or `Copy-Item .env.example .env` on PowerShell, `php artisan key:generate`, `php artisan migrate`, `php artisan storage:link`, `npm install` or `npm ci`, `npm run dev`). Seed only approved room definitions and **synthetic** demo data, not private workbook contacts.
6. Configure an **Apache VirtualHost** (e.g. `uniqa-suites.test`) pointing its **DocumentRoot to `C:/xampp/htdocs/uniqa-suites/public`**, never to Laravel's project root. Include a sample VirtualHost, local Windows hosts-file mapping `127.0.0.1 uniqa-suites.test`, `AllowOverride All` / Apache rewrite prerequisites, and instructions for restart. Alternatively explain `php artisan serve` as a development-only fallback, not a requirement to expose private directories through Apache.
7. Document how to run both web and Vite dev processes, queue worker (`php artisan queue:work`), and local task scheduling (`php artisan schedule:work`); for sustained Windows deployment show **Task Scheduler** running `php artisan schedule:run` every minute and a supervised equivalent for queues. Do not imply XAMPP itself runs Laravel Scheduler/queue workers automatically.
8. Provide `php artisan optimize:clear`, log inspection, permissions/storage troubleshooting, MySQL connection/port troubleshooting and steps to verify PHP 8.2 is the CLI PHP in PATH rather than another PHP installation.
9. Deliver **MySQL/MariaDB-compatible** schema migrations and database-backed automated test instructions. Configure a **separate test database** with its own credentials; never run migration-refresh or destructive tests against the owner's real/historical data.
10. Include actual restore-tested backups for database **and** private uploaded evidence; an XAMPP/local Windows computer is **not** itself a secure production hosting/backup strategy. Document an appropriate PHP 8.2-compatible, MySQL-compatible production environment and deployment process separately.

### 20.4 MySQL transaction safety and scheduling
- Use `DB::transaction()` plus Eloquent `lockForUpdate()` on locked room inventory rows and consistent room ordering; select/check overlapping active holds, confirmed/checked-in booking-room allocations and maintenance blocks **inside** the same transaction.
- Use overlap condition `existing_start < proposed_end AND existing_end > proposed_start`, with approved turnover buffers and no false positive for correctly modeled back-to-back stays.
- All write paths must use the identical inventory reservation service and lock discipline, including reschedules, multi-room moves, imports and temporary holds. Retry detected deadlocks safely; reject conflicts with an actionable message; never partially commit finance/booking state on failure.
- Use database-backed queues, cache and sessions initially when compatible; do not require Redis, Docker, PostgreSQL or cloud services merely to run the core system in XAMPP. Redis and external services can be optional production optimizations only.
- Ensure idempotent job processing for hold expiration, post-checkout cleaning, laundry, alerts, imported calendar events and daily/monthly reporting. Scheduler and workers must run as real processes to make automation functional.
- Use MySQL/MariaDB-safe query builder/SQL. Do not use unsupported PostgreSQL-only exclusion constraints, partial indexes, `ILIKE`, `RETURNING` assumptions, PostgreSQL JSON operators or native range types. Verify full-text search and JSON usage against the installed database variant if selected.

### 20.5 Architecture, quality and privacy
**Quality standards:** thin controllers; domain folders (Bookings, Availability, Pricing, Finance, Tasks, Inventory, Parking, LegacyImport, Integrations, Reporting, Users); explicit state transitions; versioned MySQL-compatible migrations; factories and synthetic seeds; resilient database queues; monitoring and logs; transaction-safe services; validated imports. Secure file scanning/type constraints, limits, authorized or signed downloads, encrypted secrets, backups, rate limiting, CSRF/session controls, ORM/query parameter binding, XSS-safe output and no secrets in client bundles.

**Security/privacy:** comply with applicable Philippine privacy rules subject to owner/legal review; retain only necessary data; separate staff permissions; encrypt Wi-Fi credentials and protect payment proofs; never expose real guest or staff details in public/demo fixtures, screenshots or logs. Use HTTPS, `APP_DEBUG=false`, hardened database accounts, off-site backup and queue/scheduler supervision on actual production hosting.

## 21. Branding and experience design

Use the **original UNIQA SUITES assets** exactly as supplied: source image with ivory/cream `UNIQA SUITES` lettering and elegant interlaced `UQA` monogram on deep brown; use the appropriate landscape mark in the navbar, monogram on favicon/sidebar and proper logo on PDFs. Do not use a generic new logo or incorrectly reconstruct text. Preserve source aspect ratio and transparent variant where available.

Initial color tokens (fine-tune against source logo): Chocolate `#2D241F`; Warm Ivory `#F8F4EC`; Champagne Gold `#C6A675`; Espresso `#46362D`; Soft Beige `#EDE4D8`; White `#FFFFFF`. Use a warm, premium but practical design: refined type pair, large real photography on public pages, high contrast, clean reservation calendar, intuitive colored status chips with readable labels, restrained transitions, small mobile task cards, soft shadows and consistent padding. No excessive gradient/template clutter.

**Public UI must feel like hospitality. Admin UI must behave like an efficient operations tool.** Distinct user-focused pages, searchable/filterable tables, clear empty/error/success states, sticky date filters, quick-add booking, source icon + status, warnings on actions that change guest cost, and useful cross-links to guest/room/expense/task records.

## 22. Required screens and functional coverage

At minimum implement functional routes and complete flows for:

1. Guest website landing; suite search; room detail; Messenger inquiry helper; policy/FAQ/contact.
2. Staff login / account settings; role-aware navigation.
3. Today/Owner dashboard and actionable alerts.
4. Multi-property, room-by-room calendar with add/edit/hold/confirm/cancel and conflict messages.
5. Booking list/detail, guest profile and complete stay/payment/source/notes history.
6. Quotation calculator, version history, copy-to-Messenger/Instagram message, PDF and print.
7. Rate rule administration, room configuration and published catalog management.
8. Receipts/payments verification; settlement reconciliation; expense CRUD; cashbook; balances.
9. Housekeeping task board, mobile task completion, inspection queue and laundry tracking.
10. Inventory/reorder/restock, maintenance/work orders and damage/claims.
11. Parking inventory/calendar and optional attach-to-booking charge.
12. Restricted room Wi-Fi/instructions, staff advances (where enabled).
13. Historical import wizard, anomaly review, mapping decisions and reconciliation report.
14. Owner analytics, exported monthly statement and operational reports.
15. Staff/permissions, templates, policy/fees, integrations, audit log and settings.

Every primary action should have working data persistence, server-side validation, error handling, appropriate permissions and unit/integration tests. A button that merely looks functional is incomplete.

## 23. Test plan / release acceptance gates

Write automated tests covering critical rules and demo scenarios with **synthetic** guest records:

**Reservations**
- Two genuinely concurrent MySQL/InnoDB transactions attempt to confirm the same physical room overlapping dates: only one succeeds; the other receives a clear conflict without partial financial updates. Test with separate database connections/processes; SQLite-only concurrency tests are not sufficient.
- Confirmed Airbnb booking entered manually blocks a Facebook-origin booking for the same room/date.
- Expired hold releases dates, but an already confirmed reservation does not disappear.
- Back-to-back check-out/check-in preserves correct dates while requiring room readiness/turnover.
- Moving a room, extending dates or changing a group booking rechecks all conflicts.
- Out-of-service and unresolved critical maintenance restrictions prevent invalid assignments.

**Pricing**
- Validate all nine current room names/capacities/rates after review and approval.
- Test weekday/weekend crossovers and effective-dated rates; no silently assumed weekend rule.
- Venice quoted weekday ₱1,700, weekend ₱1,900; no exceeding maximum 4 guests.
- Early check-in 1 PM ₱200 / noon ₱400 / 10 AM ₱800 under default settings; an early time cannot bypass room readiness.
- Existing confirmed booking does not change if an administrator edits future rates.

**Finance**
- Separate GCash payment from booking source Facebook and separate Airbnb payout from Airbnb booking gross.
- Partial payments, overpayment, refunds, mismatched payout, duplicates and evidence under-review statuses reconcile correctly.
- A report on room-night revenue differs from cash collected when payout occurs in another month, and labels explain why.
- Expenses allocated by room/property flow to reporting; unpaid expenses are not falsely treated as cash paid.

**Operations**
- Checkout creates exactly one cleaning job on retry, with laundry if configured; completed cleaning awaits inspection.
- Inventory is reduced only when actual consumption is recorded; empty workbook inventory tabs create no stock.
- A damage report cannot silently create a payable/claim until approved.
- Parking cannot be allocated to two guests simultaneously.
- Housekeeping users cannot access salary, guest payment methods, bank proof or room secrets beyond explicit authorization.

**Migration**
- Imports January–December sheet variants using individual block mappings; correct date serial conversion and historical room aliases.
- Review flags `2221` vs `2224`, `2510` vs `2520`, historical vs current capacities, ambiguous `14Q` location.
- Repeated daily guest names are not treated as distinct confirmed bookings without evidence.
- `NO BOOKING` does not become a sale; formulas/zeros do not become cleared receipts.
- Re-running same file/import does not double the history; import rollback is reliable.
- Sensitive Wi-Fi and employee financial data do not appear in demo/public exports.

**Release:** all core workflows pass automated tests, database migrations and seed run on clean environment, smoke tests work on mobile and desktop, security checks pass, data review is documented, backup restore is tested, and owner acceptance scenarios are demonstrated end to end.

## 24. Delivery plan, priorities and definition of done

**Phase 0 — Source audit & decisions (must come first).** Inventory both workbooks by tab, map the nine rooms, identify ambiguities, build data dictionary, document assumed policies (weekend definition, per-extra-guest billing unit, standard checkout time, how external Airbnb payouts are received, hold expiry), obtain explicit approval before migrating production records.

**Phase 1 — Working foundation / MVP.** Install/validate XAMPP PHP 8.2, MySQL-compatible InnoDB, and Windows local development setup; authentication and permissions; properties and nine-room master catalog; public website; room calendar, inquiry/hold/confirm lifecycle, conflict-safe inventory, booking sources; rate engine, quote/PDF/copy-message; simple receipt tracking; essential dashboard.

**Phase 2 — Core owner operational automation.** Check-in/out, automatic housekeeping/laundry tasks, staff portal, readiness verification, charges, GCash/bank/Airbnb settlement ledger, expense recording, detailed month and room reporting, reconciliation dashboard.

**Phase 3 — Replace secondary Excel trackers.** Parking, damage incidents, maintenance, utilities, supplies/refill stock, renovation, staff advances (restricted), full historical imports and review queue, month-end export and period lock.

**Phase 4 — Integrations, hardening and launch.** Optional supported read-only iCal / authorized channel integrations, permissions/security review, load/concurrency testing, monitoring and backups, user training, cutover, and acceptance sign-off.

**Definition of done:** owner can operate day-to-day without retyping reservations into Excel; every confirmed booking has a conflict-safe assigned room and a trackable guest/financial record; the public can inquire but cannot self-confirm/pay; receipts and external payouts reconcile separately; a checked-out room generates turnover work; the owner can see accurate room, channel, collection and expense metrics **with clear caveats for unverified legacy data**; reports export cleanly; no sensitive information leaks; all critical tests pass.

## 25. Development agent output contract

**Do not stop at a high-level architecture or Figma-style static UI.** The required execution environment is XAMPP PHP 8.2 with MySQL-compatible InnoDB, not a PostgreSQL-dependent project. If placed in a coding environment, produce real, cohesive application code and working flows. Follow this ordered process:

1. Read the workbooks and source images, inspect every relevant sheet, then publish a concise **Source Audit & Gaps** section, including the documented discrepancies above and any other validated conflicts.
2. Produce a feature-to-Excel mapping, functional scope, user roles, state diagrams, data dictionary, normalized ERD, pricing and money policy sheet, information architecture and prioritized implementation plan.
3. Create Laravel 12 / PHP 8.2 / XAMPP Apache / MySQL-compatible InnoDB / Inertia-React application structure, DB migrations, role/permission policies, domain services, queue/scheduler jobs, and tests. No PostgreSQL dependency.
4. Implement and integrate the public pages and internal workflow screens with actual database persistence. No placeholder charts or hardcoded fake business metrics in production.
5. Implement safe import staging/reconciliation and optional synthetic demo dataset; keep customer information out of fixtures and logs.
6. Provide tested Windows/XAMPP PHP 8.2 setup commands, MySQL/phpMyAdmin database creation, environment variable template (no real credentials), Apache VirtualHost configured to Laravel `public`, database queue/Windows task scheduler, backup/restore/deployment instructions, and separate MySQL test-database commands.
7. Explain any missing dependencies, unavailable integrations or rules that require owner approval. Keep these items visible as **Pending decision**, not quietly assumed.
8. Show verifiable working end-to-end demos: inquiry → quote → hold → confirmation → payment verification → stay → checkout → cleaning → reconciliation → dashboard/report.
9. At the end, provide a concise completion checklist mapped to each requirement and show which features are production-ready, incomplete or blocked. Never call a nonworking integration “connected”.

### Final success statement

Build UNIQA SUITES into **one reliable, secure, elegant property-operations system** where the owner and staff can manage nine named suites, bookings from multiple channels, differentiated prices, guest charges, receipts, Airbnb settlements, housekeeping, laundry, maintenance, damage, parking, stock, expenses, and reports from a single source of truth—**without accepting unapproved online guest bookings or inventing data absent from the Excel files**.
