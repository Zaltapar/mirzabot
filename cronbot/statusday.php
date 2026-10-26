<?php
chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
$textbotlang = languagechange();
$setting = select("setting","*",null,null,"select");

//________________[ daily report]________________
// Two windows: an end-of-day snapshot at 23:45, and a "after midnight" report
// of the previous full day during 00:00-00:45. Each day is reported once.
$midnight_time = date("H:i");
$reportnight = select("topicid","idreport","report","reportnight","select")['idreport'];
$nowMinutes = (int) date("H") * 60 + (int) date("i");
$isAfterMidnight = $nowMinutes <= 45;
$dayOffset = 0;
if ($isAfterMidnight) {
    $dayOffset = -86400;
}
$reportDayKey = date("Y-m-d", time() + $dayOffset);
$stateFile = dirname(__DIR__) . "/storage/daily_report_state.json";
$state = json_decode((string) @file_get_contents($stateFile), true);
if (!is_array($state)) {
    $state = [];
}
if (($midnight_time == "23:45" || $isAfterMidnight) && ($state["last_sent_day"] ?? "") !== $reportDayKey) {
$datefirst = date("Y-m-d", time() + $dayOffset) . " 00:00:00";
$dateend = date("Y-m-d", time() + $dayOffset) . " 23:59:59";

// Helper function to execute a prepared statement
function executeQuery($pdo, $sql, $params) {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    return $stmt;
}

// Fetch count and sum for invoices
$sqlInvoices = "SELECT COUNT(*) AS count, SUM(price_product) AS total_price, SUM(Volume) AS total_volume 
                FROM invoice 
                WHERE (FROM_UNIXTIME(time_sell) BETWEEN :startDate AND :endDate) 
                AND (status IN ('active', 'end_of_time', 'sendedwarn', 'send_on_hold')) 
                AND name_product != :testName";
$params = [':startDate' => $datefirst, ':endDate' => $dateend, ':testName' => $textbotlang['common']['labels']['testServiceName']];
$stmt = executeQuery($pdo, $sqlInvoices, $params);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$dayListSell = $result['count'] ?? 0;
$suminvoiceday = $result['total_price'] ?? 0;
$sumvolume = $result['total_volume'] ?? 0;

// Fetch test service count
$sqlTestService = "SELECT COUNT(*) AS count 
                  FROM invoice 
                  WHERE (FROM_UNIXTIME(time_sell) BETWEEN :startDate AND :endDate) 
                  AND (status IN ('active', 'end_of_time', 'sendedwarn')) 
                  AND name_product = :testName";
$stmt = executeQuery($pdo, $sqlTestService, $params);
$dayListSelltest = $stmt->fetchColumn() ?? 0;

// Fetch new users count
$sqlNewUsers = "SELECT COUNT(*) AS count 
                 FROM user 
                 WHERE (FROM_UNIXTIME(register) BETWEEN :startDate AND :endDate)";
$stmt = executeQuery($pdo, $sqlNewUsers, [':startDate' => $datefirst, ':endDate' => $dateend]);
$usernew = $stmt->fetchColumn() ?? 0;

// Fetch extension data
$datefirstextend = date("Y/m/d", time() + $dayOffset) . " 00:00:00";
$dateendextend = date("Y/m/d", time() + $dayOffset) . " 23:59:59";

$sqlExtensions = "SELECT COUNT(*) AS count, SUM(price) AS total_price 
                  FROM service_other 
                  WHERE (time BETWEEN :startDate AND :endDate) 
                  AND type = 'extend_user'
                  AND status NOT IN ('unpaid', 'failed')";
$params = [':startDate' => $datefirstextend, ':endDate' => $dateendextend];
$stmt = executeQuery($pdo, $sqlExtensions, $params);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$countextendday = $result['count'] ?? 0;
$sumcountextend = number_format($result['total_price'] ?? 0);

// Fetch top agents
$sqlTopAgents = "
    SELECT u.id, u.username, 
           (SELECT SUM(i.price_product) 
            FROM invoice i 
            WHERE i.id_user = u.id 
            AND (i.time_sell BETWEEN :startDate1 AND :endDate1) 
            AND i.status IN ('active', 'end_of_time', 'sendedwarn', 'send_on_hold')) AS total_spent 
    FROM user u 
    WHERE u.agent IN ('n', 'n2') 
    AND EXISTS (SELECT 1 
                FROM invoice i 
                WHERE i.id_user = u.id 
                AND (i.time_sell BETWEEN :startDate2 AND :endDate2) 
                AND i.status IN ('active', 'end_of_time', 'sendedwarn', 'send_on_hold')) 
    ORDER BY total_spent DESC 
    LIMIT 3";

$params = [
    ':startDate1' => strtotime($datefirstextend),
    ':endDate1' => strtotime($dateendextend),
    ':startDate2' => strtotime($datefirstextend),
    ':endDate2' => strtotime($dateendextend)
];

$stmt = executeQuery($pdo, $sqlTopAgents, $params);
$listagentuser = $stmt->fetchAll(PDO::FETCH_ASSOC);
$textagent = $textbotlang['Admin']['report']['dailyTopAgentsTitle'];
foreach ($listagentuser as $agent) {
    $textagent .= sprintf($textbotlang['Admin']['report']['dailyTopAgentRow'], $agent['id'], $agent['username'], $agent['total_spent']);
}

// Fetch panel reports
$panels = select("marzban_panel", "*", null, null, "fetchAll");
$textpanel = $textbotlang['Admin']['report']['dailyPanelsTitle'];
foreach ($panels as $panel) {
    $sqlPanel = "SELECT COUNT(*) AS orders, SUM(price_product) AS total_price, SUM(Volume) AS total_volume 
                 FROM invoice 
                 WHERE (FROM_UNIXTIME(time_sell) BETWEEN :startDate AND :endDate) 
                 AND (status IN ('active', 'end_of_time', 'sendedwarn', 'send_on_hold')) 
                 AND Service_location = :location 
                 AND name_product != :testName";
    $params = [':startDate' => $datefirst, ':endDate' => $dateend, ':location' => $panel['name_panel'], ':testName' => $textbotlang['common']['labels']['testServiceName']];
    $stmt = executeQuery($pdo, $sqlPanel, $params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $orders = $result['orders'] ?? 0;
    $total_price = $result['total_price'] ?? 0;
    $total_volume = $result['total_volume'] ?? 0;

    $textpanel .= sprintf($textbotlang['Admin']['report']['dailyPanelRow'], $panel['name_panel'], $orders, $total_price, $total_volume);
}

// Daily report text
$textreport = sprintf($textbotlang['Admin']['report']['dailyBot'], $countextendday, $sumcountextend, $dayListSell, $suminvoiceday, $dayListSelltest, $sumvolume, $usernew);

// Send reports to Telegram
if (!empty($setting['Channel_Report'])) {
    $report_data = [
        ['text' => $textagent],
        ['text' => $textreport],
        ['text' => $textpanel]
    ];

    foreach ($report_data as $report) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $reportnight,
            'text' => $report['text'],
            'parse_mode' => "HTML"
        ]);
    }
    $state["last_sent_day"] = $reportDayKey;
    if (!is_dir(dirname($stateFile))) {
        @mkdir(dirname($stateFile), 0775, true);
    }
    @file_put_contents($stateFile, json_encode($state));
}
}