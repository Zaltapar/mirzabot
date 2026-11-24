<?php
/**
 * Static regression guards for two admin-flow bugs:
 *
 * 1. Notification loops in index.php clobbered $adminrulecheck (the acting
 *    admin's role, initialized in keyboard.php), letting the last notified
 *    admin's role drive admin.php's ~100 role gates.
 * 2. The "order pay from wallet" branch silently made an existing panel user
 *    free ($paid = false) and read $DataUserOut['links'] without a guard.
 *
 * Static source assertions are used because these are source-shape regressions
 * and the bot has no unit-test harness. Run with: php tests/admin_role_regression.php
 */

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$admin = file_get_contents($root . '/admin.php');
$keyboard = file_get_contents($root . '/keyboard.php');

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

/* ------------------------------------------------------------------ *
 * Bug 1: acting admin role must not be clobbered by notification loops
 * ------------------------------------------------------------------ */

// keyboard.php is the legitimate source of the acting admin's role.
check(
    'keyboard.php derives $adminrulecheck from the acting $from_id',
    strpos($keyboard, '$adminrulecheck = select("admin", "*", "id_admin", $from_id, "select")') !== false
);

// index.php must contain no assignment to $adminrulecheck at all: any
// assignment there risks overwriting the acting admin's role before
// admin.php is require_once'd at the end of the request.
check(
    'index.php never assigns $adminrulecheck (notification loops use isolated temporaries)',
    preg_match('/\$adminrulecheck\s*=/', $index) === 0
);
check(
    'index.php contains no $adminrulecheck references at all',
    strpos($index, '$adminrulecheck') === false
);

// The four notification loops must still iterate over $admin_ids, but through
// an isolated variable (and without leaking a role variable).
check(
    'all four notification loops use the isolated $notifyAdmin temporary',
    substr_count($index, 'foreach ($admin_ids as $notifyAdmin)') === 4
);
check(
    'notification loops look up each notified admin via $notifyAdmin only',
    substr_count($index, 'select("admin", "*", "id_admin", $notifyAdmin, "select")') === 4
);
check(
    'notification loops no longer use the old $id_admin / $admin role temporaries',
    preg_match('/foreach \(\$admin_ids as \$(?:id_admin|admin)\)\s*\{[^}]*select\("admin"/s', $index) === 0
);

// admin.php must re-derive the role from $from_id, after the membership gate,
// and must have a non-privileged fallback for an empty/false select result.
$gatePos = strpos($admin, 'if (!in_array($from_id, $admin_ids))');
$roleAssignPos = strpos($admin, '$adminrulecheck = select("admin", "*", "id_admin", $from_id, "select")');
$firstUsePos = strpos($admin, "\$adminrulecheck['rule']");

check(
    'admin.php re-derives $adminrulecheck from $from_id',
    $roleAssignPos !== false
);
check(
    'role re-derivation happens after the admin membership gate',
    $gatePos !== false && $roleAssignPos !== false && $gatePos < $roleAssignPos
);
check(
    'role re-derivation happens before any role gate in admin.php',
    $roleAssignPos !== false && $firstUsePos !== false && $roleAssignPos < $firstUsePos
);
$roleBlock = $roleAssignPos === false ? '' : substr($admin, $roleAssignPos, 500);
check(
    'admin.php has a safe, non-privileged fallback for an empty role lookup',
    strpos($roleBlock, 'is_array(') !== false
    && strpos($roleBlock, "array('rule' => '')") !== false
);

/* ------------------------------------------------------------------ *
 * Bug 2: preserving the explicit "pay from wallet" choice
 * ------------------------------------------------------------------ */

$branchStart = strpos($admin, '} elseif (($datain == "addorderpay" || $datain == "addorderfree")');
check('addorderpay/addorderfree branch located', $branchStart !== false);
$branch = $branchStart === false ? '' : substr($admin, $branchStart, 6000);

check(
    'existing-user path no longer silently forces $paid = false',
    strpos($branch, '$paid = false') === false
);
check(
    'existing-user path guards the links key with a null-coalescing fallback',
    strpos($branch, "\$DataUserOut['configs'] = \$DataUserOut['links'] ?? []") !== false
);
check(
    'both new-user and existing-user paid paths deduct the order price from the wallet',
    substr_count($branch, 'if ($paid && !deductBalance($owner, $orderPrice))') === 2
);
check(
    'invoice price still reflects the administrator\'s explicit choice',
    strpos($branch, '$invoicePrice = $paid ? $orderPrice : 0;') !== false
);

if ($failures) {
    fwrite(STDERR, "\n" . count($failures) . " regression check(s) FAILED.\n");
    exit(1);
}
echo "\nAll regression checks passed.\n";
exit(0);
