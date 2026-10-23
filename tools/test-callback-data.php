<?php
require_once __DIR__ . '/../callback_data.php';

final class FakeCallbackDb
{
    public $rows = array();

    public function prepare($sql)
    {
        return new FakeCallbackStmt($this, $sql);
    }
}

final class FakeCallbackStmt
{
    private $db;
    private $sql;
    private $result = false;

    public function __construct($db, $sql)
    {
        $this->db = $db;
        $this->sql = $sql;
    }

    public function execute($params = array())
    {
        if (stripos($this->sql, 'INSERT INTO callback_token') !== false) {
            $this->db->rows[$params[0]] = $params[1];
            $this->result = true;
            return true;
        }
        if (stripos($this->sql, 'SELECT payload FROM callback_token') !== false) {
            $this->result = isset($this->db->rows[$params[0]]) ? $this->db->rows[$params[0]] : false;
            return true;
        }
        throw new RuntimeException('unexpected SQL: ' . $this->sql);
    }

    public function fetchColumn()
    {
        return $this->result;
    }
}

$pdo = new FakeCallbackDb();

$failures = 0;
function check($label, $actual, $expected)
{
    global $failures;
    if ($actual === $expected) {
        echo "PASS $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

check('short callback_data is untouched', mirzaShortenCallbackData('confirmserivces-abc'), 'confirmserivces-abc');

$exactly64 = str_repeat('a', 64);
check('64-byte callback_data is untouched', mirzaShortenCallbackData($exactly64), $exactly64);

$long = 'serviceextendselects-' . str_repeat('u', 60);
$token = mirzaShortenCallbackData($long);
check('65+ byte payload becomes an 18-byte token', strlen($token), 18);
check('token prefix', substr($token, 0, 2), 't_');
check('token is hex', ctype_xdigit(substr($token, 2)), true);
check('payload is stored verbatim', isset($pdo->rows[$token]) ? $pdo->rows[$token] : null, $long);
check('token resolves to payload', mirzaResolveCallbackData($token), $long);

check('plain callback_data resolves to itself', mirzaResolveCallbackData('confirmserivces-abc'), 'confirmserivces-abc');

$unknown = 't_' . str_repeat('0', 16);
check('unknown token resolves to itself', mirzaResolveCallbackData($unknown), $unknown);
check('malformed short token untouched', mirzaResolveCallbackData('t_zzzz'), 't_zzzz');
$nonHex = 't_' . str_repeat('z', 16);
check('18-byte non-hex untouched', mirzaResolveCallbackData($nonHex), $nonHex);

$persian = 'confirmserivces-' . str_repeat('م', 30);
$faToken = mirzaShortenCallbackData($persian);
check('multibyte payload becomes token', strlen($faToken), 18);
check('multibyte payload round-trips byte-exact', mirzaResolveCallbackData($faToken), $persian);

$second = mirzaShortenCallbackData($long);
check('tokens are unique', $second !== $token, true);
check('second token resolves', mirzaResolveCallbackData($second), $long);

$markup = array(
    'inline_keyboard' => array(
        array(
            array('text' => 'short', 'callback_data' => 'short'),
            array('text' => 'long', 'callback_data' => $long),
        ),
    ),
    'resize_keyboard' => true,
);
$sanitized = mirzaSanitizeReplyMarkup($markup);
check('array: short button kept', $sanitized['inline_keyboard'][0][0]['callback_data'], 'short');
check('array: long button shortened', strlen($sanitized['inline_keyboard'][0][1]['callback_data']), 18);
check('array: long button still resolves', mirzaResolveCallbackData($sanitized['inline_keyboard'][0][1]['callback_data']), $long);
check('array: other keys preserved', $sanitized['resize_keyboard'], true);

$json = json_encode($markup);
$sanitizedJson = mirzaSanitizeReplyMarkup($json);
$decoded = json_decode($sanitizedJson, true);
check('json: output is valid json', is_array($decoded), true);
check('json: short button kept', $decoded['inline_keyboard'][0][0]['callback_data'], 'short');
check('json: long button shortened', strlen($decoded['inline_keyboard'][0][1]['callback_data']), 18);
check('json: long button still resolves', mirzaResolveCallbackData($decoded['inline_keyboard'][0][1]['callback_data']), $long);

$plainJson = '{"keyboard":[["x"]]}';
check('markup without callback_data is untouched', mirzaSanitizeReplyMarkup($plainJson), $plainJson);
check('null markup is untouched', mirzaSanitizeReplyMarkup(null), null);

$maxLen = 0;
foreach ($sanitized['inline_keyboard'][0] as $button) {
    $maxLen = max($maxLen, strlen($button['callback_data']));
}
check('all sanitized buttons fit in 64 bytes', $maxLen <= 64, true);

if ($failures > 0) {
    echo "\n$failures check(s) failed\n";
    exit(1);
}
echo "\nall callback_data checks passed\n";
