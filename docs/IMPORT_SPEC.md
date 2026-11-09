# FILE IMPORT SPEC (orders T08, courier T10, ads T15)

Formats: .csv (delimiter auto: , ; tab |; UTF-8 BOM ok), .xlsx (first sheet; OpenSpout), .xml. NOT .xls (ask user to save as .xlsx/.csv). Max 20 MB. Only owner/admin can import. Files are stored privately only until processed, then DELETED. Never executed, never served publicly.

## Reader (RowReader)
Streams rows; first non-empty row = header. XML: the repeating element under the root is one record; child elements/attributes become columns; nested simple object (e.g. <customer><name/>) -> `customer_name`; a repeating nested group (<items><item/>) -> one row per entry with parent columns repeated. Reject any XML containing a DOCTYPE (XXE / entity-bomb protection). Use LIBXML_NONET.

## Flow
1. Upload + choose website (a `file` source website is fine). 2. System auto-detects columns (ColumnMatcher: exact normalised alias first, then "contains" for aliases >= 5 chars; each column used once). 3. MAPPING SCREEN shows header, 5 sample rows, and a dropdown per field (auto-selected); owner can fix. Required fields flagged. 4. Confirm -> queued job (`default`); if no worker heartbeat exists run synchronously (offline demo). 5. Result page: counts (rows read, created, updated, skipped by reason), "raw status -> canonical -> count" table, unmapped statuses, data-quality hints. Re-importing the same file must not duplicate anything.

## Orders fields and aliases (normalised: lowercase a-z0-9)
order_id*: orderid, orderno, ordernumber, order, id, invoice, invoiceno, orderref, reference
ordered_at: orderdate, date, createdat, createdon, orderedat, datetime, placedat
status: status, orderstatus, currentstatus
customer_name: customername, customer, name, buyer, billingname
customer_contact (hashed, never stored): phone, mobile, customerphone, contact, email, customeremail, mobilenumber
product_name: product, productname, item, itemname, title     sku: sku, productcode, itemcode, code, productid
quantity: quantity, qty, units     unit_price: unitprice, price, rate, sellingprice     line_total: linetotal, total, amount, itemtotal
discount: discount, discountamount     shipping: shipping, shippingcost, deliverycharge, deliveryfee
courier: courier, couriername, carrier, deliveryservice     consignment_id: consignment, consignmentid, trackingid, tracking
employee: employee, agent, assignedto, handledby, staff, confirmedby     city: city, district, area, region
cancel_reason: cancelreason, reason, cancellationreason     currency: currency     utm_campaign: utmcampaign, campaign
(* required; also need line_total OR unit_price)
Rules: one row = one line item; adjacent rows with the same order id form one order; non-adjacent repeats inside the SAME batch append items; a later batch replaces items (idempotent). Without any product column, one row = one whole order and line_total is the order total. Dates: Excel serial, unix time, yyyymmdd, normal strings (ambiguous a/b/yyyy is read as month/day - say so on the mapping screen). Numbers: strip currency symbols/commas, (100) = -100.

## Courier report (T10) - DO NOT GUESS COLUMNS
The owner must supply a real courier CSV first. Fields to map (not names): consignment_id, order_ref, status, cod_amount, collected_amount, delivery_fee, return_fee, area/city, shipped_at, delivered_at, returned_at, customer_contact (hash only). Matching order: consignment_id == orders.consignment_id -> order_ref == orders.external_id -> (customer_key + cod_amount == order total within 1 day window, only if unique). Show matching coverage %. Unmatched rows are kept and counted.

## Ad report (T15) - DO NOT GUESS COLUMNS
Fields: date, campaign, spend, impressions, clicks, platform conversions, platform conversion value. Platform chosen by the user (meta/google/tiktok/other). Idempotent on (tenant, platform, campaign, date).
