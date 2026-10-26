<?php
chdir(__DIR__);
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

$queueFile = 'smart_queue.json';
$infoFile = 'smart_info';

// Quiet hours: never blast users between 01:00 and 07:00 local time.
if ((int) date('G') >= 1 && (int) date('G') <= 7) {
    return;
}

// Respect the single-flight bulk queue: if a manual broadcast is mid-flight, wait.
if (is_file('users.json')) {
    $busy = json_decode((string) @file_get_contents('users.json'), true);
    if (is_array($busy) && count($busy) > 0) {
        return;
    }
}

global $pdo;
smart_msg_ensure_tables();

function smart_rule_button(string $button, $textbotlang): ?string
{
    switch ($button) {
        case 'buy':
            return json_encode(['inline_keyboard' => [[['text' => $textbotlang['textbot']['sell'], 'callback_data' => 'buy']]]]);
        case 'start':
            return json_encode(['inline_keyboard' => [[['text' => $textbotlang['keyboard']['start'], 'callback_data' => 'start']]]]);
        case 'usertestbtn':
            return json_encode(['inline_keyboard' => [[['text' => $textbotlang['textbot']['userTest'], 'callback_data' => 'usertestbtn']]]]);
        case 'helpbtn':
            return json_encode(['inline_keyboard' => [[['text' => $textbotlang['textbot']['help'], 'callback_data' => 'helpbtn']]]]);
        case 'affiliatesbtn':
            return json_encode(['inline_keyboard' => [[['text' => $textbotlang['textbot']['affiliates'], 'callback_data' => 'affiliatesbtn']]]]);
        case 'addbalance':
            return json_encode(['inline_keyboard' => [[['text' => $textbotlang['textbot']['addBalance'], 'callback_data' => 'Add_Balance']]]]);
        default:
            return null;
    }
}

// ── Phase 1: drain a queue started by phase 2 of a previous tick ──
if (is_file($queueFile) && is_file($infoFile)) {
    $remaining = json_decode((string) @file_get_contents($queueFile), true);
    $info = json_decode((string) @file_get_contents($infoFile), true);
    if (!is_array($remaining) || !is_array($info)) {
        @unlink($queueFile);
        @unlink($infoFile);
        return;
    }
    $textbotlang = languagechange();
    $keyboard = smart_rule_button((string) ($info['button'] ?? 'none'), $textbotlang);
    $ruleId = (int) ($info['rule_id'] ?? 0);

    $logStmt = $pdo->prepare("INSERT IGNORE INTO message_rule_sent (rule_id, id_user, sent_at) VALUES (?, ?, ?)");
    $userStmt = $pdo->prepare("SELECT username, namecustom, Balance, score, last_message_time,
                                (SELECT COUNT(*) FROM invoice i WHERE i.id_user = u.id) AS service_count
                               FROM user u WHERE u.id = ? LIMIT 1");

    $processed = 0;
    while ($processed < 20 && count($remaining) > 0) {
        $iduser = (string) array_shift($remaining);
        $userStmt->execute([$iduser]);
        $row = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (empty($row)) {
            continue;
        }
        $row['id'] = $iduser;
        $text = smart_fill_vars((string) $info['message'], $row);
        $result = $keyboard === null
            ? sendmessage($iduser, $text, null, 'HTML')
            : sendmessage($iduser, $text, $keyboard, 'HTML');

        if (is_array($result) && $result['ok'] === false && ($result['description'] ?? '') === "Forbidden: bot was blocked by the user") {
            $invoicecount = select("invoice", "*", "id_user", $iduser, "count");
            $userinfo = select("user", "Balance", "id", $iduser, "select");
            if ($invoicecount == 0 && ($userinfo['Balance'] ?? 0) == 0) {
                $stmt = $pdo->prepare("DELETE FROM user WHERE id = :mp1");
                $stmt->execute([':mp1' => $iduser]);
            }
        }
        if ($ruleId > 0) {
            $logStmt->execute([$ruleId, $iduser, time()]);
        }
        $processed++;
    }

    if (count($remaining) === 0) {
        @unlink($queueFile);
        @unlink($infoFile);
    } else {
        file_put_contents($queueFile, json_encode($remaining));
    }

    if ($processed > 0 && $ruleId > 0) {
        $stmtUp = $pdo->prepare("UPDATE message_rules SET last_count = last_count + ? WHERE id = ?");
        $stmtUp->execute([$processed, $ruleId]);
    }
    return;
}

// ── Phase 2: find a due rule and start its queue ──
$rules = select("message_rules", "*", "active", "1", "fetchAll");
if (!is_array($rules) || count($rules) === 0) {
    return;
}

$nowTs = time();
foreach ($rules as $rule) {
    if (!smart_rule_due($rule, $nowTs)) {
        continue;
    }
    $ruleId = (int) $rule['id'];
    $segment = (string) $rule['segment'];
    $param = (int) $rule['segment_param'];

    $candidates = smart_segment_users($segment, $param, 500);

    $interval = smart_rule_frequency_seconds((string) $rule['frequency']);
    $logWindow = ($interval === PHP_INT_MAX) ? 0 : ($nowTs - $interval);
    $sent = $pdo->prepare("SELECT id_user FROM message_rule_sent WHERE rule_id = ? AND (? = 0 OR sent_at > ?)");
    $sent->execute([$ruleId, $logWindow, $logWindow]);
    $already = array_map('strval', $sent->fetchAll(PDO::FETCH_COLUMN));

    $targets = [];
    foreach ($candidates as $row) {
        $uid = (string) $row['id'];
        if (!in_array($uid, $already, true)) {
            $targets[] = $uid;
        }
    }

    if (count($targets) === 0) {
        // Nothing to send this cycle. Mark periodic rules as run; leave "once" rules armed.
        if ((string) $rule['frequency'] !== 'once') {
            $stmtRun = $pdo->prepare("UPDATE message_rules SET last_run = ?, last_count = 0 WHERE id = ?");
            $stmtRun->execute([$nowTs, $ruleId]);
        }
        continue;
    }

    $batch = array_slice($targets, 0, 200);
    $info = json_encode([
        'rule_id' => $ruleId,
        'message' => (string) $rule['message'],
        'button' => (string) $rule['button'],
    ]);
    file_put_contents($queueFile, json_encode($batch));
    file_put_contents($infoFile, $info);

    $stmtRun = $pdo->prepare("UPDATE message_rules SET last_run = ?, last_count = ? WHERE id = ?");
    $stmtRun->execute([$nowTs, 0, $ruleId]);
    break; // one rule per tick keeps the queue single-flight
}
