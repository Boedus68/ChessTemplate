<?php
// test_exec.php — eliminare dopo il test
$out = [];
exec('whoami 2>&1', $out, $ret);
echo "exec() funziona: " . ($ret === 0 ? 'SÌ' : 'NO') . "\n";
echo "Output: " . implode("\n", $out) . "\n";

// Verifica anche shell_exec
$who = shell_exec('uname -m 2>&1');
echo "Architettura: " . $who;