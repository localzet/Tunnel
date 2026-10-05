# Localzet Tunnel

[Русская документация](README.ru.md)

A client/server messaging channel for Localzet Server processes.

## Status and compatibility

This library is the suggested replacement for the abandoned localzet/channel package. Substitution is not automatic: verify event API, framing, reconnect, delivery semantics and access controls in every dependent application.

This is a Server 4.x component; Server 7.x compatibility is not established.

## Dependencies

- `php`: `>=8.1`
- `localzet/server`: `^4.3`

## Installation

```sh
composer require localzet/tunnel
```

## Development checks

```sh
composer validate --strict
composer install
composer dump-autoload --optimize --strict-psr
composer audit
```

Installation, lint and autoload checks do not establish end-to-end behavior or production readiness.

[Historical usage notes](docs/legacy-readme.md) need verification against the current API.

## Author and license

Ivan Zorin (`localzet`), <creator@localzet.com>, https://www.localzet.com.
Source: https://github.com/localzet/Tunnel. AGPL-3.0-or-later; [LICENSE](LICENSE). Original copyright and third-party licenses remain applicable.

[Authors](.github/AUTHORS.md) · [Contributing](.github/CONTRIBUTING.md) · [Security](.github/SECURITY.md)
