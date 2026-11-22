# Localzet Tunnel

[Русская документация](README.ru.md)

A client/server messaging channel for Localzet Server processes.

## Status and compatibility

Tunnel is a small TCP message broker for PHP applications built on Localzet Server. It supports channel publish/subscribe and in-memory work queues.

## Compatibility

Version 4 requires Localzet Server 7.2.3 or newer within the 7.x series, and PHP 8.1 or newer. Version 3 also targets Server 7; the older 2.x release line targets Server 4. Version 4 changes the serialized payload contract: PHP objects and recursive arrays are rejected by default. Applications that rely on object payloads must explicitly enable serialization on both peers and use a trusted, isolated network. Version 4 also caps queued request bytes at 32 MiB per broker process.

Messages and queues are held in broker memory. Queues are not durable, and delivery is not acknowledged or retried. Each broker process holds at most 10,000 pending queue messages and 32 MiB of queued request bytes across all channels. An `enqueue` request that would exceed either limit is rejected as a whole, without an acknowledgement to the sender. The byte limit counts each queued copy by the size of its incoming frame. Run one broker process for a given channel namespace; multiple independent brokers do not share subscriptions or queue state.

By default, legacy PHP-serialized payloads may contain scalars and acyclic arrays only; PHP objects, including `__PHP_Incomplete_Class`, and recursive arrays are rejected by both server and client. JSON objects are decoded as associative arrays. To preserve PHP object payloads on a trusted, isolated network, enable object serialization on both ends: pass `allowObjectSerialization: true` as the third `Server` constructor argument and call `Client::allowObjectSerialization()` before connecting. Never enable it for untrusted peers.

Tunnel does not provide client authentication or encryption. Bind it to a private interface and apply network-level access controls. Do not expose the listener directly to the public Internet.

## Dependencies

- `php`: `>=8.1`
- `localzet/server`: `^7.2.3`

## Installation

```sh
composer require localzet/tunnel:^4.0
```

Use the 4.x or 3.x release line with Server 7. Version 4 requires an explicit opt-in for PHP object payloads and adds a 32 MiB queued-byte limit. The 2.x release line is for Server 4 applications.

## Quick start

Start a broker on a private host:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

new \localzet\Tunnel\Server('frame://127.0.0.1:9010');
\localzet\Server::runAll();
```

Connect Tunnel clients from Localzet Server workers and subscribe to a channel:

```php
use localzet\Tunnel\Client;

Client::connect('frame://127.0.0.1:9010');
Client::on('orders', static function (mixed $order): void {
    // Handle the published message.
});
Client::publish('orders', ['id' => 42]);
```

`Client` uses Localzet Server's asynchronous connection and timer APIs. Call it from an application running under Server's event loop. For ordinary CLI programs, use a protocol-aware client or run the integration through Server; `Client` does not provide a synchronous standalone API.

The broker accepts JSON messages and the legacy PHP serialization format. New clients should use JSON-compatible scalar and array payloads. PHP object payloads and recursive arrays in the legacy format require the explicit trusted-network opt-in described above.

## Behavior and limits

- `publish` sends one event to every subscriber; `publishLoop` sends it to one subscriber in rotation.
- `watch` plus `reserve` consumes queued items in memory. A process restart loses queued items.
- There is no authentication, TLS, persistence, delivery acknowledgement, replay, or cross-broker replication.
- Keep the broker on a trusted network. The listener protocol itself does not authorize channels or clients.

## Development checks

```sh
composer validate --strict
composer install
composer run check
composer audit
```

The integration test starts a local broker and checks publish, queue delivery, malformed input and disabled object deserialization. It does not establish production readiness or test multi-host deployment.

[Historical usage notes](docs/legacy-readme.md) need verification against the current API.

## Author and license

Ivan Zorin (`localzet`), <creator@localzet.com>, https://www.localzet.com.
Source: https://github.com/localzet/Tunnel. AGPL-3.0-or-later; [LICENSE](LICENSE). Original copyright and third-party licenses remain applicable.

[Authors](.github/AUTHORS.md) · [Contributing](.github/CONTRIBUTING.md) · [Security](.github/SECURITY.md)
