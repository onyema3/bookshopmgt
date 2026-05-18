🖥️ POINT OF SALE (POS)
Access & Security
•	Full-screen POS at yoursite.com/?bookshop_pos=1
•	WordPress session login
•	4–8 digit PIN login — tap PIN on number pad, no password needed
•	PIN set per staff member from the Staff admin page
•	IP whitelist — block POS access from non-whitelisted network addresses (supports CIDR ranges)
•	Progressive Web App — installable on tablet/phone home screen, works offline
Selling
•	Live book search by title, author, ISBN, or barcode
•	Book grid with cover image, price, real-time stock badge
•	Add multiple books to cart — click to add, stock ceiling enforced
•	Quantity +/− controls per cart item
•	Remove individual items or clear entire cart
•	Hold / Park a sale (F2) — save current cart, start a new sale, recall later
•	Held sales panel (F5) — view all parked carts with item count and subtotal
•	Customer search and attach to sale — autocomplete by name, phone, or email
•	Quick-add new customer without leaving the sale
Pricing & Discounts
•	Manual discount field in currency amount
•	Promo / coupon code field with real-time server validation
•	Loyalty point redemption — enter points to redeem, auto-converted to currency
•	Customer account credit usage at checkout
•	VAT / tax computed live — exclusive (added on top) or inclusive (extracted from price)
•	Payment methods — Cash, Card, Bank Transfer, Split (cash + card simultaneously)
•	Split payment — enter cash and card amounts separately
•	Amount tendered field with quick-denomination buttons (₦500, ₦1,000, ₦2,000, ₦5,000, ₦10,000) on a separate row below the input
•	Automatic change-due calculation — green for change owed, red for amount still owed
•	Manager approval modal for discounts above configured threshold %
Keyboard Shortcuts
•	F2 — Park current sale
•	F3 — Focus book search
•	F4 — Complete sale
•	F5 — Open held sales panel
•	1 / 2 / 3 / 4 — Switch payment method (Cash / Card / Transfer / Split)
•	Escape — Start new sale after receipt
Receipt
•	Store logo at top
•	Store name, tagline, full address, phone number
•	Sale reference, date/time, staff name, customer name, payment method
•	Itemised list — book title, author, quantity, unit price, line total
•	Subtotal, manual discount, promo discount, VAT/tax (each line shown/hidden as needed)
•	Grand total
•	Amount tendered and change due (cash sales only)
•	Loyalty points earned notification
•	Custom footer message
•	Print button — isolates receipt to clean 80mm-width frame for thermal printers
•	Email receipt — send to any email address from the receipt screen
•	WhatsApp receipt — pre-filled wa.me link with full receipt text
Shift Management
•	Open shift with opening cash amount
•	All cash sales linked to active shift automatically
•	Close shift with closing cash count
•	Automatic variance — expected vs actual cash, colour-coded result
•	Full shift history with opening/closing/variance per session
 
👥 CUSTOMERS & LOYALTY
Customer Database
•	Name, phone, email, address, birthday, notes
•	Full purchase history per customer
•	Loyalty points balance and cash value
•	Account credit balance (store credit)
•	Customer tier — Bronze / Silver / Gold / Platinum based on lifetime spend
•	Search by name, phone, or email
Loyalty Programme
•	Points earned automatically on every sale (configurable rate per currency unit)
•	Points redeemable at POS checkout
•	Configurable point value (currency per point)
•	Manual points adjustment with reason (admin)
•	Full points log — every earn, redeem, adjustment, expiry
•	Points expiry — configurable inactivity period, email notification on expiry
•	Birthday automation — discount code emailed on customer birthday
Customer Tiers
•	Bronze → Silver → Gold → Platinum based on configurable lifetime spend thresholds
•	Each tier carries an automatic discount percentage
•	Tier updated automatically after every sale
•	Tier badge and progress bar to next level

Customer Portal [bookshop_portal]
•	Login by phone or email — no WordPress account needed
•	8-hour session-based authentication
•	Dashboard showing tier badge, lifetime spend, points balance and value
•	Tier progression bar showing spend needed for next tier
•	Full purchase history with itemised receipts
•	Loyalty points history log — every earn and redeem with dates
•	Active reservations with status indicators
•	Submit new book reservations from the portal
•	Edit profile — name, phone, email, address, birthday
 
🏷️ PROMOTIONS & DISCOUNTS
•	Percentage off, fixed amount off, Buy X Get Y free
•	Coupon / promo codes with optional usage limits
•	Date-range scheduling — auto-activates and expires
•	Minimum purchase threshold per promotion
•	Manager approval flag — certain promos require manager PIN to apply
•	Usage counter tracked per code
 
↩️ RETURNS & REFUNDS
•	Refund button on every completed sale in the Sales log
•	Select individual items and quantities to refund — partial refunds fully supported
•	Required reason field
•	Optional restock — returns books to inventory automatically
•	Refund reference number generated (RF-XXXXXX)
•	Manager-only permission
•	Full audit trail entry per refund
 
📊 REPORTS & ANALYTICS
Five Report Tabs
•	Overview — dual-axis daily revenue + transactions chart (bar + line), genre revenue doughnut, sales by hour of day bar chart, full profit breakdown panel
•	Books — top 15 books with units, revenue, COGS, gross profit, margin %; slow movers (unsold 30 days) with stock value
•	Staff — transactions, revenue, avg sale, discounts given, revenue share bar per staff member
•	Inventory — full stock valuation with every book's cost/sell/margin/stock values; 7 KPI summary cards
•	Payments — revenue by payment method doughnut + breakdown table with share progress bars
KPI Cards — Total Revenue, Gross Profit, Profit Margin %, Transactions, Avg Sale, Cost of Goods, Discounts Given, Tax Collected
Date Shortcuts — Today, Yesterday, This Week, This Month, Last Month, This Year, Last 7 Days, Last 30 Days
Export Formats
•	CSV — full sales log with all fields
•	JSON — structured with nested line items per sale
•	Inventory CSV — every book with cost/sell/margin/stock values
•	PDF — opens clean printable page in new tab, auto-triggers browser print dialog for Save as PDF
•	Print — browser print of admin reports page
End-of-Day Report
•	Automated HTML email sent daily
•	Revenue, transactions, gross profit, staff performance, payment breakdown, top 5 books
•	Manual trigger from Settings
•	Last sent time shown in Settings
 
💳 ONLINE STORE & PAYMENTS
Online Catalogue [bookshop_catalogue]
•	Responsive book grid with covers, genre, price
•	Search and genre filter
•	Add to floating cart
•	Click & Collect or Delivery order types
•	Checkout form — name, email, phone, delivery address
•	Pay on Pickup / Pay with Paystack / Pay with Flutterwave
Payment Gateways
•	Paystack — transaction initialisation, server-side verification on callback
•	Flutterwave — transaction initialisation, server-side verification on callback
•	Both configured in Settings with public and secret keys
•	Secret keys show masked placeholder when saved, leave blank to keep existing
•	Payment updates reservation/order status on success
•	Admin email notification on payment received
Online Orders Admin
•	Order management — ref, customer, type, total, gateway, status
•	Status workflow — Pending → Paid → Processing → Ready → Completed → Cancelled
•	Line items drill-down per order
•	Admin email on every new order
Reservations
•	[bookshop_reserve] shortcode — standalone reservation form
•	Also available inside customer portal
•	Admin notified by email on each reservation
•	Status — Pending → Notified → Fulfilled → Cancelled
 
📣 BULK MESSAGING
•	Segment customers by genre purchased, activity within N days, minimum spend
•	Preview recipient count and list before sending
•	Bulk email with personalisation tokens — {name} {first_name} {points}
•	WhatsApp bulk — generates wa.me links for dispatch, one click per customer
•	Full message log — sent/failed per recipient with timestamp
 
🔌 REST API
Base URL: /wp-json/bookshop/v1/
•	GET /books — list with search and pagination
•	GET /books/{id} — single book
•	POST /books — create book
•	PUT /books/{id} — update book
•	PATCH /stock/{id} — update stock quantity
•	GET /stock/low — all low-stock books
•	GET /sales — sales log with date filtering
•	GET /sales/{id} — single sale with line items
•	GET /customers — customer list
•	GET /customers/{id} — full profile with tier and recent sales
•	GET /reports/summary — revenue, profit, transactions
•	GET /reports/top-books — top selling books
•	API key authentication via X-Bookshop-Key header
•	Key generated and regenerated from admin panel
 
🔔 WEBHOOKS
•	Fire POST to any URL on sale.completed, stock.low, or all events
•	Optional HMAC secret for signature verification
•	Compatible with Zapier, Make, and any HTTP endpoint
•	Manage from Online Orders → API tab
 
📊 GOOGLE SHEETS SYNC
•	Posts daily sales rows to Google Apps Script Web App URL
•	Each row — date, time, ref, staff, customer, payment, title, author, ISBN, qty, price
•	Auto-syncs daily via cron
•	Manual sync button in Settings
•	Last sync time shown in Settings
 
💾 BACKUP & RECOVERY
•	Daily SQL backup emailed automatically to configured address
•	Download backup on demand from Settings
•	Upload and restore from .sql file — with overwrite warning, validation, and progress feedback
•	Max 50MB restore file
•	Restore logs to audit trail with filename and statement count
•	Last backup and last restore times shown in Settings
 
🔐 STAFF & SECURITY
Roles
•	Bookshop Staff — POS access only
•	Bookshop Manager — POS + void sales, approve high discounts, process refunds
•	Administrator — full access
Audit Log
•	Every significant action logged — book edits, stock adjustments, sales, voids, refunds, shifts, POs, restores
•	Timestamp, staff name, action type, object, details
•	Viewable from Staff → Audit Log tab
Security
•	IP whitelist for POS with CIDR support
•	Capability checks on every AJAX endpoint
•	Nonces on write operations
•	Session-based customer portal (no WP account needed for customers)
•	Manager PIN required for high-value discounts and special promos
 
⚙️ SETTINGS
Store Identity — Name, tagline, address, phone, email, logo (Media Library picker), receipt footer
Financial — Currency (₦ $ £ € GH₵ KSh), tax mode (none / exclusive / inclusive), tax rate, tax label
Loyalty — Points rate, point value, expiry period in months
Operations — Manager discount threshold %, low stock alert email, WhatsApp number, EOD report email, IP whitelist
Payment Gateways — Paystack public + secret keys, Flutterwave public + secret keys + currency
Integrations — Google Sheets Apps Script URL, WooCommerce sync button
Backup — Daily backup email, download now, upload and restore
Advanced — Run points expiry manually, sync Google Sheets manually, send EOD report now, preview printable receipt
Shortcodes Reference — [bookshop_portal] [bookshop_catalogue] [bookshop_reserve]
 
📱 PROGRESSIVE WEB APP
•	Dynamic manifest with store name, logo, theme colour
•	Service worker caches POS page for offline resilience
•	AJAX calls return offline error gracefully when disconnected
•	Install prompt on supported browsers (Chrome, Edge, Safari iOS)
•	Apple-specific meta tags for home screen icon and status bar

