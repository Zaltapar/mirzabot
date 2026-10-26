<?php
/**
 * Bot sync / upgrade script
 *
 * Runs from the NEW code checkout against an INSTALLED bot directory:
 *   php sync.php                 (syncs THIS directory into ./)
 *   php sync.php /path/to/bot    (syncs THIS directory into /path/to/bot)
 *   php sync.php /path/to/bot --no-backup   (skip the file snapshot)
 *   php sync.php /path/to/bot --no-db       (skip the DB snapshot)
 *
 * What it does (in order):
 *   1. Preflight: target is a real bot install (table.php + config.php),
 *      source is a real bot source (table.php + version + db/).
 *   2. DB snapshot (best-effort mysqldump of the target's database).
 *   3. File snapshot: tar/zip of the target directory (rollback point).
 *   4. Copy source -> target, protecting install-specific paths:
 *        config.php, lang/override/, vpnbot/, storage/, vendor/, .git/
 *   5. composer install (only if vendor/ is missing or composer.json changed).
 *   6. Run the schema bootstrap + migrations + indexes + webhook
 *        (php table.php in the target dir) -> the new code takes control.
 *
 * The process is idempotent and safe to re-run. If anything fails before the
 * copy phase, the live install is untouched. The file snapshot is kept after a
 * successful sync so you can roll back.
 */

declare(strict_types=1);

const SYNC_VERSION = '1.0';

$argvClean = array_slice($argv, 1);
$targetArg = '';
$noBackup = in_array('--no-backup', $argvClean, true);
$noDb = in_array('--no-db', $argvClean, true);
foreach ($argvClean as $a) {
    if (!str_starts_with($a, '--')) {
        $targetArg = $a;
        break;
    }
}

$srcDir = rtrim(getcwd(), '/\\');
$targetDir = $targetArg !== '' ? real_path_or_null($targetArg) : null;
if ($targetDir === null) {
    $targetDir = $srcDir;
}

fail_if($srcDir === $targetDir && count($argvClean) > 0 && $targetArg !== '',
    "Source and target are the same directory; nothing to do.");
fail_if($targetDir === null, "Target directory not found: {$targetArg}");

step("Preflight checks");
fail_if(!is_file($targetDir . '/table.php'), "Target {$targetDir} is not a bot install (no table.php).");
fail_if(!is_file($targetDir . '/config.php'), "Target has no config.php - is this an installed bot?");
fail_if(!is_file($srcDir . '/table.php') || !is_file($srcDir . '/db/bootstrap.php'),
    "Source directory does not look like a bot source checkout.");
$srcVersion = trim((string) @file_get_contents($srcDir . '/version'));
$dstVersion = trim((string) @file_get_contents($targetDir . '/version'));
info("Source version: " . ($srcVersion !== '' ? $srcVersion : '?') . " -> target version: " . ($dstVersion !== '' ? $dstVersion : '?'));

// ---------------------------------------------------------------- DB snapshot
$backupDir = $targetDir . '/storage/backup';
if ($noDb) {
    info('Skipping DB snapshot (--no-db)');
} else {
    step('DB snapshot (mysqldump)');
    $dbPass = extract_db_creds($targetDir . '/config.php');
    if ($dbPass === null) {
        warn('Could not parse DB credentials from config.php - skipping mysqldump snapshot.');
    } else {
        $out = $backupDir . '/db_' . date('Ymd_His') . '.sql';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0775, true);
        }
        $dumpOk = run_mysqldump($dbPass, $out);
        if ($dumpOk) {
            info('DB snapshot written: ' . $out);
            prune_old_backups($backupDir, 'db_', 5);
        } else {
            warn('mysqldump unavailable or failed - continuing WITHOUT a DB snapshot. Take your own backup first!');
        }
    }
}

// ------------------------------------------------------------- file snapshot
if ($noBackup) {
    info('Skipping file snapshot (--no-backup)');
} else {
    step('File snapshot of current install');
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0775, true);
    }
    $snap = create_snapshot($targetDir, $backupDir . '/files_' . date('Ymd_His'));
    if ($snap !== null) {
        info('File snapshot written: ' . $snap);
        prune_old_backups($backupDir, 'files_', 3);
    } else {
        warn('Could not create a file snapshot - the sync will continue. '
            . 'Consider snapshotting ' . $targetDir . ' yourself first (e.g. tar/zip).');
    }
}

// -------------------------------------------------------------------- the copy
step('Copying source -> target (protecting install-specific paths)');
$protected = [
    'config.php' => 'bot credentials & webhook',
    'lang/override' => 'per-language custom text overrides',
    'vpnbot' => 'sales-bot instances',
    'storage' => 'runtime state (cron status, cache, backups)',
    'vendor' => 'composer dependencies',
    '.git' => 'target git metadata',
    'error_log' => 'runtime log',
];
// Upgrading a running bot must not ship the web installer: the root .htaccess
// returns 403 for every non-/install/ URL while install/index.php exists,
// which silently kills the live webhook (/index.php). Pass --fresh for brand
// new installs where the web installer UI is still wanted.
$freshInstall = in_array('--fresh', $argvClean, true);
if (!$freshInstall) {
    $protected['install'] = 'web installer (kept out of running bots: .htaccess 403 lock)';
}
$copied = 0;
$skipped = [];
foreach ($protected as $rel => $why) {
    $dst = $targetDir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_dir($dst)) {
        $skipped[] = $rel . ' (dir, ' . $why . ')';
    } elseif (file_exists($dst)) {
        $skipped[] = $rel . ' (file, ' . $why . ')';
    }
}
$preCopyComposerMd5 = md5((string) @file_get_contents($targetDir . '/composer.json'));
sync_dir($srcDir, $targetDir, $protected, $copied);
info("Copied {$copied} file(s). Preserved: " . implode(', ', $skipped !== [] ? $skipped : ['(nothing)']));

if (!$freshInstall && is_dir($targetDir . '/install')) {
    step('Removing leftover web installer (install/) from running bot');
    $leftover = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetDir . '/install', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($leftover as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($targetDir . '/install');
    info('install/ removed - while it exists, the root .htaccess returns 403 for the whole bot (webhook included).');
}

// ------------------------------------------------------------------ composer
step('PHP dependencies (composer)');
$needComposer = !is_dir($targetDir . '/vendor')
    || $preCopyComposerMd5 !== md5((string) @file_get_contents($srcDir . '/composer.json'));
if (!$needComposer) {
    info('vendor/ present and composer.json unchanged - skipping.');
} else {
    if (is_dir($targetDir . '/vendor') && $needComposer) {
        info('composer.json changed - running `composer install` in target...');
    } else {
        info('vendor/ missing - running `composer install` in target...');
    }
    $composer = locate_composer();
    if ($composer === null) {
        warn('composer not found. Run `composer install` in ' . $targetDir . ' manually, then re-run sync.');
    } else {
        $rc = 0;
        passthru(escapeshellarg($composer) . ' install --no-interaction --no-progress', $rc);
        if ($rc !== 0) {
            fail('composer install failed (exit ' . $rc . '). The new code may not work until you fix dependencies.');
        }
        info('composer install done.');
    }
}

// ------------------------------------------------- schema, migrations, webhook
step('Running schema bootstrap + migrations + indexes + webhook (table.php)');
$phpBin = PHP_BINARY;
// table.php's config load is relative (function.php: require 'config.php'), so it
// must run with the target install dir as the working directory.
chdir($targetDir);
$rc = 0;
passthru(escapeshellarg($phpBin) . ' ' . escapeshellarg('table.php'), $rc);
if ($rc !== 0) {
    fail('table.php failed (exit ' . $rc . "). Check the target's error_log. The code files are in place, but "
        . 'the database schema may not be up to date yet - run: cd ' . escapeshellarg($targetDir) . ' && '
        . escapeshellarg($phpBin) . ' table.php');
}
$checkFile = $backupDir . '/.db_check.php';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0775, true);
}
file_put_contents($checkFile, <<<'CHECK'
<?php
require 'config.php';
$ok = is_object($pdo);
if ($ok) {
    $ok = (bool) $pdo->query('SHOW TABLES LIKE "user_action_stats"')->fetchColumn();
}
exit($ok ? 0 : 3);
CHECK);
$rc2 = 0;
exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($checkFile) . ' 2>/dev/null', $checkOut, $rc2);
@unlink($checkFile);
if ($rc2 !== 0) {
    fail('table.php reported success but the database schema was NOT applied '
        . '(connection failed or user_action_stats missing). Run manually: cd '
        . escapeshellarg($targetDir) . ' && ' . escapeshellarg($phpBin) . ' table.php');
}
info('table.php done - schema, migrations and webhook are up to date.');

step('Done');
info('Sync complete. The installed bot at ' . $targetDir . ' now runs the new code.');
info('If anything misbehaves: restore the file snapshot (' . $backupDir . ') and/or the DB snapshot, then re-run `php table.php`.');

// ================================================================= helpers
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

function real_path_or_null(string $p): ?string
{
    $r = realpath($p);
    return $r === false ? null : $r;
}

function extract_db_creds(string $configPath): ?array
{
    $src = @file_get_contents($configPath);
    if ($src === false) {
        return null;
    }
    $get = static function (string $var) use ($src) {
        if (preg_match("/\\$" . $var . "\\s*=\s*'([^']*)'/", $src, $m)) {
            return $m[1];
        }
        if (preg_match("/\\$" . $var . "\\s*=\s*\"([^\"]*)\"/", $src, $m)) {
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

function run_mysqldump(array $creds, string $outFile): bool
{
    $binary = find_binary('mysqldump');
    if ($binary === null) {
        return false;
    }
    $pass = str_replace("'", '', $creds['pass']);
    $cmd = sprintf(
        '%s --host=%s --user=%s --password=%s --single-transaction --skip-lock-tables --quick --routines --triggers %s > %s 2>/dev/null',
        escapeshellarg($binary),
        escapeshellarg($creds['host']),
        escapeshellarg($creds['user']),
        "'" . $pass . "'",
        escapeshellarg($creds['name']),
        escapeshellarg($outFile)
    );
    $rc = 0;
    exec($cmd, $o, $rc);
    return $rc === 0 && is_file($outFile) && filesize($outFile) > 0;
}

function create_snapshot(string $targetDir, string $baseOut): ?string
{
    $parent = dirname($targetDir);
    $base = basename($targetDir);
    if (stripos(PHP_OS, 'win') === 0) {
        // 1) bsdtar (ships with Windows 10+; also in Git Bash environments).
        $out = $baseOut . '.tar.gz';
        $rc = 0;
        exec('tar -czf ' . escapeshellarg($out) . ' -C ' . escapeshellarg($parent) . ' ' . escapeshellarg($base) . ' 2>nul', $o, $rc);
        if ($rc === 0 && is_file($out) && filesize($out) > 0) {
            return $out;
        }
        @unlink($out);
        // 2) ZipArchive (php-zip extension).
        if (class_exists('ZipArchive')) {
            $zipOut = $baseOut . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipOut, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS));
                foreach ($iter as $file) {
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($targetDir) + 1));
                    if ($rel === '' || strpos($rel, 'storage/backup/') === 0) {
                        continue;
                    }
                    if ($file->isFile()) {
                        $zip->addFile($file->getPathname(), $base . '/' . $rel);
                    }
                }
                $zip->close();
                if (is_file($zipOut)) {
                    return $zipOut;
                }
            }
            @unlink($zipOut);
        }
        return null;
    }
    $out = $baseOut . '.tar.gz';
    // Exclude the backup dir itself: the archive is written inside the tree
    // being archived, and without this tar exits 1 ("file changed as we read it").
    $rc = 0;
    exec('tar -czf ' . escapeshellarg($out) . ' --exclude=' . escapeshellarg($base . '/storage/backup') . ' -C ' . escapeshellarg($parent) . ' ' . escapeshellarg($base) . ' 2>/dev/null', $o, $rc);
    return ($rc === 0 && is_file($out) && filesize($out) > 0) ? $out : null;
}

function prune_old_backups(string $backupDir, string $prefix, int $keep): void
{
    if (!is_dir($backupDir)) {
        return;
    }
    $files = glob($backupDir . '/' . $prefix . '*') ?: [];
    sort($files);
    while (count($files) > $keep) {
        @unlink(array_shift($files));
    }
}

function locate_composer(): ?string
{
    $composer = getenv('COMPOSER');
    if ($composer !== false && $composer !== '' && is_file($composer)) {
        return $composer;
    }
    $found = find_binary('composer');
    if ($found !== null) {
        return $found;
    }
    $candidates = [
        'C:/ProgramData/Composer/Vendor/bin/composer.phar',
        '/usr/local/bin/composer',
        '/usr/bin/composer',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return null;
}

function sync_dir(string $src, string $dst, array $protected, int &$count): void
{
    $skip = [];
    foreach (array_keys($protected) as $rel) {
        $skip[str_replace('\\', '/', $rel)] = true;
    }
    $srcRoot = rtrim($src, '\\/');
    $isDirFn = static function (string $path) use ($srcRoot, $skip): bool {
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($srcRoot))), '/');
        foreach (array_keys($skip) as $p) {
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return false;
            }
        }
        return true;
    };
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f, bool $isRecursive) use ($isDirFn): bool {
                return !$isRecursive || $isDirFn($f->getPath());
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            $target = $dst . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($srcRoot)));
            if (!is_dir($target)) {
                @mkdir($target, 0775, true);
            }
            continue;
        }
        $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($srcRoot))), '/');
        if (isset($skip[$rel])) {
            continue;
        }
        $targetPath = $dst . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $content = file_get_contents($file->getPathname());
        $oldContent = is_file($targetPath) ? file_get_contents($targetPath) : false;
        if ($content === $oldContent) {
            continue;
        }
        if (file_put_contents($targetPath, $content) === false) {
            warn('Failed to write ' . $rel);
            continue;
        }
        $count++;
    }
}
