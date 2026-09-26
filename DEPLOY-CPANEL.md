# Putting the voting system on cPanel

About 15 minutes. You need a cPanel account with **PHP 8.1 or newer** and **MySQL / MariaDB**. Nearly every cPanel host has both.

## 1. Create a subdomain (recommended)

cPanel → **Domains** (or **Subdomains**) → create e.g. `vote.yourdomain.com`.
Note the **document root** folder it creates, e.g. `public_html/vote` or `vote.yourdomain.com`.

> You can also install into a folder of your main site, e.g. `yourdomain.com/vote`. The app works in a subfolder.

## 2. Create the database

cPanel → **MySQL® Databases**:
1. **Create New Database**, e.g. `voting` (cPanel names it `cpaneluser_voting`).
2. **Add New User**, e.g. `voteuser`, with a strong password (cPanel names it `cpaneluser_voteuser`).
3. **Add User To Database**: pick both, tick **ALL PRIVILEGES**, and click Make Changes.

Keep the three names and the password for step 4.

## 3. Upload the files

cPanel → **File Manager** → open the subdomain's document root folder:
1. **Upload** `voting-system-v1.0.0.zip`.
2. Right-click it → **Extract**. `index.php`, `.htaccess`, `assets/`… should now sit directly in that folder.
3. Delete the zip.
4. Make sure the `uploads` and `storage` folders are writable (permissions **755**, which is usually the default).

> The `.htaccess` file is hidden by default. In File Manager → Settings, tick **Show Hidden Files** to see it.

## 4. Run the installer

Open `https://vote.yourdomain.com/` in your browser. The installer appears.
- **Host:** `localhost` · **Port:** `3306`
- **Database name / user / password:** from step 2
- **Site name, admin username, admin password:** your choice (10+ characters)

Click **Install**. It creates the tables, your admin account and a private `config.php`, then locks itself.

The login page then shows your **recovery code** once. Save it (photo or password manager). It lets you reset a forgotten password.

## 5. Turn on HTTPS

cPanel → **SSL/TLS Status** → select the subdomain → **Run AutoSSL**. It usually takes a few minutes.

## 6. Bring your FY27 results across (optional)

Admin → **Branding & settings** → **Import old results**. For each FY27 vote, choose its `config.json` and `data/state.json` from `VotingSystem/events/…` on your PC.

## Daily use

- **Admin panel:** `https://vote.yourdomain.com/admin`
- **Voting page:** `https://vote.yourdomain.com/<link-name>`
- **Projector:** `https://vote.yourdomain.com/<link-name>/results`

The link is permanent, and your PC doesn't need to be on.

## Updating later

1. Download a backup first: Admin → Branding & settings → **Download full backup**.
2. Upload and extract the new zip over the old files. It never contains `config.php` or your uploads, so those are kept.
3. Open any page. Database upgrades run automatically.

## Moving to another server

Install fresh (steps 1–5). Then import `database.sql` from your backup zip into the new database with **phpMyAdmin**, and copy the backup's `uploads/` files into the new `uploads/` folder.

## Troubleshooting

| Problem | Fix |
|---|---|
| 500 error straight away | Check **Select PHP Version** in cPanel is 8.1+ with `pdo_mysql` enabled. |
| "config.php cannot be created" | Set the app folder's permissions to 755. |
| Pages other than the home page show "Not Found" | The `.htaccess` file didn't upload. Re-extract the zip with hidden files shown. |
| Logo upload fails | Make `uploads/` writable (755). The limit is 5 MB per image. |
| Forgot admin password | On the login page click **Forgot password?** and use your **recovery code**. It is shown once after install, and you can make a new one in Branding & settings. Lost that too? In File Manager, create `RESET-PASSWORD.txt` in the app folder with a new password (10+ characters) on the first line, then open `/admin/login`. It is applied and the file deletes itself. |
