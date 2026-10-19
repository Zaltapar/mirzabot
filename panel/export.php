<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$maxRows = 50000;

$exportQueries = [
    'payments' => [
        'table' => $textbotlang['panel']['exportBtnPayments'],
        'query' => "SELECT id_order, id_user, price, payment_Status, Payment_Method, time, id_invoice, bottype FROM Payment_report ORDER BY time DESC LIMIT $maxRows",
        'headers' => ['id_order', 'id_user', 'price', 'status', 'method', 'time', 'id_invoice', 'bot'],
    ],
    'users' => [
        'table' => $textbotlang['panel']['exportBtnUsers'],
        'query' => "SELECT id, username, namecustom, Balance, User_Status, agent, register, limit_usertest FROM user ORDER BY register DESC LIMIT $maxRows",
        'headers' => ['id', 'username', 'name', 'balance', 'status', 'role', 'register', 'usertest_limit'],
        'format' => static function (array $row): array {
            $register = $row['register'] ?? '';
            $row['register'] = is_numeric($register) ? date('Y/m/d H:i', (int) $register) : (string) $register;
            $row['Balance'] = (string) (int) $row['Balance'];
            return $row;
        },
    ],
    'invoices' => [
        'table' => $textbotlang['panel']['exportBtnInvoices'],
        'query' => "SELECT id_invoice, id_user, username, Service_location, time_sell, name_product, price_product, Volume, Service_time, Status, refral, auto_extend, id_payment FROM invoice ORDER BY time_sell DESC LIMIT $maxRows",
        'headers' => ['id_invoice', 'id_user', 'username', 'location', 'time_sell', 'product', 'price', 'volume', 'service_time', 'status', 'referral', 'auto_extend', 'id_payment'],
        'format' => static function (array $row): array {
            $timeSell = $row['time_sell'] ?? '';
            $row['time_sell'] = is_numeric($timeSell) ? date('Y/m/d H:i', (int) $timeSell) : (string) $timeSell;
            $row['price_product'] = (string) (int) $row['price_product'];
            return $row;
        },
    ],
];

$isValidExport = isset($_GET['export']) && (isset($exportQueries[$_GET['export']]) || $_GET['export'] === 'summary');

if ($isValidExport) {
    csrf_check_get();

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        http_response_code(500);
        die('PhpSpreadsheet is not installed');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    if ($_GET['export'] === 'summary') {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');

        $stats = [];
        try {
            $stats[$textbotlang['panel']['exportStatTotalUsers']] = db_count($pdo, "SELECT COUNT(*) FROM user");
            $stats[$textbotlang['panel']['exportStatUsersToday']] = db_count($pdo, "SELECT COUNT(*) FROM user WHERE CAST(register AS UNSIGNED) >= ?", [strtotime('today')]);
        } catch (Exception $e) {
        }
        try {
            $activeStatuses = "('active','end_of_time','end_of_volume','sendedwarn','send_on_hold')";
            $stats[$textbotlang['panel']['exportStatTotalRevenue']] = number_format((int) db_query($pdo, "SELECT COALESCE(SUM(price_product),0) FROM invoice WHERE Status IN $activeStatuses")->fetchColumn());
            $stats[$textbotlang['panel']['exportStatActiveNow']] = db_count($pdo, "SELECT COUNT(*) FROM invoice WHERE Status='active'");
            $stats[$textbotlang['panel']['exportStatRevenueToday']] = number_format((int) db_query($pdo, "SELECT COALESCE(SUM(price_product),0) FROM invoice WHERE Status IN $activeStatuses AND CAST(time_sell AS UNSIGNED) >= ?", [strtotime('today')])->fetchColumn());
        } catch (Exception $e) {
        }
        try {
            $stats[$textbotlang['panel']['exportStatPendingPayments']] = db_count($pdo, "SELECT COUNT(*) FROM Payment_report WHERE payment_Status='waiting'");
        } catch (Exception $e) {
        }

        $line = 1;
        foreach ($stats as $label => $value) {
            $sheet->fromArray([$label, $value], null, "A$line");
            $line++;
        }

        foreach ([
            'Orders' => "SELECT COALESCE(Status, 'unknown') AS status, COUNT(*) AS total FROM invoice GROUP BY Status",
            'Payments' => "SELECT COALESCE(payment_Status, 'unknown') AS status, COUNT(*) AS total FROM Payment_report GROUP BY payment_Status",
        ] as $groupTitle => $groupQuery) {
            $line++;
            $sheet->fromArray([$groupTitle], null, "A$line");
            $sheet->getStyle("A$line")->getFont()->setBold(true);
            $line++;
            $sheet->fromArray(['status', 'total'], null, "A$line");
            $line++;
            try {
                foreach (db_fetchAll($pdo, $groupQuery) as $row) {
                    $sheet->fromArray([$row['status'] ?? 'unknown', (int) ($row['total'] ?? 0)], null, "A$line");
                    $line++;
                }
            } catch (Exception $e) {
            }
        }
    } else {
        $export = $exportQueries[$_GET['export']];
        $rows = [];
        try {
            $rows = db_fetchAll($pdo, $export['query']);
        } catch (Exception $e) {
        }
        $formatter = $export['format'] ?? null;
        if ($formatter) {
            $rows = array_map($formatter, $rows);
        }
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($export['table'], 0, 30, 'UTF-8'));
        $sheet->fromArray($export['headers'], null, 'A1');
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2');
        }
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($export['headers']));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="mirzabot-' . preg_replace('/[^a-z]/', '', $_GET['export']) . '-' . date('Ymd-Hi') . '.xlsx"');
    header('Cache-Control: no-store');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
}

$pageTitle = $textbotlang['panel']['exportPageTitle'];
$pageLede = $textbotlang['panel']['exportPageLede'];
$activeNav = 'export';
include __DIR__ . '/inc/layout_head.php';

$exportCards = [
    ['key' => 'payments', 'icon' => 'card', 'title' => $textbotlang['panel']['exportBtnPayments'], 'desc' => $textbotlang['panel']['exportBtnPaymentsDesc']],
    ['key' => 'users', 'icon' => 'users', 'title' => $textbotlang['panel']['exportBtnUsers'], 'desc' => $textbotlang['panel']['exportBtnUsersDesc']],
    ['key' => 'invoices', 'icon' => 'invoice', 'title' => $textbotlang['panel']['exportBtnInvoices'], 'desc' => $textbotlang['panel']['exportBtnInvoicesDesc']],
    ['key' => 'summary', 'icon' => 'chart', 'title' => $textbotlang['panel']['exportBtnSummary'], 'desc' => $textbotlang['panel']['exportBtnSummaryDesc']],
];
?>

<div class="stats fade-up">
    <?php foreach ($exportCards as $cardIndex => $card): ?>
        <div class="card" style="display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;align-items:center;gap:10px">
                <span style="color:var(--ac)"><?= icon($card['icon'], 22) ?></span>
                <strong><?= htmlspecialchars($card['title']) ?></strong>
            </div>
            <p style="margin:0;color:var(--tx2);font-size:.82rem;line-height:1.6"><?= htmlspecialchars($card['desc']) ?></p>
            <a href="export.php?export=<?= $card['key'] ?>&_csrf=<?= htmlspecialchars(csrf_token()) ?>" class="btn-link" style="margin-top:auto;font-size:.8rem">
                <?= icon('download', 14) ?> XLSX
            </a>
        </div>
    <?php endforeach; ?>
</div>

<p class="fade-up" style="color:var(--tx2);font-size:.78rem;margin-top:14px">
    <?= str_replace('{max}', number_format($maxRows), $textbotlang['panel']['exportCapNote']) ?>
</p>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
