# UI GUIDE
Style: clean, light, fast. Blade + one small CSS file + local Chart.js. No CDN, no fonts from the internet (system fonts incl. Bangla-capable fallback e.g. 'Noto Sans Bengali', 'Segoe UI', sans-serif).
Navigation: Dashboard | Orders | Products | Analytics | Courier & Loss | R&D Lab (Action Center, Findings, Experiments) | Import | Websites | Chatbot | Marketing | System | Audit (role-dependent). Show only what is built.
Dashboard (PLAIN): max 6 counters (websites, connected, orders 30 d, products, events 24 h, open findings) + quick links + "Updated HH:MM" + "Refresh data" button. No charts, no heavy queries.
Analytics: filter bar (date range, website), KPI cards, charts (daily trend, status funnel, top products), tables (courier, employee, products, stock alerts), data-quality panel, definitions panel, freshness line.
Language toggle EN/বাংলা stored in the user profile/session; R&D findings and weekly report render in the chosen language. Numbers: thousands separators; optional Bangla digits.
Every table paginated; empty states explain what to do next. Errors in plain language. Print stylesheet for the weekly report and findings (hide nav).
Accessibility: labels on inputs, sufficient contrast, keyboard-usable forms, no color-only meaning.
