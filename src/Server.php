<?php declare(strict_types=1);

/**
 * @package     Localzet Tunnel
 * @link        https://github.com/localzet/Tunnel
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Tunnel;

use localzet\Server as LocalzetServer;
use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;
use localzet\ServerAbstract;
use Throwable;

class Server extends ServerAbstract
{
    /** @var LocalzetServer */
    private LocalzetServer $server;

    /** @var Queue[] */
    protected array $queues = [];

    /**
     * @param string|null $socketName
     * @param bool $debug
     */
    public function __construct(?string $socketName = null, protected bool $debug = false)
    {
        if ($socketName) {
            localzet_start(
                name: 'Tunnel',
                count: 1,
                listen: $socketName,
                reloadable: false,
                handler: $this::class,
            );
        }
    }

    public function onServerStart(LocalzetServer &$server): void
    {
        $this->server = $server;
        $this->server->channels = [];
    }

    /**
     * @param TcpConnection $connection
     * @return void
     */
    public function onClose(ConnectionInterface &$connection): void
    {
        $this->debug && LocalzetServer::log('onClose');
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
        $this->debug && LocalzetServer::log('onMessage: ' . $request);
        if (!$request) {
            return;
        }

        try {
            $data = json_decode($request, true);
            $connection->json = true;
        } catch (Throwable $exception) {
            // $this->debug && LocalzetServer::log('Throwable: ' . $exception);
            $data = false;
        }

        if (!$data) {
            try {
                $data = unserialize($request);
                $connection->json = false;
            } catch (Throwable $exception) {
                $this->debug && LocalzetServer::log('Throwable: ' . $exception);
                return;
            }
        }

        $type = $data['type'];
        $channels = $data['channels'];
        $event_data = $data['data'] ?? null;

        switch ($type) {
            case 'subscribe':
                $this->debug && LocalzetServer::log('onMessage <<< subscribe');
                foreach ($channels as $channel) {
                    $connection->channels[$channel] = $channel;
                    $this->server->channels[$channel][$connection->id] = $connection;
                }
                break;
            case 'unsubscribe':
                $this->debug && LocalzetServer::log('onMessage <<< unsubscribe');
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
                $this->debug && LocalzetServer::log('onMessage <<< publish');
                foreach ($channels as $channel) {
                    if (empty($this->server->channels[$channel])) {
                        continue;
                    }
                    $buffer = ['type' => 'event', 'channel' => $channel, 'data' => $event_data];
                    foreach ($this->server->channels[$channel] as $connection) {
                        $buffer = $connection->json ? json_encode($buffer) : serialize($buffer);
                        $this->debug && LocalzetServer::log("onMessage[$channel]: $buffer >>> $connection->id");
                        $connection->send($buffer);
                    }
                }
                break;
            case 'publishLoop':
                $this->debug && LocalzetServer::log('onMessage <<< publishLoop');
                foreach ($channels as $channel) {
                    if (empty($this->server->channels[$channel])) {
                        continue;
                    }
                    $buffer = ['type' => 'event', 'channel' => $channel, 'data' => $event_data];

                    $connection = next($this->server->channels[$channel]);
                    if (!$connection) {
                        $connection = reset($this->server->channels[$channel]);
                    }
                    $buffer = $connection->json ? json_encode($buffer) : serialize($buffer);
                    $this->debug && LocalzetServer::log("onMessage[$channel]: $buffer >>> $connection->id");
                    $connection->send($buffer);
                }
                break;

            case 'watch':
                $this->debug && LocalzetServer::log('onMessage <<< watch');
                foreach ($channels as $channel) {
                    if (isset($this->queues[$channel])) {
                        $this->queues[$channel]->addWatch($connection);
                    } else {
                        ($this->queues[$channel] = new Queue($channel))->addWatch($connection);
                    }
                }
                break;
            case 'unwatch':
                $this->debug && LocalzetServer::log('onMessage <<< unwatch');
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
                $this->debug && LocalzetServer::log('onMessage <<< enqueue');
                foreach ($channels as $channel) {
                    if (isset($this->queues[$channel])) {
                        $this->queues[$channel]->enqueue($event_data);
                    } else {
                        $this->debug && LocalzetServer::log("onMessage[$channel]: $event_data >>> $connection->id");
                        ($this->queues[$channel] = new Queue($channel))->enqueue($event_data);
                    }
                }
                break;
            case 'reserve':
                $this->debug && LocalzetServer::log('onMessage <<< reserve');
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