# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.16] - 2026-10-06

### Fixed
- The Customer panel empty state keeps its natural height at every terminal width; the icon no longer overlaps the customer search box and the guest-sale text is no longer cut off. The panel body scrolls instead.
- While the Open Register Session window or any other dialog is open, keyboard and scanner keys no longer go to the hidden barcode field or start a catalog search behind the dialog. The opening float accepts digits, the decimal separator, Backspace and Enter from the keyboard.
- The register choices in the Open Register Session window form a radio group: Tab focuses the selected register, the arrow keys, Home and End move the selection, Space selects, and the focused choice shows a focus ring.
- Product card View details and Pin buttons, the Quick Keys previous, next and edit buttons and the Quick Keys page dots have touch areas of at least 44 by 44 pixels.
- Product names stored with HTML entities (for example `&trade;`) are decoded once on the server for the catalog, product options, categories, cart, receipt and refund screens. The terminal still renders names as plain text.
- The terminal follows a lock or sign-out made on the server: when an API reply is unauthorized, or `pos/auth/state` reports the session as locked, the terminal switches to the PIN lock screen (or the sign-in screen when the session has ended) and keeps the cart. Unauthorized API replies carry a `locked` flag and a readable message instead of the raw word unauthorized.
- The Quick Keys panel title stays readable on narrow terminals: when the panel header is too narrow, the page dots are hidden and the previous and next buttons remain.
- Small terminal buttons (catalog Load more, refund Back, All, None and Split payment, held carts, customer chips) and the Percent and Fixed toggles have touch areas of at least 44 by 44 pixels.
- Pressing Enter in the catalog search box with an exact SKU or barcode, typed or scanned, adds the product to the cart and clears the box. Any other text still runs a normal search.
- The PIN unlock pad, the cart item, cart discount and custom item keypads, the payment amount keypad and the Cash In, Cash Out and closing count keypads accept digits, the decimal separator (`.` or `,`) and Backspace from the keyboard. Enter unlocks on the PIN pad.
- Cart line names wrap to two lines instead of being cut off, and the full name shows as a tooltip. Held cart names, list titles and the Customer action button also show their full text.
- The SKU badge in the cart item window stays on one line.
- The attached customer card shows the customer group name (for example General) instead of the group id. Cart replies now carry both `group` (name) and `group_id`.
- Warning-coloured text (refunded amount badge, closed session chip, offline banner, line notes) uses a darker ink that meets AA contrast on light surfaces.
- Pressing Enter on the selected register in the Open Register Session window opens the session; Space selects a register.
