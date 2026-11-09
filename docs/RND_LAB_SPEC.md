# R&D LAB SPEC (T11-T13) - hybrid: code finds and measures, AI (optional) explains

## Principles
1. Every finding is produced by a deterministic rule on validated metrics. LLM OFF must still give full, readable findings in Bangla and English (templates).
2. Never state a cause as fact. Causes are HYPOTHESES. If data is inadequate the finding says so ("insufficient data") and lists what to measure.
3. Severity (how much it matters) and confidence (how strong the evidence is) are separate.
4. Nothing is executed automatically. Actions are recommendations + experiments needing owner approval.

## Finding record - the 10 mandatory fields
1 Finding (what) | 2 Evidence (metrics, source tables, window, comparison baseline, sample size) | 3 Risk/priority (severity + money at stake) | 4 Confidence (high/medium/low + one-line reason) | 5 Hypotheses (list, each marked "unverified") | 6 Missing data | 7 Recommended action | 8 Experiment plan | 9 Success metric (metric, baseline, target, duration) | 10 Status & outcome (new/reviewing/approved/testing/validated/rejected/dismissed + recorded result).
Dedupe by fingerprint = rule_code + website + subject (+ week); an existing open finding is updated (last_seen_at), not duplicated.

## Severity rubric (money at stake over the window)
critical: >= 10% of gross sales or immediate stock-out of a top-20% product | high: 5-10% | medium: 1-5% | low: < 1% or informational.
## Confidence rubric
high: sample >= 100 AND p < 0.01 (or |z| >= 2.58) AND data-quality score >= 85 | medium: sample >= 30 AND p < 0.05 (|z| >= 1.96) | low: anything else, or data-quality < 60. If sample < 20 do NOT raise a finding; raise an "insufficient data" info finding instead (max one per rule per week).

## Statistics helpers (App\Support\Stats, unit-tested with known values)
two-proportion z-test (pooled), z-score of latest value vs trailing weekly series (needs >= 8 points), percent change with min-volume guard, Wilson interval for rates.

## Rule catalogue (thresholds in config/lyp.php, overridable per tenant later)
R01 Sales drop: gross sales last 7 d vs previous 7 d down >= 20% and previous period >= 20 orders.
R02 Cancellation spike: cancel rate last 7 d vs trailing 28 d baseline; two-proportion test p<0.05 and absolute rise >= 5 pp; >= 30 orders in window.
R03 Return spike: same method on return rate (delivered+returned base).
R04 Courier outlier: courier return rate vs others, z-test p<0.05, n >= 30 shipments (needs courier data, else "missing data: courier").
R05 Employee cancel outlier: employee cancel rate vs rest of team, p<0.05, assigned >= 30. Wording must avoid blaming ("workload and customer mix may differ").
R06 Stock risk: stock cover < 7 days for products in the top 20% of 30-day revenue.
R07 Dead stock: stock > 0 and no sale in 60 days; rank by stock value (needs cost or price).
R08 Concentration: top product > 40% of gross sales or top 3 > 70%.
R09 Margin compression: estimated contribution margin down >= 3 pp vs previous 30 d, only if cost coverage >= 60%.
R10 Discount impact: discounted vs undiscounted orders: AOV and return rate difference (z-test); flag if discounted orders return more.
R11 Repeat-rate decline: repeat purchase rate down >= 5 pp vs previous 60 d (>= 50 buyers).
R12 Data-quality blocker: any indicator "bad" that disables other rules -> finding explaining what is missing and what to import/measure.
(T15 adds R13 platform-vs-real ROAS gap, only with matching coverage shown.)

## Templates (bn + en)
Each rule has `title`, `summary`, `hypotheses[]`, `missing_data[]`, `action`, `experiment`, `success_metric` templates with placeholders filled from computed numbers (formatted by one helper; Bangla digits optional by language setting). Example R02 (en): "Cancellation rate rose from {base}% to {now}% (last {days} days, {n} orders). Possible causes (unverified): confirmation delays, wrong-address or fake orders, courier issues, price/offer mismatch. Missing data: cancel reasons, call attempts. Recommended: review cancel reasons for the {n_c} cancelled orders and test a confirmation call within 2 hours for new orders. Success: cancel rate <= {target}% over 14 days." Bangla version is a full translation, not machine output at runtime.

## Action Center
Page listing open findings ordered by severity then confidence, grouped "Today / This week / Info"; each card: title, money at stake, confidence badge, evidence link to the Analytics page with the same filters, buttons: Review / Create experiment / Dismiss (with reason). Empty state explains what data is needed.

## Experiments (T12)
Fields: hypothesis, design, baseline metric snapshot, success metric, minimum sample / duration, owner, approval (owner/admin only; analyst cannot approve), status flow draft -> approved -> running -> completed|cancelled, result (measured values, outcome validated/rejected/inconclusive, lesson). Closing an experiment updates the linked finding. Outcome is a recorded human judgement supported by the measured numbers; the system may compute a two-proportion test for rate metrics but never auto-declares success below the sample size.

## Optional LLM explanation (T13)
Button "AI explanation (optional)". Input = ONLY that finding's computed evidence JSON + the fixed policy prompt (facts / hypotheses / confidence / missing data / next step; untrusted text fenced; numbers copied not calculated). Runs in the `ai` queue, stored in ai_note with model name and a visible label "AI-generated hypothesis - not proof". Per-tenant monthly token budget; usage ledger without prompts; fails gracefully when LLM is off; output never changes finding fields, status or numbers. Bangla quality of small local models is weak - label accordingly.
