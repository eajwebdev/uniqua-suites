# System Design

## Excel to system

| Excel | Becomes | Screen |
|---|---|---|
| Monthly room blocks | `bookings`, `booking_rooms`, `booking_nights`, `booking_charges` | Reservations, Calendar |
| FACEBOOK / AIRBNB / OUTSIDE BOOKING tallies | `booking_sources` and live channel reports | Reports |
| NO BOOKING | Nothing: a night with no booking is simply vacant | Calendar |
| GCASH and payment columns | `receipts` (method, account, proof, verification), kept separate from source | Payments |
| Airbnb amounts and BPI context | `external_settlements` (gross, fees, expected, actual, payout date) | Channel settlements |
| CLEANING-NEW | `housekeeping_tasks`, created by checkout | Housekeeping |
| LAUNDRY | `laundry_jobs`, suggested at checkout | Laundry |
| UTILITIES, expense columns | `expenses` by category and room | Expenses |
| DAMAGE REQUEST | `incidents`, `incident_evidence` | Damage & incidents |
| RENOVATION | `work_orders`, `inventory_items` | Maintenance, Inventory |
| SALARYC.A | `employees`, `staff_cash_advances`, `staff_repayments` (restricted) | Staff Advances |
| PARKING DETAILS and calendar | `parking_spaces`, `parking_assignments` | Parking |
| WIFI DETAILS | `room_secrets` (encrypted, reveal is audited) | Room edit |
| All of the above, historically | `legacy_rows`, `import_anomalies`, `import_mappings`: never mixed with live data | Import & Data Quality |

## State flows

```
Booking
  inquiry ──hold──> tentative ──confirm──> confirmed ──check in──> checked_in ──check out──> checked_out
     │                 │  │                   │  │
     │                 │  └─expiry─> expired_hold (can be re-held)
     └──confirm────────┼───────────────────^  └──no-show──> no_show
     └──cancel─────────┴──cancel──> cancelled <──cancel──┘

Blocks inventory: confirmed, checked_in, tentative with an unexpired hold, and room_blocks.
Does not block: inquiry, cancelled, no_show, expired_hold, checked_out.

Housekeeping (separate from availability; checkout never makes a room ready)
  ready ──checkout──> dirty ──assign──> assigned ──start──> cleaning ──finish──> for_inspection ──pass──> ready
                        ^─────────────────────fail────────────────────────────────────┘

Receipt:   pending ──> verified | rejected        Only verified money reduces a balance.
Refund:    requested ──> approved ──> executed    Executed records money already sent; the system pays nothing.
           requested ──> rejected
Incident:  reported | evidence_pending | under_review ──owner approves──> approved_for_charge
           approved_for_charge ──owner reverses──> disputed       Only the owner decision creates a charge.
Import:    staged ──commit──> committed           staged or committed ──rollback──> rolled_back
```

## Inventory safety

`InventoryService` is the single gate. Each write path:

1. Opens a transaction and locks the physical `rooms` rows with `SELECT ... FOR UPDATE`, in ascending ID order.
2. Rechecks conflicts inside that transaction with `existing_start < requested_end AND existing_end > requested_start`, so a same-day check-out and check-in is allowed.
3. Writes everything, then commits. Deadlocks and lock timeouts (1213, 1205) retry up to three times.

Locking rooms rather than bookings means it still serializes when no booking exists yet. Readiness is a separate check at check-in, so a back-to-back arrival is bookable but cannot check into a dirty room. Parking uses the same pattern on `parking_spaces`.

## Money

- All amounts are integer centavos (`BIGINT`, columns ending `_cents`). Pesos are parsed from strings without passing through a float.
- On confirmation or amendment, engine charges are re-created under a new `pricing_version` and an immutable `pricing_snapshots` row is written. Old lines are voided, not deleted. Manually added charges survive repricing.
- Future rate changes add a new `rate_rules` row. Past bookings keep their stored nightly rates.
- `balance = active charges − verified receipts − settled channel gross + executed refunds`.
- Channel payouts live only in `external_settlements`, and receipts have no remittance method, so a booking's gross and its payout cannot both count as cash.

### Metric definitions

Shown beside the numbers in the app, and defined in `ReportService::DEFINITIONS`.

| Metric | Definition | Date basis |
|---|---|---|
| Booked revenue | Charges on non-cancelled, confirmed-or-later reservations | Created date |
| Earned accommodation | Nightly rates on occupied nights, before discounts | Stay date |
| Gross operating revenue | Earned accommodation plus other charges | Stay and charge date |
| Collected cash | Verified receipts + actual payouts − executed refunds | Receipt and payout date |
| Outstanding | Charges on active and completed stays less applied payments | As of today |
| Operating expenses | All recorded expenses, paid or not; unpaid shown separately | Expense date |
| Operating contribution | Gross operating revenue − expenses. **Not audited net income** | Mixed |
| Occupancy | Occupied room nights / (room nights − blocked nights) | Stay date |
| ADR | Earned accommodation / occupied room nights | Stay date |
| RevPAR | Earned accommodation / sellable room nights | Stay date |

These use different date bases, so they are not expected to add up across cards.

## Schema

69 InnoDB tables in `utf8mb4`, managed by the migrations in `database/migrations`.

```
properties 1─* rooms *─1 room_types          rooms 1─1 room_secrets (encrypted)
rooms 1─* room_images, room_blocks, room_status_events, rate_rules, rate_overrides
guests 1─* bookings *─1 booking_sources      bookings 1─* booking_rooms *─1 rooms
bookings 1─* booking_nights, booking_charges, pricing_snapshots, booking_status_events
bookings 1─* quotes 1─* quote_items
bookings 1─* receipts, refunds, external_settlements
expenses *─1 expense_categories, and optionally rooms, properties, bookings, vendors
rooms 1─* housekeeping_tasks 1─* task_events, task_attachments
rooms 1─* laundry_jobs, work_orders, incidents 1─* incident_evidence
inventory_items 1─* inventory_movements      stock = SUM(quantity) per property
parking_spaces 1─* parking_assignments
employees 1─* staff_cash_advances, staff_repayments
import_batches 1─* legacy_rows 1─* import_anomalies     import_mappings: label → room
users, roles, permissions (spatie), notifications, activity_logs, owner_decisions, application_settings, integration_health
```

Money and history use restrict-on-delete foreign keys, so a finance or audit record cannot be erased by removing its parent. Timestamps are UTC; property dates are local `DATE` columns; screens display Asia/Manila.

## Roles

| Role | Scope |
|---|---|
| Owner | Everything |
| Operations Manager | Rooms and bookings operations, housekeeping, maintenance, incidents; no finance dashboards |
| Booking / Reception | Inquiries, quotes, confirm, check-in and out, record payments; no verification or discounts |
| Finance / Bookkeeper | Payments, verification, settlements, expenses, reports; cannot change rooms |
| Housekeeping / Laundry | Own and unassigned tasks; no guest, payment or salary data |
| Maintenance | Own work orders only |
| Staff Advances / Payroll | Advances only |
| Auditor | Read-only reports and audit log |

Permissions are enforced by route middleware plus per-record checks on tasks and work orders, and pages serialize explicit fields rather than whole models. The role definitions are in `app/Support/Permissions.php`.
