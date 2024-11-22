<?php
declare(strict_types=1);

/**
 * @package     Localzet Tunnel
 * @link        https://github.com/localzet/Tunnel
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Localzet Group
 * @license     GNU Affero General Public License, version 3
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace localzet\Tunnel;

use Exception;
use localzet\Server as LocalzetServer;
use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\ProtocolInterface;
use localzet\Server\Protocols\Websocket;
use localzet\Timer;
use Throwable;

/**
 * Tunnel\Client
 */
class Client
{
    /**
     * @var callable
     */
    public static $onMessage = null;

    /**
     * @var callable
     */
    public static $onConnect = null;

    /**
     * @var callable
     */
    public static $onClose = null;

    /**
     * @var ConnectionInterface|resource|null|false
     */
    protected static mixed $connection = null;

    /**
     * @var string|null
     */
    protected static ?string $socketName = null;

    /**
     * @var int|null
     */
    protected static ?int $_reconnectTimer = null;

    /**
     * @var int|null
     */
    protected static ?int $pingTimer = null;

    /**
     * @var array
     */
    protected static array $events = [];

    /**
     * @var callable[]
     */
    protected static array $_queues = [];

    /**
     * @var bool
     */
    protected static bool $isServerEnv = true;

    /**
     * @var float
     */
    public static float $pingInterval = 25;

    /** @var string|null|class-string */
    private static ?string $protocol = null;

    /**
     * @throws Throwable
     */
    public static function connect(?string $socketName = null): void
    {
        if (self::$connection) {
            return;
        }

        self::$socketName = $socketName;

        if (PHP_SAPI !== 'cli' || !class_exists(LocalzetServer::class, false)) {
            self::$isServerEnv = false;
        }

        if (self::$isServerEnv) {
            $connection = new AsyncTcpConnection(self::$socketName);
            $connection->onClose = [self::class, 'onClose'];
            $connection->onConnect = [self::class, 'onConnect'];
            $connection->onMessage = [self::class, 'onMessage'];
            $connection->connect();

            if (empty(self::$pingTimer)) {
                self::$pingTimer = Timer::add(self::$pingInterval, [self::class, 'ping']);
            }
        } else {
            [$scheme, $address] = explode('://', self::$socketName, 2);
            $transport = LocalzetServer::BUILD_IN_TRANSPORTS[$scheme] ?? 'tcp';
            $scheme = ucfirst($scheme);
            self::$protocol = $scheme[0] === '\\' ? $scheme : 'Protocols\\' . $scheme;
            if (!class_exists(self::$protocol)) {
                self::$protocol = "localzet\\Server\\Protocols\\$scheme";
                if (!class_exists(self::$protocol)) {
                    throw new Exception("Класс \\Protocols\\$scheme не существует");
                }
            }

            if (in_array(self::$protocol, [
                LocalzetServer\Protocols\Websocket::class,
                LocalzetServer\Protocols\Ws::class,
                LocalzetServer\Protocols\Http::class,
                LocalzetServer\Protocols\Https::class,
            ])) {
                throw new Exception('Вне среды Localzet Server протоколы WebSocket и HTTP недоступны. 
                                                Используйте Frame, Text или собственный протокол.');
            }

            $connection = stream_socket_client(
                "$transport://$address",
                $errno, $errmsg,
                5, // STREAM_CLIENT_ASYNC_CONNECT
            );

            if (!$connection) throw new Exception($errmsg);
        }

        self::$connection = $connection;
    }

    /**
     * @param TcpConnection $connection
     * @param mixed $request
     * @return void
     * @throws Exception
     */
    public static function onMessage(ConnectionInterface &$connection, mixed $request): void
    {
        $data = unserialize($request);
        $type = $data['type'];
        $event = $data['channel'];
        $event_data = $data['data'];

        if ($type == 'event') {
            if (!empty(self::$events[$event])) {
                call_user_func(self::$events[$event], $event_data);
            } elseif (!empty(Client::$onMessage)) {
                call_user_func(Client::$onMessage, $event, $event_data);
            } else {
                throw new Exception("event:$event не является функцией");
            }
        } else {
            if (isset(self::$_queues[$event])) {
                call_user_func(self::$_queues[$event], $event_data);
            } else {
                throw new Exception("queue:$event не является функцией");
            }
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public static function onClose(): void
    {
        echo "Предупреждение канала: Соединение закрыто, попытка переподключения\n";
        self::$connection = null;
        self::clearTimer();
        self::$_reconnectTimer = Timer::add(1, [self::class, 'connect'], [self::$socketName]);
        if (self::$onClose) {
            call_user_func(self::$onClose);
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    public static function onConnect(): void
    {
        $all_event_names = array_keys(self::$events);
        if ($all_event_names) {
            self::subscribe($all_event_names);
        }
        self::clearTimer();

        if (self::$onConnect) {
            call_user_func(self::$onConnect);
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    public static function ping(): void
    {
        self::$connection?->send('');
    }

    /**
     * @return void
     * @throws Exception
     */
    public static function clearTimer(): void
    {
        if (!self::$isServerEnv) {
            throw new Exception('Метод clearTimer не поддерживается вне среды Localzet Server');
        }
        if (self::$_reconnectTimer) {
            Timer::del(self::$_reconnectTimer);
            self::$_reconnectTimer = null;
        }
    }

    /**
     * @param string $event
     * @param callback $callback
     * @throws Throwable
     */
    public static function on(string $event, callable $callback): void
    {
        if (!is_callable($callback)) {
            throw new Exception('Callback не поддается вызову для события.');
        }
        self::$events[$event] = $callback;
        self::subscribe($event);
    }

    /**
     * @param string|string[] $events
     * @return void
     * @throws Throwable
     */
    public static function subscribe(array|string $events): void
    {
        $events = (array)$events;
        self::send([
            'type' => 'subscribe',
            'channels' => $events
        ]);
        foreach ($events as $event) {
            if (!isset(self::$events[$event])) {
                self::$events[$event] = null;
            }
        }
    }

    /**
     * @param string|string[] $events
     * @return void
     * @throws Throwable
     */
    public static function unsubscribe(array|string $events): void
    {
        $events = (array)$events;
        self::send([
            'type' => 'unsubscribe',
            'channels' => $events
        ]);
        foreach ($events as $event) {
            unset(self::$events[$event]);
        }
    }

    /**
     * @param string|string[] $events
     * @param mixed $data
     * @param bool $is_loop
     * @throws Throwable
     */
    public static function publish(array|string $events, mixed $data, bool $is_loop = false): void
    {
        self::sendAnyway([
            'type' => $is_loop ? 'publishLoop' : 'publish',
            'channels' => (array)$events,
            'data' => $data
        ]);
    }

    /**
     * @param array|string $channels
     * @param callable $callback
     * @param boolean $autoReserve
     * @throws Throwable
     */
    public static function watch(array|string $channels, callable $callback, bool $autoReserve = true): void
    {
        if (!is_callable($callback)) {
            throw new Exception('Callback не поддается вызову для наблюдения.');
        }

        if ($autoReserve) {
            $callback = static function ($data) use ($callback) {
                try {
                    call_user_func($callback, $data);
                } finally {
                    self::reserve();
                }
            };
        }

        $channels = (array)$channels;
        self::send([
            'type' => 'watch',
            'channels' => $channels
        ]);

        foreach ($channels as $channel) {
            self::$_queues[$channel] = $callback;
        }

        if ($autoReserve) {
            self::reserve();
        }
    }

    /**
     * @param string|string[] $channels
     * @throws Throwable
     */
    public static function unwatch(array|string $channels): void
    {
        $channels = (array)$channels;
        self::send([
            'type' => 'unwatch',
            'channels' => $channels
        ]);
        foreach ($channels as $channel) {
            if (isset(self::$_queues[$channel])) {
                unset(self::$_queues[$channel]);
            }
        }
    }

    /**
     * @param string|string[] $channels
     * @param mixed $data
     * @throws Throwable
     */
    public static function enqueue(array|string $channels, mixed $data): void
    {
        self::sendAnyway([
            'type' => 'enqueue',
            'channels' => (array)$channels,
            'data' => $data
        ]);
    }

    /**
     * @throws Throwable
     */
    public static function reserve(): void
    {
        self::send([
            'type' => 'reserve'
        ]);
    }

    /**
     * @param $data
     * @throws Throwable
     */
    protected static function send($data): void
    {
        if (!self::$isServerEnv) {
            throw new Exception("Метод {$data['type']} не поддерживается вне среды Localzet Server");
        }

        self::connect(self::$socketName);
        self::$connection->send(serialize($data));
    }

    /**
     * @param $data
     * @throws Throwable
     */
    protected static function sendAnyway($data): void
    {
        self::connect(self::$socketName);
        $body = serialize($data);
        if (self::$isServerEnv) {
            self::$connection->send($body);
        } else {
            fwrite(self::$connection, self::$protocol::encode($body));
        }
    }
}
