<?php declare(strict_types=1);

use localzet\Tunnel\Client;
use localzet\Tunnel\SerializationGuard;
use localzet\Server as LocalzetServer;
use localzet\Server\Connection\TcpConnection;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/IntegrationPayload.php';

final class TunnelClientProbe extends TcpConnection
{
    public bool $json = false;
    public array $sent = [];

    public function __construct() {}

    public function send(mixed $data, bool $raw = false): ?bool
    {
        $this->sent[] = $data;
        return true;
    }

    public function __destruct() {}
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$onMessageParameter = (new ReflectionMethod(\localzet\Tunnel\Client::class, 'onMessage'))->getParameters()[0];
expect(!$onMessageParameter->isPassedByReference(), 'Tunnel async message callback accepts its connection argument normally.');

function connectTo(int $port)
{
    $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 3);
    if (!$socket) {
        throw new RuntimeException("Cannot connect to fixture: {$errorMessage}");
    }
    stream_set_timeout($socket, 3);
    return $socket;
}

function sendJson($socket, array $message): void
{
    $payload = json_encode($message, JSON_THROW_ON_ERROR);
    fwrite($socket, pack('N', strlen($payload)) . $payload);
}

function readBytes($socket, int $length): string
{
    $bytes = '';
    while (strlen($bytes) < $length) {
        $chunk = fread($socket, $length - strlen($bytes));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Incomplete Tunnel frame.');
        }
        $bytes .= $chunk;
    }
    return $bytes;
}

function readJson($socket): array
{
    $header = readBytes($socket, 4);
    $length = unpack('Nlength', $header)['length'];
    $payload = readBytes($socket, $length);
    return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
}

$reservation = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!$reservation) {
    throw new RuntimeException($errorMessage);
}
$address = stream_socket_get_name($reservation, false);
$port = (int)substr(strrchr($address, ':'), 1);
fclose($reservation);

$serverDebugMarker = 'tunnel-server-debug-secret-' . bin2hex(random_bytes(8));
$process = proc_open(
    [PHP_BINARY, __DIR__ . '/fixture_server.php', (string)$port, 'debug'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    dirname(__DIR__),
);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start Tunnel fixture.');
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$subscriber = null;
$secondSubscriber = null;
$publisher = null;
$queueConsumer = null;
$malformedClient = null;
$markerPath = sys_get_temp_dir() . '/tunnel-unserialize-' . bin2hex(random_bytes(8));

$acyclicPayload = ['id' => 7, 'data' => ['ready' => true, 'optional' => null]];
expect(SerializationGuard::supports($acyclicPayload), 'Scalar and nested-array payloads must remain supported.');
expect(!SerializationGuard::supports(['nested' => new IntegrationPayload($markerPath)]), 'Nested PHP object payloads must be rejected.');
$cyclicPayload = [];
$cyclicPayload['self'] =& $cyclicPayload;
expect(!SerializationGuard::supports($cyclicPayload), 'Recursive arrays must be rejected without recursive traversal.');

$callbackMessages = [];
Client::$onMessage = static function (string $channel, mixed $data) use (&$callbackMessages): void {
    $callbackMessages[] = [$channel, $data];
};
$sendAnyway = new ReflectionMethod(Client::class, 'sendAnyway');
$sendAnyway->setAccessible(true);
$sendRejected = false;
try {
    $sendAnyway->invoke(null, [
        'type' => 'publish',
        'channels' => ['security'],
        'data' => new IntegrationPayload($markerPath),
    ]);
} catch (InvalidArgumentException) {
    $sendRejected = true;
}
expect($sendRejected, 'Tunnel client must reject outgoing objects before opening a connection.');

$clientConnection = new TunnelClientProbe();
$clientConnectionProperty = new ReflectionProperty(Client::class, 'connection');
$clientConnectionProperty->setAccessible(true);
$clientConnectionProperty->setValue(null, $clientConnection);
$untrustedClientPayload = serialize([
    'type' => 'event',
    'channel' => 'security',
    'data' => ['nested' => new IntegrationPayload($markerPath)],
]);
Client::allowObjectSerialization(false);
Client::onMessage($clientConnection, $untrustedClientPayload);
expect($callbackMessages === [] && !file_exists($markerPath), 'Tunnel client must reject nested object responses before dispatch.');

$sendRejected = false;
try {
    $sendAnyway->invoke(null, [
        'type' => 'publish',
        'channels' => ['security'],
        'data' => new IntegrationPayload($markerPath),
    ]);
} catch (InvalidArgumentException) {
    $sendRejected = true;
}
expect($sendRejected && $clientConnection->sent === [], 'Tunnel client must reject outgoing objects without opt-in.');

Client::onMessage($clientConnection, serialize([
    'type' => 'event',
    'channel' => 'plain',
    'data' => $acyclicPayload,
]));
expect($callbackMessages === [['plain', $acyclicPayload]], 'Tunnel client must deliver scalar and nested-array responses.');

Client::allowObjectSerialization(true);
$sendAnyway->invoke(null, [
    'type' => 'publish',
    'channels' => ['trusted'],
    'data' => new IntegrationPayload($markerPath),
]);
expect(count($clientConnection->sent) === 1 && is_string($clientConnection->sent[0]), 'Tunnel client opt-in must retain legacy object serialization.');
$clientConnection->sent = [];
Client::onMessage($clientConnection, serialize([
    'type' => 'event',
    'channel' => 'trusted',
    'data' => new IntegrationPayload($markerPath),
]));
expect(count($callbackMessages) === 2 && $callbackMessages[1][1] instanceof IntegrationPayload, 'Tunnel client opt-in must preserve trusted object responses.');
expect(file_exists($markerPath), 'Trusted Tunnel object response should retain legacy wakeup behavior.');

$debugLogPath = sys_get_temp_dir() . '/tunnel-debug-' . bin2hex(random_bytes(8)) . '.log';
$clientDebugProperty = new ReflectionProperty(Client::class, 'debug');
$previousLogFile = LocalzetServer::$logFile;
$previousDaemonize = LocalzetServer::$daemonize;
LocalzetServer::$logFile = $debugLogPath;
LocalzetServer::$daemonize = true;
$clientDebugProperty->setValue(null, true);
$clientDebugMarker = 'tunnel-client-debug-secret-' . bin2hex(random_bytes(8));
try {
    Client::onMessage($clientConnection, json_encode([
        'type' => 'event',
        'channel' => 'security',
        'data' => $clientDebugMarker,
    ], JSON_THROW_ON_ERROR));
} finally {
    $clientDebugProperty->setValue(null, false);
    LocalzetServer::$logFile = $previousLogFile;
    LocalzetServer::$daemonize = $previousDaemonize;
}
$clientDebugLog = is_file($debugLogPath) ? (string)file_get_contents($debugLogPath) : '';
@unlink($debugLogPath);
expect(!str_contains($clientDebugLog, $clientDebugMarker), 'Tunnel client debug output must omit response payloads.');

@unlink($markerPath);
Client::allowObjectSerialization(false);
$clientConnectionProperty->setValue(null, null);
Client::$onMessage = null;

try {
    $deadline = microtime(true) + 8;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            throw new RuntimeException('Tunnel fixture exited before accepting connections.');
        }
        $subscriber = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.2);
        if (is_resource($subscriber)) break;
        usleep(50_000);
    } while (microtime(true) < $deadline);

    expect(is_resource($subscriber), 'Tunnel fixture did not start in time.');
    stream_set_timeout($subscriber, 3);
    sendJson($subscriber, ['type' => 'subscribe', 'channels' => ['events']]);
    sendJson($subscriber, ['type' => 'watch', 'channels' => ['jobs']]);
    sendJson($subscriber, ['type' => 'reserve']);
    $secondSubscriber = connectTo($port);
    sendJson($secondSubscriber, ['type' => 'subscribe', 'channels' => ['events']]);

    $publisher = connectTo($port);
    sendJson($publisher, ['type' => 'publish', 'channels' => ['events'], 'data' => $serverDebugMarker]);
    $event = readJson($subscriber);
    expect($event === ['type' => 'event', 'channel' => 'events', 'data' => $serverDebugMarker], 'Channel publish delivery failed.');
    $secondEvent = readJson($secondSubscriber);
    expect($secondEvent === $event, 'A later channel subscriber received a malformed event.');

    $queueConsumer = connectTo($port);
    sendJson($queueConsumer, ['type' => 'enqueue', 'channels' => ['jobs'], 'data' => $serverDebugMarker]);
    $job = readJson($subscriber);
    expect($job === ['type' => 'queue', 'channel' => 'jobs', 'data' => $serverDebugMarker], 'Queue reservation delivery failed.');

    $malformedClient = connectTo($port);
    sendJson($malformedClient, ['type' => 'publish', 'channels' => [['invalid']], 'data' => 'ignored']);
    $invalidPayload = 'not-json';
    fwrite($malformedClient, pack('N', strlen($invalidPayload)) . $invalidPayload);
    $serializedPayloads = [
        serialize([
            'type' => 'publish',
            'channels' => ['events'],
            'data' => new IntegrationPayload($markerPath),
        ]),
        serialize([
            'type' => 'publish',
            'channels' => ['events'],
            'data' => ['nested' => new IntegrationPayload($markerPath)],
        ]),
    ];
    $cyclicData = [];
    $cyclicData['self'] =& $cyclicData;
    $serializedPayloads[] = serialize([
        'type' => 'publish',
        'channels' => ['events'],
        'data' => $cyclicData,
    ]);
    foreach ($serializedPayloads as $serializedPayload) {
        fwrite($malformedClient, pack('N', strlen($serializedPayload)) . $serializedPayload);
    }
    usleep(100_000);
    expect(!file_exists($markerPath), 'Untrusted serialized object executed __wakeup().');

    sendJson($publisher, ['type' => 'publish', 'channels' => ['events'], 'data' => 'still-alive']);
    $event = readJson($subscriber);
    expect($event['data'] === 'still-alive', 'Malformed messages disrupted the Tunnel server.');

    usleep(100_000);
    $serverDebugLog = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    expect(!str_contains($serverDebugLog, $serverDebugMarker), 'Tunnel server debug output must omit request and message payloads.');

    echo "Tunnel publish, queue, malformed-input, serialization-guard and debug-redaction checks passed.\n";
} finally {
    foreach ([$subscriber, $secondSubscriber, $publisher, $queueConsumer, $malformedClient] as $socket) {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
    proc_terminate($process);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($process);
    @unlink($markerPath);
}
