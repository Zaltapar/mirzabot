<?php
/**
 * Telegram rejects the WHOLE message when any callback_data is longer than 64
 * bytes. Long payloads (username + product code) are swapped for a short token
 * that is resolved again when the button is pressed.
 */
function mirzaShortenCallbackData($data)
{
    global $pdo;
    $data = (string) $data;
    if (strlen($data) <= 64) {
        return $data;
    }
    if (!is_object($pdo) || !method_exists($pdo, 'prepare')) {
        error_log('callback_data too long and no DB connection: ' . substr($data, 0, 80));
        return $data;
    }
    try {
        $token = 't_' . bin2hex(random_bytes(8));
        $stmt = $pdo->prepare('INSERT INTO callback_token (token, payload, created_at) VALUES (?, ?, ?)');
        $stmt->execute([$token, $data, date('Y/m/d H:i:s')]);
        return $token;
    } catch (\Throwable $e) {
        error_log('callback_data too long and token store failed: ' . $e->getMessage());
        return $data;
    }
}
function mirzaResolveCallbackData($data)
{
    global $pdo;
    $data = (string) $data;
    if (strlen($data) !== 18 || strpos($data, 't_') !== 0 || !ctype_xdigit(substr($data, 2))) {
        return $data;
    }
    if (!is_object($pdo) || !method_exists($pdo, 'prepare')) {
        return $data;
    }
    try {
        $stmt = $pdo->prepare('SELECT payload FROM callback_token WHERE token = ? LIMIT 1');
        $stmt->execute([$data]);
        $payload = $stmt->fetchColumn();
        return is_string($payload) && $payload !== '' ? $payload : $data;
    } catch (\Throwable $e) {
        return $data;
    }
}
function mirzaSanitizeReplyMarkup($replyMarkup)
{
    if (is_array($replyMarkup)) {
        if (!isset($replyMarkup['inline_keyboard']) || !is_array($replyMarkup['inline_keyboard'])) {
            return $replyMarkup;
        }
        foreach ($replyMarkup['inline_keyboard'] as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $buttonIndex => $button) {
                if (is_array($button) && isset($button['callback_data']) && is_string($button['callback_data'])) {
                    $replyMarkup['inline_keyboard'][$rowIndex][$buttonIndex]['callback_data'] =
                        mirzaShortenCallbackData($button['callback_data']);
                }
            }
        }
        return $replyMarkup;
    }
    if (!is_string($replyMarkup) || strpos($replyMarkup, 'callback_data') === false) {
        return $replyMarkup;
    }
    $markup = json_decode($replyMarkup, true);
    if (!is_array($markup)) {
        return $replyMarkup;
    }
    $markup = mirzaSanitizeReplyMarkup($markup);
    $encoded = json_encode($markup);
    return $encoded === false ? $replyMarkup : $encoded;
}
