ANAIS PHP-PDO SRS Package
=========================

Folder to copy in XAMPP:
  C:\xampp\htdocs\anais

Default database name:
  anais_db

Fresh install:
1. Start Apache and MySQL in XAMPP.
2. Open phpMyAdmin.
3. Import: anais/database/01_clean_empty_default_owner.sql
4. Copy the whole anais folder to C:\xampp\htdocs\anais
5. Open: http://localhost/anais/login.php
6. Default Owner login is written at the bottom of the SQL file.

Updating an old database:
1. Backup your old anais_db first.
2. Import: anais/database/02_FINAL_UPDATE_EXISTING_DATABASE.sql
3. Copy/replace the anais folder in htdocs.

Important file structure:
- config/db.php
- includes/auth.php
- includes/header.php
- includes/footer.php
- includes/error_handler.php
- includes/po_status_sync.php
- api/auto_po_generate.php
- api/supplier_po_action.php
- api/supplier_register.php
- assets/css/style.css
- assets/js/app.js
- assets/img/anais-logo.png

Main SRS-aligned modules included:
- Login/logout with role-based access
- Dashboard
- Inventory
- Stock-In
- Stock-Out with discount amount/percent and printable OR
- Suppliers
- Purchase Orders
- Auto Purchase Order generation from low-stock alerts
- Supplier Portal
- Deliveries with incomplete/partial delivery status sync
- Reports
- Account management with supplier password reset temporary password flow

LATEST UPDATE NOTES
===================
This package includes the requested Account Management, Dashboard, Inventory, Stock-Out, Reports, PO, and SRS/FDD updates.

Fresh database:
- Import database/01_clean_empty_default_owner.sql

Existing database:
- Back up your old anais_db first.
- Import database/02_FINAL_UPDATE_EXISTING_DATABASE.sql

Important new database fields:
- users: contact_number, email, address, must_change_password
- products: barcode, warranty_months
- stock_transactions: customer_id_no, warranty_until
- purchase_orders: payment_status, payment_method, amount_paid, payment_remarks
- po_returns table for damaged / wrong item returns

New page:
- /anais/register_account.php for Employee/Staff account requests only. Accounts are Inactive until Owner activates them.

- Request Account was moved to the Employee/Staff login tab. Supplier registration remains on the Supplier tab using /anais/register_supplier.php.

LATEST ACCOUNT MANAGEMENT CHANGE
- Owner manual account creation has been disabled.
- Accounts must be requested/registered from the login page first.
- Owner can manage requested accounts from Account Management.
