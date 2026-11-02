<?php declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/IntegrationPayload.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

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

$process = proc_open(
    [PHP_BINARY, __DIR__ . '/fixture_server.php', (string)$port],
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
    sendJson($publisher, ['type' => 'publish', 'channels' => ['events'], 'data' => ['ok' => true]]);
    $event = readJson($subscriber);
    expect($event === ['type' => 'event', 'channel' => 'events', 'data' => ['ok' => true]], 'Channel publish delivery failed.');
    $secondEvent = readJson($secondSubscriber);
    expect($secondEvent === $event, 'A later channel subscriber received a malformed event.');

    $queueConsumer = connectTo($port);
    sendJson($queueConsumer, ['type' => 'enqueue', 'channels' => ['jobs'], 'data' => 'job-1']);
    $job = readJson($subscriber);
    expect($job === ['type' => 'queue', 'channel' => 'jobs', 'data' => 'job-1'], 'Queue reservation delivery failed.');

    $malformedClient = connectTo($port);
    sendJson($malformedClient, ['type' => 'publish', 'channels' => [['invalid']], 'data' => 'ignored']);
    $invalidPayload = 'not-json';
    fwrite($malformedClient, pack('N', strlen($invalidPayload)) . $invalidPayload);
    $serializedPayload = serialize([
        'type' => 'invalid',
        'channels' => [],
        'data' => new IntegrationPayload($markerPath),
    ]);
    fwrite($malformedClient, pack('N', strlen($serializedPayload)) . $serializedPayload);
    usleep(100_000);
    expect(!file_exists($markerPath), 'Untrusted serialized object executed __wakeup().');

    sendJson($publisher, ['type' => 'publish', 'channels' => ['events'], 'data' => 'still-alive']);
    $event = readJson($subscriber);
    expect($event['data'] === 'still-alive', 'Malformed messages disrupted the Tunnel server.');

    echo "Tunnel publish, queue, malformed-input and object-deserialization checks passed.\n";
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
