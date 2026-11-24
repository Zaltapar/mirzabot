<?php
const AUTOEXTEND_MAX_FAILS = 3;
const AUTOEXTEND_MAX_WINDOW_DAYS = 30;
const AUTOEXTEND_REPORT_THROTTLE = 259200; // 3 days

// Test seam: the regression harness defines AUTOEXTEND_TEST_MODE before including
// this file. Named functions below are still declared, but the cron bootstrap and
// the database loop are skipped. Production runs are unaffected.
if (defined('AUTOEXTEND_TEST_MODE')) {
    return;
}
chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';

$setting = select("setting", "*");
$cronStatus = json_decode((string) $setting['cron_status'], true) ?: [];
if (($cronStatus['autoextend'] ?? true) === false) {
    // return (not exit) so the shared cron dispatcher keeps running other jobs.
    return;
}

$ManagePanel = new ManagePanel();
$faLang = languagechange(dirname(__DIR__), 'fa');
$daywarn = max(1, intval($setting['daywarn'] ?? 2));
$windowSeconds = $daywarn * 86400;
$now = time();
$throttleSeconds = AUTOEXTEND_REPORT_THROTTLE;

$stmt = $pdo->prepare("SELECT * FROM invoice WHERE auto_extend = '1' AND Status IN ('active', 'sendedwarn', 'send_on_hold', 'end_of_time', 'end_of_volume') AND (time_cron <= :t OR time_cron IS NULL) ORDER BY time_sell ASC LIMIT 50");
$stmt->execute([':t' => $now - 1600]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($invoices as $invoice) {
    update("invoice", "time_cron", $now, "id_invoice", $invoice['id_invoice']);
    clearSelectCache('invoice');
    try {
        autoExtendProcess($invoice, $ManagePanel, $windowSeconds, $now, $throttleSeconds);
    } catch (Throwable $e) {
        error_log('mirza autoextend: invoice ' . $invoice['id_invoice'] . ': ' . $e->getMessage());
    }
}

function autoExtendProcess($invoice, $panelObj, int $windowSeconds, int $now, int $throttleSeconds): void
{
    global $pdo;
    // daywarn is an admin-editable warning window (unbounded in admin.php).
    // Reusing it raw for renewals could renew a brand-new service months early.
    $windowSeconds = min($windowSeconds, AUTOEXTEND_MAX_WINDOW_DAYS * 86400);

    $panel = select("marzban_panel", "*", "name_panel", $invoice['Service_location'], "select");
    if (!$panel) {
        autoExtendNotify($invoice, 'autoRenewPanelGone', ['{username}' => $invoice['username']], $throttleSeconds);
        autoExtendReport($invoice, 'config error: panel removed', $invoice['Service_location'], $throttleSeconds);
        update("invoice", "auto_extend", "0", "id_invoice", $invoice['id_invoice']);
        return;
    }
    if ($panel['status'] == "disabled") {
        autoExtendNotify($invoice, 'autoRenewPanelDown', ['{username}' => $invoice['username']], $throttleSeconds);
        return;
    }
    $user = select("user", "*", "id", $invoice['id_user'], "select");
    if (!$user) {
        return;
    }
    $live = $panelObj->DataUser($panel['name_panel'], $invoice['username']);
    if (!is_array($live) || ($live['status'] ?? '') == "Unsuccessful") {
        $liveMsg = is_array($live) ? (string) ($live['msg'] ?? '') : '';
        if (stripos($liveMsg, 'panel not found') !== false) {
            // Deterministic: the panel mapping is gone, retrying cannot help.
            autoExtendNotify($invoice, 'autoRenewPanelGone', ['{username}' => $invoice['username']], $throttleSeconds);
            autoExtendReport($invoice, 'config error: panel not found', $panel['name_panel'], $throttleSeconds);
            update("invoice", "auto_extend", "0", "id_invoice", $invoice['id_invoice']);
        } elseif (stripos($liveMsg, 'user not found') !== false) {
            // Panels report this with differing casing ("User not found",
            // "user not found"); match case-insensitively and stop retrying.
            autoExtendNotify($invoice, 'autoRenewPanelGone', ['{username}' => $invoice['username']], $throttleSeconds);
            autoExtendReport($invoice, 'config error: service missing on panel', $panel['name_panel'], $throttleSeconds);
            update("invoice", "auto_extend", "0", "id_invoice", $invoice['id_invoice']);
        } else {
            // Transient panel/network failure: notify (throttled) but keep
            // auto-renew enabled so a temporary outage is not fatal.
            autoExtendNotify($invoice, 'autoRenewPanelDown', ['{username}' => $invoice['username']], $throttleSeconds);
        }
        return;
    }
    // Renew when EITHER condition holds:
    //  - the service is inside a bounded time-expiry window, OR
    //  - the volume is actually depleted (a positive data_limit exhausted).
    // data_limit == 0 means unlimited traffic; it must never be treated as
    // "used >= limit", otherwise every unlimited service renews immediately.
    $dataLimit = $live['data_limit'] ?? null;
    $usedTraffic = $live['used_traffic'] ?? null;
    $volumeDepleted = in_array($live['status'] ?? '', ['limited', 'expired'], true)
        && is_numeric($dataLimit) && (float) $dataLimit > 0
        && is_numeric($usedTraffic)
        && ((float) $usedTraffic >= (float) $dataLimit);
    $expireRaw = $live['expire'] ?? null;
    // Non-numeric expire must not be subtracted: PHP 8 throws, and on older
    // versions it coerced to 0 which looked like an immediately-expiring user.
    $remain = is_numeric($expireRaw) ? ((float) $expireRaw - $now) : PHP_INT_MAX;
    $inTimeWindow = $remain <= $windowSeconds;
    if (!$volumeDepleted && !$inTimeWindow) {
        return;
    }
    $product = autoExtendResolveProduct($invoice, $user, $panel);
    if ($product === 'gone') {
        autoExtendNotify($invoice, 'autoRenewProductGone', ['{username}' => $invoice['username']], $throttleSeconds);
        autoExtendReport($invoice, 'config error: product no longer in shop', $panel['name_panel'], $throttleSeconds);
        update("invoice", "auto_extend", "0", "id_invoice", $invoice['id_invoice']);
        return;
    }
    if ($product === null) {
        autoExtendNotify($invoice, 'autoRenewNotFound', ['{username}' => $invoice['username']], $throttleSeconds);
        autoExtendReport($invoice, 'could not resolve product', $panel['name_panel'], $throttleSeconds);
        return;
    }
    $price = (float) $product['price_product'];
    $reservedSpec = json_decode((string) ($invoice['auto_extend_spec'] ?? ''), true);
    $renewAtAnyPrice = is_array($reservedSpec) ? (int) ($reservedSpec['price_lock'] ?? 1) === 1 : true;
    $oldPrice = (float) $invoice['price_product'];
    if (!$renewAtAnyPrice && $oldPrice > 0 && $price > $oldPrice + 0.009) {
        $lastMsg = (int) ($invoice['auto_extend_msg_at'] ?? 0);
        $throttled = $lastMsg > 0 && (time() - $lastMsg) < $throttleSeconds;
        autoExtendNotify($invoice, 'autoRenewPriceHold', [
            '{username}' => $invoice['username'],
            '{old}' => number_format($oldPrice, 0),
            '{new}' => number_format($price, 0),
        ], $throttleSeconds);
        if (!$throttled) {
            autoExtendReport($invoice, 'price rose ' . number_format($oldPrice, 0) . ' -> ' . number_format($price, 0) . ' Toman; held by price-lock policy', $panel['name_panel'], $throttleSeconds);
        }
        return;
    }
    if ($price > 0 && !deductBalance($user, $price)) {
        autoExtendNotify($invoice, 'autoRenewBalanceLow', [
            '{username}' => $invoice['username'],
            '{need}' => number_format($price, 0),
            '{have}' => number_format((float) $user['Balance'], 0),
        ], $throttleSeconds);
        return;
    }
    // Pass the already-resolved rows so ManagePanel::extend() does not repeat
    // the panel/product lookups with potentially stale codes.
    $extend = $panelObj->extend($panel['Methodextend'], $product['Volume_constraint'], $product['Service_time'], $invoice['username'], $product['code_product'], $panel['code_panel'], $panel, $product);
    if (!is_array($extend) || empty($extend['status'])) {
        if ($price > 0) {
            addBalance($user['id'], $price);
        }
        $rawMsg = is_array($extend) ? ($extend['msg'] ?? 'unknown') : 'unknown';
        $errMsg = is_scalar($rawMsg) ? (string) $rawMsg : json_encode($rawMsg, JSON_UNESCAPED_UNICODE);
        autoExtendHandleFailure($invoice, $errMsg, autoExtendIsConfigError($errMsg), $throttleSeconds, $panel['name_panel']);
        return;
    }
    // A successful renewal resets the consecutive-failure bookkeeping.
    $meta = autoExtendMeta($invoice);
    unset($meta['renew_fail_count'], $meta['renew_fail_at']);
    autoExtendSaveMeta($invoice, $meta);
    $priceChangedLine = '';
    if (abs((float) $invoice['price_product'] - $price) > 0.009) {
        $texts = autoExtendUserTexts($invoice);
        $priceChangedLine = strtr($texts['users']['extend']['autoRenewPriceNote'], ['{old}' => number_format((float) $invoice['price_product'], 0)]);
    }
    update("invoice", "price_product", (string) $price, "id_invoice", $invoice['id_invoice']);
    $reservedSpec = json_decode((string) ($invoice['auto_extend_spec'] ?? ''), true);
    if (is_array($reservedSpec) && (isset($reservedSpec['volume']) || !empty($reservedSpec['code_product']))) {
        update("invoice", "Volume", (string) ($product['Volume_constraint'] ?? ''), "id_invoice", $invoice['id_invoice']);
        update("invoice", "Service_time", (string) ($product['Service_time'] ?? ''), "id_invoice", $invoice['id_invoice']);
    }
    $value = json_encode([
        "volumebuy" => $product['Volume_constraint'],
        "Service_time" => $product['Service_time'],
        "oldvolume" => $live['data_limit'] ?? null,
        "oldtime" => $live['expire'] ?? null,
        'code_product' => $product['code_product'],
    ]);
    $pdo->prepare("INSERT INTO service_other (id_user, username, value, type, time, price, output, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([
        $invoice['id_user'], $invoice['username'], $value, 'extend_user', date('Y/m/d H:i:s'), (string) $price, json_encode($extend, JSON_UNESCAPED_UNICODE), 'paid'
    ]);
    clearSelectCache('invoice');
    bustPanelDataCache($panel['name_panel'], $invoice['username']);
    $texts = autoExtendUserTexts($invoice);
    $msg = strtr($texts['users']['extend']['autoRenewSuccess'], [
        '{username}' => $invoice['username'],
        '{price}' => number_format($price, 0),
        '{pricechanged}' => $priceChangedLine,
    ]);
    $kbLabel = $texts['textbot']['purchasedServices'] ?? '';
    $kb = $kbLabel !== '' ? json_encode(['inline_keyboard' => [[['text' => $kbLabel, 'callback_data' => 'backorder']]]]) : null;
    sendmessage($invoice['id_user'], $msg, $kb, 'HTML', $invoice['bottype'] ?? null);
    // Successes are reported every time; only failure noise is throttled.
    autoExtendReport($invoice, 'renewed at ' . number_format($price, 0), $panel['name_panel'], 0);
}

function autoExtendResolveProduct($invoice, $user, $panel)
{
    global $pdo, $faLang;
    $agent = $user['agent'] ?? 'f';
    $loc = $panel['name_panel'];
    $productFilter = "status_product != 'disable' AND one_buy_status != '1'";
    $reserved = json_decode((string) ($invoice['auto_extend_spec'] ?? ''), true);
    if (is_array($reserved)) {
        if (!empty($reserved['code_product'])) {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE code_product = :c AND (Location = :loc OR Location = '/all') AND (agent = :a OR agent = 'all') AND $productFilter LIMIT 1");
            $stmt->execute([':c' => $reserved['code_product'], ':loc' => $loc, ':a' => $agent]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['name_product'] = $reserved['name_product'] ?? $row['name_product'];
                return $row;
            }
        }
        // A stale reserved code_product must not block the volume/days
        // fallback: this was an elseif, so the fallback never ran.
        if (isset($reserved['volume'])) {
            $price = autoExtendCustomPrice($panel, $agent, (int) $reserved['volume'], (int) ($reserved['days'] ?? 0));
            if ($price !== null) {
                return [
                    'code_product' => 'custom_volume',
                    'name_product' => $faLang['users']['customSellVolume']['title'] ?? 'Custom',
                    'Volume_constraint' => $reserved['volume'],
                    'Service_time' => $reserved['days'] ?? 0,
                    'price_product' => (string) $price,
                ];
            }
        }
    }
    $code = trim((string) ($invoice['code_product'] ?? ''));
    if ($code === 'customvolume' || $code === 'custom_volume') {
        $price = autoExtendCustomPrice($panel, $agent, (int) $invoice['Volume'], (int) $invoice['Service_time']);
        if ($price === null) {
            return 'gone';
        }
        return [
            'code_product' => 'custom_volume',
            'name_product' => $faLang['users']['customSellVolume']['title'] ?? 'Custom',
            'Volume_constraint' => $invoice['Volume'],
            'Service_time' => $invoice['Service_time'],
            'price_product' => (string) $price,
        ];
    }
    if ($code !== '') {
        $stmt = $pdo->prepare("SELECT * FROM product WHERE code_product = :c AND (Location = :loc OR Location = '/all') AND (agent = :a OR agent = 'all') AND $productFilter LIMIT 1");
        $stmt->execute([':c' => $code, ':loc' => $loc, ':a' => $agent]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: 'gone';
    }
    $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :n AND (Location = :loc OR Location = '/all') AND (agent = :a OR agent = 'all') AND $productFilter LIMIT 1");
    $stmt->execute([':n' => $invoice['name_product'], ':loc' => $loc, ':a' => $agent]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $stmt = $pdo->prepare("SELECT * FROM product WHERE Service_time = :t AND Volume_constraint = :v AND (Location = :loc OR Location = '/all') AND (agent = :a OR agent = 'all') AND $productFilter LIMIT 1");
    $stmt->execute([':t' => $invoice['Service_time'], ':v' => $invoice['Volume'], ':loc' => $loc, ':a' => $agent]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    return 'gone';
}

function autoExtendCustomPrice($panel, $agent, int $volumeGb, int $days)
{
    $priceper = json_decode((string) ($panel['pricecustomvolume'] ?? ''), true);
    $timeper = json_decode((string) ($panel['pricecustomtime'] ?? ''), true);
    if (!is_array($priceper) || !isset($priceper[$agent]) || !is_array($timeper) || !isset($timeper[$agent])) {
        return null;
    }
    return ($volumeGb * (float) $priceper[$agent]) + ($days * (float) $timeper[$agent]);
}

function autoExtendUserTexts($invoice)
{
    $user = select("user", "lang", "id", $invoice['id_user'], "select");
    $lang = $user['lang'] ?? 'fa';
    if (!in_array($lang, ['fa', 'en', 'ar', 'ru', 'zh'], true) || !file_exists(__DIR__ . '/../lang/' . $lang . '.php')) {
        $lang = 'fa';
    }
    return languagechange(dirname(__DIR__), $lang);
}

/**
 * Bookkeeping lives inside the existing auto_extend_spec JSON column, so no new
 * DB column or migration is needed at cron runtime. Existing spec consumers
 * only read their own keys and ignore these extra ones.
 */
function autoExtendMeta($invoice): array
{
    $spec = json_decode((string) ($invoice['auto_extend_spec'] ?? ''), true);
    return is_array($spec) ? $spec : [];
}

function autoExtendSaveMeta($invoice, array $meta): void
{
    update("invoice", "auto_extend_spec", json_encode($meta, JSON_UNESCAPED_UNICODE), "id_invoice", $invoice['id_invoice']);
}

/**
 * Deterministic configuration problems (bad panel/product mapping) can never
 * be fixed by retrying; everything else is a transient panel/network error.
 */
function autoExtendIsConfigError($msg): bool
{
    $text = is_scalar($msg) ? strtolower((string) $msg) : '';
    foreach (['data not found', 'panel not found', 'product not found', 'local config error'] as $needle) {
        if (strpos($text, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function autoExtendHandleFailure($invoice, string $errMsg, bool $configError, int $throttleSeconds, string $panelName): void
{
    $params = ['{username}' => $invoice['username']];
    $meta = autoExtendMeta($invoice);
    // Only count failures that are genuinely consecutive. A lone failure from
    // days ago must not combine with a new one to disable auto-renew.
    $lastFailAt = (int) ($meta['renew_fail_at'] ?? 0);
    if ($lastFailAt > 0 && (time() - $lastFailAt) > 86400) {
        $meta['renew_fail_count'] = 0;
    }

    if ($configError) {
        // Deterministic: stop now, retrying a broken mapping is pointless.
        $meta['renew_fail_count'] = AUTOEXTEND_MAX_FAILS;
    } else {
        $meta['renew_fail_count'] = (int) ($meta['renew_fail_count'] ?? 0) + 1;
    }
    $meta['renew_fail_at'] = time();

    $exhausted = $configError || $meta['renew_fail_count'] >= AUTOEXTEND_MAX_FAILS;
    if ($exhausted) {
        $meta['renew_fail_count'] = 0;
    }
    autoExtendSaveMeta($invoice, $meta);
    // Keep the local copy in sync so the throttled report does not overwrite
    // the failure counter we just persisted.
    $invoice['auto_extend_spec'] = json_encode($meta, JSON_UNESCAPED_UNICODE);

    if ($exhausted) {
        update("invoice", "auto_extend", "0", "id_invoice", $invoice['id_invoice']);
        // Force exactly one final notice even if an earlier failure was just
        // notified within the DM throttle window.
        autoExtendNotify($invoice, 'autoRenewDisabled', $params, $throttleSeconds, true);
        $label = $configError
            ? 'config error: '
            : 'renew failed after ' . AUTOEXTEND_MAX_FAILS . ' attempts: ';
        autoExtendReport($invoice, $label . $errMsg, $panelName, $throttleSeconds);
        return;
    }

    autoExtendNotify($invoice, 'autoRenewExtendFailed', $params, $throttleSeconds);
    autoExtendReport(
        $invoice,
        'renew failed (' . (int) $meta['renew_fail_count'] . '/' . AUTOEXTEND_MAX_FAILS . '): ' . $errMsg,
        $panelName,
        $throttleSeconds
    );
}

function autoExtendNotify($invoice, $key, array $params, int $throttleSeconds, bool $force = false): void
{
    $last = (int) ($invoice['auto_extend_msg_at'] ?? 0);
    if (!$force && $throttleSeconds > 0 && $last > 0 && (time() - $last) < $throttleSeconds) {
        return;
    }
    $texts = autoExtendUserTexts($invoice);
    $template = $texts['users']['extend'][$key] ?? '';
    if (!is_string($template) || $template === '') {
        // A missing locale key must never turn into an empty Telegram message.
        $template = 'Auto-renewal for your service has changed. Please contact support.';
    }
    $msg = strtr($template, $params);
    sendmessage($invoice['id_user'], $msg, null, 'HTML', $invoice['bottype'] ?? null);
    if ($throttleSeconds > 0) {
        update("invoice", "auto_extend_msg_at", time(), "id_invoice", $invoice['id_invoice']);
    }
}

function autoExtendReport($invoice, $result, $panelName, int $throttleSeconds = AUTOEXTEND_REPORT_THROTTLE): void
{
    global $setting;
    if (empty($setting['Channel_Report'])) {
        return;
    }
    $meta = autoExtendMeta($invoice);
    $last = (int) ($meta['renew_report_at'] ?? 0);
    if ($throttleSeconds > 0 && $last > 0 && (time() - $last) < $throttleSeconds) {
        return;
    }
    $topic = select("topicid", "idreport", "report", "reportcron", "select");
    $text = "🔁 [AutoExtend] " . $result . " — service '" . $invoice['username'] . "' (panel: " . $panelName . ", user: " . $invoice['id_user'] . ")";
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $topic['idreport'] ?? 0,
        'text' => $text,
        'parse_mode' => "HTML",
    ]);
    if ($throttleSeconds > 0) {
        $meta['renew_report_at'] = time();
        autoExtendSaveMeta($invoice, $meta);
    }
}
