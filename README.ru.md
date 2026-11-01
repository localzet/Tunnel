# Localzet Tunnel

[English documentation](README.md)

Клиент-серверный канал сообщений между процессами Localzet Server.

## Состояние и совместимость

Это рекомендованная замена заброшенного localzet/channel. Замена не автоматическая: нужно проверить API событий, framing, reconnect, семантику доставки и контроль доступа в зависимых приложениях.

Эта версия рассчитана на Server 7.x. Десериализация PHP-объектов по умолчанию отключена. Для сохранения объектных данных в доверенной изолированной сети включите режим на обеих сторонах: передайте `allowObjectSerialization: true` третьим аргументом конструктора `Server` и вызовите `Client::allowObjectSerialization()` до подключения. JSON поддерживается. Не включайте десериализацию объектов для недоверенных узлов.

## Зависимости

- `php`: `>=8.1`
- `localzet/server`: `^7.0`

## Установка

```sh
composer require localzet/tunnel
```

## Проверки разработки

```sh
composer validate --strict
composer install
composer dump-autoload --optimize --strict-psr
composer audit
```

Установка, lint и автозагрузка не подтверждают сквозное поведение или готовность к эксплуатации.

[Исторические примеры](docs/legacy-readme.md) нужно сверять с текущим API.

## Автор и лицензия

Ivan Zorin (`localzet`), <creator@localzet.com>, https://www.localzet.com.
Source: https://github.com/localzet/Tunnel. AGPL-3.0-or-later; [LICENSE](LICENSE). Сохраняются исходные уведомления авторов и лицензии сторонних компонентов.

[Authors](.github/AUTHORS.md) · [Contributing](.github/CONTRIBUTING.md) · [Security](.github/SECURITY.md)
