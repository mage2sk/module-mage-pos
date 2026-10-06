# Panth MagePos - Cashier User Guide

This guide walks you through **every flow on the POS terminal**, in the order you'll meet them during a shift. It is written for cashiers - no Magento knowledge required. Examples use a fictional shop, **Acme Store** (`example.com`).

> Some buttons described here may be hidden for you. What you can do (refunds, discounts above a limit, opening the register, editing the layout, ...) depends on the **role** your manager assigned to your POS account.

---

## Table of Contents

1. [Opening the Terminal & Logging In](#1-opening-the-terminal--logging-in)
2. [PIN Lock & Unlock](#2-pin-lock--unlock)
3. [Opening a Register Session](#3-opening-a-register-session)
4. [The Terminal Screen](#4-the-terminal-screen)
5. [Adding Products to the Cart](#5-adding-products-to-the-cart)
6. [Editing Cart Lines](#6-editing-cart-lines)
7. [Custom Products (Ad-hoc Sale Lines)](#7-custom-products-ad-hoc-sale-lines)
8. [Customers](#8-customers)
9. [Discounts & Coupons](#9-discounts--coupons)
10. [Multiple Carts & Holding a Sale](#10-multiple-carts--holding-a-sale)
11. [Taking Payment (Checkout)](#11-taking-payment-checkout)
12. [Receipts](#12-receipts)
13. [Refunds & Returns](#13-refunds--returns)
14. [Cash In / Cash Out](#14-cash-in--cash-out)
15. [X Report (Mid-Shift Check)](#15-x-report-mid-shift-check)
16. [Closing the Session (Z Report)](#16-closing-the-session-z-report)
17. [Offline Mode](#17-offline-mode)
18. [Personalising Your Terminal (Layout & Theme)](#18-personalising-your-terminal-layout--theme)
19. [Logging Out](#19-logging-out)
20. [Quick Reference Card](#20-quick-reference-card)

---

## 1. Opening the Terminal & Logging In

1. On the till device, open the browser and go to **`https://example.com/pos`** (your manager will give you the exact address - it's your shop's website address followed by `/pos`).
2. The login screen appears. Enter your **username** and **password**, pick your **register** (e.g. *Main Register*) if more than one is listed, and tap **Log In**.
3. If a register session is already open on this register, you land straight on the selling screen. Otherwise you'll be asked to open one (see [section 3](#3-opening-a-register-session)).

> **Note for managers:** a fresh installation ships with **no working login**. The seeded `admin` user is disabled and has no usable password or PIN - set a strong password and a PIN for it (and enable it) in `Admin -> Point of Sale (MagePos) -> POS Users` before anyone can sign in.

If your login fails, check CAPS LOCK and ask your manager to verify your account is active.

---

## 2. PIN Lock & Unlock

To protect the till, the terminal **locks itself automatically** after a few minutes without input (your manager configures how many). You'll see a lock overlay with a number pad.

- **To unlock:** type your **PIN** (usually 4 digits) on the keypad. You're back exactly where you left off - the cart is untouched.
- You can also lock the terminal manually any time you step away (lock button in the header).
- If you forget your PIN, log in again with your full username and password, or ask your manager to set a new PIN.

The PIN only works for the person who was already logged in - a colleague taking over must log in with their own account.

---

## 3. Opening a Register Session

Before you can sell, the register needs an **open session** - this is how the system tracks the cash in your drawer.

1. When prompted (or via the **Session** panel -> **Open Session**), count the cash already in the drawer.
2. Enter that amount as the **Opening Float** (e.g. `200.00`).
3. Optionally add a note (e.g. "Saturday morning, drawer counted by Sam").
4. Tap **Open Session**.

From now on, every cash sale, refund, and cash movement is recorded against this session until it's closed.

> If the terminal says you don't have permission to open a session, ask a manager - opening/closing is often reserved for supervisors.

---

## 4. The Terminal Screen

The default layout (yours may differ - layouts are personal):

- **Catalog panel** (left) - search box, category browser, and product results
- **Cart panel** (right) - current sale: lines, quantities, totals, and the **Pay** button
- **Quick Keys panel** - one-tap tiles for your shop's most-sold products
- **Customer panel** - attach or create a customer for this sale
- **Holds panel** - parked sales waiting to be resumed
- **Session panel** - register status, cash in/out, X report, close session
- **Header bar** - cart tabs, your name, online/offline indicator, lock, settings, logout

---

## 5. Adding Products to the Cart

There are four ways - use whichever is fastest:

### a) Scan a barcode
Just scan. The scanner types the code and the product jumps into the cart (quantity +1 if it's already there). The terminal listens for scans all the time - you don't need to tap anything first.

### b) Camera scan
On tablets without a scanner gun, tap the **camera icon** in the catalog panel and point the camera at the barcode (works on supported browsers).

### c) Search
Type part of the product **name or SKU** into the search box. Results appear as you type - tap a product to add it.

### d) Quick Keys & categories
Tap a **quick key tile** for one-tap add of popular items, or browse the **category tree** and tap products from the listing.

If the customer buys 3 of the same item, either scan it 3 times or change the quantity on the line (next section).

---

## 6. Editing Cart Lines

Tap a line in the cart to work with it:

- **Quantity:** use the **+ / −** buttons or tap the quantity to open the keypad and type the exact number.
- **Remove line:** tap the remove (✕ / bin) control on the line.
- **Line note:** add a note to the line (e.g. "gift wrap"), printed with the order.
- **Line discount / price override:** see [Discounts](#9-discounts--coupons).
- **Clear cart:** the clear-cart button empties the whole sale (you'll be asked to confirm).

The totals box always shows subtotal, discounts, tax, and the grand total - updated live by the server, so what you see is exactly what will be charged.

---

## 7. Custom Products (Ad-hoc Sale Lines)

For something that isn't in the catalog (a repair fee, a market-stall item, a one-off service):

1. Tap **Custom Product** (in the cart panel).
2. Enter a **name** (this prints on the receipt - e.g. "Watch battery replacement"), the **price**, the **quantity**, and pick a **tax class** if asked.
3. Tap **Add** - it appears in the cart like any other line.

> If you don't see the Custom Product button, your role doesn't allow it - ask a manager.

---

## 8. Customers

Sales work fine as **guest** sales - you don't have to attach a customer. Attach one when they want the order on their account, an emailed receipt, or their **member pricing**:

### Find an existing customer
1. In the **Customer** panel, type a name, email, or phone number.
2. Tap the right match. Their name shows on the cart, and any **customer-group pricing** is applied automatically - prices in the cart may update.

### Create a new customer
1. Tap **New Customer**.
2. Enter first name, last name, email (and phone if you have it).
3. Tap **Create** - they're saved to the store and attached to this sale.

### Detach
Tap the ✕ next to the attached customer to go back to a guest sale.

---

## 9. Discounts & Coupons

> Every discount is checked against **your personal limit** (set by your role). If you try to give more than allowed, the terminal refuses - call a manager to apply it under their login.

### Whole-cart discount
1. Tap **Discount** in the cart totals area.
2. Choose **Percent** (e.g. `10` for 10%) or **Fixed** (e.g. `5.00` off).
3. Confirm - the discount shows as its own line in the totals. Tap it again to change or **remove** it.

### Single-line discount
1. Tap the line -> **Discount**.
2. Choose percent or fixed amount for just that item. The receipt shows the original price and the discount.

### Price override
1. Tap the line -> **Override Price** (only visible if your role allows it).
2. Type the new price. The original price stays visible on the receipt.

### Coupon codes
1. Tap **Coupon** and type the code the customer gives you (e.g. `WELCOME10`).
2. Valid codes apply instantly using the store's normal promotion rules. Tap **Remove** to take it off.

---

## 10. Multiple Carts & Holding a Sale

### Cart tabs
You can serve several customers at once. Use the **+ tab** in the header to open a fresh cart and switch between tabs freely - each keeps its own items, customer, and discounts.

### Hold (park) a sale
Customer forgot their wallet in the car?

1. Tap **Hold** in the cart panel.
2. Give it a **label** you'll recognise ("Red jacket lady", "Phone order Mr. Jones").
3. The cart is parked and the terminal is free for the next customer.

### Retrieve a held sale
1. Open the **Holds** panel - every parked sale is listed with its label and time.
2. Tap **Restore** to load it back into a cart, or **Delete** if it's no longer needed.

Holds survive logouts and are shared on the register, so a colleague can resume a sale you parked.

---

## 11. Taking Payment (Checkout)

1. Tap the big **Pay** button. The checkout screen shows the **amount due** and the available payment methods.

### Cash
1. Tap **Cash**.
2. Enter what the customer hands you - type it on the keypad or tap a **denomination shortcut** (e.g. the 50 button when they give you a 50 note).
3. The screen shows **Change Due** instantly. Tap **Complete Sale**, give the change, done. (The drawer pops automatically if configured.)

### Card / other offline methods
1. Tap **Card** (or Check, Bank Transfer, ... - whatever your store has set up).
2. Take the payment on your physical card terminal as usual.
3. If a **reference** field appears, it's mandatory - type the approval/transaction code from the card terminal slip.
4. Tap **Complete Sale**.

### Payment link / QR (online methods)
1. Tap the online method (e.g. **Payment Link**).
2. Tap **Complete Sale** - the terminal shows a **QR code / link** for the customer to scan and pay on their phone.
3. The order is recorded as *awaiting payment* and is completed once the payment arrives.

### Split payment
Customers can pay with **several methods on one sale**:

1. Add the first payment row (e.g. **Cash - 20.00**).
2. Tap **Add Payment** and add the next (e.g. **Card - remaining balance** - the terminal pre-fills what's left).
3. Repeat as needed. The **Remaining** counter must reach zero (cash may go over - the excess becomes change).
4. Tap **Complete Sale**.

> The terminal won't let you complete a sale that doesn't add up - if the button is greyed out, check the Remaining amount.

---

## 12. Receipts

After every sale the **receipt screen** appears:

- **Print** - sends the 80 mm receipt to the printer via the normal print dialog (usually one tap if the printer is set as default).
- **Email** - type or confirm the customer's email and tap Send. (If a customer with an email was attached, it's pre-filled; your store may also email automatically.)
- **Reprint** - need a copy later? Find the order via **Orders / Refunds** search and print the receipt again.

Each receipt carries a unique receipt number like `main-12-45` (register - session - sale number) - useful when a customer phones about a purchase.

---

## 13. Refunds & Returns

> Requires refund permission. If you don't see the Refunds button, call a manager.

1. Open **Orders / Refunds** and find the order: scan/enter the **receipt or order number**, search by the **customer's email**, or pick from the register's **recent sales** list.
2. Open the order and select **which items** and **what quantity** the customer is returning.
3. Choose **how to refund** - e.g. cash back from the drawer, or back onto their card (handled on your card terminal, recorded here). Refunds can be split just like payments.
4. Set the **Restock** toggle: ON if the item goes back on the shelf, OFF if it's damaged.
5. Enter a **reason** (e.g. "wrong size") and tap **Refund**.

The refund creates an official credit note, and any cash given back is automatically deducted from your drawer's expected cash.

---

## 14. Cash In / Cash Out

Whenever money moves in or out of the drawer **without a sale**, record it - otherwise your end-of-day count won't match.

- **Cash In** (adding money): e.g. fetching extra change from the safe. Session panel -> **Cash In** -> amount -> reason ("Change from safe") -> confirm.
- **Cash Out** (removing money): e.g. paying the window cleaner, or a cash lift to the safe. Session panel -> **Cash Out** -> amount -> reason -> confirm.

Every movement is listed in the session with your name, the time, and the reason.

---

## 15. X Report (Mid-Shift Check)

An **X report** is a snapshot of the session so far - it does **not** close anything:

1. Session panel -> **X Report**.
2. You'll see: opening float, sales count and totals, totals **per payment method**, cash in/out movements, refunds, and the **expected cash** currently in the drawer.

Use it for a mid-shift drawer check or when handing over to a colleague.

---

## 16. Closing the Session (Z Report)

At the end of your shift:

1. Session panel -> **Close Session**.
2. **Count every coin and note** in the drawer and enter the total as **Counted Cash**.
3. The terminal compares it with the expected cash and shows the **Over / Short** difference:
   - **0.00** - perfect drawer 🎉
   - **Over** (+) - more cash than expected
   - **Short** (−) - less cash than expected
4. Add a note if there's a difference you can explain ("20 note found under tray").
5. Tap **Close Session**. The **Z report** is saved permanently with all totals - your manager can review it in the back office.

After closing, no more sales can be made until a new session is opened (if your store requires open sessions).

---

## 17. Offline Mode

If the internet drops, the terminal **keeps working**:

- The header indicator turns to **Offline**. Product search now uses the local copy of the catalog stored on the device.
- **Cash and card (offline) sales work normally.** Completed sales are queued on the device.
- When the connection returns, queued sales **upload automatically** - you'll see the queue counter drop to zero. Nothing is ever sent twice.

**Not available while offline:** creating customers, validating coupon codes, and payment-link/QR methods. Receipts can still be printed.

> Don't close the browser or clear its data while sales are queued - let the queue sync first (indicator shows pending count).

---

## 18. Personalising Your Terminal (Layout & Theme)

> Requires layout permission. Your setup is saved to **your account** - it follows you to any device.

Open **Settings** (gear icon) in the header:

- **Theme:** light or dark mode, **accent color**, density (comfortable / compact), button size, and font size - handy for small screens or low light.
- **Edit Layout:** turns on layout mode. Now you can:
  - **Drag** any panel by its header to a new spot
  - **Resize** panels from the bottom-right corner - everything snaps neatly to the grid
  - **Hide/show** panels you don't use
  - Apply a **preset**: *Classic* (catalog left, cart right), *Mirrored* (swapped - great for left-handed cashiers), *Catalog Max*, or *Compact*
- Tap **Save** when you're happy, or re-apply a preset to start over.

---

## 19. Logging Out

At the end of your shift, **after closing your session** (section 16):

1. Tap your name / the logout button in the header.
2. Confirm. The terminal returns to the login screen, ready for the next cashier.

Just stepping away for a minute? Use the **lock** instead and unlock with your PIN when you return.

---

## 20. Quick Reference Card

| I want to... | Do this |
|---|---|
| Start my shift | Go to `/pos` -> log in -> open session with counted float |
| Sell an item | Scan it (or search / tap a quick key) -> **Pay** |
| Change a quantity | Tap the line -> keypad or + / − |
| Sell something not in the system | **Custom Product** -> name, price, qty |
| Give a discount | **Discount** on cart or line (within your limit) |
| Apply a coupon | **Coupon** -> enter code |
| Member pricing | Attach the customer in the Customer panel |
| Park a sale | **Hold** -> label; resume from the Holds panel |
| Serve two customers at once | **+** cart tab in the header |
| Take cash | Pay -> Cash -> tendered amount -> give change shown |
| Card payment | Pay -> Card -> charge terminal -> enter reference |
| Split cash + card | Pay -> add multiple payment rows until Remaining = 0 |
| Customer pays by phone | Pay -> Payment Link -> customer scans the QR |
| Reprint / email a receipt | Orders search -> open order -> Print / Email |
| Do a return | Orders / Refunds -> find order -> items -> refund method -> reason |
| Add/remove drawer cash | Session panel -> Cash In / Cash Out + reason |
| Check the drawer mid-shift | Session panel -> **X Report** |
| End my shift | Session panel -> **Close Session** -> counted cash -> log out |
| Terminal locked | Enter your **PIN** |
| Internet down | Keep selling - sales sync automatically later |
| Move panels around | Settings -> **Edit Layout** -> drag & drop -> Save |

---

*Panth MagePos - built by [Panth Infotech](https://kishansavaliya.com). Managers: see [`README.md`](README.md) for installation, configuration, and security notes (including why the default `admin` credentials must be changed immediately).*
