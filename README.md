# WKC Voting System

Live, anonymous voting for WKC events, with a projector view.

- **[PLAN.md](PLAN.md):** the plan for the reusable, admin-managed platform (PHP + MySQL, cPanel-ready).
- **`events/`:** the original FY27 Retreat votes, kept exactly as they ran:

| Folder | Vote | Port | Responses |
|---|---|---|---|
| `events/fy27-inspiring-leader/` | Who is your Most Inspiring Leader? | 3080 | 36 votes |
| `events/fy27-top-strategies/` | Rank your top 3 strategies | 3081 | 32 responses |

Each event folder has its own README and `start-voting.bat`. Double-click it to bring that vote (and its admin page, results and CSV export) back online. Live results, admin keys and logs are in each event's `data/` folder, which git ignores.
