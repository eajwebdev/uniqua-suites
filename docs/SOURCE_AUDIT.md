# Source Audit & Gaps

Findings from reading both workbooks and the logo files on 4 November 2026. This document holds aggregates only: no guest, staff, contact or Wi-Fi values. The workbooks themselves are excluded from version control.

## What was supplied

| File | Contents | How it is used |
|---|---|---|
| `UNIQA_ROOM LIST.xlsx` | `ROOM DETAILS`, `WIFI DETAILS`, `PARKING DETAILS`, `2026 Parking Calendar` (Aug to Dec) | Nine rooms and rates seeded as proposed. Stall IDs and proposed rates seeded. Wi-Fi and vendor contacts **not** seeded: staff enter them under Rooms and Parking |
| `UNIQA SALES RECORDING 2026.xlsx` | 12 monthly tabs, `CLEANING-NEW`, `LAUNDRY`, `UTILITIES`, `DAMAGE REQUEST`, `RENOVATION`, `SALARYC.A`, `SAMPLE`, empty `REFILLS` and `REFILLS INVENTORY` | Staged through the import wizard. `SALARYC.A` is deliberately not imported |
| Three logo files | Stacked "Management System" mark, wide wordmark, UA monogram badge | Cropped of surrounding margin for the web; artwork unchanged |

## Trial import of the real sales workbook

Run on a scratch database and then wiped. Nothing was loaded into `db_uniquasuites`.

| Measure | Result |
|---|---|
| Rows staged | 1,722: 1,362 daily sales, 40 no-booking, 299 cleaning, 20 damage, 1 utility |
| Date range | 1 January to 31 December 2026 |
| Bookings, receipts or guests created | 0 |
| Re-import of the same file | 0 new rows, all detected as duplicates |
| Rows linked to a current room | 1,451, with 271 unlinked pending owner decisions |
| Monthly room blocks read | 58 |
| Blocks where rows sum exactly to the sheet's own total | 38 |
| Blocks that differ | 20, including 4 whose sheet total is 0 |

| Flag | Rows or blocks |
|---|---|
| `missing_checkout_date` | 1,362 |
| `unverified_payout_or_cash_receipt` | 954 |
| `legacy_or_placeholder_value` | 408 |
| `possible_multi_day_duplicate` | 322 |
| `unknown_or_conflicting_room` | 263 |
| `historical_unlisted_room` | 230 |
| `booking_origin_vs_payment_source_conflict` | 159 |
| `future_or_unconfirmed_record` | 122 |
| `formula_vs_manual_total_mismatch` | 52 |
| `capacity_or_rate_conflict` | 31 |
| `unmatched_damage_or_cleaning_entry` | 20 |
| `ambiguous_date_or_room` | 0 |

## Conflicts for the owner to resolve

All of these are listed in the app under **Settings > Assumptions and decisions register**, and room labels are resolved once under **Import & Data Quality**.

1. **What is unit 2221?** January to June sheets head it `LOOP 2221 - STUDIO`, and it stops in July, when `2520 - STUDIO` first appears. The brief suggests it may be NEW YORK 2224, but that is a **2-bedroom**. The sheets fit BALI 2520 or a separate former unit better. Its 161 sales rows stay unlinked until confirmed.
2. **2510 vs 2520.** The parking calendar reads "UNIQA BALI, 25TH FLOOR - 2510". Every other sheet uses 2520.
3. **14Q location.** Sales headings say `LOOP 14Q`, and the parking calendar says "UNIQA BERLIN, 14H FLOOR". The room list places BERLIN T3, 14Q at Mesaverte. Note the floor: 14H on the parking calendar versus 14Q.
4. **Capacities.** Headings disagree with the room list: Tokyo 1725 says 7 (list 5), Venice 1410 says 5 (list 4), Bali 2520 says 5 (list 4), Rio says 4 (list 2).
5. **Room labels needing a manual match.** `918` (missing its leading zero), and blank or `Mesaverte` units on damage rows.
6. **Block totals that do not match.** April Mesaverte, June 2221, July 2520 and August Mesaverte show a sheet total of 0 with sales in the rows. September 1725 differs by about ₱14,148. See the reconciliation table on the import screen; these are reported, not corrected.

## Other findings

- `LAUNDRY` has date rows and monthly totals, but every value is 0. There is no historical laundry cost to import.
- `UTILITIES` has one value in the entire sheet, and `REFILLS` tabs are empty, so no stock or utility history is created.
- `CLEANING-NEW` switches from real dates to typed text such as "Sept 1 2026" partway through the year. Cells marked `RENO` are kept as flagged text, not costs.
- Three different monthly layouts exist. Each block's columns are found from its own header row, not fixed positions.
- `2026 Parking Calendar` covers August to December only, and one cell holds a broken `=AI(...)` formula. It was reviewed but is **not imported**: enter current parking assignments directly.
- One Loop stall in `PARKING DETAILS` has no stall ID and was not seeded. Add it under Parking.
- `ROOM DETAILS` lists no link for NEW YORK and misspells a type as `1 BEDROM`; the seed reads it as 1 Bedroom.
- Sheet labels such as "TOTAL NET PROFIT" are not used as definitions. Metrics in this system have explicit definitions shown beside them.
- The badge PNG has stray coloured pixels outside its border, from its original background removal. The web copy clips them. **A clean export from your designer would be better.**

## Not supplied, so not invented

Official Messenger, Instagram, Facebook and contact details; room photos; street address, maps and amenities; standard check-out time and late fee; Airbnb fee and payout schedule; cancellation policy; tax rules; a cutover date. The public site works without these and shows nothing false in their place.
