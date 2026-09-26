# WKC Voting System: plan

A reusable, self-hosted live voting platform built from the two FY27 Retreat votes. Everything is controlled from an admin panel: you create, edit, duplicate and delete voting pages, and change any text, icon, logo or colour without touching code. It runs on your cPanel hosting.

---

## 1. What we're keeping from FY27

Both FY27 votes worked well at the retreat: **36 votes** for the Most Inspiring Leader and **32 ranked responses** for Top 3 Strategies. The new system keeps everything that worked and makes it configurable:

| FY27 feature | In the new system |
|---|---|
| Anonymous, no login for voters | Default for every poll |
| 1 vote per device | A setting per poll (1, 2, 3… votes per device) |
| 2 special 3-vote links | "Access codes": generate any number, each with its own vote allowance |
| Pick 1 name | **Single choice** poll type |
| Pick up to 3 at once | **Multiple choice** poll type (pick up to N) |
| Rank top 3 with 3/2/1 points | **Ranked** poll type (any N, any points) |
| Required "how will you apply it" | Optional or required comment per pick, with your own label text |
| Live projector, top 5, slide-up animation | Projector page per poll; how many rows to show is a setting |
| Answers wall under the projector | Toggle per poll, with moderation (hide an answer) |
| QR code, WhatsApp text | Share kit in admin: QR download plus a ready-made WhatsApp message |
| CSV export | CSV and Excel export, plus a printable results summary |
| Premium dark projector design | Kept as the default theme; colours, logo and fonts editable |

---

## 2. Recommended technology (built for cPanel)

| Part | Choice | Why |
|---|---|---|
| Server | **PHP 8.1+** | Every cPanel host supports it. You already run PHP apps (MaidTrack, Car Tracker). |
| Database | **MySQL / MariaDB** | Included with cPanel. It's `C:\xampp` here for local testing. |
| Front end | Plain HTML/CSS/JS with **no build step** | Upload the files and it works. It reuses the FY27 design system. |
| Live updates | **Short polling** (~2 s, sends almost nothing when unchanged) | Shared cPanel hosting cuts off long-held connections, so the FY27 long-polling won't work there. Short polling works everywhere. |
| Hosting | Your cPanel, on a subdomain such as `vote.yourdomain.com`, with free AutoSSL (https) | The link is **permanent**: no more changing trycloudflare addresses, and it doesn't need your PC switched on. |

Until the cPanel is ready it runs on this PC (XAMPP), and a Cloudflare tunnel can still make it public for one-off events.

---

## 3. Admin panel

### 3.1 Login and accounts
- Admin login with username and password (passwords hashed). This is needed once the system is public on cPanel.
- Change password, "log out everywhere".
- *(Optional)* Several admins with roles: **Owner** (everything), **Editor** (create/edit polls), **Viewer** (results only).

### 3.2 Dashboard
- Cards for every voting page: status (Draft / Open / Closed / Archived), response count, live mini chart.
- Quick buttons on each card: **Voting page**, **Projector**, **Results**, **Edit**, **Duplicate**, **QR**, **Copy WhatsApp text**.
- Search and filter by event or status.

### 3.3 Create / edit / delete voting pages
- **Create wizard:** type, then question, then options, then rules, then look, then publish.
- **Edit anything at any time:**
  - title, subtitle, small top label ("WKC FY27 RETREAT"), footer, thank-you message, button texts
  - options: add, rename, reorder by drag, hide, delete
  - an icon per option: initials, emoji, or an uploaded photo/logo
  - rules: votes per device, comments required/optional, results visibility
- **Live preview:** phone view and projector view side by side while you edit.
- **Duplicate** a poll, e.g. to reuse FY27 for FY28 and just change the names.
- **Delete** goes to a Trash first, where it can be restored. Emptying the Trash is permanent.
- **Archive** closed events so the dashboard stays tidy while results stay viewable.

### 3.4 Branding (global and per poll)
- Upload the logo and favicon. There's a site-wide default, and each poll can use its own logo.
- Theme colours: accent colour picker (PetWorld orange by default), background style, light/dark/auto.
- Fonts: a choice of heading and body font pairs, e.g. the current Playfair + Jakarta.
- All fixed wording on the voting pages can be edited: "Cast your vote", "Live results", "Scan to vote", "Completely anonymous…".

### 3.5 Running a vote live
- Open / close now, or **schedule** opening and closing times.
- **Countdown timer** on the projector ("Voting closes in 02:00").
- **Reveal mode:** hide results until you press **Reveal**, then show 5th to 1st with a drum-roll animation. Good for award moments.
- **Answers moderation:** hide any comment from the projector with one click. It stays in the export.
- Reset votes (with confirmation).

### 3.6 Results and exports
- Live results page for admins with all options, points, and 1st/2nd/3rd counts.
- **Export:** CSV, Excel (.xlsx), and a printable **PDF summary** to send to the board.
- Chart of responses over time (hourly, to keep anonymity).

### 3.7 Access codes (replaces the special links)
- Generate codes per poll: "2 codes × 3 votes" (like FY27), or "80 codes × 1 vote".
- **Printable QR card sheet:** one QR per card to hand out at the door. This is the strongest way to stop double voting without logins.
- Each code is claimed by the first phone that opens it, and can be released if someone changes phone.

---

## 4. Voting pages (what voters see)

**Poll types:**

| Type | Example |
|---|---|
| Single choice | Most Inspiring Leader |
| Multiple choice (up to N) | "Pick up to 3 favourite activities" |
| Ranked (top N, custom points) | Top 3 Strategies |
| Rating scale *(phase 4)* | "Rate the retreat 1–5 ★" |
| Open question / word cloud *(phase 4)* | "One word to describe FY27" |
| Survey *(phase 4)* | Several questions on one page |

**Every voting page includes:**
- the FY27 look: mobile-first, light/dark, confetti on submit, one vote per device
- its own short link, e.g. `vote.yourdomain.com/fy28-leader`, plus a QR code

**Projector page per poll:**
- top N on one screen
- rows slide up live, with gold/silver/bronze medals
- a QR code on screen
- an answers wall to scroll down to
- a fullscreen button

**Presentation mode:** the projector cycles through several polls in turn.

---

## 5. More functions I suggest

Ranked by value for your events:

| Priority | Function | Why it helps |
|---|---|---|
| ★★★ | Access-code QR cards | Stops double voting. It's the one weakness of "1 per device" (incognito). |
| ★★★ | Reveal mode + countdown | Makes award moments dramatic for the boss and board. |
| ★★★ | Duplicate poll / templates | Next year's event takes 2 minutes to set up. |
| ★★★ | Comment moderation | Nothing embarrassing reaches the big screen. |
| ★★ | PDF results summary | A clean report for the board after the event. |
| ★★ | Scheduled open/close | Voting opens and closes by itself during sessions. |
| ★★ | Option photos | Staff photos next to names on the leader vote. |
| ★★ | Presentation mode | One projector tab rotates through all live polls. |
| ★ | Rating / word cloud / survey types | Covers feedback forms after sessions. |
| ★ | Multi-language texts (EN / 中文 / BM) | If the audience is mixed. |
| ★ | Multiple admins with roles | Share the work with HR or event staff. |

---

## 6. Anonymity and security

- **Voters stay anonymous, as in FY27:** a ballot is never linked to a device or person. Devices are stored only as a one-way hash to enforce the vote limit.
- **Admin protection:**
  - hashed passwords
  - login rate limiting
  - protection against forged form submissions (CSRF)
  - every page and action checks you're logged in
- **Uploads:** images only, checked for type and size, and stored outside any executable path.
- **Backups:** the admin panel can download a full backup (database + uploads). cPanel's own backups also cover it.

---

## 7. Data model (MySQL)

| Table | Holds |
|---|---|
| `settings` | Site-wide branding and texts (logo, colours, fonts, wording) |
| `admins` | Admin accounts, password hashes, roles |
| `polls` | One row per voting page: slug, type, texts, rules, theme, status, schedule |
| `options` | Choices per poll: label, description, icon (initials/emoji/image), order, hidden |
| `ballots` | One row per submission, with **no device link** (hour-level time only) |
| `ballot_choices` | What each ballot picked: option, rank, comment, hidden-from-projector flag |
| `voters` | Per poll: hashed device ID and votes used (enforces the limit) |
| `access_codes` | Codes, votes allowed, which device claimed them |
| `media` | Uploaded logos, icons, photos |
| `audit_log` | Admin actions (who changed what, when) |

---

## 8. Phases

Each phase ends with something working that you can try, and with a git commit and push.

| Phase | Delivers | Size |
|---|---|---|
| **0: Merge** ✅ | Both FY27 votes combined in `VotingSystem/events/` with full history and all results | Done |
| **1: Foundation** ✅ | PHP app skeleton, database, installer, admin login, dashboard, create/edit/delete **single-choice** polls, voting page + live projector in the FY27 design | M |
| **2: All FY27 features** ✅ | Multiple-choice and ranked types, comments (required/optional), answers wall, moderation, access codes (special ballots), CSV export | M |
| **3: Flexible look** ✅ | Logo/favicon/icon uploads, option photos/emoji, colour and font pickers, all text editable, live preview, duplicate poll, Trash | M |
| **4: Show features** ✅ | Reveal mode, countdown, scheduled open/close, presentation mode, QR card sheet, Excel + PDF export | M |
| **5: cPanel go-live** ✅ package ready | Upload to your cPanel, subdomain + https, import the FY27 results as archived polls, backup/restore | S |
| **6: Extras** *(optional)* | Rating / word cloud / survey types, multi-language, multiple admins with roles | M |

---

## Status (26 Sep 2026)

Phases 1–5 are built and tested, plus **bars / pie / both** chart views. On cPanel: follow [DEPLOY-CPANEL.md](DEPLOY-CPANEL.md) with `dist/voting-system-v1.0.0.zip`. Phase 6 (extras) is not started.

## 9. Moving to cPanel (phase 5)

**What you need from your host:**
- PHP 8.1 or newer
- one MySQL database
- a subdomain

**Steps:**
1. Create the subdomain and a MySQL database + user in cPanel.
2. Upload the release zip and extract it.
3. Open `vote.yourdomain.com/install`, enter the database details, and create your admin account. The installer then locks itself.
4. Turn on AutoSSL for https.

No Node.js, no terminal, no Cloudflare tunnel needed. Updates later are a matter of uploading the new files.

---

## 10. Decisions needed from you

1. **Technology:** PHP + MySQL for cPanel, as recommended? The alternative is Node.js, which only works if your cPanel has "Setup Node.js App".
2. **Domain:** which subdomain on your cPanel, e.g. `vote.petworld.com.my`? This can wait until phase 5.
3. **Admins:** just you, or several people with roles?
4. **Stopping double votes:** keep "1 per device" as the default, with QR access codes as an option per poll?
5. **Languages:** English only, or also 中文 / BM?
6. **Scope:** build phases 1–5 in order, and leave phase 6 until later?
