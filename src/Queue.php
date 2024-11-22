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
use SplQueue;

class Queue
{
    /** @var string */
    public string $name = 'default';

    /** @var array */
    public array $watcher = [];

    /** @var array */
    public array $consumer = [];

    /** @var SplQueue|null */
    protected ?SplQueue $queue = null;

    /** @param $name */
    public function __construct($name)
    {
        $this->name = $name;
        $this->queue = new SplQueue();
    }

    /** @param TcpConnection $connection */
    public function addWatch(TcpConnection $connection): void
    {
        if (!isset($this->watcher[$connection->id])) {
            $this->watcher[$connection->id] = $connection;
            $connection->watchs[] = $this->name;
        }
    }

    /** @param TcpConnection $connection */
    public function removeWatch(TcpConnection $connection): void
    {
        if (isset($connection->watchs) && in_array($this->name, $connection->watchs)) {
            $idx = array_search($this->name, $connection->watchs);
            unset($connection->watchs[$idx]);
        }
        if (isset($this->watcher[$connection->id])) {
            unset($this->watcher[$connection->id]);
        }
        if (isset($this->consumer[$connection->id])) {
            unset($this->consumer[$connection->id]);
        }
    }

    /** @param TcpConnection $connection */
    public function addConsumer(TcpConnection $connection): void
    {
        if (isset($this->watcher[$connection->id]) && !isset($this->consumer[$connection->id])) {
            $this->consumer[$connection->id] = $connection;
        }
        $this->dispatch();
    }

    /**
     * @param $data
     * @return void
     */
    public function enqueue($data): void
    {
        $this->queue->enqueue($data);
        $this->dispatch();
    }

    /**
     * @return void
     */
    private function dispatch(): void
    {
        if ($this->queue->isEmpty() || count($this->consumer) == 0) {
            return;
        }

        while (!$this->queue->isEmpty()) {
            $data = $this->queue->dequeue();
            $idx = key($this->consumer);
            $connection = $this->consumer[$idx];
            unset($this->consumer[$idx]);
            $connection->send(serialize(['type' => 'queue', 'channel' => $this->name, 'data' => $data]));
            if (count($this->consumer) == 0) {
                break;
            }
        }
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->watcher) && $this->queue->isEmpty();
    }
}
