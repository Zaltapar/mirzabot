#!/usr/bin/env bash
#
# MirzaBot fork installer / migrator (Linux)
#
# Works on top of a server that already has PHP 8.2+, MySQL and Apache
# (the same prerequisites the original MirzaBot installer provisions).
#
# Usage:
#   sudo bash install-fork.sh update   [options]   # migrate an existing Mirza install to this fork
#   sudo bash install-fork.sh install  [options]   # fresh install of the fork
#
# Options:
#   --install-dir <dir>      installed bot directory (update) / new bot dir (install)
#   --zip <path>             use a local fork source zip (skips download)
#   --dir <path>             use an extracted fork source directory (skips download+extract)
#   --url <url>              fork source zip URL (default: Zaltapar/mirzabot main)
#   --domain <domain>        domain of the bot (install)
#   --db-name <name>         database name (install, default: mirzaprobot)
#   --db-user <user>         database user (install, generated if empty)
#   --db-pass <pass>         database password (install, generated if empty)
#   --token <bot_token>      Telegram bot token (install; needed for webhook)
#   --admin <chat_id>        admin chat id (install)
#   --bot <username>         bot @username (install)
#   --skip-snapshot          skip file/DB snapshots on update (not recommended)
#   --yes                    non-interactive (accept all defaults)
#
# "update" is the migration path from the original (OG) MirzaBot: it runs the
# bundled sync.php against the live install, which snapshots the database
# (mysqldump) and the install itself (rollback archive), copies the new code
# while PRESERVING config.php, lang/override/, vpnbot/, storage/ and vendor/,
# rebuilds composer only when needed, and runs table.php so the new schema,
# migrations, indexes and the webhook take control. Your existing database
# keeps working (all schema changes are additive).

set -u

FORG_REPO="https://github.com/Zaltapar/mirzabot"
FORG_BRANCH="main"
ZIP_URL="${FORG_REPO}/archive/refs/heads/${FORG_BRANCH}.zip"

cmd=""
install_dir=""
zip_path=""
src_dir=""
domain=""
db_name="mirzaprobot"
db_user=""
db_pass=""
bot_token=""
admin_id=""
bot_username=""
skip_snapshot=0
assume_yes=0

while [ $# -gt 0 ]; do
  case "$1" in
    install|update)
      if [ -n "$cmd" ]; then echo "ERROR: give only one command (install|update)"; exit 1; fi
      cmd="$1"; shift ;;
    --install-dir) install_dir="$2"; shift 2 ;;
    --zip) zip_path="$2"; shift 2 ;;
    --dir) src_dir="$2"; shift 2 ;;
    --url) ZIP_URL="$2"; shift 2 ;;
    --domain) domain="$2"; shift 2 ;;
    --db-name) db_name="$2"; shift 2 ;;
    --db-user) db_user="$2"; shift 2 ;;
    --db-pass) db_pass="$2"; shift 2 ;;
    --token) bot_token="$2"; shift 2 ;;
    --admin) admin_id="$2"; shift 2 ;;
    --bot) bot_username="$2"; shift 2 ;;
    --skip-snapshot) skip_snapshot=1; shift ;;
    --yes) assume_yes=1; shift ;;
    -h|--help) grep '^#' "$0" | sed 's/^# \{0,2\}//'; exit 0 ;;
    *) echo "ERROR: unknown option: $1"; exit 1 ;;
  esac
done

if [ -z "$cmd" ]; then
  echo "Usage: sudo bash install-fork.sh <install|update> [options]  (see --help)"
  exit 1
fi

log()  { printf '\033[1;36m==\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[ok]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31mFATAL:\033[0m %s\n' "$*" >&2; exit 1; }

# Root (or usable sudo) is needed for /var/www, crontab and php-fpm reloads.
if [ "$(id -u)" != "0" ]; then
  if command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
  else
    die "Run as root or with sudo available."
  fi
else
  SUDO=""
fi

# run CMD... == CMD with sudo when we are not root (sudo-less when root).
run() {
  if [ -n "$SUDO" ]; then
    sudo "$@"
  else
    "$@"
  fi
}

confirm() {
  # $1 prompt; returns 0 if yes. With --yes always yes.
  [ "$assume_yes" = "1" ] && return 0
  printf '%s [y/N] ' "$1"
  read -r ans
  case "$ans" in
    y|Y|yes|YES) return 0 ;;
    *) return 1 ;;
  esac
}

detect_install_dir() {
  for cand in /var/www/html/mirzaprobotconfig /var/www/html/mirzabotconfig /var/www/html/mirzabot; do
    if [ -f "$cand/table.php" ] && [ -f "$cand/config.php" ]; then
      echo "$cand"
      return 0
    fi
  done
  return 1
}

resolve_source() {
  # End: SRC_DIR points at an extracted fork source containing sync.php + table.php.
  local tmp=""
  if [ -n "$src_dir" ]; then
    [ -d "$src_dir" ] || die "--dir given but $src_dir does not exist"
    SRC_DIR="$src_dir"
    ok "Using local source directory: $SRC_DIR"
    return 0
  fi
  if [ -z "$zip_path" ] && [ -f sync.php ] && [ -f table.php ]; then
    # Running from a fork checkout: use it directly.
    SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
    ok "Using current checkout as source: $SRC_DIR"
    return 0
  fi
  if [ -n "$zip_path" ]; then
    [ -f "$zip_path" ] || die "--zip given but $zip_path does not exist"
  else
    if ! confirm "Download fork source from $ZIP_URL ?"; then
      die "Aborted."
    fi
    tmp="$(mktemp -d)"
    wget -q -O "$tmp/fork.zip" "$ZIP_URL" || die "Download failed: $ZIP_URL"
    zip_path="$tmp/fork.zip"
  fi
  tmp="${tmp:-$(mktemp -d)}"
  mkdir -p "$tmp/x"
  unzip -q -o "$zip_path" -d "$tmp/x" || die "Unzip failed"
  SRC_DIR="$(find "$tmp/x" -mindepth 1 -maxdepth 1 -type d | head -1)"
  [ -n "$SRC_DIR" ] || die "Could not locate the extracted source directory"
  ok "Source ready: $SRC_DIR"
}

# ---------------------------------------------------------------- update mode
do_update() {
  if [ -z "$install_dir" ]; then
    install_dir="$(detect_install_dir || true)"
    if [ -z "$install_dir" ]; then
      if [ "$assume_yes" = "1" ]; then
        die "No install dir given and none auto-detected (looked in /var/www/html/mirzaprobotconfig, mirzabotconfig, mirzabot)."
      fi
      printf 'Existing Mirza install directory: '
      read -r install_dir
      [ -n "$install_dir" ] || die "Aborted."
    fi
  fi
  [ -f "$install_dir/table.php" ] || die "$install_dir/table.php not found - not a MirzaBot install?"
  [ -f "$install_dir/config.php" ] || die "$install_dir/config.php not found - not an installed bot?"

  resolve_source
  [ -f "$SRC_DIR/sync.php" ] || die "Source has no sync.php - is this the fork?"

  local old_ver new_ver
  old_ver="$(cat "$install_dir/version" 2>/dev/null || echo unknown)"
  new_ver="$(cat "$SRC_DIR/version" 2>/dev/null || echo unknown)"
  log "Migrating Mirza install -> fork version"
  echo "    install dir : $install_dir"
  echo "    db version  : $old_ver -> $new_ver"
  echo "    source      : $SRC_DIR"

  if [ ! confirm "Proceed with the update? (DB + file snapshots will be created in $install_dir/storage/backup)" ]; then
    die "Aborted before touching the install."
  fi

  local snapargs=()
  if [ "$skip_snapshot" = "1" ]; then
    snapargs=(--no-backup --no-db)
    warn "Snapshots disabled by --skip-snapshot."
  fi

  local phpbin
  phpbin="$(command -v php || true)"
  [ -n "$phpbin" ] || die "php CLI not found in PATH"

  if ! run "$phpbin" "$SRC_DIR/sync.php" "$install_dir" "${snapargs[@]}"; then
    die "sync.php reported a problem. The install was left in a safe state (snapshots in $install_dir/storage/backup); fix the reported issue and re-run."
  fi

  ok "Code + schema are live. Reloading PHP-FPM (best effort)..."
  local rc=0
  for unit in php8.4-fpm php8.3-fpm php8.2-fpm php-fpm; do
    if run systemctl reload "$unit" 2>/dev/null; then
      ok "reloaded $unit"
      rc=1
      break
    fi
  done
  [ "$rc" = "1" ] || warn "Could not reload any php-fpm unit (no running unit found, or no permissions)."

  ensure_cron_dispatcher "$install_dir"

  log "Update complete."
  echo "    rollback    : $install_dir/storage/backup  (files_*.tar.gz / .zip + db_*.sql)"
  echo "    re-sync db  : php $install_dir/table.php"
}

# -------------------------------------------------------------- install mode
do_install() {
  resolve_source
  [ -f "$SRC_DIR/sync.php" ] || die "Source has no sync.php - is this the fork?"

  if [ -z "$install_dir" ]; then
    install_dir="/var/www/html/mirzabot-fork"
    if [ ! "$assume_yes" = "1" ]; then
      printf 'New bot directory [default: %s]: ' "$install_dir"
      read -r v
      [ -n "$v" ] && install_dir="$v"
    fi
  fi
  if [ -e "$install_dir" ] && [ -n "$(ls -A "$install_dir" 2>/dev/null)" ]; then
    die "$install_dir already exists and is not empty. Use 'update' to migrate it, or pick another --install-dir."
  fi

  # Read existing server config when available (OG installs store it here).
  if [ -z "$domain" ] && [ -f /root/confmirza/dbrootmirza.txt ]; then
    domain="$(grep -o "domain[[:space:]]*=[[:space:]]*'[^']*'" /root/confmirza/dbrootmirza.txt 2>/dev/null | head -1 | sed "s/.*'//;s/'$//")"
    [ -n "$domain" ] || domain=""
  fi
  if [ -z "$domain" ] && [ ! "$assume_yes" = "1" ]; then
    printf 'Bot domain (e.g. bot.example.com): '
    read -r domain
  fi
  [ -n "$domain" ] || die "Bot domain is required (--domain)."

  if [ -z "$bot_token" ] && [ ! "$assume_yes" = "1" ]; then
    printf 'Telegram bot token: '
    read -r bot_token
  fi
  if [ -z "$admin_id" ] && [ ! "$assume_yes" = "1" ]; then
    printf 'Admin chat id: '
    read -r admin_id
  fi
  if [ -z "$bot_username" ] && [ ! "$assume_yes" = "1" ]; then
    printf 'Bot username (without @): '
    read -r bot_username
  fi
  [ -n "$bot_token" ] || die "Bot token is required (--token)."
  [ -n "$admin_id" ] || die "Admin chat id is required (--admin)."
  [ -n "$bot_username" ] || die "Bot username is required (--bot)."

  # Create database + user (needs MySQL root; OG stores creds in /root/confmirza/dbrootmirza.txt).
  local root_pass=""
  if [ -f /root/confmirza/dbrootmirza.txt ]; then
    root_pass="$(grep -o "\\\$pass[[:space:]]*=[[:space:]]*'[^']*'" /root/confmirza/dbrootmirza.txt 2>/dev/null | head -1 | sed "s/.*'//;s/'$//")"
  fi
  if [ -z "$root_pass" ]; then
    warn "MySQL root password not found in /root/confmirza/dbrootmirza.txt."
    if [ ! "$assume_yes" = "1" ]; then
      printf 'MySQL root password: '
      read -r -s root_pass
      echo
    fi
  fi
  [ -n "$root_pass" ] || die "No MySQL root password available - create the database/user manually and re-run."

  if [ -z "$db_user" ]; then
    db_user="$(head -c8 /dev/urandom | od -An -tx1 | tr -d ' \n' | cut -c1-8)"
  fi
  if [ -z "$db_pass" ]; then
    db_pass="$(head -c10 /dev/urandom | od -An -tx1 | tr -d ' \n' | cut -c1-12)"
  fi

  log "Creating database '$db_name' and user '$db_user'"
  run mysql -uroot -p"$root_pass" -e "
    CREATE DATABASE IF NOT EXISTS \`$db_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '$db_user'@'localhost' IDENTIFIED BY '$db_pass';
    CREATE USER IF NOT EXISTS '$db_user'@'%' IDENTIFIED BY '$db_pass';
    GRANT ALL PRIVILEGES ON \`$db_name\`.* TO '$db_user'@'localhost';
    GRANT ALL PRIVILEGES ON \`$db_name\`.* TO '$db_user'@'%';
    FLUSH PRIVILEGES;
  " 2>/dev/null || die "MySQL user/database creation failed (check root password / permissions)."
  ok "database ready: $db_name / $db_user"

  log "Preparing $install_dir"
  run mkdir -p "$install_dir"

  # Stage via sync.php so the protected-path semantics are identical to updates.
  run mkdir -p "$install_dir/lang/override" "$install_dir/storage" "$install_dir/vendor"
  # Seed the files sync.php protects, so it has something to preserve.
  printf '<?php // placeholder\n' | run tee "$install_dir/vendor/autoload.php" >/dev/null

  # sync.php preflight expects table.php + config.php in the target, so seed
  # them first (config.php is written from the template with the new db creds).
  run cp "$SRC_DIR/table.php" "$install_dir/table.php"
  log "Writing config.php"
  sed -e "s/{database_url}/localhost/" \
      -e "s/{database_name}/$db_name/" \
      -e "s/{username_db}/$db_user/" \
      -e "s/{password_db}/$db_pass/" \
      -e "s/{API_KEY}/$bot_token/" \
      -e "s/{admin_number}/$admin_id/" \
      -e "s/{domain_name}/$domain/" \
      -e "s/{username_bot}/$bot_username/" \
      "$SRC_DIR/config.php" | run tee "$install_dir/config.php" >/dev/null \
    || die "Failed to write config.php"
  run chmod 640 "$install_dir/config.php"
  ok "config.php written (db: $db_user / $db_pass - keep it secret)"

  if ! run php "$SRC_DIR/sync.php" "$install_dir" --no-db --no-backup; then
    die "sync.php failed during fresh install."
  fi
  ok "code copied"

  log "Installing PHP dependencies (composer)"
  local composer
  composer="$(command -v composer || true)"
  if [ -z "$composer" ]; then
    [ -x "$install_dir/composer.phar" ] || {
      run wget -q -O "$install_dir/composer.phar" https://getcomposer.org/installer.phar \
        || die "composer unavailable and download failed"
    }
    composer="$install_dir/composer.phar"
  fi
  run "$composer" -d "$install_dir" install --no-dev --no-interaction --no-progress \
    || die "composer install failed"
  ok "composer done"

  log "Running schema bootstrap + migrations + webhook (table.php)"
  run php "$install_dir/table.php" || die "table.php failed - check $install_dir/error_log"
  ok "schema + webhook ready"

  # Apache: enable + point the vhost at the new dir.
  if run systemctl is-active apache2 >/dev/null 2>&1 || run systemctl is-active httpd >/dev/null 2>&1; then
    warn "Apache is running but this script does not manage virtual hosts."
    echo "    Create/enable a vhost with DocumentRoot $install_dir (AllowOverride All), then:"
    echo "    run: systemctl reload apache2"
  fi

  ensure_cron_dispatcher "$install_dir"

  log "Install complete."
  echo "    bot url     : https://$domain/"
  echo "    db          : $db_name ($db_user)"
  echo "    open Telegram and send /start to @${bot_username}"
}

# ------------------------------------------------------------ cron dispatcher
ensure_cron_dispatcher() {
  local bot_dir="$1"
  local marker="cronbot/run.php"
  # The per-minute dispatcher is what runs every job (including new ones like
  # autoextend). One crontab line is enough for both root and www-data.
  local line="* * * * * php $bot_dir/cronbot/run.php >/dev/null 2>&1"
  local user
  for user in root www-data; do
    if run crontab -u "$user" -l 2>/dev/null | grep -qF "$marker"; then
      continue
    fi
    if ( run crontab -u "$user" -l 2>/dev/null; echo "$line" ) | run crontab -u "$user" - 2>/dev/null; then
      ok "cron dispatcher registered for $user"
    else
      warn "Could not add the cron dispatcher line for $user (crontab unavailable?)."
    fi
  done
}

case "$cmd" in
  update) do_update ;;
  install) do_install ;;
esac
