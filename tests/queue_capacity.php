<?php declare(strict_types=1);

/**
 * @package     Localzet Tunnel
 * @link        https://github.com/localzet/Tunnel
 *
 * @copyright   Copyright © 2016-2026 Localzet Group. All rights reserved.
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use localzet\Tunnel\Server;
use localzet\Server\Connection\TcpConnection;

class CapacityTestConnection extends TcpConnection
{
    public int $id = 1;
    public bool $json = true;
    public array $watchs = [];

    public function __construct() {}

    public function send(mixed $data, bool $raw = false): ?bool { return true; }

    public function __destruct() {}
}

function expectCapacity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$server = new Server();
$queuesProperty = new ReflectionProperty(Server::class, 'queues');
$connection = new CapacityTestConnection();
$connection->id = 1;

for ($i = 0; $i < 10_000; $i++) {
    $server->onMessage($connection, json_encode([
        'type' => 'enqueue',
        'channels' => ['jobs'],
        'data' => $i,
    ], JSON_THROW_ON_ERROR));
}

$queues = $queuesProperty->getValue($server);
$jobs = $queues['jobs'];
expectCapacity($jobs->pendingCount() === 10_000, 'The queue should accept exactly 10,000 pending messages.');
$server->onMessage($connection, json_encode([
    'type' => 'enqueue',
    'channels' => ['audit'],
    'data' => 'overflow',
], JSON_THROW_ON_ERROR));
$queues = $queuesProperty->getValue($server);
expectCapacity(!isset($queues['audit']), 'The process-wide limit must reject an enqueue on another channel.');

$server->onMessage($connection, json_encode([
    'type' => 'watch',
    'channels' => ['jobs'],
], JSON_THROW_ON_ERROR));
for ($i = 0; $i < 10_000; $i++) {
    $server->onMessage($connection, json_encode(['type' => 'reserve'], JSON_THROW_ON_ERROR));
}
expectCapacity($jobs->pendingCount() === 0, 'Reserving should drain queued messages and release capacity.');

$server->onMessage($connection, json_encode([
    'type' => 'unwatch',
    'channels' => ['jobs'],
], JSON_THROW_ON_ERROR));
$queues = $queuesProperty->getValue($server);
expectCapacity(!isset($queues['jobs']), 'An empty unwatched queue should be removed.');

$server->onMessage($connection, json_encode([
    'type' => 'enqueue',
    'channels' => ['audit'],
    'data' => 'after-removal',
], JSON_THROW_ON_ERROR));
$queues = $queuesProperty->getValue($server);
expectCapacity($queues['audit']->pendingCount() === 1, 'Removing a queue must release capacity for a later enqueue.');

echo "Tunnel queue capacity checks passed.\n";
