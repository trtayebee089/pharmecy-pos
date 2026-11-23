# WP1: Navigation Inventory, Core (Dashboard, Product, Purchase, Sale/POS, Online Orders, Transfer, Returns)

- **Source of truth:** `D:\01 REDPHARMA\00 CODEBASE\backend - ocr pos\` (read-only; nothing was modified, nothing was executed. `artisan route:list` was deliberately **not** run because it can write `bootstrap/cache`).
- **Primary inputs:** `resources/views/backend/layout/sidebar.blade.php` (737 lines, read in full), `routes/web.php` (1195 lines, read in full), plus controllers and views per section.
- **Method:** static reading. Sections 2-5 were produced by parallel reviewers working from the same brief and then spot-checked (e.g. `SaleController.php:1155-1156` commented-out transaction, `PurchaseController.php:1488-1496` early return before `update()`, `product/edit.blade.php:240` empty `expire_date`, `CouponController.php:83-103` wipe routine: all confirmed).
- **Out of scope here (WP2):** Accounting, Expense, Income, HRM, People, Reports, Settings, Membership/Rewards (Customer Ecosystem), Manufacturing. They appear only in the skeleton below.
- **Citation conventions:** `file:line`. `SC` = `app/Http/Controllers/SaleController.php`. `UNVERIFIED` = could not be confirmed from code alone.

---

## 0. Full sidebar skeleton (entire file, names + gate)

Permissions are checked in Blade via `$role_has_permissions_list->where('name', X)->first()`. The list is shared by middleware `common` (`app/Http/Middleware/Common.php:88-91`, cache key per role_id). No `@can` is used anywhere in the sidebar.

| # | Group (sidebar line) | Group gate | Items: name -> route [item gate] | Status |
|---|---|---|---|---|
| 1 | Dashboard (2) | none | `url('/dashboard')` | live |
| 2 | Customer Ecosystem (3-10) | **none** | Membership Dashboard, Membership Plans, Customers, Membership History, Renewals, Reports (`membership.admin.*`); Reward Management (`rewards.admin.dashboard`) [all ungated] | live, WP2 |
| 3 | Product (11-50) | category OR products-index OR print_barcode OR stock_count OR adjustment | Category -> category.index [category]; Product List -> products.index [products-index]; Add Product -> products.create [products-index AND products-add]; Print Barcode -> product.printBarcode [print_barcode]; Adjustment List / Add Adjustment -> qty_adjustment.index/.create [adjustment]; Stock Count -> stock-count.index [stock_count] | live |
| 4 | Purchase (51-74) | purchases-index | Purchase List -> purchases.index; Add Purchase [purchases-add]: role_id==6 -> purchases.new.create; others -> purchases.new.create (mobile, `d-md-none`) and purchases.create (desktop); Import Purchase By CSV -> `purchases/purchase_by_csv` [purchases-add] | live |
| 5 | Sale (75-116) | sales-index OR packing_slip_challan OR gift_card OR coupon OR delivery | Sale List -> sales.index [sales-index]; POS -> sale.pos [sales-add]; Add Sale -> sales.create [sales-add]; Import Sale By CSV -> `sales/sale_by_csv` [sales-add]; Packing Slip List -> packingSlip.index, Challan List -> challan.index [packing_slip_challan]. **HTML-commented (hidden):** Delivery List, Gift Card List, Coupon List, Courier List (104-113) | live; 4 hidden |
| 6 | Online Orders (118-132) | sales-index OR delivery OR online-orders | Orders List -> online-sales.index [sales-index OR online-orders]; Delivery List -> delivery.index [delivery] | live |
| 7 | Manufacturing (133-141) | module `manufacturing` enabled | Production List, Add Production: **HTML-commented** | hidden, WP2 |
| 8 | Expense (142-159) | expenses-index | Expense Category, Expense List, Add Expense (modal) [expenses-add] | WP2 |
| 9 | Income (160-173) | incomes-index | Income Category, Income List, Add Income (modal) [incomes-add] | WP2 |
| 10 | Quotation (174-189) | quotes-index | Quotation List, Add Quotation [quotes-add]: **entire group HTML-commented** | hidden (covered in 4.9) |
| 11 | Transfer (190-206) | transfers-index | Transfer List -> transfers.index; Add Transfer -> transfers.create, Import Transfer By CSV -> `transfers/transfer_by_csv` [transfers-add] | live |
| 12 | Return (208-224) | returns-index OR purchase-return-index | Sale -> return-sale.index [returns-index]; Purchase -> return-purchase.index [purchase-return-index] | live |
| 13 | Accounting (225-253) | account-index OR balance-sheet OR account-statement OR money-transfer | Account List, Add Account (modal) [account-index]; Money Transfer [money-transfer]; Balance Sheet [balance-sheet]; Account Statement (modal) [account-statement] | WP2 |
| 14 | HRM (254-301) | department OR employees-index OR attendance OR payroll OR monthly-payroll OR advances-index OR holiday OR payroll-report | Department, Employee, Attendance, Payroll, Monthly Payroll, Advances Payment, Payroll Report, Holiday (each own gate) | WP2 |
| 15 | People (302-364) | users-index OR customers-index OR billers-index OR suppliers-index | User List/Add User, Customer List/Add Customer, Biller List/Add Biller, Supplier List/Add Supplier; Riders List [sales-index] | WP2 (Riders noted in 4.13) |
| 16 | Reports (366-595) | any of 23 report perms | Summary Report [profit-loss]; **Daily Profit & Cost [ungated, 428-434]**; Best Seller; Product Report; Daily/Monthly Sale; Daily/Monthly Purchase; Sale Report; **Challan Report [ungated, 481]**; Sale Report Chart; Payment Report; Purchase Report; Customer Report; Customer Group Report; Customer Due Report; Supplier Report; Supplier Due Report; Warehouse Report; Warehouse Stock Chart; Product Expiry Report; Product Quantity Alert; Product Order Report [product-qty-alert]; Custom Product Sales Report [sales-index]; **Adjustment Report [ungated, 575-577]**; Daily Sale Objective; User Report; Biller Report | WP2 |
| 17 | Addons / WooCommerce / eCommerce (596-610) | role_id != 5 + module flags | all **HTML-commented** | hidden |
| 18 | Settings (611-736) | **none** (group always shown) | Role Permission [`$role->id <= 2`]; Warehouse [warehouse]; Customer Group [customer_group]; Brand [brand]; Unit [unit]; User Profile [none]; General Setting [general_setting]; Reward Point Setting [reward_point_setting]; Payment Gateways [payment_gateway_setting]; POS Settings [pos_setting]; HRM Setting [hrm_setting]; Barcode Settings [barcode_setting]; Shipping Setting [shipping_setting]. **HTML-commented:** SMS Template, Custom Field, Discount Plan, Discount, All/Send Notification, Tables, Currency, Tax, Create SMS, Backup DB, Mail Setting, SMS Setting, Languages | Brand/Unit/Barcode covered in sect. 2; rest WP2 |

Sidebar-level observations:
- **HTML comments do not stop Blade.** `<!-- @if(...) ... {{ route(...) }} -->` still evaluates on the server (e.g. sidebar.blade.php:104-113, 134-140, 178-188). The links are only hidden in the browser and remain in page source. If a referenced route name is ever removed, every page will throw. Recommendation: use `{{-- --}}`.
- **The `$role` Blade variable is cross-user.** `Common.php:75-77` caches `user_role` under a **global** key (not per user) for one year, and `role_has_permissions` likewise (`Common.php:83-86`). The sidebar's `@if($role->id <= 2)` Role Permission link (sidebar.blade.php:658) therefore reflects whichever user first warmed the cache. `role_has_permissions_list` is correctly keyed by role_id (`Common.php:88`).
- **Duplicate DOM ids:** `purchase-create-menu` x2 (63-66), `customer-report-menu` x2 (513-518), `profit-loss-report-menu` x2 (419, 428).
- **Gate mismatches:** "Add Product" requires products-index as well (nested at 29-37); Sale group shows if only `gift_card`/`coupon`/`delivery` is held but those children are commented, so it renders an empty group; Riders List is gated by `sales-index` inside People.
- **No sidebar entries exist** for Brand/Unit under Product (they are under Settings), Variant, Labels, Gift Card, Coupon, Courier, Quotation, Cash Register, or Product History.

---

## 0.1 `routes/web.php` structural findings (affect every module in this WP)

| # | Finding | Evidence | Impact |
|---|---|---|---|
| R1 | **Unbalanced nesting.** Line 607 opens `Route::controller(ReportController::class)->group(` and 608 opens `Route::prefix('report')->group(`. Line 649 `});` closes only the prefix group; the controller group closes at **1195** (the extra `});`). | web.php:607-608, 649, 1194-1195 | Everything in 650-1194 is nested in a ReportController controller group. Nested `Route::controller` groups override it (Laravel 10 `RouteGroup` merge; see 3.0), and array/resource actions are unaffected, so those routes work. |
| R2 | **Sales routes registered under `/report/` bound to ReportController.** Lines 614-648 (`sales/product_sale/{id}`, `sales/get-sale/{id}`, `importsale`, `sales/recent-sale`, `sales/recent-draft`, `sales/check-status/{id}`, `sales/test-conversion/{id}`, `sales/convert-draft/{id}`, `sales/lims_sale_search`, `sales/getproduct/{cat}/{brand}`, `sales/getfeatured`, `sales/get_gift_card`, `sales/paypalSuccess`, `sales/gen_invoice/{id}`, `add_payment`, ...) use **string** actions, so they resolve to `ReportController@<method>`. ReportController has none of these methods (grep: 0 matches each; `limsSaleSearch` exists in neither controller). | web.php:614-648 | These URLs throw "method does not exist" (500). Several pages depend on them: Import Sale (sect. 4.4), Sale List "View" (4.1), Add/Edit Sale product loading (4.2/4.3), and POS side features (5.2). |
| R3 | **POS lives at `/report/pos`.** `pos/{id?}` -> `SaleController@posSale` name `sale.pos` (617) and `pos` name `pos.index` (618) are inside the `report` prefix. | web.php:617-618 | The POS page's **relative** AJAX URLs resolve under `/report/...`. Some hit working explicit-array routes (625-627); others hit the broken string routes. See the table in 5.2. |
| R4 | **Whole route blocks are duplicated:** online-sales, packing-slips, challans, delivery, quotations, purchases, transfers, qty_adjustment, return-sale, return-purchase (first copy 422-605, second 651-817); unit (277-293 twice); `sales` resource (421, 650). | as listed | The later definition wins for the same method+URI. Name collisions are silent. The two return-purchase copies differ: only the second has add-payment/getpayment/mark-paid, and those methods don't exist (3.10). |
| R5 | **Unauthenticated destructive endpoints** (outside the `auth` group at 140): `GET /migrate` runs `Artisan::call('migrate')`; `GET /cc` clears all caches; `GET /update-coupon` -> `CouponController@updateCoupon` **truncates every DB table with FK checks off, then recursively deletes the filesystem directory given in `?data=`**; `GET /auto-update-dashboard` (named `dashboard`) renders the auto-update dashboard. | web.php:72-76, 78-99, 101, 103; CouponController.php:83-103 | **CRITICAL.** One anonymous GET can wipe the production pharmacy database and files. This looks like a vendor "kill switch". Remove immediately. |
| R6 | `GET /phpfileinfo` runs `phpinfo()` for any logged-in user. | web.php:1035-1040 | Discloses environment and config, including secrets. Remove. |
| R7 | **No route-level authorization middleware.** All app routes share `['common','auth','active']` (web.php:140). Permission checks are left to each controller, and many controllers skip them (see sect. 2-5). | web.php:140 | A user who knows a URL can bypass the sidebar's hiding. |
| R8 | Two routes named `dashboard` semantics: `auto-update-dashboard` is named `dashboard` (103), while the sidebar uses `url('/dashboard')` (HomeController@dashboard, 203). No `route('dashboard')` call was found in `resources/views` or `app` (grep), so the name is currently unused, but it would point at the unauthenticated page. | web.php:103, 203 | Naming trap; remove the auto-update route. |

---

## 1. Dashboard

| Field | Detail |
|---|---|
| Menu | Dashboard (sidebar.blade.php:2), no gate |
| Route | `GET /dashboard` -> `HomeController@dashboard` (web.php:203; unnamed). `GET /` -> `HomeController@index`, which redirects to `dashboard` (HomeController.php:42-46). Also `GET /home` -> `HomeController@home` (web.php:135, auth only, returns `backend.home`, HomeController.php:39). |
| View | `resources/views/backend/index.blade.php` (607 lines). Customer role (role_id 5) instead gets `backend.customer_index` with their own sales, payments, quotations and returns (HomeController.php:88-102). The restaurant module kitchen dashboard is dead for pharmacy (HomeController.php:104-111). |
| Widgets and gates | Revenue/Profit summary cards (`revenue_profit_summary` perm, index.blade.php:48-50, 79); Cash Flow chart (`cash_flow`, 126-128); Monthly summary (`monthly_summary`, 141-143); Yearly report (`yearly_report`, 161-163); Recent Transaction tabs (176-275; some tabs `role_id == 1` only, 184); Best Seller month / year qty / year price (276-340; 274 role_id==1). |
| Filters | Date range + warehouse -> `GET dashboard-filter/{start}/{end}/{warehouse_id}` (web.php:212; HomeController.php:365), called relatively (index.blade.php:376, 549, 560, 583). |
| AJAX endpoints | `/yearly-best-selling-price`, `/yearly-best-selling-qty`, `/monthly-best-selling-qty`, `/recent-sale`, `/recent-purchase`, `/recent-quotation`, `/recent-payment` (index.blade.php:356-500; web.php:204-210); `setting/general_setting/change-theme/{c}` (537). **`$.ajax({url: '{{url("/")}}', dataType:'json'})` on every load (515-528)** expects user JSON from `/`, but `/` redirects to HTML. It always fails; dead code from a "user show" modal. |
| Business rules | Month-to-date revenue = Σ(grand_total − shipping_cost) − returns + income; profit = revenue + purchase_return − average COGS − expense (HomeController.php:126-134, 152-161, `calculateAverageCOGS`). If `staff_access == 'own'` and role_id > 2, everything is filtered to `user_id = Auth::id()` (HomeController.php:118, 308, 370). |
| DB tables | sales, product_sales, returns, return_purchases, expenses, incomes, purchases, payments, quotations, products, customers |
| Bugs / design | (a) It toggles MySQL `strict` off and reconnects mid-request (HomeController.php:86-88, 234-235, 371-372). This is fragile and changes the session SQL mode for the rest of the request. (b) The AJAX widget endpoints (`/recent-sale`, `/recent-purchase`, `/recent-payment` ...) have **no permission check**: a cashier can fetch recent purchases and payments JSON even if their card is hidden (HomeController.php:306-318). (c) The Recent Quotation tab remains although quotations are hidden. (d) The dead `url('/')` AJAX call. (e) Two different "dashboard" routes (R8). (f) The `Common` middleware runs product alert and DSO counts on **every request** (Common.php:68-74), a cost borne by every page. |
| Recommendation | **Redesign.** Keep KPIs (sales, profit, expiry and low-stock alerts, which matter for a pharmacy), compute them server-side with permission checks, drop quotation and restaurant branches. |

---
## 2. Product group

Paths below are relative to `backend - ocr pos/`. Abbreviations: PC = `app/Http/Controllers/ProductController.php`, W = `routes/web.php`, SB = `resources/views/backend/layout/sidebar.blade.php`, V = `resources/views/backend/`.
Every route here sits inside `Route::group(['middleware' => ['common','auth','active']])` (W:140). That means login is always required. Role permissions are checked only where this section says so.

### 2.0 Summary matrix

| # | Menu item (SB line) | URL / route name | Controller@method | Sidebar perm | Server-side perm check | Recommendation |
|---|---|---|---|---|---|---|
| 2.1 | Category (SB:28) | GET `/category`, `category.index` | CategoryController@index | `category` | index only (CategoryController:27) | Migrate. Remove the cascade delete |
| 2.2 | Product List (SB:31) | GET `/products`, `products.index` | PC@index (125) | `products-index` | index only (PC:129) | Redesign |
| 2.3 | Add Product (SB:36, nested inside the `products-index` check) | GET `/products/create`, `products.create` | PC@create (400) / store (419) | `products-add` | create only (PC:403). **store has no check** | Redesign |
| 2.4 | Print Barcode (SB:40) | GET `/products/print_barcode`, `product.printBarcode` | PC@printBarcode (1162) | `print_barcode` | **none** | Preserve the function, rebuild the code |
| 2.5 | Adjustment List / Add (SB:42-43) | `/qty_adjustment`, `qty_adjustment.index` / `.create` | AdjustmentController | `adjustment` | index only (AdjustmentController:24) | Redesign (stock ledger) |
| 2.6 | Stock Count (SB:46) | `/stock-count`, `stock-count.index` | StockCountController | `stock_count` | index only (StockCountController:21) | Redesign |
| 2.7 | Brand (Settings, SB:689) | `/brand`, `brand.index` | BrandController | `brand` (SB:622) | **none anywhere** | Migrate. Use as Manufacturer |
| 2.8 | Unit (Settings, SB:692) | `/unit`, `unit.index` | UnitController | `unit` (SB:624) | index only (UnitController:17) | Migrate. Lock conversions |
| 2.9 | Barcode Settings (SB:729) | `/barcodes`, `barcodes.index` | BarcodeController | `barcode_setting` (SB:654) | yes, `hasPermission()` (BarcodeController:14-24) on index/data/create/store/edit/update | Preserve |
| 2.10 | Labels (not in sidebar) | GET `/labels/show`, `print.labels` (W:969) | LabelsController@show | n/a | **none** | Likely unused. Merge into 2.4 |
| 2.11 | Variant | not routed | VariantController is empty (10 lines) | n/a | n/a | Drop |

Products columns that are not in migrations: `box_qty`, `stripe_qty` and `piece_qty` have no migration. They exist in the database, because ReportController:340-372 selects them, and the AI order service uses them (`app/Services/OpenAI/AiDraftOrderGenerationService.php`). `expire_date` comes only from `database/migrations/2025_03_08_003250_add_expire_date_to_products.txt`, a `.txt` file that never runs, so the column was added by hand. I found **no** generic-name, strength, manufacturer, rack, schedule or OCR columns on `products`. Grepping migrations, models and the product views for generic/strength/manufactur/rack found nothing product-related. The only pharmacy-specific product data is `expire_date` plus the box/strip/piece pack sizes.

---

### 2.1 Category
- **Routes**: W:296-303 are `category/import` (POST, `category.import`), `category/deletebyselection` (POST), `category/category-data` (POST) and `Route::resource('category')`. `create`/`show` are routed but not implemented, so they would error.
- **View**: `V/category/create.blade.php`. One page holds the list, an edit modal (`category.update` with dummy id 1, PUT, :42) and an import modal (:128). The add form is also in this view.
- **Table columns** (:25-31, filled by `categoryData` CategoryController:34): checkbox, Category, Parent Category, Number of Product, Stock Quantity, Stock Worth (Price/Cost), Action (Edit modal, Delete form). Search is name LIKE. Ordering uses id/name/parent_id/is_active.
- **AJAX calls**: POST `category/category-data` (:206), GET `category/{id}/edit` (:186) which returns JSON, POST `category/deletebyselection` (:307).
- **Fields**: name\*, image, parent_id, is_sync_disable, featured, icon, page_title, short_description. The last two are ecommerce-only.
- **Validation**: store (:134) checks name `max:255` and unique among active rows, image `image|mimes`, icon `mimetypes`. `required` is **not** enforced server-side. Update (:237) uses the same rules.
- **DB tables**: `categories`, `products` (on delete).
- **Bugs and risks**
  - **Deleting a category deactivates every product in it**: destroy (:397-401), deleteBySelection (:377-381). One click can remove hundreds of SKUs from the POS.
  - No permission check on store/edit/update/destroy/import/deleteBySelection/categoryData. Only index checks.
  - update writes `DB::table('categories')->update($request->except(...))` (:250, :320). This bypasses `$fillable`, so any posted field goes straight into the SQL update.
  - update is blocked unless `env('USER_VERIFIED')` (:234). Calling `env()` at runtime returns null when config is cached, which would break every category edit. UNVERIFIED: depends on the server's .env and config cache.
  - `icon` is missing from Category `$fillable`, so `store` silently drops the icon (Category::create at :213).
  - In import, the parent is `firstOrNew` with no save (:355), so `parent_id` stays null and the parent is never created.
  - categoryData has an N+1: about 5 queries per row (:88-95).
  - The edit endpoint has no permission check and returns the raw row.

### 2.2 Product List
- **Route**: GET `/products` → PC@index (125-155). A legacy `index11` (90) is still in the file but unrouted.
- **View**: `V/product/index.blade.php`.
  - Category filter `#pr_category` (:56).
  - Top buttons, shown only when `products-add` is present (:66-71): Add Product, Import Product modal (`product.import`, :98), Bulk Update modal (`product.bulkUpdateproduct`, :134), Download bulk (`product.downloadBulkProducts`).
  - Sample CSV link uses `sample.download` (W:180-194), which serves `public/sample_file/sample_products.csv`.
  - DataTable buttons: excel, print, bulk delete, colvis (:549-616).
- **Columns** (:79-88): checkbox, name, code, brand, category, qty (`products.qty` plus unit code, the global total rather than per warehouse), price, cost, expiry date, custom-field columns, options.
- **Data source**: POST `products/product-data` (`ajax.loader.product-data`, W:237), which is PC@productData (302-360) via Yajra. Search covers name, code, category name and brand name. The category filter works. `warehouse_id` is sent (:498) but ignored.
- **Row actions** come from `getProductActionButtons` (362-399): View, Edit (`products-edit`), History (`product_history`), Barcode (`print_barcode`), Delete (`products-delete`).
- **View modal**: the row's `data-product` array drives it (:279-284, :300-460). The modal calls `products/getdata/{id}/{variant}`, `products/variant-data/{id}` and `products/product_warehouse/{id}`. The last one returns batch_no and expiry per `product_warehouse.product_batch_id`.
- **Bugs and risks**
  - **The permission list comes from the client.** Action buttons are rendered from `$request['all_permission']`, which the browser posts (index:495, PC:356). Hiding a button is cosmetic anyway, because destroy (1896) and deleteBySelection (1876) have no server check.
  - **Likely broken features**: productData never returns a `product` field, but the row's `data-product` (index:511) is set from `data['product']`. The View modal (:280, :284) and bulk delete (`product_data[12]`, :590-592) therefore receive `undefined`. Bulk delete then reports "No product is selected!". UNVERIFIED at runtime.
  - destroy and deleteBySelection soft-deactivate products (`is_active=0`) and delete their image files. They do not check stock or open transactions.
  - Import (PC@importProduct 1276-1392):
    - It matches on **name** (firstOrNew by name, :1337), so re-importing a product **resets `qty` to 0** (:1354). It also inserts a **new `product_warehouse` row for warehouse 1** every time (:1376-1380), which creates duplicates.
    - `expire_date` is forced to 2999-12-31 (:1358).
    - Brands and categories are auto-created (:1322, :1327).
    - box/stripe/piece go through `fill()` but are **not in `$fillable`**, so they are dropped. The `striqty` key is also a typo (:1361).
    - There is no transaction (rows before an error stay committed), no permission check, and the execution-time and memory limits are set very high (8000M).
    - The modal's documented column order (:105) does not match the sample file.
  - Bulk Update (PC@bulkUpdateproduct 1602-1676) updates only box/stripe/piece qty and cost, matched by code. It does use a transaction. Changing cost has no audit trail, and there is no permission check. The older variants `bulkUpdateproduct1` and `bulkUpdateproduct_olds` are dead code.
  - Download (PC@downloadBulkProducts 1681-1755):
    - It is a GET with no permission check that exports **cost and profit for every product** to any logged-in user.
    - It has an N+1 (a `Product_Warehouse` sum per product, :1716) with a **hard-coded `whereDate('updated_at','!=','2025-07-08')`** data hack.
    - `$product->quantity` (:1720) and `$product->expiry_date` (:1730) do not exist, so the expiry column is always blank.
    - `unit` is not eager-loaded and a null unit is fatal (:1726). The Content-Type header is xlsx while the file is CSV.
  - `products.qty`, `product_warehouse.qty` and per-batch quantities are maintained separately with no reconciliation.

### 2.3 Add Product / Edit Product
- **Routes**: GET `products/create` → PC@create (400). POST `products` → PC@store (419). GET `products/{id}/edit` → PC@edit (973). The edit form posts to POST `products/update` → PC@updateProduct (1004) (W:245). The resource `PUT products/{id}` points to a **non-existent `update()`**.
- **Create view** (`V/product/create.blade.php`). The form `#product-form` is submitted by AJAX or Dropzone (:1340-1420).
  - Fields: warehouse_select\* (copied into hidden `stock_warehouse_id[]`), type=standard (hidden), name\*, code\* (Generate button → GET `products/gencode`, a Keygen 8 digit numeric), barcode_symbology\*, file.
  - Further fields: brand_id, category_id\*, unit_id\* plus sale_unit_id and purchase_unit_id (GET `products/saleunit/{id}`), cost\*, price\*, wholesale_price, expire_date, alert_quantity, stock[]\*, box_qty, stripe_qty, piece_qty, custom fields, product_details. Ecommerce mode adds tags/meta/related.
  - Inline modals post to `brand.store` and `category.store`.
  - The tax field is commented out (:1513-1521), and there are no is_batch, is_variant or IMEI inputs in the markup, even though JS handlers for them remain (:1195, :1202).
- **Server validation**: store (421-434) checks only code and name `max:255` and unique among active products. Cost, price, category and unit are **not required server-side**. updateProduct (1007-1021) has the same rules.
- **store side effects**:
  - The name is HTML-escaped before saving (:437), so the database holds encoded text.
  - Images are ignored and forced to `zummXD2dvAtI.png` (:441). `featured` is set to 1.
  - When initial stock is above 0, `autoPurchase` (472-551) creates a `purchases` row (`pr-` reference, status 1, payment_status 2), a `product_purchases` row, a **`payments` row of type Cash with account_id 0**, and upserts `product_warehouse`.
  - The auto purchase can therefore distort cash and purchase reports. It creates **no `product_batches` row**; expiry lives only in `products.expire_date`.
  - box/stripe/piece are assigned (:445-447) but **dropped by `$fillable`** (`app/Models/Product.php:16-75`).
- **updateProduct side effects** (1023-1056):
  - The **edit form's `expire_date` input has no value** (`edit.blade.php:240`), so every save **wipes the expiry date to null** (PC:1031).
  - The stock field (edit:251-260) shows `Product_Warehouse::where(product_id)->first()->qty`, which can be any warehouse or batch row. It is repeated once per warehouse, but only one `stock_warehouse_id[]` is posted (edit:54, the first active warehouse).
  - The server **overwrites** `product_warehouse.qty` and sets `products.qty` to that single value (1047-1055). If that warehouse has no row, `products.qty` becomes **0** (`$initial_stock >= 0` is always true).
  - None of this is recorded as an adjustment or audit entry.
  - There is no permission check (edit checks `products-edit` at :976, the POST does not).
- `create` and `edit` call `Role::firstOrCreate(['id'=>role_id])` (402, 975), which can insert a blank role.
- The name is unique among active products, which blocks real pharmacy duplicates such as the same brand name in different strengths when no strength field exists.
- **Recommendation**: rebuild with a server-side FormRequest, a proper medicine master (generic, strength, form, manufacturer, rack, pack size), and stock entered only through purchases or adjustments.

### 2.4 Print Barcode
- PC@printBarcode (1162-1180) has no permission check.
  - With `?data=code (name)` (sent from the product list row action), it preloads rows through `limsProductSearch` (1197-1245). That method returns name, code, price, a barcode PNG via DNS1D, promo price, currency, qty, id, variant_id and cost.
  - The view `V/product/print_barcode.blade.php` adds products through `products/lims_product_search` (:221). Per-label options are name, price, promo price, business name, brand and sizes. A barcode_setting is selected from `barcodes`.
  - Preview opens GET `/labels/print` (`print.label`) with the whole form serialized in the query string (:268-271).
- **Bugs**:
  - `$default` is computed but not passed to the view (1176), so the default sticker layout is never preselected.
  - LabelsController@printLabel prints **whatever name and price the client sends** (LabelsController:54-62), so a shelf label can show a falsified price. Views escape output (no `{!!` in `print_label.blade.php`).
  - `limsProductSearch` errors if no product is found (`$products` is undefined at 1244).
  - `print_barcode_backup.blade.php` and `print_barcode_rongta.blade.php` are unused copies.

### 2.5 Adjustment List / Add Adjustment
- **Routes**: W:566-572, plus a **duplicate block at W:781-786** inside the ReportController group (W:607-1195). Its actions are wrapped in a nested `controller(AdjustmentController)` group, so they resolve to the correct controller.
  - Endpoints: `qty_adjustment/getproduct/{id}`, `qty_adjustment/lims_product_search` (`product_adjustment.search`), POST `qty_adjustment/deletebyselection`, and the resource (show is not implemented).
- **Index** (`V/adjustment/index.blade.php`):
  - Columns (:18-24): checkbox, Date, Reference, Warehouse, Products, Note, Action (Edit, Delete).
  - Bulk delete posts to `qty_adjustment/deletebyselection` (:183).
  - The list is loaded in full with no pagination (AdjustmentController:28). `staff_access=own` filtering is commented out (:25-27).
- **Create/Edit** (`V/adjustment/create|edit.blade.php`):
  - Fields: warehouse_id\*, document (file), product search using GET `/products/get-search-library` (:133) and `qty_adjustment/lims_product_search` (:264).
  - Each row has unit_cost[] (hidden), qty[], action[] (`+` or `-`), product_code[] and product_id[]. The form also has total_qty, item and note.
  - Also reached from Stock Count, through `stock_count/qty_adjustment.blade.php` (:13, :26, hidden `stock_count_id`).
- **Validation**: none at all (store 178, update 260).
- **Stock effects**: `products.qty` and `product_warehouse.qty` (taking `first()` for product+warehouse, **ignoring `product_batch_id`**), plus `product_variants.qty`. The tables involved are `adjustments` and `product_adjustments`. update and delete reverse the old lines, then apply the new ones.
- **Bugs and risks**
  - **No transactions.** A missing `product_warehouse` row causes a fatal on `null->qty` (232/239) after part of the stock has already been changed.
  - Only index has a permission check. store, update, destroy and deleteBySelection have none, and they hard-delete records and reverse stock.
  - The cost lookup joins `product_purchases.product_id = purchases.id` (69, 91, 148, 157), which is the **wrong key** (it should be `purchase_id`). Unit cost is therefore wrong or falls back to `products.cost`.
  - The `unit_cost` posted by the client is trusted (200).
  - `user_id` is set (188) but is **not in Adjustment `$fillable`**, so who made the adjustment is never recorded.
  - Document upload has no validation and keeps the **original client filename** under `public/documents/adjustment` (191-192, 269-270). Arbitrary file types (for example .php) land in the web root. UNVERIFIED whether the web server would execute them.
  - Batch products: adjusting does not touch `product_batches.qty`.

### 2.6 Stock Count
- **Routes**: W:1087-1094, consisting of POST `stock-count/finalize`, GET `stock-count/stockdif/{id}`, GET `stock-count/{id}/qty_adjustment` and the resource (only index, store and destroy exist, and destroy is empty).
- **Index** (`V/stock_count/index.blade.php`):
  - Columns (:17-26): Date, Reference, Warehouse, Category, Brand, Type, Initial File, Final File, Action.
  - The create modal (:119) takes warehouse_id\*, type (full or partial), category_id[] and brand_id[].
  - The finalize modal (:171) takes final_file\* (CSV), stock_count_id and note.
  - The difference modal loads `stockdif/{id}` and links to `stock-count/{id}/qty_adjustment`, which pre-fills the adjustment form.
- **Logic**: store (37-84) writes a CSV of name, code, IMEI, expected (`product_warehouse.qty`) and an empty Counted column to `public/stock_count/`. finalize stores the uploaded CSV. stockDif and qtyAdjustment compare the expected and counted columns.
- **Bugs and risks**
  - The CSVs are built by string concatenation followed by `explode(',')` (65-75). **Product names containing commas shift the columns.**
  - The files sit in public `/stock_count/` with predictable timestamp names, which leaks stock levels.
  - The "expected" quantity is a stale snapshot taken at creation time.
  - Batch rows produce several lines with the same code.
  - stockDif falls back to a code `LIKE` match (113), which can pick the wrong product. qtyAdjustment fatals when a code is unknown (`$product_data->id`, 162).
  - No permission checks except index. The finalize extension check relies only on the client filename.

### 2.7 Brand
- **Routes**: W:306-313 are POST `importbrand` (`brand.import`), POST `brand/deletebyselection`, GET `brand/lims_brand_search` (`brand.search`, **method does not exist**) and the resource.
  - The view calls POST `/exportbrand` (`brand/create.blade.php:224`), which is **not routed**. `exportBrand()` exists (BrandController:212) but is unreachable.
- **View** (`V/brand/create.blade.php`):
  - Server-rendered table of all active brands (:24-29): Image, Brand, Action (Edit modal, Delete).
  - Create modal: title\*, image, plus ecommerce page_title and short_description. There are also an edit modal and an import modal.
- **Validation**: title `max:255` and unique among active rows. image `image|mimes|max:100000` (BrandController:29, :75).
- **Bugs**:
  - **No permission check in any method**, including index.
  - `destroy` refuses when the brand is used by products (:188-193), but `deleteBySelection` (:149) **skips that check**.
  - `store` mass-assigns `$request->except('image')`; `$fillable` limits the damage.
  - Import takes `image` straight from the CSV.
- **Note**: in pharmacy use, Brand is effectively the manufacturer.

### 2.8 Unit
- **Routes**: W:277-284 and a **verbatim duplicate at W:286-293**. Both contain `importunit`, `unit/deletebyselection` and `unit/lims_unit_search` plus the resource.
  - Because the resource's `GET unit/{unit}` is registered before `unit/lims_unit_search`, that URL resolves to the non-existent `show()`. The search endpoint is dead, and no view uses it.
- **View** (`V/unit/create.blade.php`):
  - Columns: Code, Name, Base Unit, Operator, Operation Value, Action.
  - Create and edit fields: unit_code\*, unit_name\*, base_unit, operator, operation_value.
  - There is also an import modal. The view calls POST `/exportunit` (:283), which is not routed.
- **Validation**: code and name `max:255` and unique among active rows (UnitController:27, :69).
- **Bugs**:
  - Only index has a permission check.
  - Changing `operator` or `operation_value` retroactively changes quantity conversion for every past and future transaction that uses the unit. There is no guard.
  - Delete does not check whether products use the unit.
  - `operator` is free text (the view's placeholder says "Enter your Name").
  - In import, an unknown base unit causes a fatal on `$base_unit->id` (:120).

### 2.9 Barcode Settings
- **Routes**: W:961-967 are GET `/barcodes/set_default/{id}` (→ `setDefault`, **method missing**), POST `barcodes/barcode-data` (`barcodes.data`) and the resource.
- **Views**: `V/barcode/index|create|edit`.
  - Fields: name, description, width, height, top_margin, left_margin, row_distance, col_distance, stickers_in_one_row, paper_width, paper_height, stickers_in_one_sheet, is_default, is_continuous, is_custom.
  - The list shows custom templates only (`is_custom=1`).
- **Validation**: none. Barcode uses `$guarded=['id']`, but the controller uses `$request->only(...)`.
- **Bugs**:
  - `destroy` and `show` are empty (BarcodeController:180, :254), so the Delete button does nothing.
  - Permission checking is correct here, and is the one controller in scope that checks every action.

### 2.10 Labels (not in sidebar)
- **Routes**: W:969-971 are `/labels/show` (`print.labels`), `/labels/add-product-row` (→ `addProductRow`, **method missing**) and `/labels/print` (`print.label`).
- `show` renders `V/labels/show.blade.php`. Its add-row call (:160) fails because the method is missing.
- Only `/labels/print` is in use, by Print Barcode.
- The controller imports the non-existent `App\Product` and `App\SellingPriceGroup` (LabelsController:6-7), which are unused. There is no permission check.
- **Recommendation**: drop `show` and `add-product-row`, and keep `print` behind `print_barcode`.

### 2.11 Variant
- `VariantController` is empty and has no routes.
- The `variants` and `product_variants` tables are still read by PC (`variantData` 948, `productWithVariant` 1188, `limsProductSearch` 1207) and by AdjustmentController.
- The create UI no longer exposes `is_variant`.
- **Recommendation**: likely unused for a pharmacy. Migrate only if data exists. UNVERIFIED: no row counts.

### 2.12 Product routes not reachable from the sidebar

| Route (W line) | Method | Permission check | Linked from |
|---|---|---|---|
| GET `products/history` `products.history` (248) + POST `products/{sale,purchase,sale-return,purchase-return}-history-data` (249-252) | history (553), *HistoryData (579-946) | `product_history` on the page only; the data endpoints have none | Product list row action (PC:377); `V/product/history.blade.php` (:12, :189, :281, :407, :499). The data endpoints use `orWhere` on search (PC:623-636), which escapes the product filter and leaks other products' sales |
| GET `products/get-search-library` `ajax.product.searchlibrary` (236) | getSearchLibrary (50) | none | adjustment create:133 and edit:243, report/adjustment_report:93. N+1 per result when warehouse_id is set (:73) |
| GET `products/search` (239) | search (1074) | none | No view references found. Likely unused |
| GET `products/lims_product_search` `product.search` (244) | limsProductSearch (1197) | none | product create:1098, edit:1034, print_barcode:221 |
| GET `products/gencode`, `products/saleunit/{id}`, `products/getdata/{id}/{vid}`, `products/product_warehouse/{id}`, `products/variant-data/{id}` | | none | create/edit/index view modal |
| GET `check-batch-availability/{pid}/{batch}/{wh}` (260) | checkBatchAvailability (1247) | none | sale/create:963, quotation create/create_sale/edit, return edit, return_purchase edit. The only batch-aware product endpoint |
| GET `products/all-product-in-stock`, `products/show-all-product-online` (258-259) | 1860, 1868 | none. These are **GETs that mass-update every product** (CSRF-free state change) | No view links found. Ecommerce addon gate only |
| POST `exportproduct` `product.export` (257) | **exportProduct does not exist** → 500 | | No links. Dead |
| POST `importproduct`, POST `bulkUpdateproduct`, GET `download-bulk` (254-256) | 1276, 1602, 1681 | none | Product list top buttons |
| POST `products/deletebyselection` (245) | 1876 | none | Product list bulk delete |
| GET `download-sample-products` `sample.download` (180) | closure | none (static file) | Product import modal |
| PUT `products/{id}` (resource) | **update() does not exist** | | Not used. The edit form posts to `products/update` |

### 2.13 Cross-cutting findings (Product group)
1. **Server-side authorization is largely missing.** Almost every write endpoint (product store/update/destroy/import/bulk, category/brand/unit CRUD, adjustment CRUD, stock count) relies on the sidebar or hidden buttons. The product list even takes the permission list from the client (PC:356).
2. **Stock integrity**:
   - `products.qty`, `product_warehouse.qty`, `product_variants.qty` and `product_batches.qty` are updated independently, with no transactions and no ledger.
   - Product edit overwrites stock and can zero it (PC:1041-1056).
   - Import zeroes quantity on existing names (PC:1354).
   - Adjustments ignore batches.
3. **Expiry handling is weak for a pharmacy.** There is a single `products.expire_date`, which is wiped on every edit (edit.blade:240, PC:1031) and forced to 2999-12-31 on import. Batch and expiry exist only through `product_batches`, and no product page creates those rows.
4. **Silent data loss through `$fillable`**: box/stripe/piece qty in product create, edit and import; `adjustments.user_id`; `categories.icon`.
5. **Dead or broken routes**: exportProduct, the products PUT resource, brand.search, `/exportbrand`, `/exportunit`, `unit/lims_unit_search` (shadowed by show), `barcodes/set_default`, `labels/add-product-row`, and the duplicated unit and adjustment route blocks.
6. **Performance**: N+1 in categoryData, downloadBulkProducts, productWarehouseData (Warehouse::find per row) and getSearchLibrary. Adjustment and brand lists are unpaginated.
7. **Destructive cascades**: deleting a category deactivates all its products. Brand bulk delete bypasses the in-use check.

---

## 3. Purchase, Transfer & Returns

Scope: sidebar groups **Purchase** (`sidebar.blade.php:51-74`), **Transfer** (`:190-206`), **Return** (`:208-224`). Controllers: `PurchaseController` (PC), `TransferController` (TC), `ReturnController` (RC, sale return), `ReturnPurchaseController` (RPC). Paths are relative to `backend - ocr pos/`. Line refs: `PC:123` = `app/Http/Controllers/PurchaseController.php:123`; views under `resources/views/backend/`.

### 3.0 Route registration notes (applies to all four modules)

| Topic | Finding |
|---|---|
| Duplicate blocks | Every route in these modules is registered twice: block A `routes/web.php:525-604`, block B `:744-817`. Both sit inside the `['common','auth','active']` group opened at `web.php:140` (UNVERIFIED: closing brace of that group not traced, but both blocks are below it and before EOF at 1195). |
| Which wins | `RouteCollection::addToCollections` keys routes by `method + domain + uri` (`vendor/.../Routing/RouteCollection.php:60-68`). A later identical method+URI replaces the earlier route object **at the original array position**, so block B silently wins, and matching order is unchanged. Duplicate names therefore don't conflict (the old route object is dropped). |
| Nested in ReportController group | `web.php:607` opens `Route::controller(ReportController::class)->group(`, and it closes only at `:1194-1195`, so block B (650-1194) is nested in it. Laravel 10.48.29 `RouteGroup::merge` does `if (isset($new['controller'])) unset($old['controller']);` (`vendor/.../Routing/RouteGroup.php:23-24`), so each inner `Route::controller(X)` overrides ReportController. `Route::resource(...)` and `[Class,'method']` array routes name their class explicitly and are not affected. **Conclusion: block B works as intended.** The only risk is a future bare-string route added inside 650-1194 outside an inner controller group: it would resolve to ReportController. |
| Block-B-only routes | `return-purchase/add-payment` (`addPayment`), `return-purchase/getpayment/{id}` (`getPayment`), `return-purchase/mark-paid` (`markPaid`) at `web.php:806-808`. **None of these methods exist in RPC** (grep finds no `addPayment/getPayment/markPaid` in `ReturnPurchaseController.php`), and no view references them. Calling them throws BadMethodCallException (500). These are dead routes from a half-finished feature. |
| Missing `show` | `Route::resource` registers `GET purchases/{id}`, `transfers/{id}`, `return-sale/{id}`, `return-purchase/{id}`, but none of the four controllers define `show()`, so these return 500. Nothing links to them. |
| Legacy | `routes/web_old.php:210-245` still holds old-style string routes. Not loaded (UNVERIFIED: RouteServiceProvider not checked). |

### 3.1 Purchase → Purchase List

| Item | Detail |
|---|---|
| Menu | Purchase → Purchase List (`sidebar:57`), shown when the role has `purchases-index` (`sidebar:52-54`) |
| Route | `GET /purchases` → `purchases.index` → `PC@index` (`PC:41`) |
| Gate (controller) | `purchases-index` (`PC:45`). The view receives `$all_permission` (the role's permission names) at `PC:69-73`. |
| Purpose | Lists purchases with filters, totals footer, view modal, payments, bulk delete |
| Filters | Date range (default: last 1 year, `PC:61-68`), warehouse, purchase status (0 All/1 Recieved/2 Partial/3 Pending/4 Ordered), payment status (0/1 Due/2 Paid) (`purchase/index.blade.php:15-64`). The form reloads the page via GET, and the values are passed to the DataTable. |
| Header buttons | `purchases-add`: role 6 gets `purchases.new.create`; other roles get `purchases.create`; also Import Purchase (`index.blade:66-73`) |
| Table columns | checkbox, Date, Reference, Supplier, Purchase Status, Grand Total, Returned Amount, Paid, Due, Payment Status, custom fields (`is_table`), Action (`index.blade:77-92`) |
| Data endpoint | `POST purchases/purchase-data` → `PC@purchaseData` (`PC:705-956`). Sort columns: created_at, reference_no, grand_total, paid_amount. `returned_amount` = SUM(`return_purchases.grand_total`) per row (N+1, `PC:870`). Due = grand_total − returned − paid (`PC:873`). |
| Row actions | View (modal); Duplicate (`purchases-add`); Edit (`purchases-edit`); View Payment (`purchase-payment-index`); Add Payment (`purchase-payment-add`); Delete form (`purchases-delete`) (`PC:878-912`) |
| View modal | Header data comes from a hand-built JSON string in the `data-purchase` attribute (`PC:933`). Lines come from `GET purchases/product_purchase/{id}` → `productPurchaseData` (`PC:958`; product [code], batch_no, qty, unit, tax, tax_rate, discount, total, return_qty). Print uses `window.print` (`index.blade:405-414`). There is a document download link. |
| Payments modals | **Add**: `POST purchases/add_payment` → `addPayment` (`PC:1541`). Fields: paying_amount, amount, paid_by_id (1 Cash/3 Credit Card/4 Cheque), cheque_no, account_id, payment_note, purchase_id (`index.blade:~219-279`). **View**: `GET purchases/getpayment/{id}` (`PC:1597`), with edit/delete shown per `purchase-payment-edit/delete` (`index.blade:458-460`). **Edit**: `POST purchases/updatepayment` (`PC:1652`). **Delete**: `POST purchases/deletepayment` (`PC:1727`). |
| Bulk delete | `POST purchases/deletebyselection` with `purchaseIdArray` (`index.blade:705-710`, `PC:1757`). The button is hidden client-side without `purchases-delete` (`index.blade:879`). |
| Email | none for purchases |
| DB tables | purchases, product_purchases, payments, payment_with_cheque, payment_with_credit_card, accounts, suppliers, warehouses, users, currencies, return_purchases, purchase_product_return, custom_fields, pos_setting |

**Server trust of `all_permission`.** `purchaseData` builds the row-action buttons from the client-posted `all_permission` (`PC:887-906`; JS posts it at `index.blade:582`). This affects only which buttons appear. However, the target actions are **not** all re-checked server-side:
- `destroy` checks `purchases-delete` (`PC:1829`). On failure it returns nothing (null response).
- `deleteBySelection`, `addPayment`, `updatePayment`, `deletePayment`, `update`, `store`, `importPurchase` and `purchaseData` have **no permission check**. Any authenticated user can POST to them (CSRF token still required).

**Payment business rules**
- `paid_amount += amount`. `payment_status` = 1 (Due) if balance ≠ 0, else 2 (`PC:1545-1551`). There is no validation that the amount ≤ due, so overpaying is possible.
- Returns are ignored in `payment_status`: a purchase that was partly returned and then paid net shows **Due** forever.
- A Payment row is created with `account_id` and reference `ppr-…` (`PC:1562-1571`). Account balances are derived from payments (UNVERIFIED: AccountsController not checked).
- The Credit Card path performs a real **Stripe charge in USD** against `stripeToken` (`PC:1576-1590`), refunds on edit and delete (`PC:1684`, `1743`), and runs in bulk delete. This is meaningless for supplier payments and dangerous.
- `Payment::latest()->first()` is used to fetch the just-saved id (`PC:1573`), which is a race condition.
- No DB transaction.

### 3.2 Purchase → Add Purchase (two screens) — `create` vs `create-new`

| | **Desktop: `purchases.create`** | **Mobile / role 6: `purchases.new.create`** |
|---|---|---|
| Route | `GET /purchases/create` → `PC@create` (`PC:105`) | `GET /purchases-new/create` → `PC@createNew` (`PC:132`), `web.php:543/760` |
| Who | Sidebar `d-none d-md-block` for roles ≠ 6 (`sidebar:66`); header quick-add (`layout/main.blade.php:228`); index button for roles ≠ 6 | Role 6 always; others on mobile (`d-md-none`, `sidebar:63-65`); index button for role 6 |
| Gate | `purchases-add` (`PC:108`) | `purchases-add` (`PC:138`) |
| View | `purchase/create.blade.php` (1026 lines) | `purchase/create-new.blade.php` (1317 lines) |
| Header fields | Date (`created_at`), Reference (auto-generated JS, `create.blade:394`), Bill No. For role 1 only (`create.blade:44-73`): warehouse select (default id 1), supplier select. Other roles get hidden `supplier_id = {{Auth::user()->role_id}}` (**bug: role id posted as supplier id**, `create.blade:72`) and `warehouse_id = 1`. Status: role 1 sees Recieved/Partial/Pending/Ordered, others only Pending/Ordered (`create.blade:78-85`). Document upload. Hidden currency, exchange rate. Order tax, discount, shipping, note. | Reference only. Hidden `supplier_id = Auth::user()->supplier_id`, `warehouse_id = 1`, `status = 3` (Pending) for everyone, including admins (the `@if(role_id==1)` block is empty, `create-new.blade:128-134`). Shipping and order tax fixed at 0 (hidden). Note. |
| Line items | name/code, qty, recieved (visible when status=2), batch_no, expired_date (required if `is_batch`), net unit cost, discount, tax, subtotal; edit-row modal (qty, unit, cost, discount, tax rate) | Same columns. Status is a hidden input, so `$('select[name=status]').val()` is undefined and `recieved` = 0 (`create-new.blade:718-753`). **The cart is persisted in `localStorage.purchase_products`** (`:506-855`, cleared at `:1133`). |
| Product search | Autocomplete `GET search-product?term=` → `getProductList` (`PC:170`; active standard products with `price>0`, 10 non-variant + 10 variant matches). On select, `GET purchases/lims_product_search?data=code` → `limsProductSearch` (`PC:1115`) returns [name, code, cost, tax rate, tax name, tax_method, units, operators, values, id, is_batch, is_imei]. | Same autocomplete. Select goes to `GET purchases-new/lims_product_search/search` (same method, `web.php:545`) |
| Controller extras | `lims_product_list_*` passed as empty arrays | **Loads the full product list twice** (`productWithoutVariant`, `productWithVariant`, `PC:156-157`), which is heavy and apparently unused by the autocomplete (UNVERIFIED usage in blade). Warehouses are passed under the misleading name `$lims_supplier_list` (`PC:145`). |
| Submit | `POST purchases` → `PC@store` | same `PC@store` |
| Effect | Admin can create Received or Partial → stock in immediately | Always a **Pending purchase order (no stock)**. It is received later via Edit by an admin. |

Summary of the difference: `create` is the full SalePro purchase form (admin can receive stock directly). `create-new` is a simplified mobile / supplier-portal (role 6) "order request" screen that always produces a pending PO for warehouse 1, keeps its cart in localStorage, and has no supplier, warehouse or status choice. Role 6 appears to be a supplier-linked user (`users.supplier_id`; `purchaseData` restricts role 6 to own rows, `PC:729-731`). UNVERIFIED: the role-6 name was not read from the DB.

**`PC@store` (`PC:243-486`) business rules**
- Wrapped in `DB::beginTransaction`/commit/rollBack (good). **No validation and no permission check.** `Purchase::create($request->except('document'))` mass-assigns the fillable fields (`Purchase.php:11`) straight from the client, including `grand_total`, `paid_amount`, `payment_status`, `status` and `user_id` (overwritten). All totals are trusted from the client, with no server recomputation.
- Role 6 defaults: warehouse 1, status 3, supplier = user's supplier (`PC:255-268`).
- Per line, the unit conversion is `recieved × operation_value` (`*`) or `÷` (`PC:342-348`). **Stock is incremented by `recieved` regardless of status.** Pending and Ordered lines post `recieved = 0`, so they have no stock effect. Partial adds only the received qty.
- Batch: `product_batches` is matched on `product_id` + `batch_no` (warehouse-agnostic). If it exists, qty is added and **`expired_date` is overwritten** with the new value. Otherwise a new batch row is created (`PC:352-371`).
- `products.qty += quantity` (`PC:424`). `product_warehouse`: a batch row is keyed `product_id + product_batch_id + warehouse`; a non-batch row is matched on `product_id + warehouse` **without excluding batch rows** (`PC:417-420`). It then sets `product_batch_id = null` on whatever row it found (`PC:429`). **Bug: a non-batch purchase of a product that already has batch rows will add qty to an arbitrary batch row and detach it from its batch.**
- A new batch row in `product_warehouse` copies the price from any priced row (`PC:399-409`).
- Variant products: `product_variants.qty += quantity`.
- **Product cost price (`products.cost`) is never updated** from `net_unit_cost`, so there is no average or last-cost logic. Cost lives only in `product_purchases.net_unit_cost`.
- No Payment row is created. Any `paid_amount` posted is stored without a payment record. The forms send 0, but nothing prevents another value.
- IMEI duplicate checks apply (irrelevant to a pharmacy). Errors redirect to `purchases/create` even when the user came from `create-new` (`PC:449,454,484`).
- Tables touched: purchases, product_purchases, product_batches, product_warehouse, products, product_variants, custom_fields (dynamic columns on `purchases`, `PC:313-325`).

### 3.3 Purchase → Edit / Duplicate (hidden, row actions)

| Item | Detail |
|---|---|
| Edit | `GET purchases/{id}/edit` → `PC@edit` (`PC:1189`, gate `purchases-edit`). The view `purchase/edit.blade.php` posts `PUT purchases/{id}` → `PC@update` (`PC:1212`, **no permission check**). |
| Duplicate | `GET purchases/duplicate/{id}` → `PC@duplicate` (`PC:1518`, gate `purchases-add`). View `purchase/duplicate.blade.php` posts to `purchases.store`, so it is effectively a create. |
| Receiving workflow | Pending POs (from create-new, AI drafts, or create) are received by an admin editing the PO, changing status to Recieved/Partial and saving. |

**`PC@update` logic (`PC:1212-1516`).** Transaction at `PC:1245`. Step 1: for every old line, reverse the old received qty from product, variant, batch and warehouse (`PC:1272-1340`), then delete the line. Step 2: re-apply all posted lines exactly as in `store` (`PC:1342-1471`). Step 3: save only `status`, `bill_no`, `item` and the totals (`PC:1474-1487`), **commit and return at `PC:1488-1489`.**

Bugs:
1. **Header changes are never saved.** `$lims_purchase_data->update($data)` and the custom-field save at `PC:1495-1510` are unreachable code after the `return`. Supplier, warehouse, date, note, document, currency, `paid_amount`, `payment_status` and custom fields are all silently ignored on edit. A new document file is still moved to disk, and the old one deleted (`PC:1230`).
2. **Warehouse change corrupts stock.** New stock goes into `$data['warehouse_id']` (`PC:1381,1394,1411`), but `purchases.warehouse_id` keeps the old value. The next edit or delete then reverses from the wrong warehouse.
3. **`return_qty` is lost.** Lines are deleted and recreated, and `return_qty` is not carried over, so purchase-return history on the lines is reset.
4. **Non-admin edit un-receives stock (likely).** The status `<select>` offers Recieved/Partial only for role 1 (`edit.blade:72-77`). A non-admin with `purchases-edit` opening a Received PO gets a select whose first option is Pending, so JS zeroes `recieved` (`edit.blade:543`). Saving then reverses the full received qty and adds 0. The role-1 restriction is enforced client-side only.
5. Reversal on a non-batch line uses `product_warehouse` `first()` by product + warehouse, with the same batch-row ambiguity as store.
6. Nothing stops reversing stock that was already sold: products, batches and warehouse can go negative.
7. The IMEI check uses the stale `$product_purchase_data` from the previous loop (`PC:1442`).

**Delete:** `DELETE purchases/{id}` → `PC@destroy` (`PC:1826`, gate `purchases-delete`) and bulk `deleteBySelection` (`PC:1757`, no gate). Each reverses the received qty from product, variant, batch and warehouse, deletes payments (with Stripe refunds), deletes lines, then deletes the purchase. Problems:
- **No transaction.**
- **Existing purchase returns are ignored.** Stock already removed by a return is deducted again, and `return_purchases` rows are orphaned.
- No guard against negative stock.
- A null `product_warehouse` gives a fatal error halfway through.
- Bulk delete does not remove IMEIs and does not filter the batch row by `product_id`.
- `updateFromClient` (`PC:1907-2152`) is **unrouted dead code**.

### 3.4 Purchase → Import Purchase By CSV

| Item | Detail |
|---|---|
| Route | `GET purchases/purchase_by_csv` → `purchaseByCsv` (`PC:488`, gate `purchases-add`); `POST importpurchase` (`purchase.import`) → `importPurchase` (`PC:502`, **no gate**) |
| Form (`purchase/import.blade.php`) | warehouse (required), supplier, status (1 Recieved/3 Pending/4 Ordered), document, CSV file, order tax rate, order discount, shipping, note; sample `public/sample_file/sample_purchase_products.csv` |
| CSV columns | `product_code, quantity, purchase_unit_code, cost, discount_per_unit, tax_name` (+ optional IMEI col 7) (`import.blade:72`, `PC:527-549`). There is a header row. Tax "no tax" gives rate 0. |
| Logic | Transaction. Products are looked up by `code` (active only). If status = 1, `products.qty` and the first `product_warehouse` row are updated (`PC:623-663`). Line totals are computed server-side by `tax_method` (1 exclusive, 2 inclusive). Then the header totals and grand total are recalculated (`PC:687-695`). |
| Bugs | **No batch_no or expiry support**: pharmacy batch items get stock added to an arbitrary (possibly batch) warehouse row. `products.tax_method` null leaves `$net_unit_cost` undefined (exception). `Purchase::latest()->first()` (`PC:599`) is a race. Partial status is not offered. No variant support. No product_batches update. `qty` is cast to `(int)`, so fractional quantities are truncated. |

### 3.5 AI / Order-report drafts → purchases (brief)

`POST report/create-purchase-from-orders` → `ReportController@createPurchaseFromOrders` (`ReportController.php:972`; route `web.php:841`; called from `report/order_qty_draft_product_lists.blade.php:572`).
- Groups the client-posted `orders` by `supplier_id` and creates one **Pending (status 3), unpaid** purchase per supplier in the user's warehouse. Lines have `recieved = 0`, so there is **no stock effect** until the purchase is received via Edit.
- Transaction is used. Rows without a supplier are skipped. No permission check seen in the method head (UNVERIFIED for the full body).
- The reference format `Ymd-his-supplierId` differs from the `pr-` prefix used elsewhere.
- The quotation → purchase screen (`quotation/create_purchase.blade.php:15`) also posts to `purchases.store`.

### 3.6 Transfer → Transfer List

| Item | Detail |
|---|---|
| Menu / route | Transfer List (`sidebar:197`) → `GET /transfers` → `transfers.index` → `TC@index` (`TC:29`), gate `transfers-index` |
| Filters | date range (default 1 year), from-warehouse, to-warehouse (`transfer/index.blade.php:33,50`) |
| Columns | Date, Reference, From WH, To WH, Product Cost, Product Tax, Grand Total, Status (1 Completed/2 Pending/3 Sent), Email Sent (`is_sent`) (`index.blade:80-88`) |
| Data endpoint | `POST transfers/transfer-data` → `transferData` (`TC:104-266`). Row actions: View; Edit (`transfers-edit`); Delete (`transfers-delete`), decided from the **client-posted `all_permission`** (`TC:239,244`). If the field is missing, `in_array(null)` throws a TypeError. |
| View modal | `GET transfers/product_transfer/{id}` → `productTransferData` (`TC:904`) |
| Bulk delete | `POST transfers/deletebyselection` (`transferIdArray`) → `TC:1393` (no gate) |
| Bugs | `staff_access=warehouse` uses an ungrouped `->where(from)->orWhere(to)` (`TC:118,144`), so the OR escapes the date and warehouse filters and returns transfers outside the range. In search mode, `orwhere('reference_no')` likewise bypasses the date and staff filters (`TC:186-191`), leaking other warehouses. The detail JSON is built by string concatenation (quotes in a note break the JS). The `$status` variable is overwritten. |

### 3.7 Transfer → Add Transfer / Edit / Delete

| Item | Detail |
|---|---|
| Add | `GET transfers/create` → `TC@create` (`TC:268`, gate `transfers-add`). Also header quick-add (`layout/main.blade.php:252`). Posts `POST transfers` → `TC@store` (`TC:699`, **no gate**). |
| Form fields (`transfer/create.blade.php`) | created_at, from_warehouse_id*, to_warehouse_id*, status (1 Completed/2 Pending/3 Sent), lines (name, code, batch no, qty, net unit cost, tax, subtotal; hidden product_id, product_code, product_batch_id, purchase_unit, imei), shipping_cost, document, note. Client-side checks only: from ≠ to (`create.blade:711`). |
| AJAX | `GET transfers/getproduct/{warehouse}` (`TC:279`; stock list per warehouse, toggling SQL strict mode off and on with `DB::reconnect`); `GET transfers/lims_product_search`; batch check `GET /check-batch-availability/{product}/{batch_no}/{warehouse}` → `ProductController@checkBatchAvailability` (`web.php:260`, `create.blade:319`) |
| Stock rules (store, `TC:742-839`) | Unit conversion on qty. Status ≠ 2: deduct from the source `product_warehouse` (variant, batch row, or first row by product). Status 1: add to the destination (same keying; a missing row is created with batch/variant ids). Status 3 (Sent): deducted from source and **never added anywhere** until the transfer is edited to Completed. Status 2: no stock effect. `products.qty` is unchanged (correct, since the total is net zero). `product_batches.qty` is unchanged (batch qty is global). |
| Edit | `GET transfers/{id}/edit` (`TC:1116`, gate `transfers-edit`) → `PUT transfers/{id}` → `TC@update` (`TC:1129`, no gate). Reverses old effects for status 1 or 3, then applies the new ones. Lines are matched on `product_id` (+variant) and updated in place. |
| Delete | `DELETE transfers/{id}` → `TC@destroy` (`TC:1443`, **no gate**). Bulk `TC:1393`. |
| Email | After store, sends a `TransferDetails` mail to both warehouse emails if set, and sets `is_sent` (`TC:843-900`). |
| DB tables | transfers, product_transfer, product_warehouse, product_variants, units, warehouses, mail_settings |

Bugs and design problems:
1. **No DB transaction** in store, update, destroy or import.
2. **No server-side stock or availability validation.** A null source row gives a fatal error halfway through, leaving partial stock moves. from == to is not checked server-side.
3. Mail failure **`return $e;`** dumps the exception to the browser after stock was moved (`TC:896`). The following lines are unreachable.
4. **destroy/deleteBySelection fatal for `/` units:** `$quantity = $product_transfer_data / $unit->operation_value` divides a model object (`TC:1404`, `TC:1452`), a TypeError on PHP 8.
5. Bulk delete ignores batches (uses the first product row) (`TC:1409-1422`).
6. destroy for status 3 re-adds IMEI from the wrong variable (`TC:1518`).
7. Update matches lines on `product_id` only: two batches of the same product in one transfer get overwritten together (`TC:1379-1383`). Pending lines lose `product_batch_id` (only set for status 1 or 3).
8. The document is saved under the **client's original filename** (`TC:724,1152`), so files can collide or overwrite each other.
9. No permission check on store, update or destroy.

### 3.8 Transfer → Import Transfer By CSV

`GET transfers/transfer_by_csv` (`TC:972`, gate `transfers-add`) → `POST importtransfer` (`TC:983`, no gate). The form has from/to warehouse, status (1/2/3), CSV, shipping, note. CSV columns are `product_code, quantity, product_unit, product_cost, tax_name` (`transfer/import.blade.php:55`).
- Status 1: deducts the source's first `product_warehouse` row and adds to the destination's first row. Status 3: deducts only.
- **No batch support** (wrong for pharmacy). No transaction. Products are looked up by code without an active check.

### 3.9 Return → Sale (Sale Return)

| Item | Detail |
|---|---|
| Menu / route | Return → Sale (`sidebar:216`, gate `returns-index`) → `GET /return-sale` → `return-sale.index` → `RC@index` (`RC:40`, gate `returns-index`) |
| Filters / columns | date range, warehouse. Columns: Date, Reference, Sale Reference, Warehouse, Biller, Customer, Grand Total (`return/index.blade.php:63-69`). |
| Data endpoint | `POST return-sale/return-data` → `RC@returnData` (`RC:71-290`). Edit/Delete buttons come from client `all_permission` (`returns-edit`/`returns-delete`). In search mode, chained `orwhere` bypasses the date and staff-access filters (`RC:132-225`). |
| Add | `Add Return` button / header quick-add opens the modal `#add-sale-return` (`layout/main.blade.php:700-724`). It asks for the Sale Reference and does `GET return-sale/create?reference_no=` → `RC@create` (`RC:292`, gate `returns-add`). The sale must have `sale_status = 1` (completed). |
| Create form (`return/create.blade.php`) | Pre-filled sale lines. Per line: is_return checkbox, qty (max = sold − already returned, `create.blade:84-86`), batch id, imei, unit, net price, discount, tax. Order tax and discount, return_note, staff_note, document. A `cash_in_hand` modal (cash-register) and the hidden `change_sale_status` (`:155`). |
| Store | `POST return-sale` → `RC@store` (`RC:686-958`, **no gate, no transaction, no validation**). `store_old` (`RC:483`) is dead code. |
| Edit | `GET return-sale/{id}/edit` (`RC:1050`, gate `returns-edit`) → `RC@update` (`RC:1066`, no gate). The edit page uses warehouse product pickers: `../getproduct/{wh}`, `../getcustomergroup/{id}`, `../lims_product_search`, `../../check-batch-availability`, `../../cash-register/check-availability/{wh}` (`return/edit.blade:463-702`). |
| Delete | `DELETE return-sale/{id}` → `RC@destroy` (`RC:1462`, **no gate**). Bulk: the JS posts to **`returns/deletebyselection`** (`return/index.blade:334`), but the route is `return-sale/deletebyselection`, so **sale-return bulk delete 404s**. |
| Email | `POST return-sale/sendmail` (`RC:960`). After store, a `ReturnDetails` mail goes to the customer. |
| DB tables | returns, product_returns, product_sales (return_qty), sales (sale_status), products, product_variants, product_batches, product_warehouse, accounts (default account id stored), cash_registers, customers, mail_settings |

Business rules and bugs:
- Store adds stock back to the **sale's warehouse**: products, variant, batch qty and `product_warehouse` (`RC:781-843`). Combo children are increased per `qty_list`.
- **Batch return with a missing warehouse row creates a row *without* `product_batch_id`** (`RC:828-835`), so the batch qty and warehouse batch row diverge.
- `product_sales.return_qty` is matched by `product_id` + sale only (`RC:932-935`). With several lines of the same product (different batches), the first line absorbs every return.
- No server check that qty ≤ sold − returned (client `max` only).
- **No refund Payment, and no change to `sales.paid_amount`**: only `returns.account_id` and `cash_register_id` are stored. Refund accounting is implicit in the reports (UNVERIFIED how reports net returns).
- `change_sale_status` sets the sale to status 4 (returned).
- **Update does not adjust `product_sales.return_qty`** (no reference in `RC:1066-1366`).
- Delete reverts stock and `return_qty`, but for batches deducts the **unconverted** `qty` from `product_batches` (`RC:1526`, also `1434`) while the warehouse gets the converted `$quantity`. It also **unconditionally sets the sale back to status 1** (`RC:1559-1561`).

### 3.10 Return → Purchase (Purchase Return)

| Item | Detail |
|---|---|
| Menu / route | Return → Purchase (`sidebar:219`, gate **`purchase-return-index`**) → `GET /return-purchase` → `RPC@index` (`RPC:34`), which checks **`returns-index`** (`RPC:37`). **Gate mismatch:** a role with only `purchase-return-index` sees the menu but is refused. A role with only `returns-index` can open it. |
| Filters / columns | date range, warehouse. Columns: Date, Reference, Purchase Reference, Warehouse, Supplier, Grand Total (`return_purchase/index.blade.php:63-68`). |
| Data endpoint | `POST return-purchase/return-data` → `RPC@returnData` (`RPC:65`). Row actions check **`returns-edit`/`returns-delete`** (sale-return permissions) instead of `purchase-return-edit/delete` (`RPC:224,229`). The search-mode orwhere bypass is the same as in the other modules. |
| Add | Modal `#add-purchase-return` (`layout/main.blade.php:727-750`) with the purchase reference → `GET return-purchase/create` → `RPC@create` (`RPC:258`, gate `purchase-return-add`). **Any purchase status is accepted**, including Pending with 0 received. |
| Create form (`return_purchase/create.blade.php`) | purchase lines with is_return, qty (**max = ordered qty**, not received − returned, `create.blade:81`), batch, unit, cost, discount, tax; `account_id` select (`:154`), return_note, staff_note, document, order tax |
| Store | `POST return-purchase` → `RPC@store` (`RPC:416-611`, no gate, no transaction, no validation) |
| Edit / Delete | `GET return-purchase/{id}/edit` (`RPC:701`, gate `purchase-return-edit`) → `RPC@update` (`RPC:717`, no gate). `DELETE return-purchase/{id}` (`RPC:990`, no gate). Bulk `return-purchase/deletebyselection` (`RPC:940`, no gate). |
| AJAX | `return-purchase/getproduct/{wh}` (`RPC:275`), `lims_product_search` (`RPC:358`), `product_return/{id}` (`RPC:613`), `sendmail` (`RPC:648`) |
| DB tables | return_purchases, purchase_product_return, product_purchases (return_qty), products, product_variants, product_batches, product_warehouse, suppliers |

Business rules and bugs:
- Store deducts stock from the purchase's warehouse: products, variant, batch and warehouse row (`RPC:486-523`).
- **Combo child variant qty is *increased* (`+=`) instead of decreased** (`RPC:545`).
- A null warehouse row gives a fatal error after partial updates.
- `product_purchases.return_qty += qty`, matched by product only (`RPC:591-596`).
- **Update, destroy and bulk delete never decrement `product_purchases.return_qty`**, so the counter drifts upward.
- Delete adds the batch qty back unconverted (`RPC:971,1019`).
- No supplier refund, Payment, or account entry is created. Supplier due is only implicitly reduced, via `purchaseData` (grand_total − returned − paid) and the supplier due report (`report/supplier_due_report.blade.php:47`).
- The mail uses the raw `Mail::send('mail.return_details')` without `setMailInfo`.

### 3.11 Hidden routes (not in sidebar) and their callers

| Route | Caller |
|---|---|
| `purchases/{id}/edit`, `purchases/duplicate/{id}`, `DELETE purchases/{id}` | purchaseData row actions |
| `purchases/purchase-data`, `product_purchase/{id}`, `getpayment/{id}`, `add_payment`, `updatepayment`, `deletepayment`, `deletebyselection` | `purchase/index.blade` JS and modals |
| `purchases/lims_product_search`, `search-product`, `purchases-new/lims_product_search/search` | create, create-new, edit, duplicate, `quotation/create_purchase.blade:653` |
| `purchases.create` | header quick-add `layout/main.blade:228` |
| `report/create-purchase-from-orders` | `report/order_qty_draft_product_lists.blade:572` |
| `transfers/{id}/edit`, `DELETE transfers/{id}`, `transfer-data`, `product_transfer/{id}`, `getproduct/{wh}`, `lims_product_search`, `deletebyselection`, `importtransfer` | transfer views |
| `transfers.create` | header quick-add `layout/main.blade:252` |
| `return-sale/create`, `return-purchase/create` | header modals `layout/main.blade:700-750` (also index "Add Return" buttons) |
| `return-sale/product_return/{id}` | also `customer_index.blade.php:624` |
| `return-sale/getcustomergroup`, `getproduct`, `lims_product_search`, `sendmail` | return edit view / mail button |
| `return-purchase/add-payment`, `getpayment/{id}`, `mark-paid` | **none; controller methods missing (dead)** |
| `GET {purchases,transfers,return-sale,return-purchase}/{id}` (show) | none; no `show()` method |
| `PC@updateFromClient`, `RC@store_old`, `TC@limsProductSearchOld`, `return/create_backup.blade.php` | dead code |

### 3.12 Cross-cutting assessment and recommendations

| Concern | Status |
|---|---|
| Authorization | Index, create and edit *pages* are gated. **Mutations mostly are not**: store/update in all 4 modules, destroy in TC/RC/RPC, all `deleteBySelection`, all purchase payment endpoints, both imports. The server trusts the client `all_permission` only for button rendering, so server enforcement is missing rather than spoofable. RPC uses the wrong permission names. |
| Transactions | Only purchase store, update and import (and createPurchaseFromOrders) use them. Transfers, returns, purchase delete and payments do not, and every path has many fatal-on-null points, so partial stock corruption is likely. |
| Stock model | Stock is held in `products.qty`, `product_warehouse.qty` (per batch row) and `product_batches.qty` (global per batch, not per warehouse), all updated imperatively in about 15 copy-pasted places. Inconsistent unit conversion (batch revert uses raw qty) and non-batch lookups that hit batch rows will drift these totals. There is no stock ledger or movement table. |
| Pharmacy fit | Batch and expiry are handled in manual purchase and transfer, but **missing from both CSV imports**. Expiry is overwritten on re-purchase of the same batch_no. Cost price is never updated. IMEI, Stripe and combo logic is irrelevant noise. |
| Validation | No FormRequest or server validation anywhere. Totals, prices and quantities are client-computed and trusted. |

| Page | Recommendation |
|---|---|
| Purchase List + payments | **Redesign/migrate**: server-side totals, payment ledger with due including returns, remove Stripe |
| Add Purchase (create / create-new) | **Merge into one** PO workflow (draft → ordered → receive, with partial GRN), mobile-friendly. Preserve the business rule of "role-1-only receiving" but enforce it server-side. |
| Edit purchase | **Redesign urgently** (header not saved, warehouse drift, return_qty loss). Replace "edit to receive" with an explicit Receive action. |
| Import Purchase CSV | Migrate with batch/expiry columns, or retire |
| AI/Order drafts → PO | Preserve (creates pending POs safely). Add a permission check. |
| Transfers (list/add/edit/import) | Redesign: transaction, server stock checks, batch-aware CSV, explicit Send/Receive steps, fix the divide-by-model and `return $e` bugs |
| Sale Return | Migrate: line-level linkage to `product_sale` id, refund payment record, fix bulk-delete URL and return_qty on edit |
| Purchase Return | Migrate: fix gates, cap at received − returned, revert return_qty on delete, supplier credit/refund record |
| Dead routes/methods | Remove (`return-purchase` payment routes, `updateFromClient`, `store_old`, `create_backup`, `web_old.php`) and collapse the duplicated route blocks |

---

## 4. Sale group (non-POS), Online Orders, Quotation, Gift Card, Coupon, Delivery

Code root: `backend - ocr pos/`. All paths are relative to it. Line numbers come from static reading and nothing was run. "CONFIRMED" means the route table and the controller agree. Runtime behaviour was not exercised.

### 4.0 Cross-cutting findings (read first)

| # | Severity | Finding | Evidence |
|---|---|---|---|
| X1 | **CRITICAL** | `GET /update-coupon?data=<dir>` is registered **outside every auth middleware**. It runs `SET FOREIGN_KEY_CHECKS=0`, **truncates every table** in the database, then recursively deletes the directory given in `?data=` (`rmdir`/`unlink`). This is an unauthenticated kill switch. It has nothing to do with coupons. | `routes/web.php:101` (auth groups start at :132 and :140); `app/Http/Controllers/CouponController.php:83-103`. Also present in `database/routes/web.php:96` and `routes/web_old.php:37` (dead copies). |
| X2 | HIGH | **Mis-scoped route block.** `web.php:607` opens `Route::controller(ReportController::class)` and `:608` opens `prefix('report')`. Lines 614-648 therefore register the legacy sale routes as `/report/sales/...` (and `/report/importsale`, `/report/pos`). Routes with string actions bind to `ReportController`, which has **none** of these methods (grep: zero matches for productSaleData, getSale, importSale, limsSaleSearch, getProductByFilter, getGiftCard, recentSale, checkSaleStatus, convertDraftToSale and others). Only the entries that use an explicit `[SaleController::class, ...]` action work (`pos`, `lims_product_search`, `getcustomergroup`, `getproduct/{id}`), and they work only at the `/report/...` URL. | `routes/web.php:607-649` vs `app/Http/Controllers/ReportController.php` (class at :51). `bootstrap/cache` has no `routes-v7.php`, so the live route file is authoritative. |
| X3 | HIGH | **Pages broken because of X2** (they call `/sales/...` URLs that no longer exist): (a) Sale List "View" modal: `$.get('sales/product_sale/'+id)` returns 404, so the product lines never load (`sale/index.blade.php:1305`). (b) **Import Sale By CSV**: the form posts to `route('sale.import')` = `/report/importsale` and hits `ReportController@importSale`, which does not exist, so the request returns a 500 (`sale/import.blade.php:18`, `web.php:616`). The CSV import is completely broken. (c) **Add Sale** (`sale/create.blade.php`): `getcustomergroup/`(:701) and `getproduct/`(:778) resolve to `/sales/getcustomergroup/{id}` and `/sales/getproduct/{id}`, which return 404. `lims_product_search`(:1028) and `get_gift_card`(:1503) resolve to `/sales/lims_product_search` and `/sales/get_gift_card`, which match `Route::resource('sales')` → `sales/{sale}` → `SaleController@show`. That method **does not exist**, so the request returns a 500. Product search and warehouse product loading on Add Sale are broken. (d) **Edit Sale** (`sale/edit.blade.php:712,717,754,761,1032`) has the same 404s and 500s through `../getproduct/` and `../lims_product_search`. (e) Quotation → Create Sale gift-card lookup `../../sales/get_gift_card` returns a 500 (`quotation/create_sale.blade.php:804`). POS works only because `sale.pos` is itself served from `/report/pos` (`web.php:617`), so its relative `sales/...` calls land under `/report/sales/...`. That is fragile and accidental. |
| X4 | HIGH | **Sale List "Download Excel" is broken.** The button points at `url('sales/export-all')` (`sale/index.blade.php:67`), but no `sales/export-all` route exists. Only `online-sales/export-all` exists (`web.php:429,658`). The URL falls into `sales/{sale}`, which calls the missing `SaleController@show` and returns a 500. `SaleController::exportSalesExcel` (:798) is unreachable. |
| X5 | HIGH | **No server-side authorization on destructive or financial endpoints.** `Kernel.php` has no permission middleware. Row actions are filtered only by `$request['all_permission']`, an array **the browser posts** to `sales/sale-data` (`sale/index.blade.php:1081`, consumed at `SaleController.php:602,612,618,623,655`). The endpoints themselves never re-check: `update` (:3296), `destroy` (:4695), `deleteBySelection` (:4550), `addPayment` (:3970), `updatePayment` (:4195), `deletePayment` (:4395), `getPayment`, `genInvoice`, `sendSMS`, `sendMail`, `whatsappNotificationSend`, `saleData` itself (no permission check), all OnlineOrderController endpoints except index/edit/saleByCsv, `PackingSlipController` (all methods including `index`, `store` and `delete`), `ChallanController` (all methods), `DeliveryController` store/update/delete, `GiftCardController::recharge` and destroy, `CouponController` store/update/destroy, `CourierController` (all methods). Any logged-in user (any role) can delete sales, payments and packing slips, finalize challans and recharge gift cards by direct POST. The bulk-delete button is also gated client-side on `env('USER_VERIFIED')` (`sale/index.blade.php:638,1228`). |
| X6 | HIGH | **No DB transactions** in sale `store` (wrapped in `/*DB::beginTransaction()...*/`, `SaleController.php:1155`), `update`, `destroy`, `deleteBySelection`, payments, `DeliveryController`, `ChallanController::store`, or `PackingSlipController::delete`. A partial failure leaves stock, payments and sale rows inconsistent. Where try/catch exists it is `catch(Exception $e)` without `use Exception` in a namespaced file (`PackingSlipController.php:277`, `ChallanController.php:438`). That catches the non-existent `App\Http\Controllers\Exception`, so the rollback never runs and users see a raw 500. |
| X7 | MED | Commented-out sidebar items are HTML comments, so the Blade `@if` and `route()` calls inside them still execute and the links ship in the page source (`sidebar.blade.php:104-113,174-189`). They are harmless only because the routes exist. |
| X8 | MED | Every route block in scope is registered **twice** (web.php 394-522 and again at 650-741). The duplicates are byte-identical and the later registration wins. `POST /importsale` is also registered for `OnlineOrderController` (`web.php:432,661`, name `online-sale.import`) and for `ReportController` under `/report` (:616, name `sale.import`). |

---

### 4.1 Sale List (`sales-index`)

| Item | Detail |
|---|---|
| Menu | Sale → Sale List (`sidebar:93-95`). Group gate is sales-index OR packing_slip_challan OR gift_card OR coupon OR delivery (`sidebar:89`). |
| Route | `GET /sales` `sales.index` → `SaleController@index` (:92). Data: `POST /sales/sale-data` → `saleData` (:162). |
| Gate | Controller: `hasPermissionTo('sales-index') || role_id<=2` (:95). `saleData` has **no check**. |
| Purpose | Server-side DataTable of sales, limited to `sale_type='pos'`. The view forces `sale_type: (sale_type ? sale_type : 'pos')` (`index.blade.php:1085`), so sales without `sale_type='pos'` (for example website orders) are hidden. The DB default for `sale_type` is UNVERIFIED: no migration in the repo defines it, so Add Sale rows could be hidden if the default is not 'pos'. |
| Filters | Date range (default last 1 year, :125-131), warehouse, sale_status (0/1/2/4; **no 3 Draft or 5 Processing option**, blade:44-48), payment_status (0-4), payment_method (`whereHas payments`). Staff scoping applies via `config('staff_access')` own/warehouse for role_id>2 (:184-187). |
| Columns | checkbox, date (Asia/Dhaka), reference, Billed By (biller), customer (+phone, hidden deposit and points), products, total qty (per unit), grand total, paid, due (`grand_total - returned - paid`), payment status badge (heading reads "Status"), custom table fields, options (`blade:83-114, 610-628`). Footer totals. |
| Search | Numeric input searches `reference_no LIKE %x` (suffix only). Any other input is parsed as a date, so searching by name or phone does not work (:290-296). It also joins `product_sales`, which can duplicate rows (UNVERIFIED whether a later groupBy exists). |
| Row actions (`SaleController.php:592-660`) | Generate Invoice (`sale.invoice`), View (modal; product lines **broken**, X3a), Edit (`sales.edit`, or for drafts (status 3) `sales/{id}/create` → `createSale`, which **redirects to POS** (:2622-2636)), Create Packing Slip (only if `config('is_packing_slip')` and status 2 or 5), View Payment, Add Payment, Add Return (`return-sale/create?reference_no=`), Send SMS (modal → `sale.sendsms`), WhatsApp (form POST → `whatsappNotificationSend`, which redirects to a wa.me URL), Add Delivery (`$.get('delivery/create/'+id)` then modal posting to `delivery.store`), Delete (`DELETE sales/{id}`). |
| Modals | `#sale-details`, `#packing-slip-modal` (→ `packingSlip.store`), `#view-payment`, `#add-payment` / `#edit-payment` (Cash, Gift Card, Credit Card, Cheque, Paypal, Deposit, Points; account select), `#add-delivery` (status Packing/Delivering/Delivered, courier, file), `#send-sms` (SMS template). |
| Bulk | Delete-by-selection → `POST sales/deletebyselection` (`blade:1225-1250`). |
| AJAX | `sales/get-sold-items/{id}`, `sales/getpayment/{id}`, `sales/product_sale/{id}` (**404**), `delivery/create/{id}`, `sales/deletebyselection`, `sales/export-all` (**500**), `package.fetchData` (SaaS only). |
| Status codes | sale_status: 1 Completed, 2 Pending, 3 Draft, 4 Returned, 5 Processing (packing slip created). payment_status: 1 Pending, 2 Due, 3 Partial, 4 Paid. delivery.status: 1 Packing, 2 Delivering, 3 Delivered (`SaleController.php:538-579`). |
| DB | sales, product_sales, payments, payment_with_{gift_card,cheque,credit_card,paypal}, customers (deposit/expense/points), deliveries, returns, product_returns, units, custom_fields, sms_templates, gift_cards, accounts, couriers. |

**Delete / bulk delete** (`destroy` :4695, `deleteBySelection` :4550). Problems:
- **Batch stock is decremented instead of restored**: `$lims_product_batch_data->qty -= $product_sale->qty` (:4629, :4775). `update` restores with `+=` (:3468), so delete is the inconsistent path. It corrupts batch and expiry stock, which matters for a pharmacy.
- Linked returns are hard-deleted without reversing the stock they already restored, and then the **full** sale qty is added back. Stock is inflated by the returned quantity.
- Stock is restored only when `sale_status==1`. A Processing sale (5, where the packing slip already deducted stock, see 4.5) or a challan in transit loses that stock permanently.
- Reward points **earned** at store (`:1302-1306`) are not reversed. Points **used** are only reversed through `deletePayment`.
- Coupon `used -= 1` is applied without a null check.
- No permission check and no transaction.

**Payments**:
- `addPayment` (:3970) sets payment_status to 2 when balance ≠ 0 and to 4 when balance = 0. It never sets 3 Partial, and an overpayment shows as Due. Floats are compared with `==`.
- Points payments deduct `ceil(amount/per_point_amount)` (:4102-4107).
- Gift card payments adjust `gift_cards.expense`.
- `deletePayment` redirects with `not_permitted` flash for a success message (:4449).
- No permission checks (`sale-payment-*` is enforced only in JS, `blade:828-830`).

**Recommendation**: **Redesign.** Fix X2-X5 immediately: move lines 614-648 out of the report prefix or point the views at the working URLs, add a `sales/export-all` route, and add server-side `can:` middleware. Rewrite delete/return/stock reversal as one transactional service.

### 4.2 Add Sale (`sales-add`)

| Item | Detail |
|---|---|
| Menu | Sale → Add Sale (`sidebar:98`). POS link sits next to it (`sidebar:97`), out of scope. |
| Route | `GET /sales/create` `sales.create` → `create` (:1114), which renders **`backend.sale.create`** (`create.blade.php`, 1686 lines). Submit: `POST /sales` → `store` (:1152), **shared with POS**. |
| Gate | `sales-add` (:1117). `store` has no permission check. |
| Fields | Date, reference_no (`max:191|required|unique:sales` only if sent, :1157-1162), customer (+ add-customer modal → `customer.store`), warehouse, biller, product search/table (qty, batch, discount, tax, unit), order tax, order discount, coupon, shipping, sale_status, payment_status, payment block (paid_by, amount, gift card, cheque, account), document (ext whitelist jpg/jpeg/png/gif/pdf/csv/docx/xlsx/txt, :1220-1228), sale_note, staff_note, custom fields, currency/exchange rate. Cash-register check modal (`cashRegister.store`). |
| Business rules (store) | Stock is deducted only when `sale_status==1` (:1414,1469,1511), including batch qty (:1490). Status 2 (Pending) reserves nothing. Reward points are earned if `grand_total >= minimum_amount` (:1302-1306). Used points are deducted (:1641). Coupon and gift card balances are updated. Email goes out only for status 1 (:1550). SMS goes out (:1670). |
| Broken | X3c: warehouse product list, product search, customer-group pricing and gift-card lookup all fail (404 or 500). |
| Recommendation | **Likely unused in practice**, because POS is the working entry point. Either fix the URLs or retire it in favour of POS plus a back-office "invoice" form. |

**`sale/create_sale.blade.php` (4851 lines) is orphaned**: no controller renders `backend.sale.create_sale` (grep of app/ and resources/). `sale.draft` (`sales/{id}/create`) → `createSale` redirects to `pos.index` with `draft_sale_id` (:2622-2636). Recommendation: delete.

### 4.3 Edit Sale (hidden; reached from the Sale List row)

- Route: `GET /sales/{id}/edit` → `edit` (:3272), gate `sales-edit`. It renders `sale/edit.blade.php`, which submits `PUT sales/{id}` → `update` (:3296).
- **`update` has no permission check.** It logs the full request payload, including customer data, to `laravel.log` (:3298-3300).
- Any draft (status 3) is force-converted to status 1 on save (:3318-3321).
- Stock: old quantities are restored and new ones deducted, including batches (+= :3468, -= :3695). This only happens when the old or new status is 1.
- Broken AJAX: X3d.
- Recommendation: **redesign** together with 4.1.

### 4.4 Import Sale By CSV (`sales-add`)

- Route: `GET /sales/sale_by_csv` → `saleByCsv` (:3096), gate `sales-add`. It renders `sale/import.blade.php`, which offers the sample file `../sample_file/sample_sale_products.csv`.
- **Submit is broken** (X3b). The working `SaleController@importSale` (:3111) is unreachable.
- `online-sales/sale_by_csv` (OnlineOrderController:1440) renders the same view, so it is broken the same way.
- Recommendation: **likely unused, since it currently cannot work.** Re-route only if the business needs it.

### 4.5 Packing Slip List (`packing_slip_challan`)

| Item | Detail |
|---|---|
| Route | `GET /packing-slips` `packingSlip.index` → `PackingSlipController@index` (:19), **no permission check**. Data: `POST packing-slips/packing-slip-data` (:24). Create: `POST packing-slips/store` (from the Sale List modal). Invoice: `GET packing-slips/invoice/{id}`. Delete: `POST packing-slips/delete/{id}`. |
| Columns / status | reference, sale, amount, status badge (Pending, In Transit, Delivered, Cancelled) (:100-105). Ordered by `CAST(col AS SIGNED)`. The list page has a "Create Challan" form → `challan.create` (`packing_slip/index.blade.php:11`). |
| store (:125) | `reference_no = latest()+1` (race-prone). Creates `packing_slips` and `packing_slip_products`, sets `product_sales.is_packing=1`, and **deducts stock** from products, product_warehouse and product_variants, including combo children (:173-236). It does **not** deduct `product_batches`, so batch stock drifts. Creates or extends the `deliveries` row (status 1). Sets sale_status=5. |
| delete (:291) | Restores product, warehouse and variant stock (no batch), sets sale_status=2, and deletes the **whole delivery** even if other packing slips share it. It does not check whether the slip is already In Transit or Delivered on a challan. No transaction and no permission check. |
| Recommendation | **Preserve the concept, redesign it.** Use a single stock ledger and batch-aware movements, and enforce status guards. |

### 4.6 Challan List (`packing_slip_challan`)

| Item | Detail |
|---|---|
| Route | `GET /challans` `challan.index` → `ChallanController@index` (:22), no permission check. Filters: courier and status (Active/Close). Data: `POST challans/challan-data`. Create form: `POST challans/create` (with packing_slip_id CSV; only slips in Pending status are accepted, :248-271). Save: `POST challans/store`. Print: `GET challans/invoice/{id}`. Money receipt: `GET challans/money-reciept/{id}`. Finalize form: `GET challans/finalize/{id}`, then `POST challans/update/{id}`. |
| store (:274) | No validation. Reference is the last reference + 1. Each slip is set to 'In Transit' and its delivery to status 2 with the chosen courier. Lists are stored as CSV strings (`packing_slip_list`, `amount_list`). |
| finalize/update (:318) | For each slip: if cash, cheque and online are all 0 the slip is marked Failed, otherwise Delivered. `product_sales.is_delivered` is set and the delivery goes to 3 once no slips are Pending. A `payments` row is created per method (`createPayment` :445, default account, open cash register), `sales.paid_amount` is incremented, sale_status=1 is set when all lines are delivered, and payment_status=4 only when the due amount is exactly 0. **Not idempotent**: the code never checks `status=='Close'`, so re-posting creates duplicate payments. Failed slips leave the stock deducted and the sale at Processing. `createPayment` crashes if no default account exists. |
| Recommendation | **Preserve the business flow (courier COD reconciliation) and redesign it**: normalized tables instead of CSV columns, idempotent finalize, and permission checks. |

### 4.7 Online Orders → Orders List (`sales-index` OR `online-orders`)

| Item | Detail |
|---|---|
| Route | `GET /online-sales` `online-sales.index` → `OnlineOrderController@index` (:96), gate sales-index OR online-orders (:100). Data: `POST online-sales/sale-data` (:171). The view passes `sale_type:'website'` (`online-sale/index.blade.php:791`) and the query also hard-filters `sale_type='website'` (:196). |
| Columns | checkbox, date, reference, Rider (`order_trackings` → `riders.full_name`), customer, products, qty, grand total, paid, due, status (tracking `current_status`: pending, processing, in_transit, delivered = "Delivered - Paid") (`:455-520`). |
| Filters | Date, warehouse, and a "Delivery status" select (1 Delivered, 2 In Transit, 4 Returned) that actually filters on **`sale_status`**, so it is mislabelled (`blade:34-37`). Also sale_status, payment_status, payment_method. Search covers reference, customer, phone, biller, rider and IMEI. |
| Row actions (:503-516) | View (`online-sales.show` by reference_no), **Delete (always rendered, no permission)**, Generate Invoice (`sale.invoice`, the SaleController version). |
| Header buttons | "Add Sale" → `online-sales.create` → **`OnlineOrderController@create` does not exist, so it returns a 500**. `store` is missing as well. "Import Sale" → broken (4.4). Download Excel → `online-sales/export-all` → `exportSalesExcel` (:545), which works. |
| Payment status badge bug | `$sale->payment_status || $sale->sale_status==1 ? 'Paid' : 'Due'` (:490). Every non-zero payment_status (1 Pending, 2 Due) renders as **Paid**. |
| Show page | `GET online-sales/{ref}` (resource show, :524) or the hidden duplicate `GET online-orders/show/{reference_no}` (`web.php:1176`). Renders `online-sale/show.blade.php` with a status dropdown (`#order_status`, AJAX → `admin.online-sale.update-status`), Assign Rider modal (→ `admin.online-sale.assign-rider`), and invoice. The "Add Tracking Update" modal has `action=""`, which is dead UI (`show.blade.php:254`). `$sale?->tracking->current_status` fatals if no tracking row exists (:41). |
| assignRider (:253) | Validates order_id and rider_id. Requires an existing `order_trackings` row. Sets `assigned_rider_id`, sets `current_status='assigned'` (a value the list badge does not know), writes history, and sets `riders.status='assigned'`. Transactional. No permission check. Duplicate route `online-orders/assign-rider` (`web.php:1177`). |
| updateStatus (:328) | Accepts any string as status (`required|string`). **`in_transit` deducts stock every time it is selected.** It is not idempotent, so toggling processing → in_transit → in_transit deducts again. The deduction skips unit conversion, `product_batches`, and combos. **`delivered` sets sale_status=1, payment_status=4 and `paid_amount=grand_total` without creating a `payments` row**, so cash and accounts never see the money. Going straight to delivered skips the stock deduction. No transaction and no permission check. |
| destroy / deleteBySelection (:2742, :2613) | A copy of the SaleController versions with the same bugs (batch `-=` at :2679, :2810). It restores stock only if sale_status==1, while online stock was deducted at in_transit. |
| Other copies | OnlineOrderController duplicates about 2,000 lines of SaleController (payments, invoice, getProduct, POS helpers, and `online-pos/{id?}` → `posSale`). Several routed methods are **not defined** in OnlineOrderController or in `app/Traits`: `posSale`, `createSale`, `recentSale`, `recentDraft`, `limsSaleSearch`, `getSale`, `paypalSuccess`, `paypalPaymentSuccess`, `create`, `store`. All of those routes return a 500 (grep-verified). |
| DB | sales, product_sales, order_trackings, order_tracking histories (`$tracking->histories()`; table name UNVERIFIED), riders, products, product_warehouse, product_variants. |
| Recommendation | **Redesign.** Build an explicit order state machine with one stock-reservation point, real payment records on delivery, permissions, and drop the cloned controller. |

### 4.8 Online Orders → Delivery List (`delivery`)

| Item | Detail |
|---|---|
| Route | `GET /delivery` `delivery.index` → `DeliveryController@index` (:28), gate `delivery`. Data: `GET delivery/delivery_list_data` (:43, also gated). Also `product_delivery/{id}`, `create/{id}` (JSON prefill used by the Sale List and Online list "Add Delivery"), `store`, `sendmail` (DeliveryChallan mail), `{id}/edit` (JSON), `update`, `deletebyselection`, `delete/{id}`. |
| Columns | reference, sale reference, packing-slip references, customer, courier, address, products, status (1 Packing, 2 Delivering, 3 Delivered). |
| store (:197) | `firstOrNew` by the **client-supplied** reference_no, so it can overwrite another delivery. **The file upload has no extension or MIME validation**: it is saved under `public/documents/delivery/<ref>.<client ext>`, which is a potential web-shell upload (UNVERIFIED whether the web server executes PHP in that path). Emails the customer when status ≠ 1. No permission check on store, update or delete. |
| Status | The sidebar shows the same link twice: active under Online Orders (`sidebar:128-130`) and commented under Sale (`:104-106`). `delivery/index_new.blade.php` is not referenced, so it is likely dead. |
| Recommendation | **Merge into the order/packing redesign.** Validate uploads now. |

### 4.9 Quotation (hidden; sidebar commented `sidebar:174-189`)

- Routes: `quotations` resource plus `quotation-data`, `product_quotation/{id}`, `lims_product_search`, `getcustomergroup/{id}`, `getproduct/{id}`, `{id}/create_sale`, `{id}/create_purchase`, `sendmail`, `deletebyselection` (`web.php:507-522`, dup :728-741).
- Gates: `quotes-index` (:42), `quotes-add` (:363), `quotes-edit` (:891). `createSale` (:1049), `createPurchase`, `store`, `update`, `destroy` and `deleteBySelection` have **no permission checks**.
- `create_sale` posts to `sales.store`. Quotation status is not updated by SaleController (grep finds no `quotation` there), so a converted quote stays open (UNVERIFIED whether the view sends a quotation id).
- Still reachable by direct URL.
- Recommendation: **likely unused.** Remove the routes or keep them behind a feature flag.

### 4.10 Gift Card (hidden; `sidebar:107-109` commented)

- Routes: `gift_cards` resource, `gift_cards/gencode`, `gift_cards/recharge/{id}`, `gift_cards/deletebyselection` (`web.php:1016-1023`).
- `index` is gated on the **wrong permission, `unit`** (GiftCardController:23).
- `store` and `update` validate (:47, :115).
- `recharge` (:141) adds to `amount` without validation, permission check or transaction. It ignores the `{id}` path param and uses the body's `gift_card_id`.
- Delete is a soft deactivate.
- Balance = `amount - expense`. Sales and payments adjust `expense`.
- Still used **as a payment method** in the Sale List payment modals (`lims_gift_card_list`).
- Recommendation: likely unused as a product. If kept, migrate it as a stored-value ledger.

### 4.11 Coupon (hidden; `sidebar:110-112` commented)

- Routes: `coupons` resource, `coupons/gencode`, `coupons/deletebyselection` (`web.php:1027-1033`), plus `update-coupon` (**X1**).
- `index` is gated on `unit` (CouponController:20).
- `store` and `update` take `$request->all()` with **no validation** (:39-69). `update` ignores `{id}` and uses `coupon_id` from the body.
- Delete is a soft deactivate.
- Sale delete decrements `used`.
- Recommendation: **remove `updateCoupon` and its route immediately.** The module is likely unused. If kept, redesign it with validation and limits.

### 4.12 Courier (hidden; `sidebar:113` commented)

- `couriers` resource (`web.php:1025`). `CourierController` (50 lines) has **no permission checks** at all.
- Used by the challan and delivery courier selects.
- Recommendation: preserve as master data, add permissions.

### 4.13 Riders (People group, `sales-index`, `sidebar:359-361`)

- `riders/` (index gated `sales-index`), `table-loader`, `store`, `update`, `DELETE destroy/{id}` (`web.php:1158-1166`).
- Validator-based create and update.
- Delete is blocked while the rider is assigned to active orders (RiderController:112-130).
- Write endpoints have no permission checks.
- Feeds online-order rider assignment.
- Recommendation: preserve and add permissions.

### 4.14 Hidden routes in scope (not in sidebar)

| Route | Linked from | State |
|---|---|---|
| `sales/{id}/edit`, `PUT sales/{id}` | Sale List row | Works partially (X3d) |
| `sales/{id}/create` (sale.draft) | Sale List row for drafts | Redirects to POS |
| `sales/gen_invoice/{id}`, `sales/sendmail`, `sendsms`, `whatsapp-notification`, payment routes, `get-sold-items`, `custom-products[/{sale_id}]` (gated `sales-index`, view `custom_products.blade.php`) | Sale List / POS | Work. `custom-products` is linked from nowhere found (UNVERIFIED) |
| `sales/recent-sale`, `recent-draft`, `today-sale`, `today-profit/{wh}`, `check-discount`, `getfeatured`, `print-last-reciept`, `paypalPaymentSuccess/{id}` | POS / create | Work |
| `/report/sales/*`, `/report/importsale` (string actions) | Nothing that works | **Broken (X2)** |
| `/report/sales/check-status/{id}`, `test-conversion/{id}`, `convert-draft/{id}` | Debug or POS | Broken (ReportController). `testDraftConversion` is a debug endpoint |
| `online-pos/{id?}` | Unknown | Likely a 500 (no `posSale` in OnlineOrderController) |
| `online-orders/show/{ref}`, `online-orders/assign-rider` | Duplicates of online-sales | Work |
| `quotations/*`, `gift_cards/*`, `coupons/*`, `couriers/*` | Commented menus | Reachable by URL; weak or no permission checks |
| `update-coupon` | None | **Unauthenticated DB wipe (X1)** |
| `/migrate`, `/cc` (`web.php:72-99`) | None | Also outside auth (out of scope; flag to the infra section) |

### 4.15 Recommendation summary

| Page | Verdict |
|---|---|
| Sale List | Redesign (fix X2-X5 now) |
| Add Sale (create.blade) | Likely unused or broken; fix URLs or retire in favour of POS |
| create_sale.blade.php | Dead; delete |
| Edit Sale | Redesign with a transactional stock service |
| Import Sale CSV | Broken; re-route or drop |
| Packing Slip / Challan | Preserve the flow, redesign the data model (CSV columns, no batch stock, non-idempotent finalize) |
| Online Orders | Redesign: state machine, payments on delivery, no cloned controller |
| Delivery | Merge into fulfilment; validate uploads |
| Quotation / Gift Card / Coupon / Courier | Likely unused; remove routes or add proper permissions. Remove `update-coupon` immediately |
| Riders | Preserve and add permissions |

---

## 5. POS Screen (deep dive)

Paths are relative to `backend - ocr pos/`. `pos` = `resources/views/backend/sale/pos.blade.php`, `SC` = `app/Http/Controllers/SaleController.php`, `web` = `routes/web.php`. I read the code only and did not run it. Some findings depend on runtime behaviour. I mark those PLAUSIBLE or UNVERIFIED.

### 5.1 Entry point, route and permission gate

| Item | Finding | Evidence |
|---|---|---|
| Route | `GET pos/{id?}` → `SaleController@posSale` (name `sale.pos`). Plain `pos` is also registered (name `pos.index`). Both are inside `Route::prefix('report')`, so the **real URL is `/report/pos`** | web:617-618 (prefix opened at 608) |
| Middleware | `common, auth, active` | web:140 |
| Sidebar | Links to `route('sale.pos')` and is shown only with the `sales-add` permission | sidebar.blade.php:87,95-96 |
| Server gate | `posSale` checks Spatie `hasPermissionTo('sales-add')` and otherwise redirects back | SC:2434-2435, 2509-2510 |
| `posSale` quirk | `isset($id)` is always true because the default is `''`. So `Sale::find('')` runs on every load and the third `return view` is dead code | SC:2500-2507 |
| Draft edit entry | `createSale($id)` checks `sales-edit` and stores `draft_sale_id` in the session. It redirects to `pos.index`, and `posSale` then loads the draft rows. It does not check ownership or that the sale is a draft (status 3) | SC:2622-2638, 2486-2498 |
| Data loaded per page | All active customers, including phone numbers (cache 24h), customer groups, warehouses, billers, taxes, brands, categories, tables, **all active coupons including codes**, currencies, `Sale::count()`, and custom fields | SC:2442-2483 |
| Data sent to the browser | Deposit balance and points for **every** customer, the full coupon list including codes, the reward settings and the Stripe public key, all as JS variables | pos:349-357, 2299-2301, 2316, 2260 |

### 5.2 AJAX / URL resolution table (page served at `/report/pos`)

A relative URL resolves against `/report/`. If the page is opened as `/report/pos/{id}`, every relative URL resolves under `/report/pos/…` instead and returns 404.

| pos line(s) | URL as written | Abs/Rel | Resolves to | Route hit | Result |
|---|---|---|---|---|---|
| 1530 | `'/products/get-search-library'` (Select2) | root-abs | `/products/get-search-library` | web:236 ProductController@getSearchLibrary | **OK** (no permission check) |
| 2168, 2947 | `url('/sales/getfeatured')` | abs | `/sales/getfeatured` | web:395 SC@getFeatured | OK, but the grid container `.table-container` does not exist in the markup, so the result is never shown |
| 2183, 2943 | `url('/')/sales/getfeatured?page=2` | abs | same | web:395 | OK (dead UI) |
| 2172, 3367, 5091, 5110, 5156 | `url('/sales/recent-sale')/` | abs | `/sales/recent-sale` | web:396 SC@recentSale | **OK** |
| 2176, 3372, 5096, 5113, 5164 | `url('/sales/recent-draft')/` | abs | `/sales/recent-draft` | web:397 SC@recentDraft | **OK** |
| 2244 | `route('package.fetchData')` | named | — | **No such route name** in routes/*.php | Only rendered when `saleprosaas_landlord` is configured. It would throw RouteNotFoundException there |
| 2612 | `route('customer.store')` | named | `POST /customer` | web:381 resource | OK |
| 2626 | `'notifications/mark-as-read'` | **rel** | `/report/notifications/mark-as-read` | none | **404**. The working route is `/notifications/mark-as-read` (web:1129) |
| 2634 | `url('/cash-register/showDetails/')/{wh}` | abs | `/cash-register/showDetails/{wh}` | web:1115 | OK, but **500 when no register is open**: null dereference at CashRegisterController:82 |
| 2659 | `'sales/today-sale/'` | **rel** | `/report/sales/today-sale` | web:643 → ReportController@todaySale | **500. The method is missing** (grep confirms) |
| 2689 | `'sales/today-profit/'+wh` | **rel** | `/report/sales/today-profit/{wh}` | web:644 → ReportController | **500. The method is missing** |
| 2734 | `'sales/getcustomergroup/'+id` | **rel** | `/report/sales/getcustomergroup/{id}` | web:626 [SC, explicit] | **OK**. This is the only call that sets `customer_group_rate` |
| 2745 | `'cash-register/check-availability/'` | rel | `/report/cash-register/…` | none | Would 404, but it is **dead**: the function is redeclared at 2782 and the later declaration wins |
| 2776, 3388 | `url('/')/sales/getcustomergroup/`+id | abs | `/sales/getcustomergroup/{id}` | **none** (not in the SC group web:394-419) | **404**. When the cashier changes the customer (3385-3391), the group rate is **never updated** |
| 2784 | `url('/cash-register/check-availability')/{id}` | abs | `/cash-register/check-availability/{id}` | web:1112 | OK. At 2780 it is called with the **customer id** instead of the warehouse id (`var id` was reassigned at 2775) |
| 2907-2908, 2931-2932 | `url('/sales/getproduct')/{cat}/{brand}` | abs | `/sales/getproduct/{c}/{b}` | **none** at root. It exists only under /report (web:628), where it is bound to ReportController and the method is missing | **404**. The category and brand UI is also absent from the markup (dead) |
| 2967 | `'sales/getproduct/'+wh` | **rel** | `/report/sales/getproduct/{id}` | web:627 [SC explicit] | **OK**. The warehouse stock list the whole POS depends on works only because of this relative path |
| 3073 | `'sales/get-sale/'+id` | **rel** | `/report/sales/get-sale/{id}` | web:615 → ReportController@getSale | **500. The method is missing**. Recent Transaction "View" is broken |
| 3110 | `'sales/product_sale/'+id` | **rel** | `/report/sales/product_sale/{id}` | web:614 → ReportController | **500. The method is missing** (never reached because 3073 fails first) |
| 3105 | `href="documents/sale/…"` | rel | `/report/documents/sale/…` | none | 404 (unreachable) |
| 3320 | `href="sales/{id}/edit"` | **rel** | `/report/sales/{id}/edit` | none | **404**. Recent-sale Edit is broken |
| 3348 | `href="sales/{id}/create"` | **rel** | `/report/sales/{id}/create` | web:640 → ReportController@createSale | **500. The method is missing**. Draft "Edit" is broken. The working route is `/sales/{id}/create` (web:409) |
| 3323, 3351 | `url('/sales')/{id}` DELETE form | abs | `DELETE /sales/{id}` | web:421/650 resource → SC@destroy | OK. **`destroy` has no permission check** (SC:4695+) |
| 3487 | `'check-batch-availability/…'` | **rel** | `/report/check-batch-availability/…` | none | **404**. Manual batch-number entry is never validated. The working route is web:260 at root |
| 3817, 3923 | `url('/sales/get_gift_card')/` | abs | `/sales/get_gift_card` | Matches the resource **`sales/{sale}` show** route. `SaleController` has **no `show()`** | **500** (BadMethodCallException) |
| 4183 | `url('sales/lims_product_search')` | abs | `/sales/lims_product_search` | web:145 SC@limsProductSearch | **OK** |
| 4643 | `url('/')/sales/check-discount?…` | abs | `/sales/check-discount` | web:414 | OK |
| 5082 | form `route('sales.store')` | named | `POST /sales` | resource store → SC@store (pos:274) | **OK** |
| 5103 | `url('/sales/check-status/')+id` | abs | `url()` trims the trailing slash, giving `/sales/check-status123` | resource show → no `show()` | **500** (debug code; the error is swallowed) |
| 5128 | `url('sales/gen_invoice/')+'/'+id` | abs | `/sales/gen_invoice/{id}` | web:402 | OK |
| 192 | `route('sales.printLastReciept')` | named | `/sales/print-last-reciept` | web:411 | OK |
| 1350 | `route('cashRegister.close')` | named | `POST /cash-register/close` | web:1116 | OK |
| camera partial:1 | `route('pos.product.vision-search')` | named | `POST /pos/product/vision-search` | web:142, `throttle:20,1` | OK |

Net effect: Today Sale, Today Profit, Recent-sale View/Edit, Draft Edit, gift card lookup, batch validation, notification mark-read, category/brand filtering and customer-group refresh are all broken. Adding products, saving a sale, printing, recent lists and register details work.

### 5.3 Layout and panels

| Area | Content | Evidence |
|---|---|---|
| Header | Home, Full Screen, POS Settings (permission checked with raw `DB::table('role_has_permissions')` queries in the view), Print Last Receipt, Cash Register Details, Today Sale and Today Profit (both permission-gated in the view only), notifications, user menu | pos:166-273, 172-209 |
| Top fields | Date (editable, so **sales can be back-dated**), Warehouse (hidden if the user has `warehouse_id`), Biller, Customer with "+" add, plus restaurant service/table/waiter when that module is on | pos:291-409, 295 |
| More options | Reference No (free text), Currency and exchange rate, custom fields | pos:417-497 |
| Search row | Select2 product search, Custom-product "+", Camera (vision), Barcode (html5-qrcode) | pos:511-548 |
| Cart table | Product (name, code, in-stock, batch input), Brand, Price, Qty (−/+ and delete), Subtotal | pos:549-671 |
| Totals | Items(qty), Total, Discount (edit button). **The Coupon, Tax and Shipping controls are commented out** | pos:727-749, 739-747 |
| Action bar | Recent Transaction, Draft, Cancel, **Cash only**. No buttons exist for gift card, card, cheque, deposit, points or multiple payment | pos:754-771 |
| Modals | add-payment, order discount, coupon, order tax, shipping, custom product, invoice, edit line, add customer, recent transactions, sale details, register details, today sale, today profit. **Cash-register-open modal commented out** | pos:773-1473, 1240-1270 |
| Product grid | JS populates `.table-container`, `.category-img`, `.brand-img` and `#category-filter`, but **none of these exist in the markup** (dead) | pos:2185-2214, 2892-2964, 3000-3035 |

### 5.4 Keyboard shortcuts and barcode scanning

- **No keyboard shortcuts.** There are no `keydown` or hotkey handlers. Only Enter in the custom-product form (pos:1763-1768) and `onkeyup` saveValue/payment recalculation (pos:4001-4049).
- On-screen virtual keyboard (jQuery Keyboard) when the POS setting `keybord_active`=1 (pos:277-280, 2453-2542).
- **Hardware scanner:** it types into the Select2 AJAX box (`minimumInputLength:1`, `delay:200`, server LIKE search, limit 20). Enter selects whichever result is highlighted when the AJAX call returns. There is no exact-code fast path, so a fast scan followed by Enter can select nothing or a partial match (PLAUSIBLE) (pos:1528-1584; ProductController:50-88).
- Legacy jQuery-UI autocomplete on `#lims_productcodeSearch` (auto-add on a single match, or a 13-character EAN fallback) is attached to what is now a `<select>`. It is effectively dead (pos:3405-3431). The 13-character fallback would also send a 2-segment payload, causing "Undefined array key 3" in limsProductSearch (SC:2767).
- **Camera barcode button** (`barcode()` with html5-qrcode) sets `.val(decodedText).trigger('change')` on the Select2 AJAX select. No matching option exists and `select2:select` is never fired, so the scan most likely does nothing (UNVERIFIED at runtime) (pos:1504-1520).
- **Weight/embedded barcodes:** server logic exists (first 7 chars = code, next 5 = weight/1000) when the 4th `|` segment is 1 (SC:2767-2772). The live Select2 path always sends `|0` (pos:1636), so **embedded barcodes are unsupported in practice**.
- **Batch/expiry auto-select:** none on scan. `getProduct` returns **one arbitrary batch per product** (`groupBy product_id` with MySQL strict mode switched off and `DB::reconnect`, no expiry ordering, so **no FEFO**) (SC:2236-2266). That batch number is pre-filled in the row (pos:4265-4266). **Expired batches are never blocked** client- or server-side. The batch check returns `expired_date` but only checks existence (ProductController:1247-1274), and its URL 404s anyway (§5.2).

### 5.5 Product search, stock lookup and `without_stock`

| Step | Behaviour | Evidence |
|---|---|---|
| Typeahead | `products.name LIKE %t% OR code LIKE %t%`, active, `price>0`, limit 20. **No generic-name search.** `warehouse_id` is not sent, so the displayed qty is the **global** `products.qty` | ProductController:55-67,72-74; Product.php:11-14; pos:1533-1538 |
| XSS | Results are rendered with `escapeMarkup: m=>m`, so product name, brand and category are injected as raw HTML | pos:1583, 1604-1616 |
| On select | Builds `code| name | Qty: N unit |0`. Calls `productSearch`, then a **synchronous** AJAX call to `lims_product_search` with the payload `…?customer_id?qty` | pos:1636-1637, 4139-4186 |
| `limsProductSearch` | Exact `code` match or variant `item_code`. Applies customer discount plans (date, weekday, qty), else the promotion price, else `products.price`. Returns tax, units, flags and cost. **500 (null dereference) if the code is not found** (SC:2808,2811). Price comes from `products.price` while `checkDiscount` uses the warehouse price (inconsistent) | SC:2749-2897, 2905-2912 |
| Out-of-stock gate on first add | Parses digits from the label `"Qty: N"`. 0.5 parses as 0 and is blocked. **A negative stock of -3 parses as 3 and is allowed.** The grid path label `null` would always say out of stock | pos:4219-4229 |
| Warehouse list | `sales/getproduct/{wh}` returns parallel arrays (code, name, qty, type, id, …, warehouse price, batch, expiry). A **hard-coded `whereDate(updated_at,'!=','2025-07-08')`** hides any stock row last updated on that date. The variant and digital/service loops do not push `product_price`, so the price array is **misaligned** for later items | SC:2213-2429 (2214, 2240, 2269, 2290; 2363-2385, 2409-2426) |
| Stock check | `checkQuantity` compares unit-converted qty to `product_qty[pos]` only when `without_stock=='no'`. **If the product is not in the warehouse list (pos<0), there is no check at all**, so stock-less or hidden products are sold freely | pos:4670-4750 (4675-4681, 4689) |
| `without_stock` | Comes from `general_settings`, set in config and the view by Common middleware. It filters `qty>0` server-side in getProduct | Common.php:66; SC:2216-2228; pos:2262 |
| Server | `store()` performs **no stock-availability check**. Stock can go negative | SC:1469-1498 |

### 5.6 Camera / vision search (end to end)

1. **Frontend:** the `#posCameraModal` partial (data-recognition-url, CSRF) (camera-capture.blade.php:1). `pos-camera-capture.js` uses `getUserMedia` (environment camera) (js:429-462). `pos-camera-detector.js` does motion/stability detection (stable for 700ms) to auto-capture (detector:13-34, 273-277). Capture is a canvas JPEG at quality 0.9 (js:573-597). Optional browser `BarcodeDetector` (js:605-616). `fetch` POSTs a multipart `image` plus optional `barcode` with a 30s abort (js:618-657).
2. **Server:** `PosVisionSearchController` (invokable, `throttle:20,1`, web:142-143) checks `sales-add` and returns 403 otherwise (Ctl:43-44). It validates the image (jpeg/png/webp, ≤4MB, ≤4096px) and barcode (≤100) (Ctl:47-50). If a barcode is present it runs an exact `code` match (`PosVisionProductMatcher::codeMatches`) (Ctl:53-73).
3. **AI provider:** `App\Services\OpenAI\ProductVisionService`. HTTP POST to OpenAI `/v1/responses` with `store:false`, a strict JSON schema (product_name, strength, generic_name, manufacturer, barcode, code, confidence), connect timeout 5s and total 20s. Images over 1600px or 1.5MB are re-encoded (ProductVisionService:21-121). **Config keys named:** `OPENAI_API_KEY` and `OPENAI_VISION_MODEL` (default `gpt-4.1-mini`) via `services.openai.*` (config/services.php:33-36). Missing key → RuntimeException → 503.
4. **Matching:** barcode/code exact match, then a name match: first word `LIKE`, up to 60 rows, scored 55-95 with a strength bonus. **Auto-select needs a score ≥90, a 15-point lead and confidence ≥0.8.** `generic_name` and `manufacturer` are read **but unused** (PosVisionProductMatcher:147-194).
5. **Response:** `{status: found|matches|not_found|unavailable, source, reading, matches[{id=code, code, name, quantity(global qty), score}], auto_select, search_term}` (Ctl:65-99).
6. **Back in POS:** if `auto_select.code` is in the warehouse `product_code` array, it fires the normal `select2:select` add flow. Otherwise it opens Select2 pre-filled with the search term (pos:1641-1682).
7. **Failures:** 503 JSON with a friendly message. The log records the exception class and message only, not the image (Ctl:77-92). The JS **auto-retries continuously** while the modal is open (js:693-707, 743-748), so it can hit the 20/min throttle and keep spending OpenAI calls (PLAUSIBLE).
8. **Privacy:** the image goes to a third party (OpenAI). It is not stored locally, and the modal says so (camera-capture.blade.php:48). A `Server-Timing` header is exposed only in the local environment from 127.0.0.1 (Ctl:20-41).

### 5.7 Customer, customer group, line editing and units

- Customer group rate = `customer_groups.percentage/100`, applied as a **markup**: price×rate + price×rate×group (pos:4309-4312). `getCustomerGroup` throws a null dereference if the customer or group is missing (SC:2742-2747).
- **Price compounding bug:** `checkDiscount` ignores the server price (`data[0]`). It multiplies the current `product_price` by the exchange rate and adds the group markup again on **every qty change** (pos:4646). It also runs once right after add (pos:4355), so with a non-zero group percentage prices inflate (PLAUSIBLE when the group percentage is not 0). `$('#discount')` is set from `data[2]` (undefined, NaN) and then overwritten (pos:4647-4660).
- **Line edit modal** (qty, unit discount, price option retail/wholesale, unit price, tax, unit): it is only *shown* for role_id ≤2 (pos:4259-4263, 3614-3690). The server trusts whatever arrives.
- **Unit conversion:** operator `*` or `/` with operation_value, both client and server (pos:4752-4761; SC:1458-1475). Server: `Unit::where('unit_name',…)` by **name**, global first match (SC:1459). `$quantity` is undefined if the operator is neither (SC:1470-1473).
- **Totals math is inconsistent:** `calculateRowProductData` sets a row subtotal that already nets discount and tax. `calculateGrandTotal` and `updateSubtotal` then compute `qty×displayPrice − discount + tax` again, so line discount and tax are **counted twice** in `total_price` and `grand_total`, which then disagree with Σ`subtotal[]` (pos:4829-4833 vs 4944-4952, 4601-4613).
- **Custom (non-catalogue) products:** product_id 0 with code = timestamp. Stored in `custom_product_sales` with no stock effect (pos:1689-2037; SC:1340-1402).
- The **"IMEI" field is polluted:** `limsProductSearch` returns `product_data[2]`, which is the Select2 label `" Qty: N unit "`, as index 18 (SC:2892). `addNewProduct` writes it into `imei_number[]`, twice (pos:4290-4291, 4363-4369), and repeat adds append again (pos:4234-4241). The server saves it to `product_sales.imei_number` (SC:1533) and strips it from `product_warehouse.imei_number` (SC:1511-1521). **invoice58 and a4 print it as "IMEI or Serial"** (invoice58:145-146, a4_invoice:201-202). It is commented out in invoice.blade (184-185), which looks like a workaround.

### 5.8 Order discount, tax, shipping, coupon, gift card, points, membership

| Feature | Client | Server | Evidence |
|---|---|---|---|
| Order discount | Flat or %, **no limit or permission**. Can exceed the total (negative grand total) | Stored as sent (`order_discount` cast to float) | pos:871-899, 4959-4980; SC:1270-1272 |
| Order tax | Modal exists, button commented out. Still applied from the localStorage-restored select | Stored as sent | pos:917-939, 743, 2355, 4958-4990 |
| Shipping | Modal exists, button commented out | Stored as sent | pos:940-956, 746, 4982-4987 |
| Coupon | Validated **only in JS** against the full coupon list (qty/used, expiry, minimum amount). UI commented out | `used += 1` with **no validity check**, also for drafts | pos:4543-4598, 740; SC:1243-1247 |
| Gift card | Balance check only in JS; the list URL returns 500 (§5.2); unreachable from the UI | `expense += amount` with **no balance or expiry check** | pos:3809-3831, 3979-3994; SC:1627-1632 |
| Points redeem | `pointCalculation` reads `input[name=paid_amount]` (actually `paid_amount[]`), so NaN `used_points` | `points -= used_points` with no balance check | pos:3889-3898; SC:1585-1586, 1640-1643 |
| Points earn | — | `floor(grand_total/per_point_amount)` when ≥ minimum. **No sale_status check, so drafts earn points** and a converted draft earns again | SC:1301-1307 |
| Deposit | JS check against `deposit[]` | `customer.expense += amount` with no balance check | pos:3862-3871; SC:1636-1639 |
| Membership | **Not used by POS.** `app/Services/Membership` exists but is not referenced in SC or pos (grep) | — | — |

### 5.9 Payments, paid/due/change

- **Reachable from the UI: Cash only.** `.payment-btn`/`#cash-btn` call `appendRemoveElement('cash')`, which hides the method select and "Add More" (pos:765-769, 3708-3714, 3774-3808). The handlers for `#gift-card-btn`, `#credit-card-btn`, `#multiple-payment-btn` and others bind to elements that do not exist (pos:3734-3768).
- Methods supported server-side (paid_by_id): 1 Cash, 2 Gift Card, 3 Credit Card, 4 Cheque, 5 Paypal, 6 Deposit, 7 Points, 8 Pesapal, or any string (SC:1571-1593).
- **Credit card:** stores the **full card number** and holder name as JSON in `payment_with_credit_card.data`, with charge_id hard-coded to `'12345'` (SC:1617-1626). This is a PCI risk, though not reachable from the current UI.
- Change: client `paying − paid` (pos:4052-4063). Server `payments.change = paying_amount[0] − paid_amount[key]`, always using index 0 (SC:1603).
- Payment status: `balance = grand_total − paid_amount` but **only for the last array element** (foreach overwrite) (SC:1192-1195). Any non-zero balance, including overpayment, sets 2 (due), else 4 (paid) (SC:1199-1202). `1563`: `$data['paid_amount']` is the array again, so `array > 0` is always true (SC:1284, 1563).
- Account: `Account::where('is_default')->first()->id` throws a null dereference if none is set (SC:1597-1598). Payment reference `spr-Ymd-his` is not unique (SC:1600).
- Dangling `if` with no body: when cash and bank are set, it makes `$lims_payment_data = Payment::latest()->first()`, which can pick another terminal's payment (SC:1611-1614).

### 5.10 Hold/draft and recent transactions

- Draft: `#draft-btn` sets `sale_status=3` and submits by AJAX (pos:3716-3728). The server creates the sale with no stock movement. The response is a `redirect('sales')`, which the AJAX call follows (SC:1679-1684). **Coupon usage and reward points are still applied** (SC:1243, 1303).
- **Draft to sale is broken.** Hidden `draft` is always 0 and there is no `sale_id` field (pos:723; grep). As a result, `store()` never deletes the original draft (SC:1204-1211): finalising an edited draft **creates a new sale and leaves the draft**. On draft load, `loadDraftDataToPOS` empties the correct server-rendered rows and rebuilds them from `$product_sale->product->name`, `product_code` and `saleUnit`. `Product_Sale` has no such relations or columns (Product_Sale.php), so the rebuilt rows show **name "N/A", empty code and unit "Pc"** (pos:5244-5284). On submit, `Unit::where('unit_name','Pc')` can be null, which returns 500 (PLAUSIBLE) (SC:1459-1460). Leftover debug endpoints (`convertDraftToSale`, `testDraftConversion`, `checkSaleStatus`, all GET and state-changing, with no permission check) are routed only to ReportController (missing methods) (SC:2528-2603; web:621-623).
- Recent lists: last 10 completed sales and last 10 drafts, own-only when role >2 and `staff_access=own` (SC:2513-2526, 2605-2620). View, Edit and Draft-edit links are broken (§5.2). Delete works with no permission check. Customer name is inserted unescaped (XSS) (pos:3311, 3342).
- **Cart persistence:** the whole `tbody` HTML and per-row prices are kept in **localStorage** and restored on load (pos:2360-2446, 5501-5508). Prices are therefore client-persisted and editable. A script placed **outside `@push`** (pos:5501-5508) renders before the layout HTML, so its MutationObserver likely never attaches (UNVERIFIED).

### 5.11 Cash register dependency

- **A register is NOT required.** The "open register" modal is commented out (pos:1240-1270), so `$('#cash-register-modal').modal('show')` does nothing (pos:2797). `store()` attaches `cash_register_id` only if one is open (SC:1166-1173). Sales without a register are missing from register reports. When no register is found the server does not overwrite `cash_register_id`, so a client-sent value is mass-assigned (Sale::$fillable includes it, Sale.php:19).
- `CashRegisterController`: `store` mass-assigns `$request->all()` (24-31). **`close` closes any register by id with no ownership check** (119-125). `getDetails` has no auth scoping (33-72). `showDetails` returns 500 when no register is open (82). `total_cash` adds *all* payment methods to cash in hand (69, 114). Register queries `user_id + warehouse_id + status` (76-80, 129-133).

### 5.12 `store()`: validation, writes, stock and transactions (SC:1152-1686)

| Aspect | Finding |
|---|---|
| Permission | **None in `store()`**, and the constructor has no middleware (SC:87-90). Any authenticated active user can POST `/sales` |
| Validation | Only `reference_no` (`unique:sales`, max 191) **when supplied**. A document extension whitelist uses the client-supplied extension (SC:1157-1163, 1218-1242). No validation of products, qty, prices, warehouse, customer or payments |
| Reference no | `Ymd-his`: 12-hour clock with no AM/PM and second resolution. Duplicates across terminals or across AM/PM are possible. Auto-generated numbers are not uniqueness-validated, and the migration has no unique index (SC:1189-1190; migration 2018_04_10_085927:18) |
| Status codes | sale_status 1=completed, 3=draft, 5=restaurant (hidden input pos:705-709). payment_status 2=due, 4=paid (SC:1199-1202) |
| Tables written | `sales` (+ custom-field update), `custom_product_sales`, `product_sales`, `products.qty`, `product_variants.qty`, `product_warehouse.qty`/`imei_number`, `product_batches.qty`, combo children, `coupons.used`, `customers.points`/`expense`, `payments`, `payment_with_credit_card`/`gift_card`/`cheque`, `gift_cards.expense`. File to `public/documents/sale` (SC:1281-1653) |
| Stock decrement | Read-modify-write in PHP (`qty = qty - quantity; save()`), with **no row locks and no transaction**. `DB::beginTransaction` and the try/catch are commented out (SC:1155-1156, 1656-1660). Concurrent sales can lose updates. Any mid-way exception leaves a partial sale with stock already moved |
| Batch | Uses the client `product_batch_id` with no check that it belongs to the product or warehouse, or that it has not expired (SC:1465-1467, 1483-1492) |
| Draft replace | Deletes the old draft and its lines **before** creating the new sale, with no transaction (SC:1204-1211), though the flag is never set (§5.10) |
| Client-trusted values | grand_total, total_price, discount, tax, net_unit_price, subtotal, order_discount, coupon_discount, shipping, used_points, paid amounts, created_at (back-dating), sale_status, cash_register_id, coupon_id, gift_card_id. **Nothing is recomputed server-side** (SC:1255-1278, 1323-1333, 1534-1540) |
| Post-commit crash | `$lims_pos_setting_data['send_sms']` is defined only inside the payment loop. If there were no payments and an SMS provider and template exist, this is an undefined variable and a **500 after the sale is saved** (SC:1615 vs 1667) |
| Response | status 1 → plain sale id. Status 1 with draft → redirect to invoice. Otherwise → redirect to /sales (SC:1679-1684) |

### 5.13 Printing, invoices and notifications

- After a successful sale, JS loads `/sales/gen_invoice/{id}` into `#print-layout` and wipes `<head>`. It calls `window.print()` after 50ms, and `onafterprint` triggers a reload on desktop (pos:5126-5154). A stray production URL text (`https: //redpharma…/sales/gen_invoice/43`) at pos:5139 parses as a JS label plus a comment.
- `genInvoice`: the template comes from the POS setting. `invoice_option=='A4'` or `sale_type=='online'` → `a4_invoice`. Thermal 58 → `invoice58`. Otherwise → `invoice` (SC:3955-3967). **No auth or ownership check on `{id}`** (sequential IDs) (SC:3845). Templates' own `auto_print` is commented out (invoice:261-264, invoice58:323-326, a4:359-363). **Expiry date is not printed**, only batch number (invoice:177-179, invoice58:138-140). Product name is rendered with `{!! !!}` (XSS) (invoice:190, invoice58:180, a4:181).
- `printLastReciept`: prints the latest completed sale **globally**, not per user or terminal (SC:3835-3843).
- Email: synchronous `Mail::to(customer)` when a mail setting exists and the sale is completed (SC:1549-1561). SMS: synchronous `SmsModel→SmsService` when an active provider, a default template and POS `send_sms==1` are all present (SC:1662-1675). WhatsApp: a route exists (web:417) but POS never calls it.

### 5.14 Server-side vs client-side permission checks

| Action | Client gate | Server gate |
|---|---|---|
| Open POS | Sidebar `sales-add` | `sales-add` (SC:2435) |
| Save sale or draft | — | **none** (SC:1152) |
| Vision search | — | `sales-add` (Ctl:44) |
| Product list, search, discount, customer group | — | **none** |
| Edit line price/discount/tax | role_id ≤2 shows the edit link (pos:4259) | **none** |
| Order discount | — | **none** |
| Today sale / profit | View-level permission query (pos:197-220) | **none** (and the routes are broken) |
| Delete sale | Confirm dialog | **none** (SC:4695) |
| Close register | — | **none, no ownership check** |
| Invoice view | — | **none, no ownership check** |

Note: the global-key permission cache in Common.php (~76-86) does not affect `posSale`, which uses Spatie directly. The view's header items use raw per-role DB queries (pos:174-208).

### 5.15 Dead or debug code

Commented-out Select2 block (pos:2039-2162), cash-register modal (pos:1240-1270), coupon/tax/shipping triggers (pos:739-747), legacy jQuery-UI autocomplete (pos:3405-3431), product-grid and filter JS with no markup, `confirmDelete` defined 3× (pos:3381, 4087, 4132), `isCashRegisterAvailable` defined 2× (pos:2743, 2782), many `console.log` calls, `\Log::info` in store/recentDraft/productSaleData (SC:1358-1370, 2612, 2617, 2967), debug draft-conversion endpoints (SC:2528-2603), `async:false` AJAX (pos:2242, 4182, 4642), `env()` calls in the view (pos:1328, 1399, 2568). Unauthenticated `GET /migrate` and `GET /cc` (cache clear) routes also exist at web:72-99 (outside this scope but critical).

### 5.16 Top bugs and risks (ranked)

1. **No DB transaction or locking in `store()`**, and stock is decremented by read-modify-write. Partial sales and lost updates are possible. A 500 after commit (SC:1667) invites a retry and a duplicate sale (SC:1155, 1475-1498).
2. **All money values are client-trusted.** Prices, discounts, totals, coupon, gift card, points, deposit and back-dating are accepted without server recomputation or balance checks, and `store()` has **no permission check** (SC:1152-1278, 1563-1653).
3. **Route breakage from the `/report` prefix.** Today Sale/Profit, Recent View/Edit, Draft Edit, gift card and batch-check all fail with 404/500 (§5.2).
4. **Draft lifecycle is broken.** The draft is never deleted on completion, reloaded rows lose name, code and unit, and drafts earn points and consume coupons (pos:723, 5244-5284; SC:1204-1211, 1243, 1303).
5. **Pharmacy safety gaps.** No FEFO, an arbitrary batch is chosen, expired batches can be sold, there is no generic-name search, the stock check is skipped for unlisted products and stock can go negative (SC:2258-2262; pos:4675-4681).
6. **Data quality and security.** The "Qty: N" label is stored as IMEI and printed. XSS through Select2, the recent list and `{!! !!}` invoices. Invoice and register-close IDORs. Card numbers stored in plaintext (latent). Double-counted line discount and tax in totals, and customer-group price compounding (pos:4290, 1583, 4646, 4944-4952; SC:1619-1625, 3845).

### 5.17 Recommendation

**Redesign, keep the data model.** Replace `store()` with a transactional service that has a `sales-add` gate and FormRequest validation. Recompute prices, discounts and taxes from the DB (warehouse price, discount plans, customer group). Lock `product_warehouse`/`product_batches` rows with `lockForUpdate`, use FEFO batch allocation with an expiry block, generate unique references with a DB index, and accept an idempotency key. Fix routing by moving the POS out of the `report` prefix and using `route()` everywhere. Rebuild the POS front end around a single cart state object instead of parallel arrays, the tbody-HTML-in-localStorage approach and synchronous AJAX. **Preserve** the camera/vision pipeline (well-bounded: permission check, throttle, validation, `store:false`, friendly failures). Add generic and manufacturer matching to it and a back-off on auto-retry.

---

## 6. Consolidated top risks (this WP)

| # | Severity | Risk | Evidence |
|---|---|---|---|
| 1 | CRITICAL | Anonymous `GET /update-coupon` truncates all tables and deletes an arbitrary directory; `GET /migrate`, `GET /cc` also have no login check | web.php:72-101; CouponController.php:83-103 |
| 2 | CRITICAL | Sale `store()` has no DB transaction, no row locking and no permission check, and trusts browser prices, totals, discounts, coupon, gift card and points | SC:1155-1156 (commented `beginTransaction`), SC:1152-1278, 1475-1498 (sect. 5.12) |
| 3 | HIGH | `/report` prefix mis-nesting breaks Import Sale, Sale List "View", Add/Edit Sale product loading and many POS side features (Today Sale/Profit, Recent edit, drafts, gift card lookup) | web.php:607-649 (R2/R3); sect. 4.0, 5.2 |
| 4 | HIGH | Most write/delete endpoints across Product, Purchase, Transfer, Return, Sale, Packing Slip, Challan and Online Orders lack server-side permission checks; list action buttons come from a browser-supplied `all_permission` | SC:602-655; ProductController.php:356; sect. 2.13, 3.12, 4.0 |
| 5 | HIGH | Editing a product nulls its expiry date (empty input) and overwrites warehouse stock without audit | product/edit.blade.php:240; ProductController.php:1031, 1041-1056 |
| 6 | HIGH | Deleting a sale decrements batch stock instead of restoring it; returns are double-restored; online-order `in_transit` deducts stock on every selection | SC:4629, 4775; OnlineOrderController.php:328-400, 2679, 2810 |
| 7 | HIGH | Purchase edit returns before `update($data)`, so header changes (supplier, warehouse, date, paid) are lost while stock moves | PurchaseController.php:1488-1496 |
| 8 | HIGH | No FEFO / expired-batch blocking at POS; one arbitrary batch per product; no generic-name search (pharmacy safety) | SC:2258-2262; sect. 5.5 |
| 9 | MEDIUM | Global (not per-user) caching of `user_role` and `role_has_permissions` | Common.php:75-86 |
| 10 | MEDIUM | Product re-import resets stock to 0 and duplicates warehouse rows; new box/strip/piece fields are dropped by `$fillable` | ProductController.php:1354, 1376; Product.php:16-75 |
| 11 | MEDIUM | Transfer delete divides a model object (fatal for `/` units); purchase-return list gated by the wrong permission | TransferController.php:1404, 1452; ReturnPurchaseController.php:37 |
| 12 | MEDIUM | `phpinfo()` exposed; XSS in POS search results and `{!! !!}` invoices; upload endpoints keep original filenames with no type check | web.php:1035-1040; pos.blade.php:1583; AdjustmentController.php:191; DeliveryController.php:197 |

## 7. Hidden routes (not reachable from the sidebar): cross-reference

Per-area detail is in 2.12, 3.11, 4.14 and 5.2. Summary:

| Area | Hidden route(s) | Linked from | Note |
|---|---|---|---|
| Product | products/history, all-product-in-stock, show-all-product-online, check-batch-availability, bulkUpdateproduct, download-bulk, exportproduct, get-search-library, labels/* | product index row actions and buttons, POS, purchase screens (see 2.12) | `exportproduct`, `labels/add-product-row` and `barcodes/set_default` point at missing methods (2.12) |
| Purchase | purchases/{id}/edit, duplicate/{id}, payments (add/get/update/delete), search-product, purchases-new/lims_product_search/search | purchase list row actions, create-new screen | see 3.11 |
| Sale | sales/{id}/edit, gen_invoice, payments, custom-products, sale.draft `sales/{id}/create`, print-last-reciept, sendsms, whatsapp, today-sale/profit | sale list and POS | many broken under `/report` (R2) |
| Quotation | entire quotations resource | sidebar commented; dashboard "Recent Quotation" tab | likely unused |
| Gift card / Coupon / Courier | gift_cards, coupons, couriers resources | sidebar commented; POS gift card/coupon modals | coupon button commented in POS (5.8) |
| Cash register | cash-register/* | POS (register modal commented out, 5.11) | gated only by auth |
| Online POS | `online-pos/{id?}` | online list "Add Sale" | `OnlineOrderController@posSale` missing -> 500 (4.7) |
| Misc | `/home`, `/documentation`, `/ecommerce-documentation`, `addon-list`, `my-transactions/{y}/{m}`, `saas/install/*`, `developer-section`, `new-release`, `bugs`, `version-upgrade`, `bug-update` | none in sidebar | SaaS, auto-update and docs leftovers from the vendor; recommend removal. `developer-section` and auto-update routes are outside `auth` (web.php:106-123): UNVERIFIED whether their controllers self-check |

## 8. Items documented and UNVERIFIED register

- **Sidebar items in scope documented:** 26 live entries (Dashboard 1; Product 7 + Brand, Unit, Barcode Settings = 10; Purchase 3; Sale 6 incl. POS; Online Orders 2; Transfer 3; Return 2), plus 9 hidden or commented modules (Quotation, Gift Card, Coupon, Courier, Delivery-in-Sale, Labels, Variant, Riders note, Cash Register).
- **UNVERIFIED** (code-only, nothing executed):
  - Runtime behaviour of the product View modal and bulk delete (`data['product']` mismatch).
  - Whether uploaded files (adjustment documents, delivery files) are executable by the web server.
  - Default of `sales.sale_type` (no migration defines it); this decides whether Add-Sale sales appear in the Sale List.
  - Meaning of role_id 6 (gets create-new purchase only).
  - Whether the POS camera barcode button does anything; whether the vision auto-retry exhausts the 20/min throttle.
  - Full permission logic of `createPurchaseFromOrders`.
  - Whether `developer-section` / auto-update controllers enforce auth internally.
  - Variant data existence in production DB.
