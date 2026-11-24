<?php
/**
 * Regression guard for HTML parse_mode message construction.
 *
 * Several flows interpolate user-controlled text into sprintf() templates that
 * are later sent to Telegram with parse_mode 'HTML'. An unescaped '<' (or a
 * stray '</b>') makes Telegram reject the whole message with
 * "can't parse entities", so support tickets / rejection notices silently
 * never arrive while broadcasts are still counted as sent.
 *
 * The checks below are behavioural: the real sprintf assignments are extracted
 * from the source files and evaluated with attacker-controlled values, and the
 * real smart_fill_vars() function is extracted and executed. This proves both
 * that the injected values are escaped and that the markup in the language
 * templates is left intact.
 *
 * Run with: php tests/html_escape_regression.php
 */

$root = dirname(__DIR__);
$indexSource = file_get_contents($root . '/index.php');
$adminSource = file_get_contents($root . '/admin.php');
$functionSource = file_get_contents($root . '/function.php');

$failures = [];
function check(string $description, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "PASS: $description\n";
    } else {
        $failures[] = $description;
        echo "FAIL: $description\n";
    }
}

/** Evaluate one single-line assignment extracted verbatim from a source file. */
function evalAssignment(string $source, string $needle, string $targetVar, array $vars): string
{
    $pos = strpos($source, $needle);
    if ($pos === false) {
        throw new RuntimeException("assignment not found: $needle");
    }
    $eol = strpos($source, "\n", $pos);
    $line = trim(substr($source, $pos, ($eol === false ? strlen($source) : $eol) - $pos));
    foreach ($vars as $name => $value) {
        $$name = $value;
    }
    eval($line);
    return (string) $$targetVar;
}

/** Stable stand-ins with the same placeholder counts and markup as lang/en.php. */
function messageVars(array $overrides = []): array
{
    $vars = [
        'textbotlang' => [
            'Admin' => [
                'reportgroup' => [
                    // 7 %s, with real <a> markup that must survive untouched.
                    'supportMessage'  => '<a href="tg://user?id=%s">%s</a> | %s | @%s | %s | %s %s',
                    'supportMessage2' => '<a href="tg://user?id=%s">%s</a> | %s | @%s | %s | %s',
                    'noteChanged'     => 'Service: %s | Previous: %s | New: %s | Time: %s',
                    'paymentRejected' => 'Method: %s | Admin: %s | @%s | Amount: %s | Reason: %s | User: %s',
                ],
            ],
            'users' => [
                'Balance' => ['rejectedNotice' => 'Reason: %s | Tracking: %s'],
            ],
        ],
        'from_id'        => 123,
        'timejalali'     => '1403/01/01 00:00:00',
        'username'       => 'alice',
        'departeman'     => ['name_departman' => 'Support'],
        'text'           => 'ordinary message',
        'caption'        => 'ordinary caption',
        'invoice'        => ['username' => 'service-user', 'note' => 'ordinary note'],
        'trakingdetail'  => ['name_departman' => 'Support'],
        'user'           => ['Processing_value_one' => 'ORD123'],
        'Payment_report' => [
            'Payment_Method' => 'card',
            'price'          => 1000,
            'id_user'        => 123,
        ],
    ];
    foreach ($overrides as $name => $value) {
        $vars[$name] = $value;
    }
    return $vars;
}

echo "-- index.php support ticket message --\n";
$needle = "\$textsuppoer = sprintf(\$textbotlang['Admin']['reportgroup']['supportMessage'],";
$msg = evalAssignment($indexSource, $needle, 'textsuppoer', messageVars([
    'text'    => '<b>EVIL</b>',
    'caption' => '<i>CAPTION</i>',
]));
check('support ticket body is entity-escaped', strpos($msg, '&lt;b&gt;EVIL&lt;/b&gt;') !== false);
check('support ticket body contains no raw injected tag', strpos($msg, '<b>EVIL</b>') === false);
check('support ticket caption is entity-escaped', strpos($msg, '&lt;i&gt;CAPTION&lt;/i&gt;') !== false);
check('support ticket caption contains no raw injected tag', strpos($msg, '<i>CAPTION</i>') === false);
check('support ticket template <a> markup is untouched', strpos($msg, '<a href="tg://user?id=123">') !== false);

$plain = evalAssignment($indexSource, $needle, 'textsuppoer', messageVars());
check('support ticket normal text is unchanged', strpos($plain, 'ordinary message ordinary caption') !== false && strpos($plain, '&amp;') === false);

echo "\n-- index.php support reply message --\n";
$needle2 = "\$textsuppoer = sprintf(\$textbotlang['Admin']['reportgroup']['supportMessage2'],";
$reply = evalAssignment($indexSource, $needle2, 'textsuppoer', messageVars([
    'text' => '<b>REPLY</b>',
]));
check('support reply is entity-escaped', strpos($reply, '&lt;b&gt;REPLY&lt;/b&gt;') !== false);
check('support reply contains no raw injected tag', strpos($reply, '<b>REPLY</b>') === false);
check('support reply template <a> markup is untouched', strpos($reply, '<a href="tg://user?id=123">') !== false);

echo "\n-- index.php invoice note-change report --\n";
$needle3 = "\$textreport = sprintf(\$textbotlang['Admin']['reportgroup']['noteChanged'],";
$note = evalAssignment($indexSource, $needle3, 'textreport', messageVars([
    'invoice' => ['username' => 'service-user', 'note' => '<b>OLD</b>'],
    'text'    => '<b>NEW</b>',
]));
check('previous invoice note is entity-escaped', strpos($note, '&lt;b&gt;OLD&lt;/b&gt;') !== false);
check('previous invoice note contains no raw injected tag', strpos($note, '<b>OLD</b>') === false);
check('new invoice note is entity-escaped', strpos($note, '&lt;b&gt;NEW&lt;/b&gt;') !== false);
check('new invoice note contains no raw injected tag', strpos($note, '<b>NEW</b>') === false);

echo "\n-- admin.php payment rejection notices --\n";
$needle4 = "\$text_reject = sprintf(\$textbotlang['users']['Balance']['rejectedNotice'],";
$reject = evalAssignment($adminSource, $needle4, 'text_reject', messageVars([
    'text' => '<b>REASON</b>',
]));
check('rejection reason sent to user is entity-escaped', strpos($reject, '&lt;b&gt;REASON&lt;/b&gt;') !== false);
check('rejection reason sent to user contains no raw injected tag', strpos($reject, '<b>REASON</b>') === false);

$needle5 = "\$text_report = sprintf(\$textbotlang['Admin']['reportgroup']['paymentRejected'],";
$report = evalAssignment($adminSource, $needle5, 'text_report', messageVars([
    'text' => '<b>REASON</b>',
]));
check('rejection reason in the report channel is entity-escaped', strpos($report, '&lt;b&gt;REASON&lt;/b&gt;') !== false);
check('rejection reason in the report channel contains no raw injected tag', strpos($report, '<b>REASON</b>') === false);

echo "\n-- function.php smart_fill_vars() broadcast templates --\n";
function extractFunction(string $source, string $name): string
{
    $start = strpos($source, "function $name");
    if ($start === false) {
        throw new RuntimeException("function not found: $name");
    }
    $braceStart = strpos($source, '{', $start);
    $depth = 0;
    $length = strlen($source);
    for ($i = $braceStart; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException("unterminated function: $name");
}

eval(extractFunction($functionSource, 'smart_fill_vars'));
$smart = smart_fill_vars('Hello <b>{name}</b> (@{username}, {balance})', [
    'namecustom'       => '<b>NAME</b>',
    'username'         => 'bob',
    'Balance'          => 12,
    'score'            => 3,
    'service_count'    => 1,
    'last_message_time' => 0,
    'id'               => 55,
]);
check('broadcast {name} is entity-escaped', strpos($smart, '&lt;b&gt;NAME&lt;/b&gt;') !== false);
check('broadcast {name} contains no raw injected tag', strpos($smart, '<b>NAME</b>') === false);
check('broadcast template <b> markup is untouched', substr_count($smart, '<b>') === 1 && substr_count($smart, '</b>') === 1);
check('broadcast numeric vars are untouched', strpos($smart, '(@bob, 12)') !== false);

$smartPlain = smart_fill_vars('Hello {name}', [
    'namecustom'       => 'Alice',
    'username'         => 'alice',
    'Balance'          => 0,
    'score'            => 0,
    'service_count'    => 0,
    'last_message_time' => 0,
    'id'               => 1,
]);
check('broadcast normal name is unchanged', $smartPlain === 'Hello Alice');

if ($failures) {
    fwrite(STDERR, "\n" . count($failures) . " HTML-escape regression check(s) FAILED.\n");
    exit(1);
}
echo "\nAll HTML-escape regression checks passed.\n";
exit(0);
