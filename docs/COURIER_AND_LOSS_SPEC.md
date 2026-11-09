# COURIER, LOSS & SUSPICIOUS-ORDER SPEC (T10)

## 1. "Money lost" report (investor-facing headline)
For a date range + website: cancelled value, returned value, total lost value, share of gross order value, split by courier, by area/city, by employee, by product, by cancel_reason. Plus estimated return handling cost = SUM(delivery_fee + return_fee) of returned shipments WHEN courier data exists (else "needs courier report"). Wording: "estimated sales not realised", never "profit lost". Always show data-quality and matching coverage.

## 2. Courier scorecard
Per courier and per area: shipments, delivered, returned, in transit, success rate (delivered/(delivered+returned)), COD collected vs expected (if available), average delivery time (only when shipped_at & delivered_at exist). Minimum sample: 20 shipments, else show "insufficient data" (no ranking). Highlight outliers with a two-proportion z-test vs the average of other couriers (p < 0.05 and n >= 30) and label them "statistically unusual", not "bad".

## 3. Suspicious-order flags (ADVISORY ONLY)
Rules (customer_key = hashed contact; no phone stored):
- S1 repeat-failure: same customer_key with >= 3 cancelled/returned orders in 30 days.
- S2 duplicate: same customer_key, same item set and total within 24 h (>= 2 orders).
- S3 burst: >= 5 orders from the same customer_key within 1 hour.
Flags go to a Review queue (status open -> reviewed_ok / confirmed, with reviewer + time). The system NEVER cancels, blocks, or alters an order. Show "possible false positives: repeat legitimate buyers, family orders" next to every flag. Keep flag evidence as order ids/times only.

## 4. Tests
Known dataset -> exact lost-value numbers; sample-size guard; z-test outlier only above threshold; flags created exactly per rule boundaries (2 vs 3 failures); reviewing a flag never changes an order; tenant isolation on every query.
