# Completion Checklist

Status as of 4 November 2026, mapped to the sections of the build prompt.

- **Done**: works end to end and is covered by automated tests or a browser check.
- **Partial**: the core works; the listed part is missing.
- **Blocked**: needs something from the owner.

Nothing here is "production-ready" yet: see [Before go-live](#before-go-live).

| § | Requirement | Status | Notes |
|---|---|---|---|
| 1, 25 | Source audit and decisions register | Done | [SOURCE_AUDIT.md](SOURCE_AUDIT.md); register under Settings |
| 2 | Nine-room catalog, proposed until approved | Done | Publish needs approval first |
| 2 | Platform listing IDs per room | Partial | Table exists, no screen; use the booking's source reference |
| 5 | Statuses, holds, half-open conflicts, room locks | Done | Includes a real two-process race and a lock-mechanism test |
| 5 | Room moves, date changes, multi-room bookings | Partial | Engine and tests cover multi-room; the amend screen moves to a single room |
| 5 | Min stay, max advance, room cutoffs | Not built | No approved policy exists yet |
| 5 | Calendar: monthly grid, sticky rooms, mobile list | Done | Custom-built, see deviations |
| 6 | Effective-dated rates, overrides, snapshots, early fee | Done | All brief test cases pass |
| 6 | Weekend and extra-guest rules | Blocked | Off until the owner decides, by design |
| 6 | Taxes | Not built | Prompt says only if configured; no rules supplied |
| 7 | Charges, receipts, verification, balances, refunds | Done | |
| 7 | Channel settlements and no double counting | Done | |
| 7 | Expenses, cashbook, period lock | Done | Reversal entries are manual; there is no adjustment workflow screen |
| 7 | Finance approval thresholds, second reviewer | Not built | Expense approval exists without thresholds |
| 7 | Report basis selector, KPIs with definitions | Done | KPIs show each basis; no single selector control |
| 7, 14 | Monthly room-by-room export resembling the old grid | Not built | Nightly ledger export exists, in list form |
| 8 | Source taxonomy, copy-ready messages | Done | All 11 templates; nothing auto-sent |
| 9 | Public site, filters, indicative availability, inquiry helper | Done | Works without photos and contacts |
| 9 | Galleries, map links, Messenger link | Blocked | Upload and config are ready; content not supplied |
| 9 | Inquiry logging | Not built | Deliberately: inquiries are entered by staff |
| 10 | Turnover automation, inspection, laundry, photos | Done | |
| 10 | Shift-based auto-assignment, clock-in/out, supply use on the task | Partial | Manager assigns; start and finish times recorded; supplies logged as stock movements |
| 10 | Work orders and safety blocks | Done | |
| 10 | Inventory ledger, reorder alerts, restock tasks | Done | No stock seeded |
| 11 | Incidents, owner-only charge, reversal | Done | Communications log is the audit log, not a threaded timeline |
| 12 | Parking inventory, conflicts, optional charge | Done | Parking calendar not imported |
| 13 | Encrypted Wi-Fi vault, audited reveal | Done | Not imported; staff enter it |
| 14 | Dashboard in the prompt's priority order | Done | |
| 14 | Reports and CSV, XLSX, PDF exports | Partial | 7 reports; utilities and parking have no dedicated report |
| 15 | Scheduler jobs, idempotency, notifications | Partial | No quiet hours or per-user subscriptions; month-end report preparation not automated |
| 16 | Integration status, read-only iCal | Partial | Tested against simulated feeds only, not a real Airbnb URL |
| 17 | Import wizard: staging, flags, mapping, rerun, rollback, reconciliation | Done | Tested on the real workbook; `SALARYC.A` and parking calendar not imported |
| 17 | Cutover | Blocked | Needs a date and owner sign-off |
| 18 | Roles and permissions on every route | Done | Sensitive access tested per role |
| 18 | MFA | Not built | Should be added before internet exposure |
| 20 | Laravel 12, PHP 8.2, Inertia, React, database queue | Done | MariaDB 10.4.32 |
| 20.3 | Setup docs, backup and restore | Partial | Restore tested. Apache virtual host and Task Scheduler documented but not exercised |
| 21 | Branding | Done | Clean badge export would be better |
| 23 | Automated tests | Done | 58 tests, 338 assertions, on MariaDB. Refuses a cached config or a database not named `_test` |

## Deviations from the prompt's stack

| Prompt | Built instead | Why |
|---|---|---|
| FullCalendar | Custom room timeline | The FullCalendar resource timeline is a paid product; the prompt allows another component, but this one is hand-built |
| shadcn/ui | Own components in the same style (`resources/js/components/ui.tsx`) | Smaller and matched to the brand |
| React Hook Form + Zod | Inertia `useForm` with Laravel server validation | One source of validation rules, on the server |
| `uniqa_suites` database | `db_uniquasuites` | Your instruction |

## Before go-live

1. Fix the database login and run the first-run steps in the README.
2. Resolve the decisions register, especially weekend days, extra-guest billing and unit 2221.
3. Approve and publish rooms; upload photos; supply official contact links.
4. Add MFA, and run a security review for internet exposure.
5. Host on HTTPS with `APP_DEBUG=false`; set a real mail driver; test the Apache and Task Scheduler setup.
6. Import and review historical data; reconcile future Airbnb bookings; set a cutover date; train staff.
7. Owner acceptance, on phones and desktops, of the full journey.
