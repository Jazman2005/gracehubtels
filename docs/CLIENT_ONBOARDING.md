# Client Onboarding — Deploying Gracehubtels to a New Client

Deploys a fresh, isolated instance of this codebase to a client's own cPanel
hosting via git instead of a manual file upload. Each client gets their own
hosting account, database, and secrets — only the source code is shared.

## 0. One-time setup (do this once, not per client)

- [ ] Keep `origin` (`github.com/Jazman2005/payweb`) **private**. Clients never get
      GitHub access — only their own server pulls from it, and only you push to it.
- [ ] Confirm the client's cPanel host supports one of:
  - **cPanel "Git Version Control"** feature (most common — a UI button, no
    terminal needed), or
  - **SSH access** on the account (works the same via plain `git` commands).
  If neither is available, this workflow doesn't apply for that host — fall
  back to the manual upload for that one client only.

## 1. Per-client checklist

**Create hosting + repo access**
- [ ] Client (or you, on their behalf) sets up their cPanel hosting + domain.
- [ ] Generate a GitHub deploy key or use an HTTPS token with **read-only**
      access to `payweb` for that server — don't reuse your own personal push
      credentials on a client's box.

**Clone the code**
- [ ] Via cPanel → Git Version Control → "Create" → paste the repo URL, target
      directory `public_html` (or a subfolder if the domain is an add-on).
  - Or via SSH: `git clone <repo-url> public_html`
- [ ] Confirm `user/core/db_config.php` did **not** come through (it's
      gitignored — if it's missing, that's correct, not a bug).

**Configure this install**
- [ ] `cp user/core/db_config.php.example user/core/db_config.php`
- [ ] Fill in `DB_HOST` / `DB_USER` / `DB_PASS` / `DB_NAME` for a **new,
      dedicated database** created in this client's cPanel (never share a DB
      across clients).
- [ ] Generate a unique `VENDOR_ACCESS_KEY` — e.g. run
      `php -r "echo bin2hex(random_bytes(32));"` locally — and paste it in.
      Never reuse a key across two clients.
- [ ] Optionally set `VENDOR_CONTACT_EMAIL` (shown to the client's admin staff
      when they hit a locked premium feature).

**Build the database**
- [ ] Import the base schema: `database/msjpay.sql`
- [ ] Apply every `database/migration_vNN.sql` **in numeric order**, currently
      up through `migration_v31.sql` — there's no migration runner, so check
      the `database/` folder for anything newer than this doc before you start.
- [ ] Do **not** import any `*.sql.bak` or `msjdkjef_api.sql`-style file — those
      are local data snapshots, not schema, and are gitignored on purpose.

**Lock the install down for this client**
- [ ] Visit `https://clientdomain.com/grace-hub-tels/vendor-access.php?key=<their VENDOR_ACCESS_KEY>`
- [ ] Set Customer Services / Admin Tools / Provider Access toggles per what
      this client is paying for (e.g. lock Reteller if they only bought an
      AutoSyncNG plan).
- [ ] Confirm the page returns a plain 404 with the key omitted/wrong —
      that's the page working correctly, not broken.

**Client's own setup**
- [ ] Client creates their own admin superadmin account, SMTP settings,
      payment gateway keys, and VTU provider keys through their own admin
      panel (`/grace-hub-tels`) and `site_settings` — none of that lives in
      the codebase, so nothing above needs redoing per feature they enable.
- [ ] Set up their own cron jobs (`cron_check_vtu.php`, `cron_refund.php`,
      any others) in their cPanel's cron manager — point at their own PHP
      binary path.

**Verify**
- [ ] Log in to their admin, confirm dashboard loads with real DB state (zero
      users/transactions, not an error).
- [ ] Run one small real test purchase end-to-end once they've entered live
      provider keys, before calling the install done.

## 2. Pushing an update to an existing client later

Instead of re-uploading changed files by hand:

- [ ] `git push` here once, as usual.
- [ ] On the client's server: cPanel Git Version Control → "Pull/Update", or
      via SSH: `git pull`.
- [ ] Re-check `database/` for any new `migration_vNN.sql` since their last
      deploy and apply only the new ones, in order.
- [ ] `db_config.php` is untracked, so it's untouched by the pull — no risk of
      overwriting their live credentials.

If you want a client frozen on a known-good version instead of always
tracking `main` (e.g. mid-testing a risky change here), tag a release
(`git tag vX`) and have that client's server check out the tag instead of
`main` — optional, not needed for routine updates.

## 3. If something goes wrong

- **Pull fails / merge conflict on a client server**: this should only ever
  happen if someone hand-edited a tracked file directly on that server
  (bypassing git). Diff it, decide whether their edit was intentional, and
  either commit it back here or discard it — don't force-pull blind.
- **Site breaks after a pull**: check `logs/` on that server first (per-channel
  flat files, e.g. `logs/error.log`) before assuming it's the new code — a
  missing migration is the most common cause.
- **Wrong client's data visible**: shouldn't be structurally possible since
  every client has a fully separate database and `db_config.php` — if this
  ever happens, treat it as a critical incident, not a bug to quietly patch.
