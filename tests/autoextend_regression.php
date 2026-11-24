<?php
/**
 * Standalone regression suite for the auto-renew defects.
 *
 * Run from the repo root:  php tests/autoextend_regression.php
 *
 * The harness defines the two test-mode constants before including the
 * production files. Those files declare their functions/classes at compile
 * time but skip their app bootstrap, so the real production code is exercised
 * against in-memory stubs (no database, no Telegram API, no network).
 */

define('AUTOEXTEND_TEST_MODE', true);
define('PANELS_TEST_MODE', true);

$GLOBALS['TEST'] = [
    'rows' => [],
    'setting' => ['daywarn' => '2', 'Channel_Report' => '@report'],
    'faLang' => ['users' => ['customSellVolume' => ['title' => 'Custom']]],
    'productsByCode' => [],
    'productsByName' => [],
    'productsBySpec' => [],
    'updates' => [],
    'select_calls' => [],
    'reports' => [],
    'messages' => [],
    'adds' => [],
    'pdo_sql' => [],
    'pdo_execs' => [],
    'deduct_ok' => true,
];

/* ------------------------------------------------------------------ *
 * Stubs for the application bootstrap dependencies
 * ------------------------------------------------------------------ */

class BasePanelDriver
{
    const UNSUPPORTED = '__UNSUPPORTED__';
}

class PanelRegistry
{
    public static $driver = null;

    public static function driver($type, $ctx = null)
    {
        return self::$driver;
    }
}

class FakePanelDriver
{
    public $live = null;
    public $extendResult = null;
    public $readCalls = 0;
    public $extendCalls = 0;

    public function read($vars)
    {
        $this->readCalls++;
        return $this->live;
    }

    public function extendService($vars)
    {
        $this->extendCalls++;
        return $this->extendResult;
    }

    public function resetUsage($vars)
    {
        return ['status' => true, 'msg' => 'ok'];
    }

    public function create($vars) { return BasePanelDriver::UNSUPPORTED; }
    public function revokeSub($vars) { return BasePanelDriver::UNSUPPORTED; }
    public function remove($vars) { return BasePanelDriver::UNSUPPORTED; }
    public function modify($vars) { return BasePanelDriver::UNSUPPORTED; }
    public function changeStatus($vars) { return BasePanelDriver::UNSUPPORTED; }
    public function extraVolume($vars) { return BasePanelDriver::UNSUPPORTED; }
    public function extraTime($vars) { return BasePanelDriver::UNSUPPORTED; }
}

class FakeStatement
{
    private $sql;
    private $params = [];

    public function __construct($sql)
    {
        $this->sql = $sql;
    }

    public function execute($params = [])
    {
        $this->params = $params;
        $GLOBALS['TEST']['pdo_execs'][] = [$this->sql, $params];
        return true;
    }

    public function fetch($mode = null)
    {
        if (stripos($this->sql, 'FROM product') !== false) {
            if (isset($this->params[':c'])) {
                return $GLOBALS['TEST']['productsByCode'][$this->params[':c']] ?? false;
            }
            if (isset($this->params[':n'])) {
                return $GLOBALS['TEST']['productsByName'][$this->params[':n']] ?? false;
            }
            if (isset($this->params[':t'])) {
                $key = $this->params[':t'] . '|' . $this->params[':v'];
                return $GLOBALS['TEST']['productsBySpec'][$key] ?? false;
            }
        }
        return false;
    }
}

class FakePdo
{
    public function prepare($sql)
    {
        $GLOBALS['TEST']['pdo_sql'][] = $sql;
        return new FakeStatement($sql);
    }
}

$GLOBALS['pdo'] = new FakePdo();

function select($table, $field, $whereField = null, $whereValue = null, $type = 'select', $options = [])
{
    $GLOBALS['TEST']['select_calls'][] = [$table, $field, $whereField, $whereValue];
    if ($table === 'setting') {
        return $GLOBALS['TEST']['setting'];
    }
    $rows = $GLOBALS['TEST']['rows'][$table] ?? [];
    if ($whereField === null) {
        return $rows ? array_values($rows)[0] : false;
    }
    foreach ($rows as $row) {
        if (array_key_exists($whereField, $row) && (string) $row[$whereField] === (string) $whereValue) {
            return $row;
        }
    }
    return false;
}

function update($table, $field, $newValue, $whereField = null, $whereValue = null)
{
    $GLOBALS['TEST']['updates'][] = [$table, $field, $newValue, $whereField, $whereValue];
    if ($whereField === null || !isset($GLOBALS['TEST']['rows'][$table])) {
        return;
    }
    // Iterate the real array by reference so writes persist between runs.
    foreach ($GLOBALS['TEST']['rows'][$table] as &$row) {
        if (array_key_exists($whereField, $row) && (string) $row[$whereField] === (string) $whereValue) {
            $row[$field] = $newValue;
        }
    }
    unset($row);
}

function clearSelectCache($table = null) {}
function bustPanelDataCache($panelName, $username) {}

function telegram($method, $datas = [], $token = null)
{
    $GLOBALS['TEST']['reports'][] = $datas;
    return true;
}

function sendmessage($chat_id, $text, $keyboard = null, $parse_mode = null, $bot_token = null)
{
    $GLOBALS['TEST']['messages'][] = ['chat_id' => $chat_id, 'text' => $text];
    return true;
}

function languagechange($dir, $lang)
{
    $keys = [
        'autoRenewPanelGone', 'autoRenewPanelDown', 'autoRenewProductGone',
        'autoRenewNotFound', 'autoRenewPriceHold', 'autoRenewBalanceLow',
        'autoRenewExtendFailed', 'autoRenewDisabled', 'autoRenewSuccess',
        'autoRenewPriceNote',
    ];
    $extend = [];
    foreach ($keys as $key) {
        $extend[$key] = '{key:' . $key . '} username={username}';
    }
    return [
        'users' => [
            'extend' => $extend,
            'customSellVolume' => ['title' => 'Custom'],
        ],
        'textbot' => ['purchasedServices' => 'Purchased services'],
    ];
}

function deductBalance($user, $price)
{
    return $GLOBALS['TEST']['deduct_ok'];
}

function addBalance($id, $price)
{
    $GLOBALS['TEST']['adds'][] = [$id, $price];
    return true;
}

function extendMethodKey($value, $default = 'resetVolumeTime')
{
    return $value;
}

/* ------------------------------------------------------------------ *
 * Load the real production code under test
 * ------------------------------------------------------------------ */

require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../cronbot/autoextend.php';

/* ------------------------------------------------------------------ *
 * Fixtures / helpers
 * ------------------------------------------------------------------ */

function resetTest(): void
{
    $GLOBALS['TEST']['rows'] = [
        'marzban_panel' => [],
        'product' => [],
        'user' => [],
        'invoice' => [],
        'topicid' => [['report' => 'reportcron', 'idreport' => 0]],
    ];
    $GLOBALS['TEST']['setting'] = ['daywarn' => '2', 'Channel_Report' => '@report'];
    $GLOBALS['setting'] = $GLOBALS['TEST']['setting'];
    $GLOBALS['TEST']['productsByCode'] = [];
    $GLOBALS['TEST']['productsByName'] = [];
    $GLOBALS['TEST']['productsBySpec'] = [];
    $GLOBALS['TEST']['updates'] = [];
    $GLOBALS['TEST']['select_calls'] = [];
    $GLOBALS['TEST']['reports'] = [];
    $GLOBALS['TEST']['messages'] = [];
    $GLOBALS['TEST']['adds'] = [];
    $GLOBALS['TEST']['pdo_sql'] = [];
    $GLOBALS['TEST']['pdo_execs'] = [];
    $GLOBALS['TEST']['deduct_ok'] = true;
    PanelRegistry::$driver = new FakePanelDriver();
}

function mkPanel(array $overrides = []): array
{
    return array_merge([
        'name_panel' => 'p1',
        'code_panel' => 'CP1',
        'type' => 'marzban',
        'version_panel' => '2',
        'status' => 'active',
        'Methodextend' => 'addTimeVolumeNextMonth',
        'inboundid' => 1,
        'inbounds' => '{}',
        'pricecustomvolume' => json_encode(['f' => 1000]),
        'pricecustomtime' => json_encode(['f' => 5000]),
    ], $overrides);
}

function mkProduct(array $overrides = []): array
{
    return array_merge([
        'code_product' => 'PROD1',
        'name_product' => 'Prod One',
        'price_product' => '0',
        'Volume_constraint' => '10',
        'Service_time' => '30',
        'inbounds' => null,
        'status_product' => 'active',
    ], $overrides);
}

function mkUser(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'Balance' => 1000000,
        'agent' => 'f',
        'lang' => 'fa',
    ], $overrides);
}

function mkInvoice(array $overrides = []): array
{
    return array_merge([
        'id_invoice' => 'INV1',
        'id_user' => 7,
        'username' => 'alice',
        'Service_location' => 'p1',
        'price_product' => '0',
        'Volume' => '10',
        'Service_time' => '30',
        'code_product' => 'PROD1',
        'name_product' => 'Prod One',
        'auto_extend' => '1',
        'auto_extend_msg_at' => 0,
        'auto_extend_spec' => null,
        'time_cron' => 0,
        'Status' => 'active',
        'bottype' => null,
        'notifctions' => json_encode(['volume' => false, 'time' => false]),
    ], $overrides);
}

function mkLive(array $overrides = []): array
{
    return array_merge([
        'status' => 'active',
        'expire' => time() + 86400,
        'data_limit' => 10737418240,
        'used_traffic' => 1024,
    ], $overrides);
}

function setupWorking(array $invoiceOverrides = [], array $liveOverrides = []): array
{
    resetTest();
    $panel = mkPanel();
    $product = mkProduct();
    $invoice = mkInvoice($invoiceOverrides);
    $GLOBALS['TEST']['rows']['marzban_panel'][$panel['name_panel']] = $panel;
    $GLOBALS['TEST']['rows']['product'][$product['code_product']] = $product;
    $GLOBALS['TEST']['rows']['user'][7] = mkUser();
    $GLOBALS['TEST']['rows']['invoice']['INV1'] = $invoice;
    $GLOBALS['TEST']['productsByCode'][$product['code_product']] = $product;
    PanelRegistry::$driver->live = mkLive($liveOverrides);
    PanelRegistry::$driver->extendResult = ['status' => true, 'msg' => 'ok'];
    return $invoice;
}

function currentInvoice(): array
{
    return $GLOBALS['TEST']['rows']['invoice']['INV1'];
}

function persistSpec(string $spec): void
{
    $GLOBALS['TEST']['rows']['invoice']['INV1']['auto_extend_spec'] = $spec;
}

function runProcess(array $invoice, int $daywarn = 2): void
{
    // Deliberately pass the RAW (uncapped) window: the production function is
    // responsible for clamping it, mirroring how the cron builds it from setting.
    $window = max(1, $daywarn) * 86400;
    autoExtendProcess($invoice, new ManagePanel(), $window, time(), 3 * 86400);
}

function extendCalls(): int
{
    return PanelRegistry::$driver->extendCalls;
}

function messagesContaining(string $needle): int
{
    $n = 0;
    foreach ($GLOBALS['TEST']['messages'] as $m) {
        if (strpos($m['text'], $needle) !== false) {
            $n++;
        }
    }
    return $n;
}

function reportsContaining(string $needle): int
{
    $n = 0;
    foreach ($GLOBALS['TEST']['reports'] as $r) {
        if (strpos((string) ($r['text'] ?? ''), $needle) !== false) {
            $n++;
        }
    }
    return $n;
}

/* ------------------------------------------------------------------ *
 * Tiny assertion runner
 * ------------------------------------------------------------------ */

$passed = 0;
$failed = [];

function check(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed[] = $name;
        echo "  FAIL  $name\n";
    }
}

function section(string $title): void
{
    echo "\n[$title]\n";
}

echo "=== auto-renew regression suite ===\n";
echo "PHP " . PHP_VERSION . "\n";

/* ------------------------------------------------------------------ *
 * (a) Local lookup mismatch must not produce "data not found"
 * ------------------------------------------------------------------ */

section('(a) panel/product lookup mismatch');

resetTest();
$resolvedPanel = mkPanel(['code_panel' => 'REAL_CODE']);
$resolvedProduct = mkProduct(['code_product' => 'REAL_PROD']);
$GLOBALS['TEST']['rows']['invoice']['INV1'] = mkInvoice(['username' => 'alice', 'code_product' => 'REAL_PROD']);
$GLOBALS['TEST']['rows']['marzban_panel']['p1'] = $resolvedPanel;
PanelRegistry::$driver->live = mkLive();
PanelRegistry::$driver->extendResult = ['status' => true, 'msg' => 'ok'];

$extendResult = (new ManagePanel())->extend(
    'addTimeVolumeNextMonth', '10', '30', 'alice', 'STALE_PROD', 'STALE_CODE',
    $resolvedPanel, $resolvedProduct
);
check(
    'extend() succeeds with pre-resolved panel/product despite stale identifiers',
    is_array($extendResult) && !empty($extendResult['status'])
);
check(
    'extend() does not return "data not found" for pre-resolved data',
    !(is_array($extendResult) && strpos((string) ($extendResult['msg'] ?? ''), 'data not found') !== false)
);
$panelRelookups = array_filter($GLOBALS['TEST']['select_calls'], function ($c) {
    return $c[0] === 'marzban_panel' && $c[2] === 'code_panel';
});
check('extend() skips the code_panel re-lookup when a resolved panel is supplied', count($panelRelookups) === 0);

/* ------------------------------------------------------------------ *
 * (b) Report throttle + bounded retry + explicit error classification
 * ------------------------------------------------------------------ */

section('(b) report throttle / bounded retries');

setupWorking();
persistSpec(json_encode(['price_lock' => 1, 'renew_report_at' => time() - 60]));
autoExtendReport(currentInvoice(), 'recent failure', 'p1', 3 * 86400);
check('autoExtendReport() throttles a repeated report inside the window', count($GLOBALS['TEST']['reports']) === 0);
persistSpec(json_encode(['price_lock' => 1, 'renew_report_at' => time() - 4 * 86400]));
autoExtendReport(currentInvoice(), 'stale report', 'p1', 3 * 86400);
check('autoExtendReport() sends once the window has elapsed', count($GLOBALS['TEST']['reports']) === 1);

setupWorking();
PanelRegistry::$driver->extendResult = ['status' => false, 'msg' => 'temporary panel error'];
for ($i = 0; $i < 4; $i++) {
    runProcess(currentInvoice());
}
check('transient extend failures disable auto-renew after bounded retries', currentInvoice()['auto_extend'] === '0');
check('exactly one final disable notification is sent', messagesContaining('autoRenewDisabled') === 1);
check('no report mislabels failures as "panel extend failed"', reportsContaining('panel extend failed') === 0);
check('failure reports are throttled (at most one across 4 runs)', count($GLOBALS['TEST']['reports']) <= 1);

setupWorking();
PanelRegistry::$driver->extendResult = ['status' => false, 'msg' => 'data not found'];
runProcess(currentInvoice());
check('deterministic config error disables auto-renew immediately', currentInvoice()['auto_extend'] === '0');
check('deterministic config error is explicitly labelled', reportsContaining('config error') >= 1);

setupWorking();
PanelRegistry::$driver->live = ['status' => 'Unsuccessful', 'msg' => 'Connection timed out'];
runProcess(currentInvoice());
check('transient panel outage does not disable auto-renew', currentInvoice()['auto_extend'] === '1');

/* ------------------------------------------------------------------ *
 * (c) No premature renewals
 * ------------------------------------------------------------------ */

section('(c) premature renewals');

setupWorking([], ['status' => 'limited', 'data_limit' => 0, 'used_traffic' => 999999999, 'expire' => time() + 100 * 86400]);
runProcess(currentInvoice(), 2);
check('unlimited data_limit is not treated as volume depletion', extendCalls() === 0);

setupWorking([], ['status' => 'active', 'expire' => 'garbage', 'data_limit' => 10737418240, 'used_traffic' => 1]);
$threw = false;
try {
    runProcess(currentInvoice(), 2);
} catch (Throwable $e) {
    $threw = true;
}
check('non-numeric expire does not throw', !$threw);
check('non-numeric expire does not trigger a renewal', extendCalls() === 0);

setupWorking([], ['status' => 'active', 'expire' => time() + 100 * 86400, 'data_limit' => 10737418240, 'used_traffic' => 1]);
runProcess(currentInvoice(), 365);
check('unbounded daywarn does not renew a service 100 days from expiry', extendCalls() === 0);

foreach (['user not found', 'Panel Not Found'] as $notFoundMsg) {
    setupWorking();
    PanelRegistry::$driver->live = ['status' => 'Unsuccessful', 'msg' => $notFoundMsg];
    runProcess(currentInvoice());
    check("case-insensitive '$notFoundMsg' is treated as deterministic and disables", currentInvoice()['auto_extend'] === '0');
}

resetTest();
$panel = mkPanel();
$staleSpecInvoice = mkInvoice([
    'code_product' => 'OLD',
    'auto_extend_spec' => json_encode(['code_product' => 'STALE', 'volume' => 10, 'days' => 30, 'price_lock' => 1]),
]);
$resolved = autoExtendResolveProduct($staleSpecInvoice, mkUser(), $panel);
check(
    'stale reserved code_product falls back to volume/days pricing',
    is_array($resolved)
    && ($resolved['code_product'] ?? '') === 'custom_volume'
    && abs((float) ($resolved['price_product'] ?? 0) - 160000.0) < 0.01
);

setupWorking([], ['expire' => time() + 86400]);
persistSpec(json_encode(['price_lock' => 1, 'renew_fail_count' => 1]));
PanelRegistry::$driver->extendResult = ['status' => false, 'msg' => 'timeout'];
runProcess(currentInvoice());
PanelRegistry::$driver->extendResult = ['status' => true, 'msg' => 'ok'];
runProcess(currentInvoice());
$meta = json_decode((string) currentInvoice()['auto_extend_spec'], true);
check('a successful renewal clears the consecutive failure count', (int) ($meta['renew_fail_count'] ?? 0) === 0);

/* ------------------------------------------------------------------ *
 * (d) Every notify key used by production must exist in the lang files
 * ------------------------------------------------------------------ */

section('(d) autoExtendNotify() keys exist in locale files');

$autoextendSrc = (string) file_get_contents(__DIR__ . '/../cronbot/autoextend.php');
preg_match_all('/autoExtendNotify\(\s*\$invoice\s*,\s*\'([A-Za-z0-9_]+)\'/', $autoextendSrc, $keyMatches);
$notifyKeys = array_values(array_unique($keyMatches[1]));
check('found autoExtendNotify() key literals in production source', count($notifyKeys) >= 8);

$autoRenewLocales = [];
foreach (glob(__DIR__ . '/../lang/*.php') as $localeFile) {
    $localeSrc = (string) file_get_contents($localeFile);
    if (preg_match('/autoRenewExtendFailed\'\s*=>/', $localeSrc)) {
        $autoRenewLocales[] = [basename($localeFile), $localeSrc];
    }
}
check('found locale files defining auto-renew notifications', count($autoRenewLocales) >= 1);

foreach ($autoRenewLocales as [$localeName, $localeSrc]) {
    foreach ($notifyKeys as $notifyKey) {
        check(
            "$localeName defines notify key '$notifyKey'",
            (bool) preg_match('/\'' . preg_quote($notifyKey, '/') . '\'\s*=>/', $localeSrc)
        );
    }
}

setupWorking();
autoExtendNotify(currentInvoice(), 'autoRenewKeyThatDoesNotExist', ['{username}' => 'alice'], 0, true);
$lastMessage = end($GLOBALS['TEST']['messages']);
check(
    'a missing notify key still sends a non-empty message',
    is_array($lastMessage) && trim((string) $lastMessage['text']) !== ''
);

/* ------------------------------------------------------------------ *
 * Result
 * ------------------------------------------------------------------ */

echo "\n----------------------------------------\n";
echo "Passed: $passed   Failed: " . count($failed) . "\n";
if ($failed) {
    echo "\nFAILED ASSERTIONS:\n";
    foreach ($failed as $name) {
        echo "  - $name\n";
    }
    echo "\nRESULT: RED (regressions reproduced)\n";
    exit(1);
}
echo "\nRESULT: GREEN (all regressions fixed)\n";
exit(0);
