# La Elena Fishyalan

La Elena Fishyalan is a PHP and MySQL restaurant management and online ordering application for a restaurant in Sinacaban. It provides a public menu, customer ordering and reservations, and separate administration and staff workspaces.

## Features

- Public restaurant landing page and searchable menu
- Customer registration, authentication, profile, favorites, cart, checkout, orders, reservations, and feedback
- Admin dashboard for menu, inventory, suppliers, staff, users, finances, compliance, and customer experience management
- Staff workspace for schedules, attendance, reservations, inventory, menu management, and online orders
- AM/PM attendance with a Philippine clock and staff schedule management
- Email-based account verification, password reset, and email change verification
- Promo code support and stock availability tracking
- Role-based access for `admin`, `staff`, and `customer`
- Cashier POS for dine-in/takeaway sales, cash change, externally verified GCash/card payments, receipts, and full refunds

## Requirements

- Windows with XAMPP
- Apache with PHP enabled
- MySQL or MariaDB
- PHP PDO MySQL extension
- A modern web browser
- Internet access for CDN-hosted Bootstrap, Font Awesome, Google Fonts, and SweetAlert2 assets

## Local Setup with XAMPP

1. Copy or clone this project into the XAMPP web root:

   ```text
   C:\xampp\htdocs\La_Elena
   ```

2. Start **Apache** and **MySQL** from the XAMPP Control Panel.

3. Create the database configured for local development. The default values are:

   ```text
   Database: Fishyalan_db
   User: root
   Password: empty
   Host: localhost
   ```

4. Create the required tables in MySQL. The `database/migrations/` directory contains timestamped schema and repair migrations. Apply them in chronological order against the project database. Verify that the required tables, including `menu_items`, users, orders, reservations, and related tables, exist before opening the application.

5. Open the public site:

   [http://localhost/La_Elena/](http://localhost/La_Elena/)

   The authentication page is available at [http://localhost/La_Elena/auth/login.php](http://localhost/La_Elena/auth/login.php).

## Public customer reviews

Apply `database/migrations/20261007_add_public_feedback.sql`, then `database/migrations/20261010_add_feedback_sharing_consent.sql` to enable the homepage's Guest Voices feed with customer consent. Customers can select **Share my review on the homepage** in **Leave Feedback**; their rating, comment, first name, last initial, and date then appear publicly. Unchecked feedback stays private and cannot be published by admins. The homepage loads the latest 12 public reviews, including all star ratings, and refreshes every 30 seconds while the page is visible.

Customer consent (`customer_shared`) is stored separately from homepage visibility (`is_public`). Admins can read every review under **Customer Feedback** and hide shared reviews or use **Display on homepage** to restore a hidden review only if the customer opted in. The server and public feed enforce this restriction. Each change shows a confirmation and preserves the current page of reviews. Empty and unavailable feeds display a message instead of sample testimonials.

The consent migration hides legacy reviews because the old visibility field could be set by either customers or admins and cannot prove customer consent. Review records are preserved; customers can submit a new review with sharing selected. Rerunning the migration preserves consent and visibility for new reviews.

Validation: `C:\xampp\php\php.exe tests/feedback.php` and `node tests/feedback-browser.cjs`. The PHP checks use temporary tables; browser checks use controlled responses and do not publish test reviews.

## Ingredient recipes and automatic stock deduction

Apply `database/migrations/20261007_add_recipe_inventory.sql` after the inventory ID repair migration. It adds order ingredient snapshots and stock movement references without changing existing stock or past orders.

In either admin or staff **Inventory Management**:

1. Add your ingredients, choose their inventory units, and enter opening stock.
2. Open **Menu Recipes** and select a food; its own recipe loads automatically. Add each ingredient and the quantity for one serving, then save before switching foods. For example, 200 grams of onion per serving deducts 400 grams when two servings are ordered. Use `0.20` if the ingredient is stored in kilograms instead.
3. Use **Stock In** for deliveries and **Stock Out** for waste/manual usage. Both show dated history; automated entries include `ONLINE #order_id` or `POS #sale_id`. The ingredients list shows total quantities in, out, and current stock.

Customer checkout deducts ingredients immediately. Confirmation does not deduct them again. POS deducts ingredients when a sale completes. Insufficient ingredients roll back the whole order/sale. Customer or management cancellation restores the original ingredient quantities once; a POS refund restores ingredients only when the cashier selects the unprepared-item restock option. Recipe edits never change previous deductions or returns.

Foods without recipes continue using existing menu portion stock only. Configure recipes to enable ingredient deduction for those foods. Existing pending online orders without an ingredient snapshot consume their configured recipe when first confirmed; past completed orders are not recalculated. Ingredient units with recipes/history cannot be changed, and ingredients used in orders cannot be deleted.

Validation: `C:\xampp\php\php.exe tests/inventory.php`, `C:\xampp\php\php.exe tests/pos.php`, and `C:\xampp\php\php.exe tests/recipe-inventory.php`. Tests use isolated data and do not modify real stock or orders.

Browser validation: `node tests/recipe-inventory-browser.cjs` uses local Edge to check both roles, recipe controls, and desktop/mobile layouts against rendered test pages.

## Attendance

Apply database/migrations/20261006_add_attendance_payroll.sql to the application database (XAMPP MariaDB) for the attendance session schema. Existing whole-shift records remain available alongside AM/PM sessions.

- Staff **Attendance** records time in/out, with scheduled or flexible sessions and a Philippine clock.
- Admin and Super Admin **Employee Management** provides staff records, schedule management, and attendance history with staff, name, date, and status filters.
- Admin **Attendance records** separates sessions into daily sections, newest first, with staff listed by name within each date. Each page shows up to seven complete attendance dates so a day's staff records stay together, including AM/PM sessions. Filters apply to the daily groups and summary counts.
- Scheduling rejects overlapping shifts and inactive staff. Shifts with attendance records cannot be deleted.
- Shifts spanning noon split at 12:00. Time in opens 15 minutes before each session. Open previous sessions remain available for time out.
- Completed scheduled time counts minutes within the scheduled session; flexible attendance counts actual completed time.
- Attendance remains separate from salary management. Staff payroll is available only in the admin workspace.

Verification: C:\xampp\php\php.exe tests/workforce.php, followed by node tests/workforce-browser.cjs. Integration tests create and remove an isolated database; browser tests use synthetic rendered pages.

## Staff payroll

Apply `database/migrations/20261012_add_payroll_editing.sql` after the attendance migration. It preserves existing salary rates and payslips and adds revision tracking for pending payroll edits. Both Admin and Super Admin can open **Employee Management → Staff Payroll**; staff cannot access this page.

1. Select a staff member, choose an hourly, daily, or fixed monthly pay basis, enter their salary rate, and click **Save salary**.
2. Choose completed payroll dates and enter the rate, allowances/bonuses, deductions, and adjustment notes. **Preview payroll** shows basic salary and net pay. **Generate payroll** saves a pending payslip with an attendance snapshot.
3. Open a pending payslip to edit its salary, pay basis, allowances, deductions, and notes. Net pay is recalculated from the saved attendance. The staff member and period stay fixed. Stale changes are rejected so concurrent edits cannot overwrite each other.
4. Print the payslip and use **Mark saved payroll as paid** after payment. Paid payslips cannot be edited. Marking paid records status; it does not transfer money or create an expense.

Daily pay combines completed AM/PM sessions, caps each attendance date at eight hours, and prorates shorter days. Hourly pay uses completed attendance minutes. Fixed monthly pay uses calendar-day proration. Allowances and deductions are manual. Net pay equals basic salary plus allowances minus deductions. Payroll rejects invalid/open attendance, overlapping periods, negative net pay, and periods ending today or later. Saving a staff salary rate does not change existing payslips.

Selecting a staff member automatically estimates salary for the current month through today using their saved rate. The estimate refreshes every 30 seconds and after payroll field changes, detecting new time-outs without creating payroll records. It shows scheduled hours, actual time in/out, counted time, and session earnings. Open or invalid sessions contribute no attendance pay and block payroll generation. Scheduled pay counts only minutes within the assigned AM/PM session, so late arrivals and early departures reduce pay. Estimates can include today; generated payroll must end before today. Pending and paid payslips retain their saved attendance snapshots.

Validation: `C:\xampp\php\php.exe tests/payroll.php`, `C:\xampp\php\php.exe tests/workforce.php`, then `node tests/workforce-browser.cjs`. PHP tests use isolated databases; browser tests use synthetic pages.

## Configuration

Database, URL, session, reservation, and mail settings are defined in `config/config.php`.

For deployment, override configuration with environment variables instead of committing credentials. Supported database variables include:

```text
DB_HOST
DB_USER
DB_PASSWORD
DB_NAME
```

The application also accepts the `GILDED_SPOON_`-prefixed versions used by the deployment configuration, such as `GILDED_SPOON_DB_HOST` and `GILDED_SPOON_DB_NAME`.

Configure SMTP variables before using account verification, password reset, or email change features:

```text
SMTP_HOST
SMTP_PORT
SMTP_USER
SMTP_PASSWORD
SMTP_SECURE
SMTP_FROM_EMAIL
SECURITY_EMAIL
```

Do not commit real database or SMTP credentials. After changing configuration, restart Apache if the environment variables are managed by the server process.

## Main Routes

| Area               | Entry point                              |
| ------------------ | ---------------------------------------- |
| Public site        | `index.html`                             |
| Menu search        | `search.html`                            |
| Authentication     | `auth/login.php` and `auth/register.php` |
| Customer dashboard | `customerSide/customer-dashboard.php`    |
| Staff dashboard    | `staffSide/staff-dashboard.php`          |
| Cashier POS        | `staffSide/pos/index.php`                |
| Admin dashboard    | `adminSide/admin-dashboard.php`          |
| Menu API           | `api/getMenuItems.php`                   |
| Promo API          | `api/apply_promo.php`                    |

## Project Structure

```text
adminSide/     Admin dashboard and management modules
api/           JSON endpoints used by the frontend
assets/        Shared frontend assets
auth/          Login, registration, verification, and password flows
config/        Database, environment, mailer, and shared configuration
customerSide/  Customer-facing pages and actions
database/      Database migrations
images/        Menu photos, profile pictures, site photos, and payment images
staffSide/     Staff dashboard and operational modules
index.html     Public landing page
index.js       Public landing-page behavior
style.css      Public landing-page styles
```

## Image folders

Images are grouped by their purpose:

```text
images/menu/               Food photos (admin and staff menu uploads)
images/profiles/customer/  Customer profile pictures
images/profiles/admin/     Admin profile pictures
images/profiles/staff/     Staff profile pictures
images/site/               Restaurant and background photos
images/payments/           Reservation payment QR images
```

New menu and profile uploads save directly to these folders. After updating an existing installation, move the old root-level `images/menu_*` files into `images/menu/`, customer photos from `images/profiles/` into `images/profiles/customer/`, admin avatars from `uploads/admin/avatars/` into `images/profiles/admin/`, and staff avatars from `uploads/staff/avatars/` into `images/profiles/staff/`. Move `FRONT.jpg` and `bg_image.png` into `images/site/`, and `reservation-gcash.jpg` into `images/payments/`.

Apply `database/migrations/20261010_organize_image_folders.sql` to update saved menu and profile paths. It is safe to rerun. Existing login sessions can still resolve old profile paths.

Deleting a menu item in either the admin or staff workspace removes its uploaded photo after the database deletion succeeds. Replacing a menu photo removes the previous upload after saving. Photos still used by another menu item are retained; profile pictures, site images, and external images are excluded from cleanup.

Validation: `C:\xampp\php\php.exe tests/menu_images.php` uses temporary menu tables and uniquely named test images without changing real menu records.

## Development Notes

- PHP pages resolve their base path dynamically, so the project can run from a local subdirectory or a hosted domain.
- The application uses Philippine Standard Time (`Asia/Manila`) for server-side date and reservation calculations.
- Keep uploaded files and configuration credentials outside version control where possible.
- Test authentication and role permissions for each user type after database or migration changes.

## Cashier Point of Sale

Apply `database/migrations/20261004_add_pos.sql` to the configured database before first use. The migration adds `pos_sales` and `pos_sale_items`; it does not modify existing menu or online order records. It can be rerun safely.

Sign in as an **ACTIVE** staff account whose assigned role is **Cashier**, then choose **Point of Sale** in the staff dashboard sidebar, **Open Point of Sale** in Quick Actions, or **POS** in the mobile navigation. Other staff roles and customers cannot access the POS endpoints. Administrators assign the Cashier role through User Management.

1. Search/filter the menu and add items. Choose dine-in or takeaway; optionally enter a table, customer name, and order notes.
2. Adjust quantities and optionally enter a fixed discount with its reason. Tax applies to the discounted subtotal. `POS_TAX_BASIS_POINTS` defaults to `1000` (10%), matching the existing online checkout rate; set it to `0` for no added POS tax. The POS setting does not change online checkout.
3. For cash, enter the amount received or select **Exact amount**. For GCash/card, collect payment externally, enter the transaction reference, and confirm receipt. These methods record verified payments and do not charge a gateway.
4. Complete the sale and print the receipt. Prices, totals, role, and available stock are checked on the server. Sales use integer cents, transactions, row locks, and a unique checkout key. Pending online orders retain their reserved stock.
5. Use **My sales & receipts** to filter your sales by date, view daily totals, and reprint receipts. A full refund requires a reason and confirmation that payment was returned externally. Select stock restoration only for sellable, unprepared items; it is off by default. Repeating a refund cannot restore stock twice.

The current cart and any uncertain checkout are saved in the current browser tab. If a request loses its response, use **Retry / recover sale**; it reuses the original checkout key instead of creating another sale. Keep that tab open until the outcome is resolved. Each cashier can view/refund their own receipts. Refunds remain in history and audit logs. Financial Report revenue and top-item charts include completed POS sales and exclude refunded sales, attributed to the original sale date.

Validation (after migration, with MySQL running):

```powershell
C:\xampp\php\php.exe tests/pos.php
node tests/pos-browser.cjs
```

The PHP tests use temporary tables and do not modify real records. The browser test uses a temporary local mock API and headless Microsoft Edge; set `EDGE_PATH` if Edge is installed elsewhere. It verifies desktop/mobile layout, checkout recovery after a lost response and page reload, receipts, history, and refunds.

## Troubleshooting

### Database connection fails

Confirm that MySQL is running, the database exists, and the values in `config/config.php` or the environment variables match your local setup. Check the Apache/PHP error log for the underlying PDO error.

### A page redirects incorrectly

Confirm that Apache is serving the project from the expected folder and that the requested URL includes the `/La_Elena/` subdirectory.

### Verification emails are not sent

Check the SMTP settings, confirm that PHP can make outbound connections, and inspect the server error log. For Gmail SMTP, use an app password rather than a normal account password when required by the account.

## GitHub source snapshot

Credentials are supplied through environment variables. Configure DB_USER and DB_PASSWORD for hosting, and SMTP_USER and SMTP_PASSWORD for email. Private profile uploads and local configuration files are excluded from this source snapshot.
