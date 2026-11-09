# METRIC DEFINITIONS (single source of truth; show these on the Analytics page)
Range filters apply to orders.ordered_at, inclusive, Asia/Dhaka. Every number shows: range, currency, computed_at (freshness). If the selected orders have MORE THAN ONE currency, show a warning and do not present summed money as comparable.

| Metric | Formula |
|---|---|
| Orders | count of orders in range |
| Gross sales | SUM(total) of orders with status <> cancelled |
| Delivered revenue | SUM(total) of status = delivered |
| AOV | gross sales / count(status <> cancelled) |
| Cancellation rate | cancelled / all orders |
| Return rate | returned / (delivered + returned) |
| Delivery rate | delivered / all orders |
| Repeat purchase rate | buyers in range with >= 2 lifetime non-cancelled orders / buyers in range (buyers identified by customer_key) |
| Revenue lost to cancel | SUM(total) of cancelled |
| Revenue lost to return | SUM(total) of returned |
| Courier success rate | delivered / (delivered + returned), orders with status shipped/delivered/returned only; show "insufficient data" below 20 orders |
| Employee confirmation rate | orders with status in (confirmed, shipped, delivered, returned) / assigned orders |
| Employee cancel rate | cancelled / assigned |
| Response time | minutes from ordered_at to first history row to_status=confirmed WHERE source<>'import'; "n/a" if no such history |
| Stock cover (days) | stock / (units sold last 30 d (non-cancelled) / 30); null if no sales |
| Estimated contribution | delivered item revenue with known cost - SUM(quantity*unit_cost) on those items; ALWAYS show cost coverage % = costed item revenue / all delivered item revenue; if coverage < 60% show "cost data insufficient" and no margin %. Before ads, shipping, returns, overhead: never call it profit. |
| Platform ROAS (T15) | platform_revenue / spend (as reported by the ad platform) |
| Real ROAS (T15) | delivered revenue of orders matched to the campaign / spend, shown WITH matching coverage % |
| Data-quality score | see below |

## Data-quality score (0-100) = 100 - weighted penalties, shown with each indicator
- orders with no items (weight 15), status = other (20), no customer key (10), shipped/delivered/returned without courier (20), no employee (10), no cost data on items (15), last order update older than 48 h for a connected site (10).
Each indicator shows its %, a level (ok <=5%, warn <=20%, bad >20%) and a plain sentence saying what it blocks (e.g. "courier missing: courier scorecard is unreliable").
