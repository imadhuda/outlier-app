<?php
require __DIR__ . '/engine_test.php';
foreach ($GLOBALS['lines'] as [$k,$n,$x]) {
    if ($k==='sec') echo "\n— $n —\n";
    else printf("  %-5s %s%s\n", strtoupper($k), $n, $x ? "  -> $x" : '');
}
echo "\n" . ($GLOBALS['fail']===0 ? 'ALL PASS' : 'FAILURES') . ": {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n\n";
exit($GLOBALS['fail'] ? 1 : 0);
