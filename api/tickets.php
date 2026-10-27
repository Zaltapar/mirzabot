<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/utils.php';
$textbotlang = languagechange();
require_once __DIR__ . '/../botapi.php';

header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Asia/Tehran');
ini_set('default_charset', 'UTF-8');
ini_set('error_log', 'error_log');

list($headers, $data, $action) = apiRequestContext();
$method = $_SERVER['REQUEST_METHOD'];
$setting = select("setting", "*");

function tck_list(array $data, string $method): void
{
    global $pdo;

    validateMethod('GET', $method);

    $paging = paginationParams($data);
    $limit = $paging['limit'];
    $offset = $paging['offset'];
    $q = $paging['q'];

    $status = (string) ($data['status'] ?? '');
    $statusOk = in_array($status, ['Unseen', 'Answered', 'Pending', 'Customerresponse', 'close'], true);
    $like = "%$q%";

    $likeSql = "";
    $likeParams = [];
    if ($q !== '') {
        $likeSql = " AND (m.Tracking LIKE :q1 OR m.iduser LIKE :q2 OR u.username LIKE :q3 OR m.text LIKE :q4)";
        $likeParams = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
    }
    $statusSql = $statusOk ? " AND m.status = :status" : "";
    $statusParams = $statusOk ? [':status' => $status] : [];

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM support_message m LEFT JOIN user u ON u.id = m.iduser WHERE 1=1" . $likeSql . $statusSql
    );
    $countStmt->execute(array_merge($likeParams, $statusParams));
    $total = (int) $countStmt->fetchColumn();
    $totalPages = (int) ceil($total / $limit);

    $stmt = $pdo->prepare(
        "SELECT m.Tracking, m.iduser, u.username, m.name_departman, m.text, m.result, m.time, m.status, m.id
         FROM support_message m LEFT JOIN user u ON u.id = m.iduser
         WHERE 1=1" . $likeSql . $statusSql . "
         ORDER BY m.time DESC LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach (array_merge($likeParams, $statusParams) as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendJsonResponse(true, "Successful", [
        'tickets' => $rows,
        'pagination' => [
            'total_items' => $total,
            'total_pages' => $totalPages,
            'current_page' => $paging['page'],
            'per_page' => $limit,
        ],
    ]);
}

function tck_detail(array $data, string $method): void
{
    global $pdo;

    validateMethod('GET', $method);

    $tracking = (string) ($data['tracking'] ?? '');
    if ($tracking === '') {
        sendJsonResponse(false, "tracking empty", []);
    }

    $stmt = $pdo->prepare("SELECT m.*, u.username FROM support_message m LEFT JOIN user u ON u.id = m.iduser WHERE m.Tracking = :tracking LIMIT 1");
    $stmt->bindValue(':tracking', $tracking, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sendJsonResponse(false, "ticket not found", [], 404);
    }

    sendJsonResponse(true, "Successful", ['ticket' => $row]);
}

function tck_reply(array $data, string $method): void
{
    global $pdo, $textbotlang;

    validateMethod('POST', $method);

    requireFields($data, ['tracking', 'text']);
    $tracking = (string) $data['tracking'];
    $text = (string) $data['text'];

    $stmt = $pdo->prepare("SELECT * FROM support_message WHERE Tracking = :tracking LIMIT 1");
    $stmt->bindValue(':tracking', $tracking, PDO::PARAM_STR);
    $stmt->execute();
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        sendJsonResponse(false, "ticket not found", [], 404);
    }
    if ($ticket['status'] == "Answered" || $ticket['status'] == "close") {
        sendJsonResponse(false, "ticket already " . $ticket['status'], []);
    }

    $time = date('Y/m/d H:i:s');
    $upd = $pdo->prepare("UPDATE support_message SET status = 'Answered', result = :result, time = :time WHERE Tracking = :tracking");
    $upd->bindValue(':result', $text, PDO::PARAM_STR);
    $upd->bindValue(':time', $time, PDO::PARAM_STR);
    $upd->bindValue(':tracking', $tracking, PDO::PARAM_STR);
    $upd->execute();

    $kb = json_encode(['inline_keyboard' => [[['text' => '↩️', 'callback_data' => 'Responsesusera_' . $tracking]]]]);
    sendmessage($ticket['iduser'], $text, $kb, 'HTML');

    sendJsonResponse(true, "Successful", ['ticket' => $tracking, 'status' => 'Answered']);
}

function tck_close(array $data, string $method): void
{
    global $pdo;

    validateMethod('POST', $method);

    $tracking = (string) ($data['tracking'] ?? '');
    if ($tracking === '') {
        sendJsonResponse(false, "tracking empty", []);
    }

    $stmt = $pdo->prepare("SELECT * FROM support_message WHERE Tracking = :tracking LIMIT 1");
    $stmt->bindValue(':tracking', $tracking, PDO::PARAM_STR);
    $stmt->execute();
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        sendJsonResponse(false, "ticket not found", [], 404);
    }

    $upd = $pdo->prepare("UPDATE support_message SET status = 'close' WHERE Tracking = :tracking");
    $upd->bindValue(':tracking', $tracking, PDO::PARAM_STR);
    $upd->execute();

    sendJsonResponse(true, "Successful", ['ticket' => $tracking, 'status' => 'close']);
}

match ($action) {
    'tickets' => tck_list($data, $method),
    'ticket_detail' => tck_detail($data, $method),
    'ticket_reply' => tck_reply($data, $method),
    'ticket_close' => tck_close($data, $method),
    default => sendJsonResponse(false, "Action Invalid"),
};
