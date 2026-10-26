<?php
chdir(dirname(__DIR__));
foreach (['fa','en','ru','zh'] as $l) {
    $t = require "lang/$l.php";
    $missing = [];
    $checks = [
        'Admin.smartMsg', 'Admin.smartMsg.segments', 'Admin.smartMsg.freqs',
        'keyboard.smartRules', 'keyboard.randomCodeBtn',
        'keyboard.timePresetNoExpire', 'keyboard.timePresetDay',
        'keyboard.timePresetWeek', 'keyboard.timePresetMonth',
        'users.summary.purchasesLine',
    ];
    foreach ($checks as $path) {
        $v = $t;
        foreach (explode('.', $path) as $p) {
            $v = is_array($v) ? ($v[$p] ?? null) : null;
        }
        if ($v === null) {
            $missing[] = $path;
        }
    }
    echo $l . ': ' . (count($missing) ? 'MISSING ' . implode(', ', $missing) : 'all keys present') . PHP_EOL;
}
