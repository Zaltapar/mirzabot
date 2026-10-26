#!/usr/bin/env php
<?php
/**
 * Bot restore tool (CLI only).
 *
 * Restores the DB dump and/or file snapshot produced by sync.php:
 *
 *   php restore.php                           → show snapshots, restore newest DB dump (needs RESTORE confirm)
 *   php restore.php db_20260101_0800.sql      → restore that DB dump (explicit resolve from storage/backup/)
 *   php restore.php --files                   → restore newest file snapshot (files_*.tar.gz / .zip)
 *   php restore.php --yes                     → skip interactive confirmations (careful!)
 *
 * After a DB restore run `php table.php` to refresh schema, migrations and webhook.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI access only');
}

$scriptFile = $_SERVER['argv'][0] ?? 'restore.php';
array_shift($argv);
$flags = array_values(array_filter($argv, static fn ($a) => str_starts_with($a, '--')));
$args = array_values(array_filter($argv, static fn ($a) => !str_starts_with($a, '--')));

$wantFiles = in_array('--files', $flags, true) && $args === [];
$skipConfirm = in_array('--yes', $flags, true);
$allowed = array_filter($flags, static fn ($a) => in_array($a, ['--files', '--yes'], true));
fail_if(count($allowed) !== count($flags), 'Unknown flags: ' . implode(' ', array_diff($flags, $allowed)) . ' — allowed: --files, --yes');

$backupDir = __DIR__ . '/storage/backup';
fail_if(!is_dir($backupDir), 'No storage/backup directory found at ' . $backupDir . '.');

$dbSnapshots = sort_by_time(glob($backupDir . '/db_*.sql') ?: []);
$fileSnapshots = sort_by_time(array_merge(
    glob($backupDir . '/files_*.tar.gz') ?: [],
    glob($backupDir . '/files_*.zip') ?: []
));

step('Restore tool — target: ' . __DIR__);

$sqlArg = array_values(array_filter($args, static fn ($a) => str_ends_with($a, '.sql')));
$fileArg = array_values(array_filter($args, static fn ($a) => str_ends_with($a, '.tar.gz') || str_ends_with($a, '.zip')));
fail_if(count($sqlArg) + count($fileArg) > 1, 'Give ONE snapshot file (db_*.sql or files_*.tar.gz/.zip).');
fail_if(!$sqlArg && !$fileArg && ($args !== []), 'Unrecognized snapshot type: ' . implode(' ', $args) . '.');

if ($sqlArg !== []) {
    restore_db(resolve_snapshot($sqlArg[0], $dbSnapshots), $skipConfirm);
} elseif ($fileArg !== []) {
    restore_files(resolve_snapshot($fileArg[0], $fileSnapshots), __DIR__, $skipConfirm);
} elseif ($wantFiles) {
    list_snapshots('file', $fileSnapshots);
    restore_files(pick_snapshot($fileSnapshots, $skipConfirm, 'file'), __DIR__, $skipConfirm, true);
} else {
    list_snapshots('DB', $dbSnapshots);
    restore_db(pick_snapshot($dbSnapshots, $skipConfirm, 'DB'), $skipConfirm, true);
    if ($fileSnapshots !== []) {
        $answer = 'no';
        if (!$skipConfirm) {
            echo '    Also restore the newest FILE snapshot "' . basename($fileSnapshots[count($fileSnapshots) - 1]) . '"? [y/N]: ';
            $answer = trim((string) fgets(STDIN));
        }
        if ($skipConfirm || strcasecmp($answer, 'y') === 0) {
            restore_files(pick_snapshot($fileSnapshots, true, 'file'), __DIR__, true, true);
        }
    }
}

step('Done. After restoring the DB run `php table.php` to refresh schema and webhook.');

// ================================================================= helpers
function sort_by_time(array $files): array
{
    usort($files, static fn ($a, $b) => filemtime($a) <=> filemtime($b));
    return $files;
}

function list_snapshots(string $label, array $files): void
{
    if ($files === []) {
        warn('No ' . $label . ' snapshots found.');
        return;
    }
    foreach ($files as $file) {
        echo '    - ' . basename($file) . '  (' . number_format(filesize($file)) . ' bytes, '
            . date('Y/m/d H:i', (int) filemtime($file)) . ")\n";
    }
}

function resolve_snapshot(string $given, array $snapshots): string
{
    $candidates = [$given, __DIR__ . '/' . $given, __DIR__ . '/storage/backup/' . $given];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    fail('Snapshot file not found: ' . $given);
}

function pick_snapshot(array $files, bool $skipConfirm, string $kind): string
{
    fail_if($files === [], 'No ' . $kind . ' snapshots found.');
    $chosen = $files[count($files) - 1];
    if ($skipConfirm) {
        return $chosen;
    }
    echo '    Restore newest ' . $kind . ' snapshot "' . basename($chosen) . '"? Type RESTORE to continue [else skip]: ';
    $answer = trim((string) fgets(STDIN));
    if (strcasecmp($answer, 'RESTORE') !== 0) {
        warn('Skipping ' . $kind . ' restore by user choice.');
        $chosen = '';
    }
    return $chosen;
}

function restore_db(string $snap, bool $skipConfirm, bool $selectionConfirmed = false): void
{
    if ($snap === '') {
        return;
    }
    if (!$skipConfirm && !$selectionConfirmed) {
        echo '    This OVERWRITES database contents with ' . basename($snap) . ' — type RESTORE to continue [else abort]: ';
        $answer = trim((string) fgets(STDIN));
        fail_if(strcasecmp($answer, 'RESTORE') !== 0, 'Aborted by user.');
    } else {
        echo '    This OVERWRITES database contents with ' . basename($snap) . "\n";
    }
    $creds = extract_db_creds(__DIR__ . '/config.php');
    fail_if($creds === null, 'Could not read DB credentials from config.php.');
    $binary = find_binary('mysql');
    fail_if($binary === null, 'mysql client not found in PATH — import the dump manually.');
    echo '    This OVERWRITES database "' . $creds['name'] . '" with ' . basename($snap) . "\n";
    $pass = str_replace("'", '', $creds['pass']);
    $cmd = sprintf(
        '%s --host=%s --user=%s --password=%s %s < %s 2>/dev/null',
        escapeshellarg($binary),
        escapeshellarg($creds['host']),
        escapeshellarg($creds['user']),
        "'" . $pass . "'",
        escapeshellarg($creds['name']),
        escapeshellarg($snap)
    );
    $rc = 0;
    exec($cmd, $o, $rc);
    fail_if($rc !== 0, 'mysql import failed (exit code ' . $rc . ').');
    info('Database restored from ' . basename($snap));
}

function restore_files(string $snap, string $targetDir, bool $skipConfirm, bool $selectionConfirmed = false): void
{
    if ($snap === '') {
        return;
    }
    if (!$skipConfirm && !$selectionConfirmed) {
        echo '    This OVERWRITES files in ' . $targetDir . ' with the contents of ' . basename($snap)
            . " — type RESTORE to continue [else abort]: ";
        $answer = trim((string) fgets(STDIN));
        fail_if(strcasecmp($answer, 'RESTORE') !== 0, 'Aborted by user.');
    } else {
        echo '    This OVERWRITES files in ' . $targetDir . ' with the contents of ' . basename($snap) . "\n";
    }
    fail_if(!restore_files_from($snap, $targetDir), 'Could not restore file snapshot.');
    info('Files restored from ' . basename($snap));
}

function restore_files_from(string $arc, string $targetDir): bool
{
    $tmp = sys_get_temp_dir() . '/mirza-restore-' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0775, true);
    $base = extract_archive($arc, $tmp);
    if ($base === null) {
        delete_dir($tmp);
        return false;
    }
    copy_tree($tmp . '/' . $base, $targetDir);
    delete_dir($tmp);
    return true;
}

function extract_archive(string $arc, string $dest): ?string
{
    $base = basename($arc);
    if (str_ends_with($base, '.zip')) {
        if (!class_exists('ZipArchive')) {
            warn('php-zip extension is not available for this snapshot.');
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($arc) !== true) {
            return null;
        }
        $zip->extractTo($dest);
        $zip->close();
        return find_base_in($dest);
    }
    if (str_ends_with($base, '.tar.gz')) {
        $rc = 0;
        if (stripos(PHP_OS, 'win') === 0) {
            exec('tar -xzf ' . escapeshellarg($arc) . ' -C ' . escapeshellarg($dest) . ' 2>nul', $o, $rc);
        } else {
            exec('tar -xzf ' . escapeshellarg($arc) . ' -C ' . escapeshellarg($dest) . ' 2>/dev/null', $o, $rc);
        }
        if ($rc !== 0) {
            return null;
        }
        return find_base_in($dest);
    }
    return null;
}

function find_base_in(string $dir): string
{
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
        if (is_file($sub . '/index.php') || is_file($sub . '/config.php')) {
            return basename($sub);
        }
    }
    $dirs = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
    return $dirs !== [] ? basename($dirs[0]) : '';
}

function copy_tree(string $src, string $dst): void
{
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $item) {
        $rel = substr($item->getPathname(), strlen($src) + 1);
        $target = $dst . '/' . str_replace('\\', '/', $rel);
        if ($item->isDir()) {
            @mkdir($target, 0775, true);
            continue;
        }
        @mkdir(dirname($target), 0775, true);
        copy($item->getPathname(), $target);
    }
}

function delete_dir(string $dir): void
{
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function step(string $msg): void
{
    echo "\n\033[1;36m==> " . $msg . "\033[0m\n";
}

function info(string $msg): void
{
    echo "    \033[32m[ok] \033[0m" . $msg . "\n";
}

function warn(string $msg): void
{
    echo "    \033[33m[!] \033[0m" . $msg . "\n";
}

function fail(string $msg): void
{
    echo "\033[31mFATAL: " . $msg . "\033[0m\n";
    exit(1);
}

function fail_if(bool $cond, string $msg): void
{
    if ($cond) {
        fail($msg);
    }
}

function extract_db_creds(string $configPath): ?array
{
    $src = @file_get_contents($configPath);
    if ($src === false) {
        return null;
    }
    $get = static function (string $var) use ($src): string|null {
        if (preg_match("/\\\$" . $var . "\s*=\s*'([^']*)'/", $src, $m)) {
            return $m[1];
        }
        if (preg_match("/\\\$" . $var . "\s*=\s*\"([^\"]*)\"/", $src, $m)) {
            return $m[1];
        }
        return null;
    };
    $host = $get('dbhost');
    $name = $get('dbname');
    $user = $get('usernamedb');
    $pass = $get('passworddb');
    if ($name === null || $user === null || $pass === null) {
        return null;
    }
    return ['host' => $host ?: 'localhost', 'name' => $name, 'user' => $user, 'pass' => $pass];
}

function find_binary(string $name): ?string
{
    if (stripos(PHP_OS, 'win') === 0) {
        $rc = 0;
        exec('where ' . escapeshellarg($name) . ' 2>nul', $o, $rc);
        if ($rc === 0 && !empty($o[0])) {
            return trim($o[0]);
        }
        return null;
    }
    $rc = 0;
    exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $o, $rc);
    if ($rc === 0 && !empty($o[0])) {
        return trim($o[0]);
    }
    return null;
}
