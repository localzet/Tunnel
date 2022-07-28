<?php

/**
 * @package     WebChannel Server
 * @link        https://localzet.gitbook.io
 * 
 * @author      localzet <creator@localzet.ru>
 * 
 * @copyright   Copyright (c) 2018-2020 Zorin Projects 
 * @copyright   Copyright (c) 2020-2022 NONA Team
 * 
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

namespace localzet\Channel;

use localzet\Core\Protocols\Frame;
use localzet\Core\Server as Core;

/**
 * Channel server.
 */
class Server
{
    /**
     * @var Core
     */
    protected $_core = null;

    /**
     * @var Queue[]
     */
    protected $_queues = array();

    private $ip;

    /**
     * @param string $ip
     * @param int $port
     */
    public function __construct($ip = '0.0.0.0', $port = 2206)
    {
        if (strpos($ip, 'unix:') === false) {
            $core = new Core("frame://$ip:$port");
        } else {
            $core = new Core($ip);
            $core->protocol = Frame::class;
        }
        $this->ip = $ip;
        $core->count = 1;
        $core->name = 'ChannelServer';
        $core->channels = array();
        $core->onMessage = array($this, 'onMessage');
        $core->onClose = array($this, 'onClose');
        $this->_core = $core;
    }

    /**
     * @return void
     */
    public function onClose($connection)
    {
        if (!empty($connection->channels)) {
            foreach ($connection->channels as $channel) {
                unset($this->_core->channels[$channel][$connection->id]);
                if (empty($this->_core->channels[$channel])) {
                    unset($this->_core->channels[$channel]);
                }
            }
        }

        if (!empty($connection->watchs)) {
            foreach ($connection->watchs as $channel) {
                if (isset($this->_queues[$channel])) {
                    $this->_queues[$channel]->removeWatch($connection);
                    if ($this->_queues[$channel]->isEmpty()) {
                        unset($this->_queues[$channel]);
                    }
                }
            }
        }
    }

    /**
     * @param \localzet\Core\Connection\TcpConnection $connection
     * @param string $data
     */
    public function onMessage($connection, $data)
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
                    $this->getQueue($channel)->addWatch($connection);
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
                    $this->getQueue($channel)->enqueue($data['data']);
                }
                break;
            case 'reserve':
                if (isset($connection->watchs)) {
                    foreach ($connection->watchs as $channel) {
                        if (isset($this->_queues[$channel])) {
                            $this->_queues[$channel]->addConsumer($connection);
                        }
                    }
                }
                break;
        }
    }

    private function getQueue($channel)
    {
        if (isset($this->_queues[$channel])) {
            return $this->_queues[$channel];
        }
        return ($this->_queues[$channel] = new Queue($channel));
    }
}