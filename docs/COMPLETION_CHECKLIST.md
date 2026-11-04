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
| 2 | Platform listing IDs per room | Done | Reference only, under Rooms; connects nothing to the platform |
| 5 | Statuses, holds, half-open conflicts, room locks | Done | Includes a real two-process race and a lock-mechanism test |
| 5 | Room moves, date changes, multi-room bookings | Done | The amend screen selects any set of rooms |
| 5 | Min stay, max advance, room cutoffs | Done | Off until the owner sets them under Settings or per room |
| 5, 22 | Staff search for vacant units with prices | Done | On the quotation screen; replaces public availability, see deviations |
| 5 | Calendar: monthly grid, sticky rooms, mobile list | Done | Custom-built, see deviations |
| 6 | Effective-dated rates, overrides, snapshots, early fee | Done | All brief test cases pass |
| 6 | Weekend and extra-guest rules | Done | Set from the suite flyers: Friday and Saturday, ₱200 per night. Owner to confirm in the register |
| 6 | Taxes | Done | None charged until the owner adds one. Kept out of revenue figures. Rate and method need the owner's accountant |
| 7 | Charges, receipts, verification, balances, refunds | Done | |
| 7 | Channel settlements and no double counting | Done | |
| 7 | Expenses, cashbook, period lock | Done | A locked month also freezes its stays. Corrections use **Reverse**, dated today |
| 7 | Finance approval thresholds, second reviewer | Done | Both off until the owner turns them on under Settings |
| 7 | Report basis selector, KPIs with definitions | Done | **Totals by date basis** report selects created, stay, receipt, payout or expense date |
| 7, 14 | Monthly room-by-room export resembling the old grid | Done | **Monthly room grid**: a row per day, a column per room, channel tallies below; CSV, XLSX and PDF |
| 8 | Source taxonomy, copy-ready messages | Done | All 11 templates; nothing auto-sent |
| 9 | Public site: browse suites, see taken dates, Book Now opens Facebook Messenger for free dates | Done | No prices, by owner decision, see deviations. Links to `m.me/uniqasuitescdo`; not yet tried from a real phone |
| 5 | No schedule conflicts | Done | Date pickers, a live server check, room locks and a database unique key per room night, with a dashboard alert for outside clashes. **Airbnb stays only count once its calendar is connected** |
| 9 | Galleries, map links | Partial | Map links not supplied. Photos seeded for all rooms, but they are enlarged from flyers and look soft, and Rio and Venice only have one stand-in each |
| 9 | Inquiry logging | Not needed | Inquiries arrive on Messenger and staff enter them |
| 10 | Turnover automation, inspection, laundry, photos | Done | |
| 10 | Auto-assign, start and finish times, supply use on the task | Done | Assigns by workload at a manager's click. No shift roster: none was supplied |
| 10 | Work orders and safety blocks | Done | |
| 10 | Inventory ledger, reorder alerts, restock tasks | Done | No stock seeded |
| 11 | Incidents, owner-only charge, reversal, communications log | Done | Each incident has a chronological history |
| 12 | Parking inventory, calendar, conflicts, optional charge | Done | The old parking calendar sheet is not imported: enter current assignments directly |
| 13 | Encrypted Wi-Fi vault, audited reveal | Done | Not imported; staff enter it |
| 14 | Dashboard in the prompt's priority order | Done | |
| 14 | Reports and CSV, XLSX, PDF exports | Done | 12 reports, all following the property filter; exports state their scope |
| 15 | Scheduler jobs, idempotency, notifications | Done | Per-person choices and arrival lead time. Month-end notice never locks a period. No quiet hours, see deviations |
| 16 | Integration status, read-only iCal | Partial | Tested against simulated feeds only. **Needs a real Airbnb link to confirm** |
| 17 | Import wizard: staging, flags, mapping, rerun, rollback, reconciliation | Done | Tested on the real workbook. Future stays are held on the calendar at commit; history prints in **Sales recording**. `SALARYC.A` and parking calendar not imported |
| 17 | Cutover | Blocked | Plan and rollback are in [CUTOVER.md](CUTOVER.md). Needs a date and owner sign-off |
| 18 | Roles and permissions on every route | Done | Five roles: Administrator, Owner, Operations Manager, Booking / Reception, Staff. Page access is ticked per person and enforced by the server. A password change ends that person's other sessions |
| 18 | MFA | Done | Authenticator-app codes and recovery codes, optional requirement for sensitive roles. Checked in a browser with generated codes, not yet with an app on a real phone |
| 20 | Laravel 12, PHP 8.2, Inertia, React, database queue | Done | MariaDB 10.4.32 |
| 20.3 | Setup docs, backup and restore | Partial | Restore tested. Apache virtual host tested on a separate port in production mode. `install-scheduler.ps1` **not yet run**; `schedule:run` itself works |
| 20.5 | Security review | Partial | Internal review done and findings fixed, dependency audits clean. Not an independent test, and not yet done on the real host |
| 24 | Staff training, cutover plan, data dictionary | Done | [STAFF_GUIDE.md](STAFF_GUIDE.md), [CUTOVER.md](CUTOVER.md), [DATA_DICTIONARY.md](DATA_DICTIONARY.md). Training itself is still to be held |
| 25 | Full journey demonstrated | Done | Guest to owner reports, in a real browser, one login per role, on Apache in production mode, synthetic data. Owner acceptance is still to come |
| 21 | Branding | Done | Clean badge export would be better |
| 21, 22 | Phone and accessibility pass | Done | On 19 pages with a phone profile: no controls under 44px, no inputs that trigger iPhone zoom, no automated accessibility violations. Automated tools find only part of what real users hit, so test with a real phone and screen reader |
| 23 | Automated tests | Done | 244 tests, 1,738 assertions, on MariaDB. Refuses a cached config or a database not named `_test` |

## Deviations from the prompt

| Prompt | Built instead | Why |
|---|---|---|
| Public estimates, rates and copyable inquiry (sections 6, 9, Journey A) | No prices on the public site. It shows taken dates only and sends guests to Messenger with free dates; staff quote from a vacancy search | Owner decisions, 4 November 2026 |
| Quiet hours | Not built | Notifications only appear inside the app, so nothing can interrupt anyone. Needed only if email or push is added |
| FullCalendar | Custom room timeline | The FullCalendar resource timeline is a paid product; the prompt allows another component, but this one is hand-built |
| shadcn/ui | Own components in the same style (`resources/js/components/ui.tsx`) | Smaller and matched to the brand |
| React Hook Form + Zod | Inertia `useForm` with Laravel server validation | One source of validation rules, on the server |
| `uniqa_suites` database | `db_uniquasuites` | Your instruction |

## Before go-live

Everything left needs the owner or the real host. [CUTOVER.md](CUTOVER.md) has the steps.

1. Press **Book Now** on a real phone and check it opens a chat with the UNIQA SUITES page.
2. Resolve the decisions register, especially where the flyers differ from the room list, the weekend and extra-guest rules taken from the flyers, and unit 2221.
3. Approve and publish rooms; add HD photos for Rio and Venice.
4. Turn on two-factor sign-in and test it with a real phone.
5. Host on HTTPS; set a real mail service; install the scheduler; run `php artisan uniqa:doctor` there until nothing fails.
6. Import and review the workbooks; reconcile future Airbnb bookings; test a real Airbnb calendar link; set a cutover date; train staff.
7. Owner acceptance, on phones and desktops, using the run-through in [CUTOVER.md](CUTOVER.md).
