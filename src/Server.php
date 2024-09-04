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

use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Frame;

/**
 * Tunnel\Server
 */
class Server extends \localzet\Server
{
    public string $name = 'Tunnel';
    public int $count = 1;
    protected array $channels = [];
    public ?string $protocol = Frame::class;

    /**
     * @var Queue[]
     */
    protected array $_queues = array();

    /**
     * @param string $ip
     * @param int $port
     */
    public function __construct(string $ip = '0.0.0.0', int $port = 2206)
    {
        if (!str_contains($ip, 'unix:')) {
            $server = parent::__construct("frame://$ip:$port");
        } else {
            $server = parent::__construct($ip);
        }

        $server->onMessage = array($this, 'onMessage');
        $server->onClose = array($this, 'onClose');
    }

    /**
     * @param $connection
     * @return void
     */
    public function onClose($connection): void
    {
        if (!empty($connection->channels)) {
            foreach ($connection->channels as $channel) {
                unset($this->channels[$channel][$connection->id]);
                if (empty($this->channels[$channel])) {
                    unset($this->channels[$channel]);
                }
            }
        }

        if (!empty($connection->watchs)) {
            foreach ($connection->watchs as $watch) {
                if (isset($this->_queues[$watch])) {
                    $this->_queues[$watch]->removeWatch($connection);
                    if ($this->_queues[$watch]->isEmpty()) {
                        unset($this->_queues[$watch]);
                    }
                }
            }
        }
    }

    /**
     * @param TcpConnection $connection
     * @param string $data
     */
    public function onMessage(TcpConnection $connection, string $data): void
    {
        if (!$data) {
            return;
        }
        $core = $this->_core;
        $data = unserialize($data);
        $type = $data['type'];
        switch ($type) {
            case 'subscribe':
                foreach ($data['channels'] as $channel) {
                    $connection->channels[$channel] = $channel;
                    $core->channels[$channel][$connection->id] = $connection;
                }
                break;
            case 'unsubscribe':
                foreach ($data['channels'] as $channel) {
                    if (isset($connection->channels[$channel])) {
                        unset($connection->channels[$channel]);
                    }
                    if (isset($core->channels[$channel][$connection->id])) {
                        unset($core->channels[$channel][$connection->id]);
                        if (empty($core->channels[$channel])) {
                            unset($core->channels[$channel]);
                        }
                    }
                }
                break;
            case 'publish':
                foreach ($data['channels'] as $channel) {
                    if (empty($core->channels[$channel])) {
                        continue;
                    }
                    $buffer = serialize(array('type' => 'event', 'channel' => $channel, 'data' => $data['data'])) . "\n";
                    foreach ($core->channels[$channel] as $connection) {
                        $connection->send($buffer);
                    }
                }
                break;
            case 'watch':
                foreach ($data['channels'] as $channel) {
                    if (isset($this->_queues[$channel])) {
                        $this->_queues[$channel]->addWatch($connection);
                    } else {
                        ($this->_queues[$channel] = new Queue($channel))->addWatch($connection);
                    }
                }
                break;
            case 'unwatch':
                foreach ($data['channels'] as $channel) {
                    if (isset($this->_queues[$channel])) {
                        $this->_queues[$channel]->removeWatch($connection);
                        if ($this->_queues[$channel]->isEmpty()) {
                            unset($this->_queues[$channel]);
                        }
                    }
                }
                break;
            case 'enqueue':
                foreach ($data['channels'] as $channel) {
                    if (isset($this->_queues[$channel])) {
                        $this->_queues[$channel]->enqueue($data['data']);
                    } else {
                        ($this->_queues[$channel] = new Queue($channel))->enqueue($data['data']);
                    }
                }
                break;
            case 'reserve':
                if (isset($connection->watchs)) {
                    foreach ($connection->watchs as $watch) {
                        if (isset($this->_queues[$watch])) {
                            $this->_queues[$watch]->addConsumer($connection);
                        }
                    }
                }
                break;
        }
    }
}