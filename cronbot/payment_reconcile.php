<?php
chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$textbotlang = languagechange();

$sinceFile = dirname(__DIR__) . '/storage/payment_reconcile_since';
$since = is_file($sinceFile) ? (int) trim((string) @file_get_contents($sinceFile)) : 0;
if ($since <= 0) {
    // Never reconcile orders that predate this feature: they were handled by the
    // old code and re-crediting them would double-pay.
    @mkdir(dirname($sinceFile), 0775, true);
    @file_put_contents($sinceFile, (string) time());
    exit(0);
}

$cutoff = date('Y/m/d H:i:s', time() - 1800);
$sinceDate = date('Y/m/d H:i:s', $since);
$stmt = $pdo->prepare(
    "SELECT * FROM Payment_report
     WHERE payment_Status = 'paid'
       AND time < :cutoff
       AND time > :since
       AND Payment_Method NOT IN ('add balance by admin', 'low balance by admin')
       AND (reconciled IS NULL OR reconciled = '')
     ORDER BY id ASC
     LIMIT 20"
);
$stmt->execute([':cutoff' => $cutoff, ':since' => $sinceDate]);

$mark = $pdo->prepare("UPDATE Payment_report SET reconciled = ? WHERE id_order = ?");
$markDelivered = function (string $state, $orderId) use ($mark): void {
    try {
        $mark->execute([$state, $orderId]);
    } catch (Throwable $e) {
        error_log('payment_reconcile: could not mark ' . $orderId . ' as ' . $state . ': ' . $e->getMessage());
    }
};

while ($order = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $orderId = (string) $order['id_order'];
    $linked = paymentOrder($orderId);
    $invoice = $linked['invoice'] ?: null;
    $service = $linked['service'] ?: null;

    $invoiceStatus = $invoice ? strtolower(trim((string) ($invoice['Status'] ?? ''))) : '';
    $serviceStatus = $service ? strtolower(trim((string) ($service['status'] ?? ''))) : '';

    if ($invoiceStatus === 'active' || $serviceStatus === 'paid') {
        $markDelivered('delivered', $orderId);
        continue;
    }
    if (!empty($order['refunded_at'])) {
        $markDelivered('refunded', $orderId);
        continue;
    }
    if (!$invoice && !$service) {
        $amount = intval($order['price']);
        if ($amount <= 0 || intval($order['id_user']) <= 0) {
            $markDelivered('orphan', $orderId);
            continue;
        }
        // Never double-credit: if the admin already settled this user's wallet
        // manually (added or deducted balance) within 30 minutes of when this
        // order was created, treat this paid-but-unlinked order as orphaned
        // instead of recrediting the full amount. A real top-up settles with
        // reconciled='delivered', so reaching this branch means the order was
        // not delivered; if admin has already compensated, do not auto-add.
        $adminSettled = $pdo->prepare(
            "SELECT COUNT(*) FROM Payment_report
             WHERE id_user = ?
               AND Payment_Method IN ('add balance by admin', 'low balance by admin')
               AND time >= ?
               AND time < DATE_ADD(NOW(), INTERVAL 30 MINUTE)"
        );
        $orderWindow = date('Y/m/d H:i:s', strtotime((string) $order['time']) - 1800);
        $adminSettled->execute([$order['id_user'], $orderWindow]);
        if ((int) $adminSettled->fetchColumn() > 0) {
            $markDelivered('orphan', $orderId);
            mirzaReportCriticalError(
                'payment-reconcile',
                'order=' . $orderId . ' paid top-up of ' . $amount . ' skipped: user ' . $order['id_user'] . ' already has a settled admin balance entry within 30 minutes of order creation; manual review recommended'
            );
            continue;
        }
        try {
            $pdo->beginTransaction();
            $claim = $pdo->prepare(
                "UPDATE Payment_report SET reconciled = 'delivered'
                 WHERE id_order = ? AND (reconciled IS NULL OR reconciled = '')"
            );
            $claim->execute([$orderId]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                continue;
            }
            addBalance($order['id_user'], $amount);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            mirzaReportCriticalError('payment-reconcile', 'order=' . $orderId . ' top-up credit failed: ' . $e->getMessage());
            continue;
        }
        $chargedText = $textbotlang['users']['Balance']['chargedThanks']
            ?? 'Wallet charged with %s (%s).';
        sendmessage($order['id_user'], sprintf($chargedText, number_format($amount), $orderId), null, 'HTML');
        mirzaReportCriticalError(
            'payment-reconcile',
            'order=' . $orderId . ' was paid but never credited; added ' . $amount . ' to wallet of user ' . $order['id_user']
        );
        continue;
    }

    if ($invoice && $invoiceStatus === 'unpaid') {
        $panelUser = $ManagePanel->DataUser($invoice['Service_location'], $invoice['username']);
        $userExists = is_array($panelUser)
            && ((($panelUser['status'] ?? '') !== 'Unsuccessful') || !empty($panelUser['username']));

        if ($userExists) {
            try {
                $pdo->prepare("UPDATE invoice SET Status = 'active' WHERE id_invoice = ? AND Status <> 'active'")
                    ->execute([$invoice['id_invoice']]);
                clearSelectCache('invoice');
            } catch (Throwable $e) {
                mirzaReportCriticalError('payment-reconcile', 'order=' . $orderId . ' invoice=' . $invoice['id_invoice'] . ' could not activate: ' . $e->getMessage());
                $markDelivered('manual', $orderId);
                continue;
            }
            $markDelivered('recovered', $orderId);
            mirzaReportCriticalError(
                'payment-reconcile',
                'order=' . $orderId . ' invoice=' . $invoice['id_invoice'] . ' user ' . $invoice['username'] . ' exists on panel ' . $invoice['Service_location'] . ' but the invoice was not active; invoice marked active. Verify manually.'
            );
            continue;
        }

        $amount = intval($order['price']);
        if ($amount <= 0 || intval($order['id_user']) <= 0) {
            $markDelivered('manual', $orderId);
            mirzaReportCriticalError('payment-reconcile', 'order=' . $orderId . ' paid but has invalid amount/user; manual review needed');
            continue;
        }

        try {
            $pdo->beginTransaction();
            $claim = $pdo->prepare(
                "UPDATE Payment_report SET refunded_at = ?, reconciled = 'refunded'
                 WHERE id_order = ? AND (refunded_at IS NULL OR refunded_at = '')"
            );
            $claim->execute([date('Y/m/d H:i:s'), $orderId]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                continue;
            }
            addBalance($order['id_user'], $amount);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            mirzaReportCriticalError('payment-reconcile', 'order=' . $orderId . ' refund failed: ' . $e->getMessage());
            continue;
        }

        $refundText = $textbotlang['paymentGateway']['deliveryFailedRefund']
            ?? '⚠️ Your payment was received but the service could not be created. %s was refunded to your wallet.';
        sendmessage($order['id_user'], sprintf($refundText, number_format($amount)), null, 'HTML');
        mirzaReportCriticalError(
            'payment-reconcile',
            'order=' . $orderId . ' was paid but never delivered; refunded ' . $amount . ' to wallet of user ' . $order['id_user'] . ' (invoice ' . $invoice['id_invoice'] . ', panel ' . $invoice['Service_location'] . ', username ' . $invoice['username'] . ')'
        );
        continue;
    }

    $markDelivered('manual', $orderId);
    $type = $service['type'] ?? ($invoice['Status'] ?? 'unknown');
    $state = $service['status'] ?? ($invoice['Status'] ?? 'unknown');
    mirzaReportCriticalError(
        'payment-reconcile',
        'order=' . $orderId . ' paid but the linked order type=' . $type . ' status=' . $state . ' is neither delivered nor refundable automatically; manual review needed'
    );
}
