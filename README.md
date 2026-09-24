# WKC FY27 Retreat — Most Inspiring Leader vote

Anonymous, no-login live voting page. Results update on every screen the moment someone votes.

## Run it

Double-click **`start-voting.bat`**. Two windows open. Keep both open for the whole vote:

1. **Server window.** Prints the local links and the **admin link** (`/admin?key=...`).
2. **Tunnel window.** Prints a public address like `https://something-random.trycloudflare.com`. That address is the voting link for everyone's phones.

Or run it by hand:

```bash
node server.js
```

```bash
cloudflared tunnel --url http://localhost:3080
```

> The trycloudflare address changes every time the tunnel restarts. Start it before the session, and share the link or QR only after that.

## Pages

| Page | What it's for |
|---|---|
| `https://<tunnel>/` | Voting page. Share this link or its QR code. |
| `https://<tunnel>/results` | Big-screen live results with a "Scan to vote" QR. Put it on the projector. |
| `https://<tunnel>/admin?key=<admin key>` | Organiser only: the 2 special 3-vote links (with QR codes), close/reopen voting, reset. |

Open the admin page through the **public tunnel address** (swap `http://localhost:3080` for the trycloudflare address). Otherwise the special links will say `localhost` and won't work on phones.

## Voting rules

- **Everyone gets 1 vote per device.** No login and no names.
- **2 special ballots get 3 votes each.** Send each person their own link from the admin page. The first phone to open a link claims it, and nobody else can use it after that. If the person changes phone, click **Release** in admin.
- **Anonymity.** Only per-candidate totals are saved. The server also keeps a hashed random device id and *how many* votes that device has used. It never stores *who* a device voted for, so no vote can be traced back to a person.

### Limitation (no-login trade-off)

"One vote per device" is enforced with a browser cookie, backed up in local storage. Someone determined could vote again from a private/incognito window or a second phone. That's the price of no login. If it matters, ask for one-time voting codes/QR cards instead.

## Change things

- **Names, title, votes per person, number of special ballots:** edit `config.json`, then restart the server.
- **Hide results until a person has voted:** set `"showResultsBeforeVoting": false`.
- **Start fresh:** click *Reset all votes* in admin. This keeps the same special links. To also generate a new admin key and new special links, stop the server and delete `data/state.json`.

Votes are saved to `data/state.json` after every vote, so a restart or crash won't lose them.
