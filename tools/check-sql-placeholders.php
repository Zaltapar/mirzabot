<?php

/**
 * Guards against the "Column count doesn't match value count" class of bug:
 * an INSERT/REPLACE whose column list and VALUES list have different lengths
 * dies at PDO::prepare() and, in a callback handler, silently aborts the flow.
 *
 * Usage: php tools/check-sql-placeholders.php [root]
 * Exit code 1 when a mismatch is found.
 */

$root = $argv[1] ?? dirname(__DIR__);
$skipDirs = ['vendor', '.git', 'node_modules', 'storage', '.github'];
$failures = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skipDirs): bool {
            if ($file->isDir()) {
                return !in_array($file->getFilename(), $skipDirs, true);
            }
            return str_ends_with($file->getFilename(), '.php');
        }
    )
);

function mirzaSplitTopLevel(string $text): array
{
    $parts = [];
    $current = '';
    $depth = 0;
    $quote = null;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        $char = $text[$i];
        if ($quote !== null) {
            $current .= $char;
            if ($char === '\\' && $i + 1 < $length) {
                $current .= $text[++$i];
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"') {
            $quote = $char;
            $current .= $char;
            continue;
        }
        if ($char === '(' || $char === '[' || $char === '{') {
            $depth++;
            $current .= $char;
            continue;
        }
        if ($char === ')' || $char === ']' || $char === '}') {
            $depth--;
            $current .= $char;
            continue;
        }
        if ($char === ',' && $depth === 0) {
            $parts[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if (trim($current) !== '') {
        $parts[] = trim($current);
    }
    return $parts;
}

function mirzaBalancedGroup(string $text, int $openPos): ?string
{
    $depth = 0;
    $quote = null;
    $length = strlen($text);
    for ($i = $openPos; $i < $length; $i++) {
        $char = $text[$i];
        if ($quote !== null) {
            if ($char === '\\') {
                $i++;
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"') {
            $quote = $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
            continue;
        }
        if ($char === ')') {
            $depth--;
            if ($depth === 0) {
                return substr($text, $openPos + 1, $i - $openPos - 1);
            }
        }
    }
    return null;
}

foreach ($iterator as $file) {
    $path = $file->getPathname();
    $source = file_get_contents($path);
    if ($source === false) {
        continue;
    }

    $offset = 0;
    while (($pos = strpos($source, '->prepare(', $offset)) !== false) {
        $offset = $pos + 10;
        $callOpen = strpos($source, '(', $pos);
        $callInner = $callOpen === false ? null : mirzaBalancedGroup($source, $callOpen);
        if ($callInner === null) {
            continue;
        }
        $arg = trim($callInner);
        if (!preg_match('/^(["\'])(.*)\1$/s', $arg, $literal)) {
            continue;
        }
        $sql = $literal[2];
        if (!preg_match('/\b(INSERT|REPLACE)\b[^;]*?\bINTO\b\s*`?\w+`?\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $columnsOpen = strpos($sql, '(', (int) $m[0][1] + strlen($m[0][0]) - 1);
        $columnsText = $columnsOpen === false ? null : mirzaBalancedGroup($sql, $columnsOpen);
        if ($columnsText === null) {
            continue;
        }
        if (!preg_match('/\bVALUES\s*\(/i', $sql, $vm, PREG_OFFSET_CAPTURE, $columnsOpen)) {
            continue;
        }
        $valuesOpen = strpos($sql, '(', (int) $vm[0][1] + strlen($vm[0][0]) - 1);
        $valuesText = $valuesOpen === false ? null : mirzaBalancedGroup($sql, $valuesOpen);
        if ($valuesText === null) {
            continue;
        }

        $columns = mirzaSplitTopLevel($columnsText);
        $values = mirzaSplitTopLevel($valuesText);
        if (count($columns) !== count($values)) {
            $line = substr_count(substr($source, 0, $pos), "\n") + 1;
            $failures[] = sprintf(
                '%s:%d columns=%d values=%d :: %s',
                str_replace($root . DIRECTORY_SEPARATOR, '', $path),
                $line,
                count($columns),
                count($values),
                preg_replace('/\s+/', ' ', trim($sql))
            );
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "SQL placeholder/column mismatches:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  ' . $failure . "\n");
    }
    exit(1);
}

echo "SQL placeholder audit passed\n";
