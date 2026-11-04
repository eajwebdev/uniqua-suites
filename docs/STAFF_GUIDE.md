# Staff Guide

Find your job below. You only see the pages an Owner or Administrator ticked for you under **Team & Permissions**, so some items here may not appear. Ask them if you need another page.

## For everyone

- **Signing in:** your invitation email has a link to set your password. If two-factor sign-in is on, you also enter a code from your phone. Lost your phone? Use a recovery code, or ask the Owner to reset it.
- **Finding things:** use the search box at the top, or press Ctrl+K. Type a page, guest name or booking reference, then press Enter.
- **Phones:** the buttons along the bottom open your most-used pages. **Menu** opens the rest.
- **Bell icon:** alerts appear here, and you can choose which ones under **Account**. Nothing is ever sent to a guest automatically.
- **Messages to guests:** the system prepares the wording. You copy it and send it yourself.
- **Mistakes:** nothing is silently erased. Most errors are fixed with a new entry, and history stays.

## Reception

**Choosing rooms.** On a new booking or when changing one, choose the dates first. Taken rooms are crossed out with the reason and cannot be ticked. If new dates clash with a room already ticked, it is unticked and you are told.

**Sending a quote.** Under **Quotes & Messages**, choose the dates and the vacant units appear by themselves. Press **Quote this**, type the guest's name, then **Copy for Messenger** and paste it into the chat. A taken unit gets no price and cannot be quoted. Press **Save quote version** to keep a record. A quote does not reserve the room: place a hold or confirm the booking to secure the dates.

**Changing a quote.** Open it and press **Update quote**. Change what is needed and save: it keeps the same reference as a new version, and earlier versions stay on record.

**Dates held from the workbook.** Stays copied from the old sales workbook show on the calendar as red blocks. They protect the dates but are not bookings. Click one to release it, then enter the real booking straight away.

**Printing the old report.** **Reports > Sales recording** has the workbook's layout, room by room, as PDF or Excel. Workbook lines are labelled unverified.

**When a guest messages on Facebook.** The website shows guests no prices, so they will ask you. Go to **Quotes & Messages**, then **New quote**:
1. Enter the dates and number of guests, then press **Find vacant units and prices**.
2. You get every vacant unit with its price for that stay, cheapest first. **Not available** shows why the others cannot be offered.
3. Press **Quote this** on the unit you want, check the breakdown, and save.
4. Copy the message into Messenger, or send the PDF. Then press **Create booking** if the guest wants it.

The list shows what is vacant right now. It reserves nothing, so place a hold as soon as the guest says yes. Airbnb bookings that nobody has entered yet will not show, so enter them promptly.

**New inquiry.** Go to **Reservations**, then **New inquiry**.
- *Booking source* is where the guest came from. How they pay is recorded separately, so a Facebook guest paying by GCash has source Facebook Messenger and payment GCash.
- An inquiry does not block the room. A hold does, until it expires. Confirming blocks it for good.

**Payments.** Open **Payments**, then **Record payment**. Attach the screenshot. A payment counts only after Finance verifies it, because a screenshot does not prove the money arrived.

**Arrivals.** The dashboard lists today's arrivals, unpaid balances and rooms that are not ready. A vacant room can still be dirty: you cannot check a guest into a room that has not passed inspection.

**Departures.** Choose **Check out**. This creates the cleaning and laundry work. The room is not ready until someone inspects it.

**Inspecting rooms.** Open **Housekeeping**, look under **Awaiting inspection**, then choose **Pass** or **Fail**.

**Changing a booking.** Choose **Change dates, room or party**. The system rechecks every room and may change the price. If the stay has nights in a locked month, it cannot be changed: ask the Owner or Finance to add an adjustment.

**Damage.** Report it under **Maintenance & Damage**, with photos. Reporting never charges the guest. Log what you say to the guest under the incident's **History**.

## Finance

**Each day**
- **Verify receipts** under **Payments**, after checking the money really arrived.
- **Clear exceptions** under **Reconciliation**: missing proof, unmatched amounts, duplicate references and payouts that have not arrived.
- **Record channel payouts** under **Channel settlements**. Keep the platform gross, fees and the actual payout separate. An Airbnb guest having paid does not mean the money has reached you.

**Expenses.** Allocate each one to a room, a property or general, and mark whether it is paid. An unpaid bill counts as an expense but not as cash out.

**Fixing a mistake.** Use **Reverse** on the receipt or expense. This adds a new opposite entry dated today, and the original stays on file. Use this for locked months as well.

**Reports.** Each figure states its date basis: stay, receipt, payout, created or expense date. They are not expected to add up across bases. Start with **Monthly room grid**, which reads like the old sheet: a blank cell is a vacant night, not a zero sale.

## Housekeeping and laundry

1. Open **Housekeeping** on your phone. You see your tasks, and any that nobody has been assigned.
2. Press **Start** when you begin.
3. Press **Finish**, tick the checklist, and add photos.
4. Under **Supplies used**, add what you used. Stock only goes down for what you record here.
5. **Send for inspection.** The room stays not-ready until someone passes it. If it fails, the task returns to you with the reason.

Report anything broken to your manager, so it becomes a work order.

## Maintenance

Open **Work orders**. You see the jobs assigned to you. Update the status and add the cost and notes. A safety-critical job stops the room being booked or marked ready until it is resolved.

## Operations Manager

- **Assign cleaning** from **Housekeeping**, one task at a time, or use **Assign by workload** to give each task to whoever has the fewest.
- **Laundry:** add quantity, provider and cost to each suggested job.
- **Block a room** under **Rooms** for repairs or owner use, with a reason.
- **Parking:** the **Calendar** tab shows each stall by month. The system refuses two holders for the same stall and dates.

## Owner and Administrator

**Giving someone access.** Go to **Team & Permissions** and click their name, or press **Invite staff**. Choose a role, which ticks its usual pages, then tick or untick any page or extra action for that person and save. Unticked pages are refused even if someone types the address. Owners and Administrators always have everything. Only an Owner can change an Owner.


**Daily:** read the dashboard from the top. **Action needed** comes first, including a warning if the scheduler has stopped.

**Decisions only you can make:**
- Approving or reversing damage charges, and approving refunds. The system only records a refund you have already sent.
- Locking a month, under **Reconciliation**, after you review it. You get a reminder on the 1st, but nothing locks by itself.
- Settings: prices, weekend nights, taxes, stay limits and policies. New values never change a confirmed booking's saved price.

**Staff changes:** under **Team & Permissions**, deactivating someone removes their access on their next click.

**Backups:** run them regularly and copy them off the PC. Keep a separate copy of `APP_KEY`, because Wi-Fi details and two-factor secrets cannot be read without it.

**Health check:** `php artisan uniqa:doctor` lists anything unsafe or not running.
