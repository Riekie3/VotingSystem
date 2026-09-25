# WKC FY27 Retreat: top 3 strategies ranking

Anonymous, no-login live ranking. Everyone ranks their 3 most valuable strategies (1st, 2nd, 3rd) and can explain how they'll apply each one. The projector shows the top 5 by points, live.

This is separate from the "Most Inspiring Leader" vote in `../voting`. It runs on its own port (**3081**) with its own data and public link.

## Run it

Double-click **`start-voting.bat`** and keep both windows open. The tunnel window prints the public `https://….trycloudflare.com` address. That address changes every time the tunnel restarts.

| Page | What it's for |
|---|---|
| `https://<tunnel>/` | Voting page. Share this link or the QR code. |
| `https://<tunnel>/results` | Projector: top 5 live on one screen, with a "Scan to vote" QR. |
| `https://<tunnel>/admin?key=<admin key>` | Organiser only: every strategy's points, all explanations grouped by strategy, Excel/CSV download, close/reopen, reset. |

The admin key is printed when the server starts. It's also stored in `data/state.json`.

## Scoring

1st = 3 points, 2nd = 2, 3rd = 1. When points are tied, the strategy with more 1st-place picks goes higher, then more 2nd-place picks.

## Rules

- **One response per device.** It must be exactly 3 different strategies. No login.
- **Explanations are required.** To make them optional, set `"requireExplanation": false` in `config.json` and restart.
- **Anonymity.** Responses are stored without any device link, and in shuffled order.
- **Device limit.** Like the first vote, the one-response limit is enforced with a browser cookie. A private/incognito window could get around it.

## Change things

Edit `config.json` for the question, strategies, points or explanation length, then restart the server. To start over, use *Reset all responses* in admin, or stop the server and delete `data/state.json`.
