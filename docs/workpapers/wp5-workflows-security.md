# WP5 — Business Workflows & Security / Tech-Debt Sweep

**System:** Red Pharma POS (Laravel 10.48.29, SalePro-derived)
**Source audited (read-only):** `D:\01 REDPHARMA\00 CODEBASE\backend - ocr pos\` (referred to below as `ocr/`)
**Diff baseline:** `D:\01 REDPHARMA\00 CODEBASE\backend\` (older snapshot)
**Method:** static trace from route → controller → model/service/event. Nothing was executed against a database; `php artisan` was not run. Route-group nesting was checked by brace-matching `routes/web.php` with a PHP tokenizer script.
**Legend:** `[V]` = verified in code; `[U]` = unverified / inferred (depends on runtime config, front-end, or deployment). Paths are relative to `ocr/` unless stated.

**Diff vs older snapshot:** only 8 app files differ (`diff -rq backend/app ocr/app`). The new parts are the AI draft-order generator (`GenerateAiDraftOrders`, `Services/OpenAI/*`, ReportController additions), the POS camera search (`PosVisionSearchController`, `PosVisionProductMatcher`), `Product::scopePosSearchable`, and a Kernel schedule entry. **Every legacy workflow issue below is present in both snapshots** unless noted otherwise.

---

## Status code reference (from code)

| Field | Values observed |
|---|---|
| `sales.sale_status` | 0 = pending web order (Api checkout), 1 = completed, 2 = pending, 3 = draft/hold, 4 = returned (set by ReturnController when `change_sale_status`) |
| `sales.payment_status` | 1 = pending, 2 = due, 3 = partial, 4 = paid (POS only writes 2 or 4) |
| `purchases.payment_status` | 1 = due, 2 = paid |
| `purchases.status` | 1 = received, 2 = partial, 3 = pending, 4 = ordered (set by the UI) `[U]` |
| `transfers.status` | 1 = completed, 2 = pending, 3 = sent |

Stock is stored in **four places that must stay in sync by hand**: `products.qty`, `product_warehouse.qty` (one row per product x warehouse x variant x batch), `product_variants.qty`, and `product_batches.qty`. Nothing enforces consistency: no DB transaction, no row locks, no ledger table. The only history is the documents themselves (sales, purchases, adjustments, transfers, returns).

---

# PART A — BUSINESS WORKFLOWS

## A1. Product creation / edit / import

### A1.1 Create — `ProductController@store` (`app/Http/Controllers/ProductController.php:419-470`)
1. Validates only `code` and `name` unique among active products, with `max:255` (`:421-434`). Price, cost, unit, category, `is_batch` and `is_imei` are **not validated**. `[V]`
2. `$data = $request->except('image','file')`. The name is HTML-escaped and `\n` becomes `<br>` (`:437`). `product_details` has `"` replaced with `@` (`:438`).
3. Forced values: `is_active=true`, `featured=1`, and **`image='zummXD2dvAtI.png'` hard-coded** (`:441`). Uploaded images are discarded, so image upload is effectively disabled in create. `[V]`
4. Pharmacy fields: `expire_date` (a single product-level date, `:440`) and pack fields `box_qty`, `stripe_qty`, `piece_qty` (`:445-447`). **There is no generic-name, strength or dosage-form column.** The only "generic" hit in the codebase is the OpenAI vision prompt. `[V]`
5. `Product::create($data)` (`:449`) is mass assignment from the whole request. The `$fillable` list (`app/Models/Product.php:16+`) includes `qty`, `cost`, `price`, `is_batch`, `is_imei`, `is_variant`, `variant_list`, `promotion_price` and more, so a crafted POST can set opening `qty` directly. `[V]`
6. **Variants are not created.** No `ProductVariant::create` exists in store or update. The old SalePro variant builder was removed, so `is_variant` can be set but has no variant rows. `[V]`
7. Initial stock: for each `stock_warehouse_id[]` with `stock>0`, `autoPurchase()` runs (`:472-551`):
   - creates a `purchases` row with `status=1`, `payment_status=2` (paid), `paid_amount=grand_total`, and cost taken from `product.cost` (± tax);
   - creates `product_purchases` with `recieved=qty`;
   - increments `product_warehouse.qty`, or creates the row;
   - creates a `payments` row with **`account_id = 0`** (`:546`), so opening stock appears as a cash outflow from a non-existent account;
   - **no batch is created even when `is_batch` is set**.
8. `products.qty += Σstock` (`:462-465`). The product and price caches are cleared.
9. Not wrapped in a transaction. The method returns nothing (the AJAX caller relies on a session flash). `[V]`

### A1.2 Edit — `ProductController@updateProduct` (`:1004-1066`)
1. Same weak validation (`:1007-1021`). `$lims_product_data->update($request->except('image','file','prev_img'))` is mass assignment again.
2. **Direct stock overwrite:** for each posted `stock_warehouse_id`, `product_warehouse.qty = stock` (`:1041-1051`). Then **`products.qty = $initial_stock`**, where `$initial_stock` holds only the *last* warehouse's value, and the guard `if($initial_stock >= 0)` is always true (`:1053-1056`).
   - With more than one warehouse, `products.qty` ends up as one warehouse's figure instead of the sum.
   - If the form does not post `stock_warehouse_id`, **`products.qty` is set to 0**. `[V]` Whether the edit form always posts it is `[U]`.
   - No adjustment document is written, so stock edits leave no audit trail.
3. No permission check (see B-AUTH-1).

### A1.3 CSV import — `ProductController@importProduct` (`:1276-1392`)
1. Checks only that a CSV exists. Headers are lower-cased and stripped to `[a-z]`.
2. Per row: brand and category use `firstOrCreate`. The unit must already exist. The product is matched by `firstOrNew(['name','is_active'=>true])`.
3. Then `fill()` with **`qty => 0`** (`:1354`) and **`expire_date => '2999-12-31'`** (`:1358`). Re-importing an existing product therefore **resets its `products.qty` to 0** and gives it a fake expiry. `[V]`
4. `Product_Warehouse::insert([... 'warehouse_id'=>1, 'qty'=>0])` runs **on every row, every import** (`:1376-1380`). Re-imports create duplicate `product_warehouse` rows. The warehouse is hard-coded to 1.
5. No transaction. If a row fails mid-file, the import is left partially applied.
6. Bulk-update variants exist at `:1395`, `:1504` and `:1602` (three copies, two of them dead). Not traced line by line.

### A1.4 Export
A Yajra datatable feeds `ProductController@productData` (`:300-360`). An Excel export exists `[U]` (not traced).

---

## A2. Purchase → stock in

### A2.1 Purchase store — `PurchaseController@store` (`app/Http/Controllers/PurchaseController.php:243-486`)
1. `DB::beginTransaction()` (`:245`). This is **one of the few transactional flows**. The catch block rolls back and flashes `$e->getMessage()` to the UI (`:482-485`).
2. `$data = $request->except('document')`. `reference_no` defaults to `pr-YmdHis` using 12-hour `h` (`:253`), which can collide.
3. Supplier role (`role_id==6`) gets warehouse 1, status 3 and its own supplier_id by default (`:255-269`).
4. Document upload: extension allow-list `jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt` (`:279-288`).
5. **`Purchase::create($data)` (`:310`)**: totals, `grand_total`, `paid_amount`, `payment_status` and `status` are **all taken from the client**. No server recomputation. `[V]`
6. Per line (`:341-477`):
   - Unit lookup by **unit_name string** (`:342`). Received base qty = `recieved × or ÷ operation_value` (`:344-348`).
   - **Batch** (`:352-373`): `ProductBatch` is looked up by `(product_id, batch_no)`, **not scoped to warehouse**. If it exists, **`expired_date` is overwritten with the new purchase's date** and `qty += quantity`. Otherwise a batch is created. No check that `expired_date` is in the future or non-null.
   - Variant: `product_variants.qty += quantity` (`:375-394`).
   - Warehouse row: a batch product matches `(product, batch, warehouse)`; a plain product matches `(product, warehouse)` and takes `->first()`. **If a non-batch purchase hits a batch row, the code then overwrites that row's `product_batch_id`** (`:429`).
   - `products.qty += quantity` (`:424`).
   - IMEI de-duplication check (`:443-462`).
   - A `product_purchases` row is created with client `net_unit_cost`, `discount`, `tax` and `total` (`:465-476`).
7. **No cost update:** `products.cost` is never recalculated. There is no weighted-average or FIFO cost. Profit reports use `product_purchases` or `products.cost` `[U]`.
8. **No payment row is created at store time**, even though `paid_amount` is persisted from the client. Payments come only from `addPayment`.
9. Commit (`:479`). There is no lock on the batch or warehouse rows, so two concurrent purchases of the same batch lose updates (read-modify-write).

### A2.2 Purchase payment — `PurchaseController@addPayment` (`:1541-1596`)
1. **No transaction and no permission check.**
2. `purchase.paid_amount += amount`. `payment_status` = 2 only if the balance is exactly 0, otherwise 1. Overpayment is still flagged "due".
3. `paid_by_id` maps 1 to Cash, 2 to Gift Card, 3 to Credit Card, and anything else to Cheque. `account_id` comes from the client.
4. A `payments` row is inserted. Then **`Payment::latest()->first()`** (`:1573`) is used to fetch "its" id. Under concurrency this returns another user's payment, so cheque or card side-records attach to the wrong payment. `[V]`
5. `updatePayment` (`:1652`) and `deletePayment` (`:1727`) mirror this.

### A2.3 Purchase edit — `PurchaseController@update` (`:1212-1516`)
1. Transaction starts at `:1245`. `payment_status` is recomputed from the client's `grand_total` and `paid_amount`.
2. **Reversal:** for every old `product_purchases` row, the old received base qty is subtracted from variant, batch, `products` and `product_warehouse` (`:1280-1340`). No negative check is made: if part of the batch was already sold, stock goes negative.
3. **Re-application:** same logic as store (`:1341-1470`), including the batch expiry overwrite.
4. Header totals are set field by field from the client (`:1476-1488`). Then **`DB::commit(); return redirect(...)` at `:1489-1490`**. The `$lims_purchase_data->update($data)` and custom-field code at `:1497-1512` is **unreachable dead code**, so `paid_amount`, `supplier_id`, `warehouse_id`, `note` and similar fields are **never updated** by an edit. `[V]`
5. A warehouse change is not handled: reversal uses `$lims_purchase_data->warehouse_id`, re-application uses `$data['warehouse_id']`, and the header warehouse is never saved. `[U]` (the exact effect depends on the form).

### A2.4 Purchase delete — `PurchaseController@destroy` (`:1826-1905`) and `deleteBySelection` (`:1757`)
1. Subtracts received qty from variant, batch, product and warehouse rows.
2. Payments: deletes cheque side-rows, issues **Stripe refunds** for card payments when a Stripe key is configured, then deletes the payments. Not transactional.
3. Hard delete of the purchase. Returns linked to the purchase are not checked.
4. A permission check exists in `destroy` (`hasPermissionTo('purchases-delete')`) `[U]`. `deleteBySelection` has none.

### A2.5 Purchase return — `ReturnPurchaseController@store` (`app/Http/Controllers/ReturnPurchaseController.php:416-716`)
1. No transaction and no permission check.
2. Per line: `variant.qty -=`, `batch.qty -=`, `products.qty -=`, `warehouse.qty -=` (`:500-520`). **No check that the returned qty is ≤ received − already returned**, and no negative-stock check.
3. Combo children: the **variant qty is incremented (`+=`) while product and warehouse are decremented** (`:545` vs `:555-556`), which is a sign bug.
4. Updates `product_purchases.return_qty`. No money movement is recorded except `return_purchases.grand_total` / `account_id`, which the account balance formula uses (A7.6).

---

## A3. Sale (POS + "Add Sale" form) — `SaleController@store` (`app/Http/Controllers/SaleController.php:1152-1686`)

**Entry points:** POS screen `GET /pos` → `posSale()` (`:2432`, checks `sales-add`), which submits `POST /sales` (resource store). The classic form `GET /sales/create` → `create()` (`:1114`, checks `sales-add`) submits to the same `store()`. **`store()` itself has no permission check** (B-AUTH-1).

### Steps
1. `$data = $request->all()` (`:1154`). **The transaction is commented out** (`:1155-1156`, `:1656-1660`). `[V]`
2. `reference_no` is validated `unique:sales` only when the client supplies it (`:1157-1163`). Otherwise it is generated as `Ymd-his` (POS, `:1190`) or `sr-Ymd-his` (`:1215`), with 12-hour `h`, no am/pm and no uniqueness retry. Two sales in the same second, or 12 hours apart, can collide.
3. `user_id = Auth::id()`. The **cash register** is looked up as the open register for `(user, client-supplied warehouse_id)` (`:1166-1173`).
4. `created_at` can come from the client (`:1175-1178`), which allows back-dating.
5. **Payment status (POS only, `:1188-1203`):**
   ```php
   foreach($new_data['paid_amount'] as $paid_amount) { $balance = $data['grand_total'] - $paid_amount; }
   ```
   Only the **last** split payment is compared to the **client-supplied** `grand_total`. With split tender (for example 50 cash + 50 card on 100), the balance is 50, so `payment_status=2` (due) even though the sale is fully paid. Exact equality decides between 4 (paid) and 2. Non-POS sales take `payment_status` **directly from the client**.
6. **Draft finalisation (`:1204-1211`):** if `draft` is set, the previous draft sale and its `product_sales` are **hard-deleted**. Its coupon usage and legacy reward points are not reversed (see 10).
7. Document upload with extension allow-list (`:1218-1242`).
8. **Coupon (`:1243-1247`):** `if($data['coupon_active']) Coupon::find(coupon_id)->used += 1`. **No server validation** of expiry, quantity, minimum amount or active flag; those checks exist only in POS JavaScript (`resources/views/backend/sale/pos.blade.php:4548-4570`). `coupon_discount` is client-supplied.
9. **Totals:** `total_discount`, `total_tax`, `total_price`, `order_tax`, `order_tax_rate`, `order_discount`, `shipping_cost` and `grand_total` are only cast to float (`:1255-1278`). Then `Sale::create($data)` (`:1281`), mass assignment of the `Sale::$fillable` list (`app/Models/Sale.php:18-20`), which includes `paid_amount`, `payment_status`, `sale_status`, `coupon_discount` and `grand_total`.
   ⇒ **The server trusts client prices, discounts, tax, totals, `grand_total` and `paid_amount`.** It never reads `products.price` when saving a sale. `[V]`
10. **Legacy reward points earn (`:1300-1307`):** if `RewardPointSetting.is_active` and `grand_total >= minimum_amount`, then `customers.points += floor(grand_total / per_point_amount)`. This happens **regardless of `sale_status` (drafts earn too) and of `payment_status`**. Finalising a draft deletes the draft without reversing its points and the new sale earns again, so **points are earned twice**.
11. `Sale::saved` hook (`app/Models/Sale.php:10-17`) dispatches `MembershipReviewRequested`. Two listeners run (A9):
    - `ReviewCustomerMembership` (queued, `afterCommit`; `QUEUE_CONNECTION=sync` in `.env`, so it runs inline), which credits membership `reward_balance`;
    - `RewardServiceProvider` closure (`app/Providers/RewardServiceProvider.php:20-27`), which runs `RewardEngine::syncSale` and credits `reward_wallets`.
    - It also calls `Schema::hasTable()` on **every sale save**.
12. **Custom products** (`product_id==0` or code `CUSTOM-…`, `:1340-1402`) go to `custom_product_sales` with client name, price and total. No stock effect. Inputs are logged to `laravel.log` (`:1358`).
13. **Regular lines (`:1405-1542`):**
    - `Product::find`; missing products are **silently skipped** (`:1409-1411`) while the header totals still include them.
    - Combo children are decremented when `sale_status==1` (`:1414-1456`).
    - Unit by `unit_name` (`:1459`). The base qty conversion uses `*` or `/`.
    - **Stock is deducted only when `sale_status == 1`** (`:1469`). Drafts (3) and pending (2) do not touch stock:
      - `products.qty -= q`
      - variant: `product_variants.qty -= q`, warehouse row by (product, variant, warehouse)
      - batch (when the client sends `product_batch_id`): `product_batches.qty -= q`, warehouse row by (batch, warehouse); **the batch's product is not checked against the line's product**
      - else: warehouse row by (product, warehouse, no variant)
      - `product_warehouse.qty -= q`
    - **No availability check and no negative-stock guard.** `without_stock` only affects which products the POS lists (`getProduct`, `:2216-2227`).
    - **No expiry check:** batch selection is the client's choice; the POS product feed and the save path never compare `product_batches.expired_date` to today. **Expired stock can be sold.** `[V]`
    - IMEI strings are removed from `product_warehouse.imei_number` (`:1511-1521`).
    - A `product_sales` row is created with client `net_unit_price`, `discount`, `tax_rate`, `tax` and `total` (`:1531-1541`).
14. **Email** `SaleDetails` goes to the customer if a mail setting exists (`:1549-1561`). The error message contains raw HTML and is rendered with `{!! !!}`.
15. **Payments (`:1563-1655`):** for each `paid_by_id[k]` with `paid_amount[k] > 0`:
    - `paying_method` mapping: 1 Cash, 2 Gift Card, 3 Credit Card, 4 Cheque, 5 Paypal, 6 Deposit, 7 Points, 8 Pesapal, otherwise the raw string.
    - **`account_id` = the default account** (`Account::where('is_default',true)`), and **`cash_register_id`** = the open register.
    - `change = paying_amount[0] - paid_amount[k]`, always against the first tendered amount.
    - Then **`Payment::latest()->first()`** (`:1613`) is used to get the id. This is a race (see A2.2).
    - **Credit Card:** stores `{card_number, card_holder_name, card_type}` as JSON in `payment_with_credit_card.data` with `charge_id='12345'` (`:1617-1626`). This is **raw card-number storage** (PCI); no gateway charge happens in POS store.
    - **Gift Card:** `gift_cards.expense += amount` and a `payment_with_gift_card` row. **No balance check** (amount − expense), no expiry check, and `gift_card_id` comes from the client.
    - **Deposit:** `customers.expense += amount`. **No check that deposit − expense ≥ amount.**
    - **Points:** `customers.points -= used_points` (client-supplied). **No balance check**; points can go negative. The money value of the points is not validated.
    - **Pesapal:** calls `submitOrderRequest` and **returns early** (`:1644-1651`), skipping the remaining payments and SMS.
16. **SMS (`:1663-1675`):** when a default SMS template and an active SMS provider exist and `pos_setting.send_sms==1`, `SmsModel::initialize`. `$lims_pos_setting_data` is only defined inside the payment loop, so an **unpaid (credit) sale with SMS configured raises an undefined-variable error (500) *after* the sale and stock writes are committed.** `[V]` (code path) / `[U]` (whether SMS is configured in prod)
17. **Response:** a completed POS sale returns the bare sale id (the JS then opens `sales/gen_invoice/{id}`); a finalised draft redirects to the invoice; everything else redirects to `/sales`.

### A3.1 Invoice / receipt
`SaleController@genInvoice` (`:3845-3968`) renders `backend/sale/invoice.blade.php` (also `invoice58`, `a4_invoice`). `printLastReciept` (`:3835`). Product names are output with `{!! $product_name !!}` (`invoice.blade.php:190`), and batch numbers are concatenated in unescaped (stored-XSS vector via a purchase `batch_no`). `[V]`

### A3.2 Draft / hold
- Saved with `sale_status=3` through the same `store()`. No stock deduction, **but legacy points are earned and coupon `used` is incremented.**
- Resume: `GET sales/{id}/create` → `createSale()` (`:2622`, checks `sales-edit`), which re-posts to `store()` with `draft=1, sale_id`.
- **`GET sales/convert-draft/{id}` (`:2528`) and `GET sales/test-conversion/{id}` (`:2557`)** flip `sale_status` from 3 to 1 **without deducting stock or creating payments**. They are GET, CSRF-able, and have no permission check. `[V]`

### A3.3 Add payment to a sale — `SaleController@addPayment` (`:3970-4131`)
`paid_amount += amount`. Status becomes 4 only on an exact balance of 0, otherwise 2. `account_id` comes from the client. `Payment::latest()` race (`:4023`). The gift-card path has no balance check. The **Stripe** path charges in **USD** (`'currency' => 'usd'`, `:4051`). No transaction and no permission check.

### A3.4 Sale edit — `SaleController@update` (`:3296-3833`)
1. No permission check (`edit()` checks `sales-edit` at `:3276`, `update()` does not). No transaction.
2. Draft conversion: if the stored status is 3, it is forced to 1 (`:3318-3322`).
3. `paid_amount = Σ client paid_amount`; `payment_status` from `grand_total − paid` (`:3351-3357`). **The `payments` table is not touched**, so `sales.paid_amount` and `Σpayments` diverge after any edit.
4. **Reversal (`:3402-3500`):** only if the *old* status was 1. Adds back combo children, variant, batch, product and warehouse qty, and restores IMEI. Then deletes the old `product_sales` and all custom product lines.
5. **Re-application:** deducts again only if the *new* status is 1. Same unchecked logic as store, with no expiry and no negative-stock check.
6. `$lims_sale_data->update($data)` (`:3774`), mass assignment. **`Log::info('About to update sale with data: '.json_encode($data))` (`:3773`)** writes the full request, including any card fields and customer data, to the log.
7. Coupon, gift card, deposit, points and reward deltas are **not reconciled** on edit.

### A3.5 Sale delete — `SaleController@destroy` (`:4695-4841`) and `deleteBySelection` (`:4550`)
1. No permission check, no transaction, **hard delete**. There is no "cancelled" status anywhere; cancellation equals deletion.
2. Linked `returns` and `product_returns` are **deleted without reversing the stock those returns had added back** (`:4699-4703`). Restoring the full sold qty below then double-counts the returned units.
3. Stock restore only if `sale_status==1` (`:4756`). Restores `product_sale->qty`, not `qty − return_qty`.
   - **BUG:** for batch lines, `$lims_product_batch_data->qty -= $product_sale->qty;` (`:4775`). The batch is **decremented again instead of incremented**, while warehouse and product are incremented. Every deleted batch sale drives `product_batches.qty` down by 2× the qty relative to the warehouse row. `[V]`
4. Payments: gift-card expense is reversed, deposit expense is reversed, cheque/card/paypal side rows are deleted, and payments are deleted. **Points used are not refunded; legacy points earned are not removed.** A coupon `used -= 1`.
5. Deletes the `deliveries` row and the document file.

### A3.6 Other sale paths (noted, not traced line by line)
`importSale` (`:3111`, CSV sale import, no permission check), `paypalSuccess` / `paypalPaymentSuccess` (`:1912`, `:1971`), Pesapal IPN (`:4843-5017`; IPN registration URL points to a hard-coded **ngrok** host, `:4870`), `sendSMS`, `sendMail`, `whatsappNotificationSend` (`:1794-1910`).

---

## A4. Sale return & refund — `ReturnController@store` (`app/Http/Controllers/ReturnController.php:686-958`)
1. No permission check (`create` checks), no transaction.
2. Header: customer, warehouse, biller and currency copied from the sale (`:693-701`). **`account_id = default account`** (`:713-714`). Cash register = the open register. `Returns::create($data)` takes all monetary totals from the client (`:737`).
3. Per selected line (`is_return[]`):
   - Product by `product_id[$key]`. Unit by name. Base qty.
   - Variant: `variant.qty +=` and warehouse row. Batch: **`batch.qty +=`** and warehouse row by (batch, warehouse). Plain: warehouse row. If missing, a warehouse row is created.
   - `products.qty += q`, `product_warehouse.qty += q` (`:838-842`).
   - **The returned stock (including expired or damaged medicine) goes straight back into sellable stock.** No quarantine or "damaged" flag.
   - A `product_returns` row is inserted with client price, tax and total (`:914-929`).
   - `product_sales.return_qty += qty`, matched by `(product_id, sale_id)->first()` (`:932-939`), so duplicate lines of the same product in one sale are mis-attributed.
4. **No guard that the return qty ≤ sold − already returned.** The same sale can be returned repeatedly, inflating stock and refunds. `[V]`
5. `sale_status = 4` if `change_sale_status` is set.
6. **Refund money:** no `payments` row and no deposit credit. The refund exists only as `returns.grand_total` + `account_id` + `cash_register_id`, which the account balance (A7.6) and cash register (A7.3) formulas subtract.
7. `Returns::booted` dispatches `MembershipReviewRequested` (`app/Models/Returns.php:9-15`), so the membership and reward wallet re-sync and reduce earned points. **The legacy `customers.points` is not reduced.**
8. Exchange: **no exchange workflow exists.** It has to be done as a return plus a new sale. `[V]` (no route or controller found)
9. Return update (`:1066`) and delete (`:1462`) reverse and re-apply similarly, with no permission check and no transaction `[U]` (not traced line by line).

---

## A5. Stock adjustment, stock count, transfer

### A5.1 Adjustment — `AdjustmentController@store` (`app/Http/Controllers/AdjustmentController.php:178-251`)
1. No permission check, no transaction, no validation.
2. **Document upload uses the original client filename with no extension check**, moved into `public/documents/adjustment/` (`:189-194`). This is a **remote code execution vector** (B-SEC-4).
3. If `stock_count_id` is set, the stock count is marked `is_adjusted`.
4. Per line, `action` `+` or `-`: variant qty, `products.qty` and `product_warehouse.qty`. **Batch is ignored.** For batch-tracked medicine, the first warehouse row for the product is adjusted and `product_batches.qty` is unchanged, so batch and warehouse totals diverge. No negative check.
5. `unit_cost` comes from the client and is stored on `product_adjustments`.

### A5.2 Stock count — `StockCountController` (`app/Http/Controllers/StockCountController.php:37-187`)
1. `store` builds the expected-qty CSV from `product_warehouse` (filtered by category/brand) and writes it to **`public/stock_count/Ymd-his.csv`**, a predictable, publicly downloadable inventory file. CSV is assembled with `explode(',')`, so product names containing commas break columns.
2. `finalize` accepts the counted CSV (extension `csv` only), saves it under `public/stock_count/`, and updates the record.
3. `stockDif` diffs expected vs counted. The adjustment is then created manually via A5.1. Batch rows appear as separate lines with the same code, so count rows are ambiguous for batch medicines.

### A5.3 Transfer — `TransferController@store` (`app/Http/Controllers/TransferController.php:699-~980`)
1. No permission check, no transaction. The document extension is validated, but the original filename is kept (`:715-726`).
2. Per line: unit by name. Source warehouse row by variant, batch or plain.
3. `status != 2` (not pending): source `product_warehouse.qty -= q` and IMEI removed (`:768-789`). **No availability check.**
4. Only `status == 1` (completed) adds to the destination row, creating it if missing (`:791-827`). Status 3 ("sent") deducts from the source and leaves stock in limbo until edited.
5. `products.qty` is unchanged (correct for an internal move). `product_batches.qty` is not split per warehouse, because batches are global.
6. Update (`:1129`) and delete (`:1443`) reverse and re-apply `[U]`.

---

## A6. Expiry, low stock, AI draft orders, POS camera search

### A6.1 Expiry
- Batch expiry lives in `product_batches.expired_date`, set at purchase and **overwritten by every later purchase of the same batch_no** (A2.1). There is also a product-level `products.expire_date` (manual; CSV import sets 2999-12-31).
- **Report only:** `ReportController@productExpiry` (`app/Http/Controllers/ReportController.php:1701-1716`) lists batches with qty > 0 and expiry ≤ today + `general_settings.expiry_value/expiry_type`.
- **No blocking:** neither the POS product feed (`SaleController@getProduct`, `:2211+`) nor `store()` filters or rejects expired batches. No alert, notification or scheduled job exists for expiry. `[V]`
- **Data hack:** `getProduct` filters `whereDate('product_warehouse.updated_at','!=','2025-07-08')` (`:2214` and the following query), which silently hides every warehouse row last touched on 8 July 2025 from the POS. It also turns MySQL strict mode off at runtime and reconnects (`:2236-2237`).

### A6.2 Low stock / qty alert
- `products.alert_quantity`. Reports: `report/product_quantity_alert`, `product_order_quantity_alert`, `product-order-report-data` (ReportController).
- `DsoAlert` command (daily sale objective) runs daily at 00:00.
- **`AutoPurchase` command (`app/Console/Commands/AutoPurchase.php`) is scheduled every 5 minutes** (`app/Console/Kernel.php:37`). For every active non-variant, non-batch product with `alert_quantity > qty`, it **creates a received, paid purchase of +10 units**, owned by the first admin, in the POS default warehouse. If the scheduler runs, this **fabricates stock and cash-out** every 5 minutes. `[V]` code / `[U]` whether cron is configured.

### A6.3 AI draft order generation (new in this snapshot)
- Command `orders:generate-ai-drafts` (`app/Console/Commands/GenerateAiDraftOrders.php`), scheduled daily at `services.openai.order_generation_time` (default 02:30) with `withoutOverlapping(360)` (Kernel diff). UI triggers are `ReportController@startGlobalAiDraftOrders`, `processGlobalAiDraftOrders` and `globalAiDraftOrderStatus`, each guarded by `abort_unless(hasPermissionTo('product-qty-alert'))`.
- `AiDraftOrderGenerationService::start` (`app/Services/OpenAI/AiDraftOrderGenerationService.php:18-72`):
  - eligible = active products with an active brand, `alert_quantity > qty` and price ≠ 0;
  - creates `ai_order_generation_runs` (guarded by a unique `active_key='global'` that acts as a mutex) and batches of 20 product ids;
  - all in a transaction.
- `processNext` (`:74-127`):
  - re-queues stale running batches (more than 3 minutes old);
  - claims a batch under `lockForUpdate`;
  - `StockForecastService::forecasts` takes 7 days of history (30 days fetched) from `product_sales` and calls OpenAI, with a deterministic fallback on invalid JSON (`app/Services/OpenAI/StockForecastService.php:181+`);
  - `saveDraftRows` upserts `product_order_quantitys` (`generation_source='ai'`), keyed by **`supplier_id = products.brand_id`**, so brand stands in for supplier.
- Caveats:
  - The forecast reads `product_sales` **without filtering by `sales.sale_status`**, so drafts, pending web orders and later-deleted sales all inflate demand. Returns are not netted. `[V]` (`StockForecastService.php:39-44`)
  - Drafts are only *suggestions*. No purchase is created. This is good.
  - The OpenAI key comes from config/env (not hard-coded). Product names and sales history are sent to OpenAI.
- Tests: `tests/Unit/AiDraftOrderGenerationServiceTest.php`, `StockForecastServiceTest.php`.

### A6.4 POS camera / vision search (new)
- Route `POST pos/product-vision-search` (inside the auth group, `throttle:20,1`, `routes/web.php:142-143`). Controller `PosVisionSearchController` checks `sales-add` and validates the image (`jpeg,png,webp`, ≤4 MB, ≤4096 px).
- Flow: optional barcode → exact `products.code` match. Otherwise `ProductVisionService::recognize` calls the OpenAI Responses API (`app/Services/OpenAI/ProductVisionService.php:68-73`, 20 s timeout) to get `{product_name, strength, barcode, code, confidence}`. `PosVisionProductMatcher` then scores LIKE-matched candidates (`app/Services/PosVisionProductMatcher.php`) and auto-selects at ≥ 90 score with ≥ 0.8 confidence. LIKE input is escaped. Read-only; no stock effect.
- This is the best-engineered code in the repo (validation, permission check, rate limit, error isolation). Tests: `ProductVisionServiceTest`, `tests/manual/pos-camera-*`.

---

## A7. Money: customer due, deposits, supplier payments, cash register, transfers, expense/income

### A7.1 Customer due collection — `CustomerController@clearDue` (`app/Http/Controllers/CustomerController.php:309-356`)
- Walks the customer's sales with `payment_status != 4` and allocates `amount` oldest-first by id, creating one **Cash** payment per sale on the **default account** and the open register.
- No validation of `amount`: negative or non-numeric values are accepted. Termination uses `$total_paid_amount == 0` with float compare. Overpayment beyond total due is **silently dropped** (not posted to deposit). No transaction, no permission check.

### A7.2 Deposits — `addDeposit` / `updateDeposit` / `deleteDeposit` (`:657-707`)
- `customers.deposit += amount` and a `deposits` row. **No `payments` / `account` / cash-register record**, so cash taken as a deposit never appears in account balances or the register. No amount validation. Deposit consumption = `customers.expense` (A3 step 15).

### A7.3 Cash register — `CashRegisterController` (`app/Http/Controllers/CashRegisterController.php`)
- `store`: `CashRegister::create($request->all())` with an opening `cash_in_hand`. Nothing prevents two open registers for the same user and warehouse at the server (only the `checkAvailability` UI check).
- `getDetails` / `showDetails`: `total_cash = cash_in_hand + Σ ALL payments on the register − returns − expenses` (`:67`, `:113`). **Card, gift card, deposit, points and cheque payments are counted as cash**, which overstates the expected drawer cash. No counted-cash entry, no over/short variance, no Z-report.
- `close`: closes **any** register id posted (`:119-125`), with no ownership check.

### A7.4 Supplier payments
`PurchaseController@addPayment` (A2.2). `SupplierController@clearDue` (`app/Http/Controllers/SupplierController.php:47`) mirrors customer clearDue for purchases with `payment_status` 1.

### A7.5 Money transfer — `MoneyTransferController` (`app/Http/Controllers/MoneyTransferController.php:31-61`)
`create($request->all())`, `update` (`find($data['id'])->update($request->all())`), `destroy`. No validation (same from/to account, negative amount) and no permission check.

### A7.6 Expense / income / account balance
- `ExpenseController@store` (`app/Http/Controllers/ExpenseController.php:176-194`): `Expense::create($request->all())`, back-datable `created_at`, linked to the open register. Income mirrors this.
- Account balances are **derived, not stored**: `AccountsController` (`app/Http/Controllers/AccountsController.php:~105-120`) computes initial + sale payments − purchase payments − returns + purchase returns − expenses − payroll ± money transfers. **Incomes appear only in the account statement (`:177`), not in the balance-sheet formula** `[U]` (verify full function). Deposits are absent from both.

---

## A8. Online / e-commerce order — `Api\OrderController@store` (`app/Http/Controllers/Api/OrderController.php:54-213`)
Route: **`POST /api/checkout` — no auth, no throttle** (`routes/api.php:12`).

1. `DB::beginTransaction()` (`:56`); rollback on exception. This flow is **transactional**.
2. Reference `webr-YmdHis` with an existence loop (a race between check and insert remains; no unique index verified `[U]`).
3. **Customer resolution:** `CustomerIdentityService::findOrCreateForCheckout` (`app/Services/CustomerIdentityService.php:134-148`). **If the phone matches an existing customer, the anonymous caller is treated as that customer.** No OTP, no token.
4. **Server-authoritative pricing:** `CheckoutPricingService::quote` (`app/Services/CheckoutPricingService.php:26-73`):
   - per item: product must be active, `qty > 0` and `products.qty >= qty` (the **global** qty, not warehouse 1 and not batch);
   - price = `products.price`, or promotion price within its dates, plus variant `additional_price`;
   - membership discount % from plan benefit `medicine_discount`;
   - coupon (row-locked `lockForUpdate`; active, expiry, quantity and minimum checked) – **fixed coupons are taken at face value with no currency conversion**;
   - delivery from `ShippingZone` (division/district/thana), or a client-submitted rate that must equal some active zone rate (any zone);
   - membership free or discounted delivery;
   - reward redemption capped at payable;
   - `rejectUnderstatedClientTotals` rejects a client subtotal or total *lower* than the server's (a higher value is ignored).
5. `Sale::create` with `user_id=1`, **`warehouse_id=1` (hard-coded)**, `currency_id=3` (hard-coded), `sale_status=0`, `payment_status=0`, `paid_amount=0`, `sale_type='website'`.
6. Per item: `product_sales` with the server price. **Stock is deducted immediately** (status 0 = pending), from `products.qty`, `product_variants.qty`, and the first `product_warehouse` row for (product, wh 1 [, variant][, batch]). The client may pass `product_batch_id` (not validated against the product, not FIFO, **no expiry check**). No row locks, so concurrent checkouts can oversell (a check-then-act race between quote and decrement).
7. `order_trackings` row + history `pending`. Coupon `increment('used')`. `MembershipRewardService::redeem` debits membership `reward_balance` under lock; afterCommit, `RewardRedeemed` debits the reward wallet too (A9).
8. `event(new OnlineOrderEvent($sale))` (broadcast). Commit. A Sanctum token is issued for new customers only.
9. **Admin fulfilment:** `OnlineOrderController@updateStatus` (`app/Http/Controllers/OnlineOrderController.php:328-422`):
   - `status` is any string;
   - **`in_transit` deducts stock again** from product, variant and warehouse (`:351-393`). Stock was already deducted at checkout, so **every shipped web order is double-deducted**, and re-posting `in_transit` deducts again (no idempotency). `[V]`
   - `delivered` sets `sale_status=1`, `payment_status=4`, `paid_amount=grand_total` (`:394-398`) **without creating a `payments` row** (COD cash never reaches any account or register);
   - **no `cancelled` branch:** a cancelled order never returns stock, coupon or reward points.
   - Rider assignment: `assignRider` (`:253+`) validates `order_id`/`rider_id` and sets the tracking rider. Courier and Delivery modules (`DeliveryController@store`, `:197`) record delivery documents (with an unsafe upload, B-SEC-4).
10. Customer-facing reads: `GET /api/orders/{id}` and `GET /api/tracking/{code}` (both **unauthenticated**); `GET /api/customer/orders/{id}` (authenticated, **no ownership check**) — see B-SEC-3. Reorder (`ReorderService`) correctly checks ownership.

---

## A9. Membership & reward points (three parallel ledgers)

| Ledger | Where written | Earn | Redeem |
|---|---|---|---|
| **Legacy** `customers.points` | `SaleController@store:1303-1306` | floor(grand_total / per_point_amount), on every POS/draft sale | POS "Points" tender (`:1640-1642`), no balance check |
| **Membership** `customer_membership_profiles.reward_balance` + `membership_transactions` | `MembershipRewardService::syncCompletedSale` via `ReviewCustomerMembership` listener | on sale saved with status 1 and payment 4, rate from plan; idempotent by (customer, type, sale) and reconciles on returns (`app/Services/Membership/MembershipRewardService.php:18-91`) | web checkout only (`redeem`, `:113-131`, locked) |
| **Reward wallet** `reward_wallets` / `reward_transactions` / `reward_expiry_batches` | `RewardEngine::syncSale` via `RewardServiceProvider` closure | same trigger; idempotency key `sale:{id}:sync:{sha1}`, pending days, FIFO expiry (`app/Services/Rewards/RewardEngine.php:29-49`, `:131-148`) | `recordRedemption` fired by the membership `RewardRedeemed` event; admin adjust; `rewards:process` hourly releases and expires |

- **Membership tiering:** `MembershipEligibilityService::purchases` sums `sales.grand_total` for status 1 / payment 4 minus returns (`app/Services/Membership/MembershipEligibilityService.php:15-27`). `MembershipEngine::review` up- or downgrades automatically (except "RED", which needs payment and admin approval per tests). Renewals and upgrade requests live in `MembershipRenewalService` / `MembershipUpgradeService`. Commands: review, expire, benefits-refresh, renewal-reminders, statistics, annual-reset (Kernel).
- **Membership discount applies only to web checkout.** The POS ignores it.
- **The POS's buggy split-tender status (A3 step 5) means split-paid POS sales never reach `payment_status=4`, so they earn no membership or wallet points and do not count toward tier.** Meanwhile, client-controlled `grand_total` on single-tender POS sales lets a cashier inflate a customer's tier spend.
- The new services are well-built: transactions, `lockForUpdate`, idempotency keys, `afterCommit` dispatch, immutable history. **But the three ledgers are not reconciled with each other, and the POS still uses only the legacy one.**

---

## A10. Cross-cutting summary

| Workflow | DB transaction | Idempotency / double-submit | Row locking | Server price/total | Server permission check on write |
|---|---|---|---|---|---|
| Product create/edit/import | No | No | No | n/a | **No** |
| Purchase store/edit | **Yes** | No | No | No (client totals) | **No** |
| Purchase payment/delete/return | No | No | No | No | **No** (destroy partial `[U]`) |
| POS / form sale store | **No (commented out)** | No (duplicate POST = duplicate sale + double stock deduction) | No | **No** | **No** |
| Sale edit/delete/payment | No | No | No | No | **No** |
| Draft convert (GET) | No | No | No | No | **No** |
| Sale return | No | No | No | No | **No** |
| Adjustment / transfer / stock count | No | No | No | n/a | **No** |
| Cash register / deposits / clear due / money transfer / expense | No | No | No | n/a | **No** |
| Web checkout (API) | **Yes** | No idempotency key | Coupon only | **Yes** | n/a (public) |
| Online order status | No | **No** (in_transit re-deducts) | No | n/a | **No** |
| Membership / reward services | **Yes** | **Yes** (keys) | **Yes** | **Yes** | API: any active staff user |
| AI draft generation | **Yes** | Run mutex | **Yes** | n/a | **Yes** (`product-qty-alert`) |
| POS vision search | n/a (read) | n/a | n/a | n/a | **Yes** (`sales-add`) |

---

# PART B — KNOWN ISSUES

Severity: **Critical** = exploitable remotely or causes silent loss of data, money or stock at scale; **High** = serious integrity or security risk requiring some precondition; **Medium** = correctness or hardening gap; **Low** = hygiene.

## B1. Unauthenticated destructive / administrative routes

| ID | Sev | Location | Issue & impact | Fix |
|---|---|---|---|---|
| B-SEC-1 | **Critical** | `routes/web.php:101` → `app/Http/Controllers/CouponController.php:83-103` | **`GET /update-coupon` (no auth) TRUNCATES EVERY DB TABLE** (`SET FOREIGN_KEY_CHECKS=0; SHOW TABLES; truncate`) and then **recursively deletes the directory in `?data=`** on the server. It is a remote kill switch / backdoor reachable by anyone, including by a crawler following a link. It is present in the old snapshot too (`backend/routes/web.php:100`). | Delete the route and the method immediately. Check web server logs for any hits to `/update-coupon`. Rotate credentials and confirm backups are intact. |
| B-SEC-2 | **Critical** | `app/Console/Kernel.php:39` + `app/Console/Commands/ResetDB.php:36-49` | **`reset:db` is scheduled `everyMinute()`**: it truncates all tables, then imports `public/salepro_data.sql` (missing, so the DB is left empty). Production only survives if the Laravel scheduler cron is **not** configured. Enabling cron for the new AI-draft, membership or reward jobs **will wipe production within 60 s.** `[V]` code / `[U]` cron state. | Remove `ResetDB` from Kernel `$commands` and `schedule()`, then delete the command. Also remove `purchase:auto` (B-BIZ-9) and `quote:daily` (every minute, emails a hard-coded sender). |
| B-SEC-3a | **Critical** | `routes/web.php:72-76` | **`GET /migrate` (no auth)** runs `Artisan::call('migrate')`. `.env` has `APP_ENV=local`, so no production confirmation guard applies. Anyone can trigger schema changes. | Delete. Run migrations only through the deploy pipeline. |
| B-SEC-3b | **High** | `routes/web.php:78-99` | **`GET /cc` (no auth)** runs `optimize:clear` and flushes the permission caches. This allows DoS / cache stampede. | Delete or move behind admin auth + POST. |
| B-SEC-5 | **Critical** | `routes/web.php:106-123` → `DeveloperSectionController.php:29-79`, `ClientAutoUpdateController.php:41-139`, `app/Traits/ENVFilePutContent.php` | The developer-section and auto-update routes are **outside the auth group**. `POST /developer-section` writes `VERSION`/`BUG_NO` into `.env` via string replace (only `index` checks `PRODUCT_MODE`) and rewrites `track/*.json`, including the list of files to download. `POST /version-upgrade` and `/bug-update` then **download zips from the vendor host (salepropos.com), extract them over `base_path()`, and run `migrate` + `db:seed`**. Only CSRF stands in the way, and a token is freely obtainable from the public login page. This is an unauthenticated overwrite of application code from a third-party server, i.e. supply-chain RCE. | Remove all four controllers' routes (`DeveloperSectionController`, `ClientAutoUpdateController`, and the `SaasInstallController` / `AddonInstallController` routes, which are inside auth but have no role check and also download and extract zips from `lion-coders.com`, `AddonInstallController.php:27-80`). Deploy only via git/CI. |
| B-SEC-6 | **High** | `routes/web.php:103` | `GET /auto-update-dashboard` renders the dashboard view without auth. Data leak depends on the view `[U]`. | Remove or move inside auth. |
| B-SEC-7 | **High** | `routes/web.php:126` + `app/Http/Controllers/Auth/RegisterController.php:72-107` | **Public self-registration** is enabled (`Auth::routes()`). `role_id`, `biller_id` and `warehouse_id` are **taken from the form unvalidated**, so a registrant can choose role 1 (Admin). The user is created `is_active=false` (blocked by `Active` middleware), but an admin activating "a new cashier" grants whatever role the person chose. Role 5 creates a customer. | `Auth::routes(['register' => false])`, or force the role server-side. |

## B2. Authorization (server-side)

| ID | Sev | Location | Issue & impact | Fix |
|---|---|---|---|---|
| B-AUTH-1 | **Critical** | Systemic. A script over 22 controllers found **no permission check in any mutating method**: `SaleController` store/update/destroy/addPayment/updatePayment/deletePayment/deleteBySelection/importSale/convertDraftToSale; `ProductController` store/updateProduct/import/bulkUpdate/destroy; `PurchaseController` update/payments/delete; `ReturnController`; `ReturnPurchaseController`; `AdjustmentController`; `TransferController`; `StockCountController`; `CashRegisterController`; `CustomerController` (clearDue, deposits); `SupplierController`; `MoneyTransferController`; `Expense`/`Income`; `AccountsController`; `GiftCardController`; `CouponController`; `DeliveryController`; `OnlineOrderController`; **`UserController` update/destroy; `RoleController` store/update/setPermission; `SettingController` (all `*Store`, `gatewayUpdate`, `posSettingStore`)** | Permissions (`hasPermissionTo`) are checked only in `index`/`create`/`edit` GET views, i.e. only the UI is hidden. Any active logged-in user (cashier, supplier role 6) can POST directly to any write endpoint. | Add `$this->authorize()` / permission middleware per route (e.g. `->middleware('permission:sales-add')` from spatie) on every write route. Add a test that iterates the routes. |
| B-AUTH-2 | **Critical** | `app/Http/Controllers/UserController.php:144-171`, `RoleController.php:90+` (`POST role/set_permission`, `routes/web.php:271`) | **Privilege escalation:** any active user can `PUT /user/{own id}` with `role_id=1` (`User::$fillable` includes `role_id` and `is_active`), or reset another user's password; or grant permissions to their own role via `setPermission` (`Role::firstOrCreate(['id'=>$request['role_id']])`). | Require the admin role for user and role management; strip `role_id`/`is_active` from self-updates. |
| B-AUTH-3 | **High** | `routes/api.php:82-108`, `Api/AdminMembershipController.php:39-42`, `Api/AdminRewardController.php:28` and the `Api/Reward*Request` / `MembershipPlanRequest` `authorize()` | The admin membership/reward API accepts **any active staff `User`** (no role or permission). A cashier can credit reward wallets (`adjust`), change reward settings and edit plans. | Require a specific permission (e.g. `membership-manage`). |
| B-AUTH-4 | **Medium** | `CashRegisterController.php:33,119` | `getDetails/{id}` and `close` act on any register id (IDOR). | Scope to `user_id = Auth::id()` or require a manager role. |

## B3. API / e-commerce

| ID | Sev | Location | Issue & impact | Fix |
|---|---|---|---|---|
| B-SEC-3 | **Critical** | `routes/api.php:13-14` → `Api/OrderController.php:215-293`; `routes/api.php:41` → `getOrderDetail` `:336-415` | **`GET /api/orders/{id}` needs no auth** and returns the full `Sale` + `customer` (name, phone, email, address) + items for sequential ids. Anyone can scrape the entire customer and order history (pharmacy purchase data is sensitive health data). `/api/customer/orders/{id}` is authenticated but **has no ownership check** (IDOR). `/api/tracking/{code}` also returns the customer. | Require Sanctum and `abort_unless($order->customer_id === auth()->id())`. Return a minimal tracking DTO for the public tracking code. |
| B-SEC-8 | **Critical** | `Api/OrderController.php:59` + `CustomerIdentityService.php:134-139` + `CheckoutPricingService.php:47-49` | **Unauthenticated checkout impersonation:** posting any existing customer's phone makes the order theirs. With `reward_points` the caller **spends that customer's membership reward balance**, and also gets their membership discount and free delivery. | For an existing phone, require an auth token or OTP; disallow redemption without auth. |
| B-SEC-9 | **High** | `routes/api.php:12` | `/api/checkout` has **no throttle and no idempotency key**. Each call decrements stock (pending orders hold stock forever, see B-BIZ-3), so it is a trivial inventory DoS and double-submit risk. | `throttle`, idempotency header, captcha for guests; reserve stock with expiry. |
| B-SEC-10 | **Medium** | `routes/api.php:113,123` → `Api/CategoryController::bootSlug`, `Api/ProductController::bootSlug` (`:168+`) | Unauthenticated **GET endpoints that rewrite every category/product slug** (`set_time_limit(60000)`, 512 MB): DoS and SEO URL churn. | Make them Artisan commands. |

## B4. File upload / RCE / XSS

| ID | Sev | Location | Issue & impact | Fix |
|---|---|---|---|---|
| B-SEC-4 | **Critical** | `AdjustmentController.php:189-193, 267-271`; `DeliveryController.php:200-205` (and update `:350-355`) | Uploads are stored under `public/documents/...` with **no extension validation**. Adjustment keeps the **client's original filename** (e.g. `shell.php`); Delivery uses `reference_no.<client ext>`. With PHP executing in `public/`, any logged-in user (no permission check, B-AUTH-1) gets **remote code execution**. No `.htaccess` blocks PHP in `public/documents` (none found). | Validate `mimes:` + size; store outside the web root (`Storage::disk('local')`) with random names; serve through a controller; add a deny-PHP rule to uploads dirs. |
| B-SEC-11 | **High** | Root `.htaccess:9-11` (`RewriteCond %{REQUEST_FILENAME} -f` → serve as-is) | If the vhost document root is the **project root** (typical SalePro shared hosting), any existing file is served directly: **`.env`** (DB, mail, OpenAI, payment keys), `composer.lock`, `storage/logs/laravel.log` (which contains full sale payloads, B-DATA-2), `track/*.json`. `[U]` (depends on docroot; APP_URL is `https://redpharma.techrajshahi.com`). | Point the docroot to `public/`. Otherwise add `<FilesMatch "^\.|\.(env|log|json|lock|sql|zip)$"> Require all denied`. Test with `curl https://<host>/.env`. |
| B-SEC-12 | **High** | `public/salepro_backup_1689056748.sql`, `public/salepro_backup_1738037751.sql` (~350 KB each), `public/delete.php`, `public/debug_payroll_params.txt`, `public/vendor/tinymce.zip` | Publicly downloadable SQL dumps (users and password hashes, customers `[U]` contents not inspected). `delete.php` recursively deletes `public/install` when requested. A debug dump file is left in the web root. | Delete from the web root, check access logs, rotate any credentials found in the dumps. |
| B-SEC-13 | **High** | `resources/views/backend/delivery/index.blade.php:62`, `index_new.blade.php:76`; `backend/sale/invoice*.blade.php:190/180` (`{!!$product_name!!}` incl. batch_no); `a4_invoice.blade.php:181`; ~133 views echo `{!! session()->get('message') !!}`, and some messages include `$e->getMessage()` (e.g. `PurchaseController.php:484`) | Stored XSS: **customer name/phone from the public checkout API** is rendered raw in the delivery list. Batch numbers are rendered raw in invoices. 411 `{!! !!}` occurrences in total. | Use `{{ }}`; escape when concatenating; never flash exception text. |
| B-SEC-14 | **Medium** | `app/Http/Controllers/TransferController.php:724,1152`, many `import*` methods | The original filename is kept (extension validated). Overwrite and collision risk; CSV-formula injection in exports `[U]`. | Random names. |

## B5. Business-logic / data-integrity defects

| ID | Sev | Location | Issue & impact | Fix |
|---|---|---|---|---|
| B-BIZ-1 | **Critical** | `SaleController.php:1152-1686` | **The POS trusts client prices, discounts, tax, coupon, `grand_total`, `paid_amount` and (for non-POS) `payment_status`.** A cashier or crafted request can sell at any price; totals in reports, membership tiering and rewards are client-defined. The coupon is validated only in JS. | Recompute line price from `products.price` / `product_warehouse.price` / promotion, tax from `taxes`, coupon from the DB (as `CheckoutPricingService` does). Treat client totals as a hint and reject mismatches. Allow price override only with a permission + audit row. |
| B-BIZ-2 | **Critical** | `SaleController.php:1155` (transaction commented), whole store/update/destroy; Return, Adjustment, Transfer | **No DB transaction and no locks on any POS stock write.** A mid-request failure (e.g. the SMS undefined-variable bug, the mail exception path, a missing unit row) leaves the sale, stock and payments half-written. Concurrent sales lose updates on `products.qty` (read-modify-write in PHP). **Double-click = two sales, double stock deduction.** | Wrap in `DB::transaction`; use `->lockForUpdate()` or atomic `decrement()`; use an idempotency token (form nonce, unique `reference_no` generated server-side with a unique index). |
| B-BIZ-3 | **Critical** | `Api/OrderController.php:110-147` + `OnlineOrderController.php:351-393` | **Web orders are double-deducted:** stock is taken at checkout **and again** at `in_transit` (and every repeat of `in_transit`). `delivered` marks paid with no payment row; there is no cancel path to restore stock. | Deduct once (choose checkout or dispatch); make status transitions a state machine with idempotent guards; on cancel, restore stock, coupon and points; create a COD payment on delivery. |
| B-BIZ-4 | **Critical** | `SaleController.php:4775` | **Deleting a batch sale decrements `product_batches.qty` instead of restoring it** (`-=` should be `+=`). Batch stock silently drifts 2×qty per deleted sale. Also `deleteBySelection` `[U]` same pattern. | Fix the sign; reconcile `product_batches` vs `product_warehouse` with a data-repair script. |
| B-BIZ-5 | **High** | `SaleController.php:4699-4703, 4782` | Deleting a sale that has returns deletes the returns *without* removing the returned stock, then restores the full sold qty, so stock is **over-counted**. Points and reward debits are not reversed. | Block deletion of sales with returns, or reverse the returns first; restore `qty - return_qty`. |
| B-BIZ-6 | **High** | `SaleController.php` store `:1469-1499`, `getProduct :2211+`; `CheckoutPricingService.php:83` | **No negative-stock or availability check on the POS; no expiry check anywhere in sales.** Expired medicine can be sold and stock can go negative. The web checkout checks only global `products.qty`, not warehouse 1 or batch. | Server-side: reject `qty > product_warehouse.qty` (unless `without_stock`); exclude batches with `expired_date < today`; default FIFO/FEFO batch selection on the server. |
| B-BIZ-7 | **High** | `SaleController.php:1192-1202` | Split-tender payment status uses only the last tender, so fully paid sales show as **due**. This feeds customer due reports, `clearDue`, and blocks membership and reward earning (status 4 required). | `payment_status` from `grand_total - array_sum(paid_amount)` with a tolerance. |
| B-BIZ-8 | **High** | `SaleController.php:1303-1306, 1204-1211, 1627-1643`; `addPayment:4026-4031` | Legacy points are earned on drafts and earned again when a draft is finalised; there is no points balance check on redemption (can go negative); no gift-card balance or expiry check; no deposit balance check. | Earn only on status 1 & paid; validate the balance under a lock; consolidate to `RewardEngine`. |
| B-BIZ-9 | **High** | `app/Console/Kernel.php:37` + `AutoPurchase.php` | `purchase:auto` every 5 min fabricates **+10-unit paid purchases** for every low-stock product, creating phantom stock and phantom supplier payments. `[U]` whether the scheduler runs. | Remove from the schedule (the AI-draft flow replaces it safely). |
| B-BIZ-10 | **High** | `ProductController.php:1041-1056`; `:1354, :1376-1380` | Product edit overwrites warehouse stock and sets `products.qty` to the last warehouse's value (or 0). CSV import resets existing `products.qty` to 0 and inserts duplicate `product_warehouse` rows. No audit trail. | Disallow stock edits via the product form (use Adjustment); fix the import to update without resetting qty; add a unique index on (product, warehouse, variant, batch). |
| B-BIZ-11 | **High** | `PurchaseController.php:1489-1512` | Purchase edit commits and returns before `update($data)`, so header fields such as `paid_amount`, `supplier_id`, `warehouse_id` and custom fields are never saved. Edits that change warehouse corrupt stock `[U]`. | Remove the early return; persist the header inside the transaction. |
| B-BIZ-12 | **High** | `PurchaseController.php:352-361`, `:1353-1361` | The batch is global (not per warehouse) and **its expiry date is overwritten by each new purchase of the same batch_no**. No expiry validation. | Unique (product, batch_no, expiry); reject conflicting expiry; per-warehouse batch qty. |
| B-BIZ-13 | **High** | `ReturnController.php:772-940` | No cap on return qty versus sold minus returned; repeated returns inflate stock and refunds; returned (possibly expired or damaged) stock goes back to sellable stock; refunds create no payment record. | Validate `qty <= product_sales.qty - return_qty`; quarantine option; a refund payment row (negative payment or refund table). |
| B-BIZ-14 | **High** | `SaleController.php:2528-2589`, `routes/web.php:622-623` | `GET sales/convert-draft/{id}` and `test-conversion` turn a draft into a completed sale **with no stock deduction and no payment**. They are CSRF-able GETs. | Delete both; finalise drafts only through the store path. |
| B-BIZ-15 | **Medium** | `SaleController.php:1613`, `:4023`; `PurchaseController.php:1573` | `Payment::latest()->first()` used to find the just-inserted payment (race). | Use `$payment->id` after `save()`. |
| B-BIZ-16 | **Medium** | `SaleController.php:3351-3357, 3774` | Sale edit sets `paid_amount` from the form without touching `payments`, so the ledger and header diverge. | Recompute `paid_amount` from `Σpayments`. |
| B-BIZ-17 | **Medium** | `AdjustmentController.php:203-238` | Adjustments ignore batches, so batch and warehouse totals diverge for batch medicines. | Require a batch for `is_batch` products. |
| B-BIZ-18 | **Medium** | `CashRegisterController.php:67,113` | Expected cash includes non-cash tenders; no counted-cash or variance. | Sum only `paying_method='Cash'` minus cash refunds and cash expenses; capture closing count. |
| B-BIZ-19 | **Medium** | `CustomerController.php:309-356, 657-707` | Clear-due accepts a negative amount and drops overpayment; deposits never hit accounts or the register. | Validate; post a deposit payment to the account and register. |
| B-BIZ-20 | **Medium** | Three reward ledgers (A9) | Legacy `customers.points`, membership `reward_balance` and `reward_wallets` are updated independently, so balances disagree and customers can be credited three times for one sale. | Choose `RewardEngine` as the single source; migrate legacy points; remove the legacy earn in `SaleController`. |
| B-BIZ-21 | **Medium** | `SaleController.php:2214` (and the duplicate query) | Hard-coded `whereDate(updated_at,'!=','2025-07-08')` hides stock rows; strict mode is turned off at runtime. | Remove; fix the underlying data. |
| B-BIZ-22 | **Medium** | `ReturnPurchaseController.php:~545` | Combo child variant qty `+=` on purchase return (should be `-=`). | Fix the sign. |
| B-BIZ-23 | **Medium** | Reference numbers `date('Ymd-his')` everywhere | 12-hour clock without am/pm; collisions after 12 h or within the same second; `reference_no` unique only when user-supplied. | Server sequence + unique index. |
| B-BIZ-24 | **Low** | `StockForecastService.php:39-44` | The AI forecast counts draft, pending and deleted sales and ignores returns. | Join `sales` with `sale_status=1`; net returns. |
| B-BIZ-25 | **Low** | `SaleController.php:4051` | Stripe charges hard-coded in `usd`; `charge_id='12345'` placeholder in POS card tender. | Use the store currency; remove the fake charge id. |

## B6. Sensitive data / secrets / config

| ID | Sev | Location | Issue & impact | Fix |
|---|---|---|---|---|
| B-DATA-1 | **High** | `SaleController.php:1617-1625` → `payment_with_credit_card.data` | Full **card number + holder name stored in plaintext JSON** (PCI-DSS violation). | Never store PAN; use the gateway token / last 4 digits only. Purge existing rows. |
| B-DATA-2 | **Medium** | `SaleController.php:3773` (`json_encode($data)` of the whole sale request), `:1358` | The full request (customer, payment, card fields) is written to `storage/logs/laravel.log`. Combined with B-SEC-11, it may be publicly readable. | Remove the debug logging; scrub logs. |
| B-DATA-3 | **High** | `app/SMSProviders/SmsToday.php:15`, `app/SMSProviders/Twilio.php:13` | **Hard-coded SMS API key** (same literal in both files; value not reproduced here). | Move to `.env`/DB settings; rotate the key. |
| B-DATA-4 | **Low** | `app/Console/Commands/DailyQuote.php:50` | Hard-coded personal sender email; sends mail every minute (`Kernel.php:41`). | Remove the command. |
| B-DATA-5 | **Medium** | `SaleController.php:4857-4870` | Pesapal IPN registration targets a hard-coded **ngrok** tunnel URL. | Use the `route()` of the app. |
| B-DATA-6 | **Medium** | `.env` | `APP_ENV=local` in what `APP_URL` indicates is production (this disables production safeguards such as migrate confirmation and enables local-only branches). `APP_DEBUG=false` (good). Real secrets are present in `.env` inside the codebase folder (values not inspected or reproduced). `config/sanctum.php` is not published (defaults). | `APP_ENV=production`; keep `.env` out of shared copies; rotate. |
| B-DATA-7 | **Low** | `VerifyCsrfToken::$except` | Empty (good). But several state-changing routes are GET (`/update-coupon`, `/migrate`, `/cc`, `convert-draft`, `bootSlug`), so CSRF protection does not apply to them. | Use POST + auth. |

## B7. SQL injection review
- No string-interpolated `whereRaw`/`DB::select`/`DB::statement` with request data was found. `LIKE` searches use bound parameters (`"%{$search}%"` passed as a binding).
- `PackingSlipController.php:44,62`: `DB::raw('CAST('.$order.' AS SIGNED)')`, where `$order` comes from a fixed `$columns` map indexed by `order.0.column`. Not injectable, but an unknown index yields `packing_slips.` (SQL error). **Low.**
- `orderBy($order, $dir)` with request `$dir`: Laravel 10 rejects directions other than asc/desc. **Safe.**
- Overall SQLi risk: **Low** `[V]` (grep-based; Modules/Manufacturing not deeply reviewed).

## B8. Mass assignment
- `$request->all()` → `create()`/`update()` across Sale, Purchase, Returns, Expense, Income, MoneyTransfer, CashRegister, Deposit, Customer and User. Notable fillable fields: `Sale` (`grand_total`, `paid_amount`, `payment_status`, `sale_status`), `Customer` (`points`, `deposit`, `expense`), `User` (`role_id`, `is_active`), `Product` (`qty`, `cost`). Membership and reward models use `$guarded = []` but are only written by services with explicit arrays (OK). **High** in combination with B-AUTH-1.

## B9. Tech debt
- **Route file chaos:** `routes/web.php` is 1,195 lines with duplicated blocks; for example, sale routes are pasted inside the `ReportController` group (`:612-647`), so some URIs are registered twice against different controllers. The last registration wins, making the effective mapping hard to reason about `[U]`. `routes/web_old.php` (396 lines) is still present.
- God controllers: `SaleController` 5,051 lines, `OnlineOrderController` 2,877, `PurchaseController` 2,153. Many duplicated `_old`/`1` methods (`getProduct_old`, `saleData1`, `store_old`, `bulkUpdateproduct1`, `bulkUpdateproduct_olds`).
- `TestController` (stub, not routed). `PrescriptionController` uses `TesseractOCR`, but the `thiagoalessio/tesseract_ocr` package is **not in composer.lock** and the controller is not routed, so it is dead code.
- No observers (`app/Observers` is empty); domain events are only on `Sale`/`Returns`.
- `Schema::hasTable()` is called on every Sale/Return save (performance).
- Vendor auto-update/installer/addon/SaaS code from SalePro (`track/`, `AutoUpdateTrait`, `SaasInstallController`, `AddonInstallController`) should be removed from a customised fork.
- Packages (from `composer.lock`): laravel/framework **v10.48.29** (Laravel 10 security support ended Feb 2025), spatie/laravel-permission 5.11.1, sanctum 3.3.3, stripe-php **v7.128** (very old API), guzzle 7.9.2, phpspreadsheet 1.29.10, intervention/image 3.11.2, `laravelcollective/html` (abandoned), `orangehill/iseed` (dev tool in require). Run `composer audit` `[U]` (not executed).

## B10. Tests
- `phpunit.xml` uses sqlite `:memory:`. 14 test files, about 40 tests:
  - **Covered:** customer API auth (register/login/OTP/logout/rate-limit/profile ownership), membership engine (tiering, RED approval, renewal idempotency), checkout pricing being server-authoritative, reward wallet ledger/expiry/reorder, AI draft generation, stock forecast, product vision service, phone/email normalisation, migrations.
  - **Not covered at all:** POS sale store/update/delete, purchase, returns, adjustment, transfer, stock count, cash register, payments, permissions/authorization, online-order status transitions, CSV imports. All critical stock and money paths above are untested.

---

## Top-10 remediation order
1. Delete `/update-coupon`, `/migrate`, `/cc`, the developer-section/auto-update/installer routes, and the `reset:db` / `purchase:auto` / `quote:daily` schedules (B-SEC-1, 2, 3a, 5; B-BIZ-9).
2. Fix unrestricted uploads (Adjustment, Delivery) and move the docroot to `public/`; remove the public `.sql` dumps / `delete.php` (B-SEC-4, 11, 12).
3. Add server-side permission middleware to every write route; lock down user/role management (B-AUTH-1, 2, 3).
4. Lock down `/api/orders/{id}` and order detail by ownership; require auth/OTP for existing-phone checkout and reward redemption (B-SEC-3, 8).
5. Make POS sale store/update/delete transactional with row locks and an idempotency token (B-BIZ-2).
6. Recompute POS prices, tax, coupon and totals server-side (B-BIZ-1).
7. Fix online order double deduction and add a cancel path (B-BIZ-3).
8. Fix the batch sign bug on sale delete and the return-delete double count; run a stock reconciliation script across the four qty stores (B-BIZ-4, 5).
9. Enforce expiry and negative-stock checks at sale time (B-BIZ-6).
10. Stop storing card numbers; rotate the hard-coded SMS key and any secrets exposed via `.env` / SQL dumps (B-DATA-1, 3).
