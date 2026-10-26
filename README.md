<div align="center">

# 🤖 VPN Bot — Enhanced Edition

### A powerful Telegram bot for selling VPN services — with fully automated config creation, Pro-grade interactivity and tuned performance.

<p>
  <a href="https://github.com/Zaltapar/mirzabot/stargazers">
    <img src="https://img.shields.io/github/stars/Zaltapar/mirzabot?style=flat-square&color=f5c518" alt="Stars"/>
  </a>
  <a href="https://github.com/Zaltapar/mirzabot/actions/workflows/ci.yml">
    <img src="https://img.shields.io/github/actions/workflow/status/Zaltapar/mirzabot/ci.yml?style=flat-square" alt="CI"/>
  </a>
  <img src="https://img.shields.io/badge/PHP-8.2-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2"/>
  <img src="https://img.shields.io/badge/version-1.1-8B949E?style=flat-square" alt="Version"/>
</p>

</div>

---

## ⚡ One-line install (fresh Ubuntu server)

Copy this single command onto a clean Ubuntu 22.04/24.04/26.04 server and run it:

```bash
curl -fsSL https://raw.githubusercontent.com/Zaltapar/mirzabot/main/install.sh | bash
```

That's it. The installer provisions PHP, Apache, MySQL and SSL, downloads the bot,
creates the database, writes `config.php`, sets the webhook and registers the cron
dispatcher. It only asks for **three things** (domain, bot token, admin chat id) —
everything else uses safe automatic defaults. For a fully unattended run, pass them
as flags:

```bash
curl -fsSL https://raw.githubusercontent.com/Zaltapar/mirzabot/main/install.sh \
  | bash -s -- install --domain bot.example.com --token 123:ABC --admin -1001234567890
```

Afterwards, `bash install.sh` (or the `mirza` command) opens a management menu
(install / update / migrate / SSL / backup / import).

---

## 📚 Table of Contents

- [✨ Overview](#-overview)
- [🆕 What's New in This Version](#-whats-new-in-this-version)
- [📖 Documentation](#-documentation)
- [🧩 Supported Panels](#-supported-panels)
- [💳 Payment Gateways](#-payment-gateways)
- [⚙️ Features](#️-features)
- [🚀 Installation & Upgrading](#-installation--upgrading)
  - [Prerequisites](#prerequisites)
  - [Migrate a running install to this version](#migrate-a-running-install-to-this-version)
  - [Fresh install](#fresh-install)
  - [Update this version in place (sync.php)](#update-this-version-in-place-syncphp)
  - [Automated build health (CI)](#automated-build-health-ci)
  - [Rollback](#rollback)
- [🌍 Languages](#-languages)
- [👥 Contributors](#-contributors)

---

## ✨ Overview

**the bot** is a feature-rich Telegram bot for selling VPN subscriptions and automating the entire sales workflow — from purchase and payment to config creation and service management.

It connects directly to your panels, builds configurations automatically, accepts a wide range of payment methods, and gives both customers and admins a clean experience through a **Telegram Mini App** and a **web admin panel**.

This enhanced edition layers a set of interactivity, UX and performance improvements on top of the core product, plus tooling that keeps upgrades to it safe and automated.

> Whether you're handing out trial accounts or running a large-scale reseller business, the bot has the tools to run it end to end.

---

## 🆕 What's New in This Version

### New in 1.1
- **Smart suggestions** — the bot learns each customer's most-used renewal plan, plan duration and service action; the favourite is marked with 🔥 and surfaced first (service actions are hoisted, plans reordered).
- **Auto-renewal with reserved spec** — "auto-renewal" now reserves the renewal spec up front (volume + days for custom services, plan for packaged ones) and the cron renews at exactly that spec when the service expires, instead of blindly renewing the current plan.
- **Telegram `copyMessage` resend** — customers can re-deliver their original purchase message straight from the service page.
- **Rich messages & drafts** (opt-in) — `sendRichMarkdown` cards for deliveries and a live checkout draft via Bot API 10.x, with automatic fallback to classic messages.
- **Force-reply prompts** — every numeric input (volume, duration, balance, discount) requests a reply keyboard instead of a silent text prompt.
- **Modern button styles** — primary/success/danger colours on main-menu buttons and disabled buttons for sold-out products.
- **Spreadsheet exports** — panel now exports payments, users, invoices and a summary sheet as `.xlsx` (CSV-free, 50k-row cap).
- **`restore.php`** — CLI tool to roll back to any `sync.php` snapshot (database + files), with a typed confirmation.

### Interactivity & UX
- **Inline main menu by default** — the primary menu is served as an inline keyboard (one-time migration flips existing installs automatically).
- **Account overview on `/start`** — each user immediately sees their services, balances and wallet state.
- **Richer pre-payment summary** — the payment confirmation shows the full service breakdown before you pay.
- **Service status chips** — purchased-service lists show live status at a glance.
- **Copy buttons** — configs, links and usernames come with one-tap copy actions.
- **Locale-aware dates** with safe language fallback for all service time displays.
- **Opt-in auto-renewal** — customers can ask for automatic renewal of expiring services; the cron `autoextend` job handles it end to end.

### Performance
- Panel data **cache** for the Telegram Mini App (busted automatically on every mutation) plus a `user.token` index.
- Lightened webview API (no root keyboard payload, de-N+1'd queries, reused user row).
- HTTP caching, gzip and non-blocking SDK loading for the webview.
- Cached exchange-rate lookups (TTL), batched channel-membership checks, lazy admin-only keyboard loads, and consolidated admin-stats queries.

### Tooling
- `sync.php` — an in-place upgrader that layers this code over a running install while preserving your credentials and state.
- `install-fork.sh` — Linux installer/migrator: migrate a running bot to this version (with DB + file snapshots) or do a fresh install.
- GitHub Actions CI (`.github/workflows/ci.yml`) — PHP lint, shell checks, a real-MySQL schema smoke test and an end-to-end upgrader test on every push.

---

## 📖 Documentation

Every menu is documented with the exact order of the steps the bot asks for, what each field accepts, the error message you get when it rejects your input, and the mistake that most often breaks that feature. See the API reference at `/api/` on your installation.

| Section | What it covers |
|---------|----------------|
| **شروع** (Getting started) | Architecture, requirements, server install, shared-host install, CLI flags, updating and removing |
| **ساختار** (Structure) | Repository layout, database tables, which file does what |
| **پنل‌ها** (Panels) | Adding each of the 13 panel types, protocol & inbound setup, per-panel menus, manual config creation, node management |
| **فروشگاه** (Shop) | Products, categories, trial accounts, On-Hold services, bulk purchase, manual sale, renewals and location changes |
| **پرداخت** (Payments) | Card-to-card with receipt approval, every online and crypto gateway, wallet and refunds |
| **مدیریت** (Administration) | Admin roles, feature switches, report channel and topics, texts, forced-join channel, web panel |
| **رشد و بازاریابی** (Growth) | Referrals, cashback, discount and gift codes, lottery and wheel, reseller system |
| **نگهداری** (Operations) | Cron jobs, backup and restore, optimization, security checklist, troubleshooting |

> The docs are generated from the bot's own source, so menu names, limits and error strings match the code rather than an older release.

---

## 🧩 Supported Panels

the bot integrates with the most popular VPN and network management panels:

| Panel | Panel |
|-------|-------|
| 🟢 **Marzban** | 🟢 **Marzneshin** |
| 🟢 **Sanaei / Alireza** |
| 🟢 **S-UI** | 🟢 **Hiddify** |
| 🟢 **WGDashboard** (WireGuard) | 🟢 **MikroTik** |
| 🟢 **IBSng** | 🟢 **Pasarguard** |

> Configs are generated automatically and are compatible with all common protocols.

---

## 💳 Payment Gateways

| Gateway | Type |
|---------|------|
| 💵 **Card-to-Card** | Manual (receipt + admin approval) |
| 🪙 **NowPayments** | Crypto |
| 🪙 **Plisio** | Crypto |
| 🪙 **CubePay** | TRON / crypto |
| 🇮🇷 **Zarinpal** | Online gateway |
| 🇮🇷 **Aqayepardakht** | Online gateway |
| 🇮🇷 **IranPay** | Online gateway |

---

## ⚙️ Features

### 🛒 Sales & Configuration
- ✅ VPN purchase with **fully automated** config creation
- ✅ Trial / test accounts for new users
- ✅ Compatibility with all common protocols
- ✅ QR codes for fast config import
- ✅ Protocol-based configuration settings
- ✅ Product, panel & gateway management

### 👤 User Experience
- ✅ **Telegram Mini App** for a modern, in-app interface
- ✅ View & manage purchased services:
  - Renew a service
  - Buy additional volume
  - Retrieve config / update subscription links
- ✅ Wallet & balance system
- ✅ Detailed purchase & trial reports
- ✅ Support section, FAQ & customizable tutorials
- ✅ Phone-number verification
- ✅ Mandatory channel membership for purchases

### 📈 Growth & Marketing
- ✅ Affiliate / referral system
- ✅ Cashback rewards
- ✅ Discount codes
- ✅ Gift codes
- ✅ Lottery system
- ✅ **Agent / reseller** system

### 🛠️ Administration
- ✅ **Web admin panel** (login-protected dashboard)
- ✅ Multiple admins support
- ✅ Balance & user management
- ✅ Full text/message customization from the bot
- ✅ Configurable username-generation methods
- ✅ Automatic backups
- ✅ Notification & expiry-reminder services (cron)
- ✅ On-hold configurations

---

## 🚀 Installation & Upgrading

### Prerequisites

| Requirement | Details |
|-------------|---------|
| 🖥️ **OS** | An Ubuntu **22.04** or **24.04** server |
| 🌐 **Domain** | A domain name pointed to your server's IP |
| ⚙️ **Stack** | PHP 8.2, Apache, MySQL, SSL |

### Migrate a running install to this version

The main path: you already run the bot on the server and want to move it to
this enhanced edition without losing credentials, overrides, sales bots or
runtime state.

```bash
# 1) get this code onto the server (checkout or download the source zip)
# 2) run the migrator against your install directory:
sudo bash install-fork.sh update --install-dir /var/www/html/mirzaprobotconfig
```

`update` (powered by the bundled `sync.php`) does, in order:

1. **Preflight** — verifies both the source checkout and the target install.
2. **DB snapshot** — `mysqldump` of the target's database into `storage/backup/`
   (skipped if `mysqldump` is unavailable; `--no-db` to skip on purpose).
3. **File snapshot** — a rollback archive of the current install
   (`--no-backup` to skip).
4. **Copy** — new code over the install, *preserving* `config.php`,
   `lang/override/`, `vpnbot/`, `storage/`, `vendor/` and `.git/`.
5. **Composer** — only when `vendor/` is missing or `composer.json` changed.
6. **Take control** — runs `php table.php` in the target (idempotent schema
   bootstrap, migrations, indexes, webhook re-set), so the new features and
   database columns are live immediately.

It then reloads PHP-FPM (best effort) and makes sure the `cronbot/run.php`
dispatcher is registered in the `root` + `www-data` crontabs — which is what
picks up new jobs like **auto-renewal** with no manual cron edits.

Options: `--zip <path>` / `--dir <path>` / `--url <url>` for the source,
`--skip-snapshot`, `--yes` for non-interactive runs.

### Fresh install

On a clean Ubuntu server there is **nothing to do but one line** (see
[One-line install](#-one-line-install-fresh-ubuntu-server)): the bundled
`install.sh` provisions the whole stack and installs this bot end to end.

On a shared host where the stack already exists, install this version into a
fresh directory instead:

```bash
sudo bash install-fork.sh install --domain bot.example.com \
  --db-name mirzaprobot --token 123:ABC --admin -1001234567890 --bot mybot
```

`install` creates the database + user, writes `config.php` from the template,
copies the code, runs composer, boots the schema/webhook and registers the
cron dispatcher.

### Update this version in place (sync.php)

When you pull new code of this edition over a running install:

```bash
# from the new checkout, point it at the installed bot:
php sync.php /var/www/html/mirzaprobotconfig
```

`sync.php` is safe to re-run and idempotent. Use `--no-db` / `--no-backup` to
skip the respective snapshots.

### Automated build health (CI)

`.github/workflows/ci.yml` runs on every push/PR:

- **Lint job** — `composer validate` + `php -l` on every non-vendor PHP file,
  `bash -n` on both installers, and ShellCheck on `install-fork.sh`
  (`install.sh` checked at error severity).
- **Smoke job (Linux + MySQL 8)** — provisions a real MySQL service, generates
  `config.php` from the template, runs `table.php`, then asserts the schema is
  healthy: `panel_data_cache` table + unique key, `invoice` auto-renew
  columns, `setting.accountsummary`, `schema_flags` migration state, the
  `idx_user_token` user index, and that the `autoextend` cron job is
  registered. Finally it smoke-tests `sync.php` end-to-end against a fake
  "legacy" install (protected files stay untouched, new code lands,
  idempotent re-run).

### Rollback

After any `update`, the rollback point lives in the install's
`storage/backup/` (file snapshot + `db_*.sql`). Restore both, re-run
`php table.php` on the restored code, and you are back on the previous
version.

---

## 🌍 Languages

the bot ships with full translations for:

🇬🇧 English · 🇮🇷 Persian (فارسی) · 🇷🇺 Russian (Русский) · 🇨🇳 Chinese (中文)

---

## 👥 Contributors

Thanks to everyone who has contributed to making the bot better:

<a href="https://github.com/Zaltapar/mirzabot/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=Zaltapar/mirzabot" alt="Contributors"/>
</a>

---

<div align="center">

**Maintained as a community fork · Made with ❤️**

⭐ [Star on GitHub](https://github.com/Zaltapar/mirzabot)

</div>
