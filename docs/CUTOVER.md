# Cutover: moving from Excel to UNIQA SUITES

For the owner and whoever runs the changeover. Work through it in order. Pick a quiet day for cutover, with no large group arriving.

## 1. Before the date

Do these in the system, under **Settings** and **Rooms**, unless noted.

- [ ] Confirm the weekend nights (Friday and Saturday, from the suite flyers). **If they are cleared, staff see vacant units without prices and cannot create quotes.**
- [ ] Confirm the extra-guest fee (₱200 per night, from the suite flyers).
- [ ] Set the check-out time and late fee, if you charge one.
- [ ] Write the cancellation policy and house rules. Add any taxes after checking with your accountant.
- [ ] Work through the rest of the **Assumptions and decisions register**.
- [ ] Connect each room's Airbnb calendar under **Settings**. **Until you do, Airbnb stays are not shown as taken on the website**, and staff must enter them by hand.
- [ ] Check all nine rooms against reality, including where the flyers and the room list differ (see the register), then approve and publish each one. Replace the stand-in flyer photos for Rio and Venice.
- [ ] Add real parking stalls and rates, opening stock counts, and Wi-Fi details.
- [ ] On the real server, set `UNIQA_MESSENGER_URL=https://m.me/uniqasuitescdo` in `.env`. **The website's Book Now buttons stay hidden without it.** Press one on a phone to check it opens a chat with your page.
- [ ] Create a login for each staff member. Turn on two-factor sign-in for yourself, then make it mandatory under **Settings**.
- [ ] Take the system through the [acceptance run-through](#acceptance-run-through).
- [ ] On the real server, run `php artisan uniqa:doctor` and fix anything marked FAIL.

## 2. Import history

History is kept separate from live data. It never creates bookings, receipts or guests.

1. The former workbook import screen is retired. Keep the original sales workbook as an offline source record and enter every future stay as a normal reservation before go-live.
2. Review the flagged rows. Decide what each room label means, especially 2221, 2510 and 14Q.
3. Compare the month and room totals with the workbook. Differences are shown, not corrected.
4. Commit the import. If it looks wrong, roll it back: re-importing the same file adds no duplicates.

## 3. Cutover day

Pick the cutover date and make one person responsible for the calendar that day.

1. **Stop new Excel entries.** Tell staff the time in advance.
2. **Save the workbooks** somewhere read-only, and keep them unchanged.
3. **Back up the system** with `scripts\backup.ps1`, and copy the backup off the PC.
4. **Check every future stay is held.** Committing the workbook blocks its future dates on the calendar and website. The import screen warns if any room label is unmatched. For each hold on the calendar, click it to release, then at once enter the real booking:
   - Enter it under its real source, with the Airbnb reservation code as the reference.
   - Confirm it. If a room conflict appears, stop and resolve it: it may be a real double booking.
   - Record only payments you can actually verify.
5. **Check the calendar against each channel,** room by room, for at least the next 90 days. Have a second person check it.
6. **Add Airbnb calendar links** under **Settings**, if you have them. They are read-only and can lag, so keep checking Airbnb directly at first.
7. **Check the scheduler is running.** The dashboard warns you if it is not.
8. **Announce it:** from now on the system is the only record.

## 4. First two weeks

- Each day, compare the calendar with Airbnb and with Messenger conversations.
- Each day, clear the dashboard's **Action needed** list.
- At the end of week one, check reconciliation and that a backup restores.
- Do not lock a month until you have reviewed it. Corrections to a locked month are new entries, dated today.

## If you need to go back

Decide this quickly, and only for a serious problem, such as wrong availability that cannot be fixed the same day.

1. Stop taking bookings in the system, and tell staff.
2. Export everything entered since cutover: **Reports**, nightly ledger and collections.
3. Go back to the saved workbooks, and type the new stays and payments into them.
4. Keep the system's data. Nothing needs deleting, and you can come back to it after the problem is fixed.

To restore a backup, use `scripts\restore.ps1`. It restores into a new, empty database and refuses to overwrite one in use, so you can look through it before switching.

## Acceptance run-through

Use a test copy, or clearly fake guest names you cancel afterwards. Do it once on a computer and once on a phone.

| # | Who | Do this | You should see |
|---|---|---|---|
| 1 | Guest | Browse the suites, then press **Book Now** on one and choose dates | Taken dates cannot be chosen; free dates open your Messenger chat; no prices anywhere on the site |
| 2 | Reception | Under **Quotes**, enter dates and guests, choose **Quote this** on a vacant unit, then save | Vacant units with prices, then a copy-ready message and a PDF; nothing sent or reserved |
| 3 | Reception | Create a booking and place a hold | The room shows as held on the calendar |
| 4 | Reception | Try to hold the same room and dates again | Refused, with the reason |
| 5 | Reception | Record a GCash payment | The balance does not fall yet |
| 6 | Reception | Confirm the booking | Price is locked |
| 7 | Finance | Verify the payment | The balance falls |
| 8 | Reception | Check the guest in, then out | The room turns dirty; cleaning and laundry appear |
| 9 | Housekeeping | Start and finish the clean, with photos and supplies | It waits for inspection; no guest payment details visible |
| 10 | Reception or manager | Pass the inspection | The room becomes ready |
| 11 | Owner | Open the dashboard and monthly room grid | The stay and money appear, each with its date basis |
| 12 | Owner | Report damage, approve a charge, then reverse it | No charge until you approve; history is kept |
| 13 | Owner | Export a report as PDF and Excel; lock a test month | Files open; a stay in that month can no longer be changed |
