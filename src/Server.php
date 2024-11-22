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

use localzet\Server as LocalzetServer;
use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;
use localzet\ServerAbstract;

class Server extends ServerAbstract
{
    /** @var LocalzetServer */
    private LocalzetServer $server;

    /** @var Queue[] */
    protected array $queues = [];

    /**
     * @param string|null $socketName
     */
    public function __construct(?string $socketName = null)
    {
        if ($socketName) {
            $this->server = localzet_start(
                name: 'Tunnel',
                count: 1,
                listen: $socketName,
                handler: $this::class,
            );
            $this->server->channels = [];
        }
    }

    /**
     * @param TcpConnection $connection
     * @return void
     */
    public function onClose(ConnectionInterface &$connection): void
    {
        if (!empty($connection->channels)) {
            foreach ($connection->channels as $channel) {
                unset($this->server->channels[$channel][$connection->id]);
                if (empty($this->server->channels[$channel])) {
                    unset($this->server->channels[$channel]);
                }
            }
        }

        if (!empty($connection->watchs)) {
            foreach ($connection->watchs as $watch) {
                if (isset($this->queues[$watch])) {
                    $this->queues[$watch]->removeWatch($connection);
                    if ($this->queues[$watch]->isEmpty()) {
                        unset($this->queues[$watch]);
                    }
                }
            }
        }
    }

    /**
     * @param TcpConnection $connection
     * @param mixed $request
     * @return void
     */
    public function onMessage(ConnectionInterface &$connection, mixed $request): void
    {
        if (!$request) {
            return;
        }
        $data = unserialize($request);
        $type = $data['type'];
        $channels = $data['channels'];
        $event_data = $data['data'];

        switch ($type) {
            case 'subscribe':
                foreach ($channels as $channel) {
                    $connection->channels[$channel] = $channel;
                    $this->server->channels[$channel][$connection->id] = $connection;
                }
                break;
            case 'unsubscribe':
                foreach ($channels as $channel) {
                    if (isset($connection->channels[$channel])) {
                        unset($connection->channels[$channel]);
                    }
                    if (isset($this->server->channels[$channel][$connection->id])) {
                        unset($this->server->channels[$channel][$connection->id]);
                        if (empty($this->server->channels[$channel])) {
                            unset($this->server->channels[$channel]);
                        }
                    }
                }
                break;
            case 'publish':
                foreach ($channels as $channel) {
                    if (empty($this->server->channels[$channel])) {
                        continue;
                    }
                    $buffer = serialize(['type' => 'event', 'channel' => $channel, 'data' => $event_data]);
                    foreach ($this->server->channels[$channel] as $connection) {
                        $connection->send($buffer);
                    }
                }
                break;
            case 'publishLoop':
                foreach ($channels as $channel) {
                    if (empty($this->server->channels[$channel])) {
                        continue;
                    }
                    $buffer = serialize(['type' => 'event', 'channel' => $channel, 'data' => $event_data]);

                    $connection = next($this->server->channels[$channel]);
                    if (!$connection) {
                        $connection = reset($this->server->channels[$channel]);
                    }
                    $connection->send($buffer);
                }
                break;
            case 'watch':
                foreach ($channels as $channel) {
                    if (isset($this->queues[$channel])) {
                        $this->queues[$channel]->addWatch($connection);
                    } else {
                        ($this->queues[$channel] = new Queue($channel))->addWatch($connection);
                    }
                }
                break;
            case 'unwatch':
                foreach ($channels as $channel) {
                    if (isset($this->queues[$channel])) {
                        $this->queues[$channel]->removeWatch($connection);
                        if ($this->queues[$channel]->isEmpty()) {
                            unset($this->queues[$channel]);
                        }
                    }
                }
                break;
            case 'enqueue':
                foreach ($channels as $channel) {
                    if (isset($this->queues[$channel])) {
                        $this->queues[$channel]->enqueue($event_data);
                    } else {
                        ($this->queues[$channel] = new Queue($channel))->enqueue($event_data);
                    }
                }
                break;
            case 'reserve':
                if (isset($connection->watchs)) {
                    foreach ($connection->watchs as $watch) {
                        if (isset($this->queues[$watch])) {
                            $this->queues[$watch]->addConsumer($connection);
                        }
                    }
                }
                break;
        }
    }
}