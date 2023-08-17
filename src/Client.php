<?php
declare(strict_types=1);

/**
 * @package     Localzet Tunnel
 * @link        https://github.com/localzet/Tunnel
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
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
use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Frame;
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
     * @var TcpConnection|resource|null
     */
    protected static $_remoteConnection = null;

    /**
     * @var string|null
     */
    protected static ?string $_remoteIp = null;

    /**
     * @var int|null
     */
    protected static ?int $_remotePort = null;

    /**
     * @var int|null
     */
    protected static ?int $_reconnectTimer = null;

    /**
     * @var int|null
     */
    protected static ?int $_pingTimer = null;

    /**
     * @var array
     */
    protected static array $_events = array();

    /**
     * @var callable[]
     */
    protected static array $_queues = array();

    /**
     * @var bool
     */
    protected static bool $_isCoreEnv = true;

    /**
     * @var float
     */
    public static float $pingInterval = 25;

    /**
     * @param string $ip
     * @param int $port
     * @throws Throwable
     */
    public static function connect(string $ip = '127.0.0.1', int $port = 2206): void
    {
        if (self::$_remoteConnection) {
            return;
        }

        self::$_remoteIp = $ip;
        self::$_remotePort = $port;

        if (PHP_SAPI !== 'cli' || !class_exists('localzet\Server', false)) {
            self::$_isCoreEnv = false;
        }

        if (self::$_isCoreEnv) {
            if (!str_contains($ip, 'unix://')) {
                $conn = new AsyncTcpConnection('frame://' . self::$_remoteIp . ':' . self::$_remotePort);
            } else {
                $conn = new AsyncTcpConnection($ip);
                $conn->protocol = Frame::class;
            }

            $conn->onClose = [self::class, 'onRemoteClose'];
            $conn->onConnect = [self::class, 'onRemoteConnect'];
            $conn->onMessage = [self::class, 'onRemoteMessage'];
            $conn->connect();

            if (empty(self::$_pingTimer)) {
                self::$_pingTimer = Timer::add(self::$pingInterval, 'localzet\Tunnel\Client::ping');
            }
        } else {
            $remote = !str_contains($ip, 'unix://') ? 'tcp://' . self::$_remoteIp . ':' . self::$_remotePort : $ip;
            $conn = stream_socket_client($remote, $code, $message, 5);
            if (!$conn) {
                throw new Exception($message);
            }
        }

        self::$_remoteConnection = $conn;
    }

    /**
     * @param TcpConnection $connection
     * @param string $data
     * @throws Exception
     */
    public static function onRemoteMessage(TcpConnection $connection, string $data): void
    {
        $data = unserialize($data);
        $type = $data['type'];
        $event = $data['channel'];
        $event_data = $data['data'];

        if ($type == 'event') {
            if (!empty(self::$_events[$event])) {
                call_user_func(self::$_events[$event], $event_data);
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
     * @throws Throwable
     */
    public static function ping(): void
    {
        self::$_remoteConnection?->send('');
    }

    /**
     * @return void
     * @throws Exception
     */
    public static function onRemoteClose(): void
    {
        echo "Предупреждение канала: Соединение закрыто, попытка переподключения\n";
        self::$_remoteConnection = null;
        self::clearTimer();
        self::$_reconnectTimer = Timer::add(1, 'localzet\Tunnel\Client::connect', array(self::$_remoteIp, self::$_remotePort));
        if (self::$onClose) {
            call_user_func(Client::$onClose);
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    public static function onRemoteConnect(): void
    {
        $all_event_names = array_keys(self::$_events);
        if ($all_event_names) {
            self::subscribe($all_event_names);
        }
        self::clearTimer();

        if (self::$onConnect) {
            call_user_func(Client::$onConnect);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public static function clearTimer(): void
    {
        if (!self::$_isCoreEnv) {
            throw new Exception('localzet\\Tunnel\\Client не поддерживает метод clearTimer без WebCore.');
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
        self::$_events[$event] = $callback;
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
        self::send(array('type' => 'subscribe', 'channels' => $events));
        foreach ($events as $event) {
            if (!isset(self::$_events[$event])) {
                self::$_events[$event] = null;
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
        self::send(array('type' => 'unsubscribe', 'channels' => $events));
        foreach ($events as $event) {
            unset(self::$_events[$event]);
        }
    }

    /**
     * @param string|string[] $events
     * @param mixed $data
     * @throws Throwable
     */
    public static function publish(array|string $events, mixed $data): void
    {
        self::sendAnyway(array('type' => 'publish', 'channels' => (array)$events, 'data' => $data));
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
        self::send(array('type' => 'watch', 'channels' => $channels));

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
        self::send(array('type' => 'unwatch', 'channels' => $channels));
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
        self::sendAnyway(array('type' => 'enqueue', 'channels' => (array)$channels, 'data' => $data));
    }

    /**
     * @throws Throwable
     */
    public static function reserve(): void
    {
        self::send(array('type' => 'reserve'));
    }

    /**
     * @param $data
     * @throws Throwable
     */
    protected static function send($data): void
    {
        if (!self::$_isCoreEnv) {
            throw new Exception("localzet\\Tunnel\\Client не поддерживает метод {$data['type']} без WebCore.");
        }
        self::connect(self::$_remoteIp, self::$_remotePort);
        self::$_remoteConnection->send(serialize($data));
    }

    /**
     * @param $data
     * @throws Throwable
     */
    protected static function sendAnyway($data): void
    {
        self::connect(self::$_remoteIp, self::$_remotePort);
        $body = serialize($data);
        if (self::$_isCoreEnv) {
            self::$_remoteConnection->send($body);
        } else {
            $buffer = pack('N', 4 + strlen($body)) . $body;
            fwrite(self::$_remoteConnection, $buffer);
        }
    }
}
