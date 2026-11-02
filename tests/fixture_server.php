<?php declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$port = (int)($argv[1] ?? 0);
new \localzet\Tunnel\Server("frame://127.0.0.1:{$port}");
$GLOBALS['argv'] = [$argv[0]];
\localzet\Server::runAll();
