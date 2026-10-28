<?php

/**
 * Encoding regression guard.
 *
 * Fails the build when any text source file contains a byte sequence that
 * indicates UTF-8 was mis-decoded as Windows-1252 / Latin-1 and re-encoded
 * (double-encoding). The two signatures that are never legitimate in source:
 *
 *   1. A 2-byte UTF-8 sequence decoding to U+0080-U+009F (the C1 control
 *      block): bytes C2 80 through C2 9F. C1 controls are control characters
 *      and must never appear in source code or user-facing strings.
 *   2. The cp1252 double-encode signature: the byte C3 B0 ('ð') immediately
 *      followed by C5 80..BF — the tell-tale left-over of a 4-byte emoji
 *      that went through the mis-decode.
 *
 * The root cause behind this gate: a file saved on Windows with a non-UTF-8
 * code page re-encodes the UTF-8 Persian/emoji it contains, producing
 * mojibake. That is exactly what happened to function.php's status chips.
 *
 * Usage: php tools/check-encoding.php [root]
 * Exit code 1 when a signature is found.
 */

$root = $argv[1] ?? dirname(__DIR__);
$skipDirs = ['vendor', '.git', 'node_modules', 'storage', 'graphify-out', '.github'];
$exts = ['php', 'js', 'ts', 'json', 'yml', 'yaml', 'md', 'txt', 'html', 'css', 'sh', 'ini', 'conf', 'sql'];

// Signature 1: C1-control block encoded as UTF-8 (U+0080..U+009F).
$c1 = '/\xC2[\x80-\x9F]/';
// Signature 2: 'ð' (C3 B0) followed by a C5-lead 2-byte sequence.
$cp1252 = '/\xC3\xB0\xC5[\x80-\xBF]/';

$failures = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skipDirs, $exts): bool {
            if ($file->isDir()) {
                return !in_array($file->getFilename(), $skipDirs, true);
            }
            return in_array(strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION)), $exts, true);
        }
    )
);

foreach ($iterator as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $content = @file_get_contents($file->getPathname());
    if ($content === false || strlen($content) > 3_000_000) {
        continue;
    }

    $hits = [];
    foreach (explode("\n", $content) as $lineno => $line) {
        if (preg_match($c1, $line)) {
            $hits[] = $lineno + 1;
            continue;
        }
        if (preg_match($cp1252, $line)) {
            $hits[] = $lineno + 1;
        }
    }

    if ($hits) {
        $failures[] = ['file' => $rel, 'lines' => $hits];
        echo 'encoding-mojibake: ' . $rel . ' at line(s) ' . implode(', ', array_slice($hits, 0, 10)) . (count($hits) > 10 ? ' ...' : '') . PHP_EOL;
    }
}

if ($failures) {
    echo PHP_EOL;
    echo 'FAILED: ' . count($failures) . ' file(s) contain a C1-control / cp1252 double-encode signature.' . PHP_EOL;
    echo 'This usually means a file was saved on a non-UTF-8 code page and its UTF-8 content was re-encoded.' . PHP_EOL;
    exit(1);
}

echo 'PASS: no C1-control / cp1252 double-encode signatures found in source files.' . PHP_EOL;
