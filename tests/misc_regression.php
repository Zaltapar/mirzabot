<?php
/**
 * Standalone regression tests for the confirmed production defects.
 * No framework, no DB, no network. Run from the repo root:
 *
 *   php tests/misc_regression.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$repoRoot = dirname(__DIR__);
$failures = 0;
$passed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        echo "[PASS] {$name}\n";
    } else {
        $failures++;
        echo "[FAIL] {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

function src(string $relativePath): string
{
    global $repoRoot;
    $content = @file_get_contents($repoRoot . '/' . $relativePath);
    return $content === false ? '' : $content;
}

function captureWarnings(callable $fn): array
{
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });
    try {
        $fn();
    } finally {
        restore_error_handler();
    }
    return $warnings;
}

echo "== 1. db/tables/message_rules.php: no trailing comma in CREATE body ==\n";
$rules = src('db/tables/message_rules.php');
if (preg_match('/<<<SQL\s*\n(.*?)\n\s*SQL,/s', $rules, $m) === 1) {
    $body = trim($m[1]);
    check('message_rules CREATE heredoc does not end with a comma', substr($body, -1) !== ',', 'tail=' . substr($body, -40));
    check('message_rules still declares updated_at INT NOT NULL DEFAULT 0', strpos($body, 'updated_at INT NOT NULL DEFAULT 0') !== false);
} else {
    check('message_rules CREATE heredoc is extractable', false, 'could not locate heredoc');
}

echo "\n== 2. cronbot/payment_reconcile.php: admin-settled guard uses one format ==\n";
$recon = src('cronbot/payment_reconcile.php');
check('no SQL DATE_ADD(NOW(), ...) in payment_reconcile.php', strpos($recon, 'DATE_ADD(NOW(), INTERVAL 30 MINUTE)') === false);
check('guard upper bound is built in PHP with Y/m/d H:i:s', strpos($recon, '$settledUpper = date(\'Y/m/d H:i:s\', time() + 1800);') !== false);
check('guard lower bound keeps the Y/m/d H:i:s format', strpos($recon, '$orderWindow = date(\'Y/m/d H:i:s\'') !== false);
$adminStmtBlock = substr($recon, strpos($recon, '$adminSettled = $pdo->prepare('), 420);
check('admin-settled query binds three parameters', substr_count($adminStmtBlock, '?') === 3 && strpos($adminStmtBlock, 'time >= ?') !== false && strpos($adminStmtBlock, 'time < ?') !== false);
// Evidence for the root cause: mixing the two formats breaks lexicographic comparison.
$mixedFormatsBreak = ('2026/11/24 10:00:00' < '2026-11-24 10:30:00');
check('root cause: Y/m/d value is NOT lexicographically < Y-m-d bound', $mixedFormatsBreak === false);

echo "\n== 3. sync.php: composer install executes inside the target dir ==\n";
$sync = src('sync.php');
check(
    'composer passthru is prefixed with cd <escaped target> &&',
    strpos($sync, "passthru('cd ' . escapeshellarg(\$targetDir) . ' && ' . escapeshellarg(\$composer) . ' install --no-interaction --no-progress', \$rc);") !== false
);

echo "\n== 4. panels/Drivers/XuiSingle.php: empty outputlink => Unsuccessful ==\n";
if (!class_exists('BasePanelDriver')) {
    class BasePanelDriver
    {
        public function Modifyuser($username, $panel, $config)
        {
            return ['status' => true];
        }
    }
}
if (!function_exists('outputlink')) {
    function outputlink($text)
    {
        return $GLOBALS['stub_outputlink'] ?? null;
    }
}
if (!function_exists('isBase64')) {
    function isBase64($string)
    {
        return false;
    }
}
if (!function_exists('addClient')) {
    function addClient(...$args)
    {
        return ['body' => '{"success":true}'];
    }
}
if (!function_exists('get_clinets')) {
    function get_clinets($username, $panel)
    {
        $client = [
            'expiryTime' => (time() + 3600) * 1000,
            'enable' => true,
            'totalGB' => 0,
            'subId' => 'subid123',
            'email' => 'user@example.com',
            'reset' => 0,
            'trafficReset' => 'never',
        ];
        return ['body' => json_encode(['success' => true, 'obj' => ['client' => $client]])];
    }
}
if (!function_exists('used_data_3xui')) {
    function used_data_3xui($panel, $username)
    {
        return ['body' => json_encode(['success' => true, 'obj' => ['up' => 0, 'down' => 0, 'lastOnline' => 0]])];
    }
}
if (!function_exists('generateUUID')) {
    function generateUUID()
    {
        return 'stub-uuid';
    }
}
require_once $repoRoot . '/panels/Drivers/XuiSingle.php';

$panel = ['inbounds' => [], 'linksubx' => 'https://panel.example/sub', 'name_panel' => 'p'];
$product = ['inbounds' => null, 'name_product' => 'prod', 'limit_ip' => 0];
$baseVars = [
    'Get_Data_Product' => $product,
    'Get_Data_Panel' => $panel,
    'usernameC' => 'userC',
    'username' => 'userC',
    'expire' => 0,
    'data_limit' => 0,
    'note' => '',
    'Output' => ['status' => 'successful'],
    'invoice' => false,
    'domainhosts' => 'bot.example',
];
$readVars = [
    'username' => 'user@example.com',
    'Get_Data_Panel' => $panel,
    'invoice' => false,
    'domainhosts' => 'bot.example',
    'Output' => [],
];

$driver = new XuiSinglePanelDriver();

// create(): null result
$GLOBALS['stub_outputlink'] = null;
$warn = captureWarnings(static function () use ($driver, $baseVars, &$createdNull): void {
    $createdNull = $driver->create($baseVars);
});
check('create(): null outputlink returns Unsuccessful', ($createdNull['status'] ?? null) === 'Unsuccessful', 'got=' . json_encode($createdNull));
check('create(): null outputlink emits no PHP warnings', $warn === [], implode(' | ', $warn));

// create(): whitespace-only result
$GLOBALS['stub_outputlink'] = "  \n\t ";
$createdBlank = $driver->create($baseVars);
check('create(): blank outputlink returns Unsuccessful', ($createdBlank['status'] ?? null) === 'Unsuccessful', 'got=' . json_encode($createdBlank));

// create(): valid result keeps the existing successful response shape
$GLOBALS['stub_outputlink'] = "vmess://a\nvless://b";
$createdOk = $driver->create($baseVars);
check('create(): valid outputlink stays successful', ($createdOk['status'] ?? null) === 'successful');
check('create(): valid outputlink keeps configs/username/subscription_url shape',
    ($createdOk['configs'] ?? null) === ['vmess://a', 'vless://b']
    && ($createdOk['username'] ?? null) === 'userC'
    && strpos((string) ($createdOk['subscription_url'] ?? ''), 'https://panel.example/sub/') === 0,
    'got=' . json_encode($createdOk));

// read(): null result
$GLOBALS['stub_outputlink'] = null;
$warn = captureWarnings(static function () use ($driver, $readVars, &$readNull): void {
    $readNull = $driver->read($readVars);
});
check('read(): null outputlink returns Unsuccessful', ($readNull['status'] ?? null) === 'Unsuccessful', 'got=' . json_encode($readNull));
check('read(): null outputlink emits no PHP warnings', $warn === [], implode(' | ', $warn));

// read(): valid result keeps status/keys
$GLOBALS['stub_outputlink'] = "vmess://a\nvless://b";
$readOk = $driver->read($readVars);
check('read(): valid outputlink keeps active status and links',
    ($readOk['status'] ?? null) === 'active' && ($readOk['links'] ?? null) === ['vmess://a', 'vless://b'],
    'got=' . json_encode($readOk));

// revokeSub(): null result
$GLOBALS['stub_outputlink'] = null;
$warn = captureWarnings(static function () use ($driver, $baseVars, &$revokedNull): void {
    $revokedNull = $driver->revokeSub($baseVars);
});
check('revokeSub(): null outputlink returns Unsuccessful', ($revokedNull['status'] ?? null) === 'Unsuccessful', 'got=' . json_encode($revokedNull));
check('revokeSub(): null outputlink emits no PHP warnings', $warn === [], implode(' | ', $warn));

// revokeSub(): valid result keeps shape
$GLOBALS['stub_outputlink'] = "vmess://a\nvless://b";
$revokedOk = $driver->revokeSub($baseVars);
check('revokeSub(): valid outputlink keeps successful shape',
    ($revokedOk['status'] ?? null) === 'successful'
    && ($revokedOk['configs'] ?? null) === ['vmess://a', 'vless://b']
    && strpos((string) ($revokedOk['subscription_url'] ?? ''), 'https://panel.example/sub/') === 0,
    'got=' . json_encode($revokedOk));

echo "\n== 5. function.php: check_active_btn() tolerates malformed JSON ==\n";
$functionSrc = src('function.php');
if (preg_match('/^function check_active_btn\([^\n]*\n\{.*?^\}/ms', $functionSrc, $fnMatch) === 1) {
    eval($fnMatch[0]);

    $goodKeyboard = json_encode(['keyboard' => [[['text' => 'Buy']], [['text' => 'Sell']]]]);
    check('check_active_btn(): matching button returns true', check_active_btn($goodKeyboard, 'Sell') === true);
    check('check_active_btn(): missing button returns false', check_active_btn($goodKeyboard, 'Other') === false);

    foreach ([
        'malformed JSON' => '{not json',
        'missing keyboard key' => '{"foo":1}',
        'keyboard is a string' => '{"keyboard":"oops"}',
        'non-string input' => null,
    ] as $label => $badKeyboard) {
        $result = null;
        $warn = captureWarnings(static function () use ($badKeyboard, &$result): void {
            $result = check_active_btn($badKeyboard, 'Buy');
        });
        check("check_active_btn(): {$label} returns false without warnings", $result === false && $warn === [],
            'result=' . var_export($result, true) . ' warnings=' . implode(' | ', $warn));
    }
} else {
    check('check_active_btn() is extractable from function.php', false, 'could not locate function');
}

echo "\n== 6. jdf()/jdate() warning path (reported, not fixed) ==\n";
check('jdf() is not defined in function.php (owned file; no fix applicable)', strpos($functionSrc, 'function jdf') === false);
check('function.php jdate() call sites use safe fixed formats',
    strpos($functionSrc, "jdate('Y/m/d H:i:s')") !== false || strpos($functionSrc, "jdate(\$format['fa'], \$ts)") !== false);

echo "\n================ RESULT ================\n";
echo "Passed: {$passed}, Failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
