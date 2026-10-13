<?php
// Cross-OS benchmark of WrapperRunner::CYCLE_SLEEP. Run from the paratest checkout root.
$root = __DIR__;
$fixture = dirname($root) . DIRECTORY_SEPARATOR . 'polling-fixture';
@mkdir("$fixture/tests", 0777, true);
for ($i = 0; $i < 400; $i++) {
    $n = sprintf('Trivial%03dTest', $i);
    file_put_contents("$fixture/tests/$n.php", "<?php\nfinal class $n extends PHPUnit\\Framework\\TestCase\n{\n    public function testTrivial(): void\n    {\n        self::assertTrue(true);\n    }\n}\n");
}
file_put_contents("$fixture/phpunit.xml", '<?xml version="1.0"?><phpunit bootstrap="' . "$root/vendor/autoload.php" . '"><testsuites><testsuite name="f"><directory suffix="Test.php">tests</directory></testsuite></testsuites></phpunit>');

$probe = static function (int $us): float {
    $start = hrtime(true);
    for ($i = 0; $i < 200; $i++) usleep($us);
    return (hrtime(true) - $start) / 1e6 / 200;
};
printf("os=%s php=%s cpus=%s usleep(10000)=%.2fms usleep(1000)=%.2fms\n", PHP_OS_FAMILY, PHP_VERSION, getenv('NUMBER_OF_PROCESSORS') ?: trim((string) shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu')), $probe(10000), $probe(1000));

$file = "$root/src/WrapperRunner/WrapperRunner.php";
$orig = file_get_contents($file);
$results = [];
for ($round = 0; $round < 3; $round++) {
    foreach ($round % 2 ? [1000, 10000] : [10000, 1000] as $sleep) {
        foreach ([1, 2, 4] as $p) {
            file_put_contents($file, preg_replace('/CYCLE_SLEEP = \d+;/', "CYCLE_SLEEP = $sleep;", $orig));
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/bin/paratest") . ' --configuration ' . escapeshellarg("$fixture/phpunit.xml") . " --processes=$p";
            $start = hrtime(true);
            exec("$cmd 2>&1", $out, $code);
            $t = (hrtime(true) - $start) / 1e9;
            if ($code !== 0 || !str_contains(implode("\n", $out), 'OK (400 tests')) { echo implode("\n", $out), "\n"; exit(1); }
            $out = [];
            $results[$p][$sleep][] = $t;
        }
    }
}
file_put_contents($file, $orig);
echo "| workers | 10ms | 1ms | saved |\n|---|---|---|---|\n";
foreach ($results as $p => $bySleep) {
    $med = static function (array $v): float { sort($v); return $v[1]; };
    $a = $med($bySleep[10000]); $b = $med($bySleep[1000]);
    printf("| %d | %.2fs | %.2fs | %.0f%% |\n", $p, $a, $b, ($a - $b) / $a * 100);
}
