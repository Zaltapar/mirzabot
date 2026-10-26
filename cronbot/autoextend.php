<?php
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
    exit(0);
}

$ManagePanel = new ManagePanel();
$faLang = languagechange(dirname(__DIR__), 'fa');
$daywarn = max(1, intval($setting['daywarn'] ?? 2));
$windowSeconds = $daywarn * 86400;
$now = time();
$throttleSeconds = 3 * 86400;

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
    $panel = select("marzban_panel", "*", "name_panel", $invoice['Service_location'], "select");
    if (!$panel) {
        autoExtendNotify($invoice, 'autoRenewPanelGone', ['{username}' => $invoice['username']], $throttleSeconds);
        autoExtendReport($invoice, 'panel removed', $invoice['Service_location']);
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
        if (is_array($live) && ($live['msg'] ?? '') == "User not found") {
            autoExtendNotify($invoice, 'autoRenewPanelGone', ['{username}' => $invoice['username']], $throttleSeconds);
            autoExtendReport($invoice, 'service missing on panel', $panel['name_panel']);
            update("invoice", "auto_extend", "0", "id_invoice", $invoice['id_invoice']);
        } else {
            autoExtendNotify($invoice, 'autoRenewPanelDown', ['{username}' => $invoice['username']], $throttleSeconds);
        }
        return;
    }
    $remain = !empty($live['expire']) ? $live['expire'] - $now : PHP_INT_MAX;
    if ($remain > $windowSeconds) {
        return;
    }
    $product = autoExtendResolveProduct($invoice, $user, $panel);
    if ($product === 'gone') {
        autoExtendNotify($invoice, 'autoRenewProductGone', ['{username}' => $invoice['username']], $throttleSeconds);
        autoExtendReport($invoice, 'product no longer in shop', $panel['name_panel']);
        update("invoice", "auto_extend", "0", "id_invoice", $invoice['id_invoice']);
        return;
    }
    if ($product === null) {
        autoExtendNotify($invoice, 'autoRenewNotFound', ['{username}' => $invoice['username']], $throttleSeconds);
        autoExtendReport($invoice, 'could not resolve product', $panel['name_panel']);
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
            autoExtendReport($invoice, 'price rose ' . number_format($oldPrice, 0) . ' -> ' . number_format($price, 0) . ' Toman; held by price-lock policy', $panel['name_panel']);
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
    $extend = $panelObj->extend($panel['Methodextend'], $product['Volume_constraint'], $product['Service_time'], $invoice['username'], $product['code_product'], $panel['code_panel']);
    if (!is_array($extend) || empty($extend['status'])) {
        if ($price > 0) {
            addBalance($user['id'], $price);
        }
        $errMsg = is_array($extend) ? json_encode($extend['msg'] ?? 'unknown', JSON_UNESCAPED_UNICODE) : 'unknown';
        autoExtendNotify($invoice, 'autoRenewExtendFailed', ['{username}' => $invoice['username']], $throttleSeconds);
        autoExtendReport($invoice, 'panel extend failed: ' . $errMsg, $panel['name_panel']);
        return;
    }
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
    autoExtendReport($invoice, 'renewed at ' . number_format($price, 0), $panel['name_panel']);
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
        } elseif (isset($reserved['volume'])) {
            $price = autoExtendCustomPrice($panel, $agent, (int) $reserved['volume'], (int) ($reserved['days'] ?? 0));
            if ($price !== null) {
                return [
                    'code_product' => 'customvolume',
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
            'code_product' => 'customvolume',
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

function autoExtendNotify($invoice, $key, array $params, int $throttleSeconds): void
{
    $last = (int) ($invoice['auto_extend_msg_at'] ?? 0);
    if ($throttleSeconds > 0 && $last > 0 && (time() - $last) < $throttleSeconds) {
        return;
    }
    $texts = autoExtendUserTexts($invoice);
    $msg = strtr($texts['users']['extend'][$key] ?? '', $params);
    sendmessage($invoice['id_user'], $msg, null, 'HTML', $invoice['bottype'] ?? null);
    if ($throttleSeconds > 0) {
        update("invoice", "auto_extend_msg_at", time(), "id_invoice", $invoice['id_invoice']);
    }
}

function autoExtendReport($invoice, $result, $panelName): void
{
    global $setting;
    if (empty($setting['Channel_Report'])) {
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
}
