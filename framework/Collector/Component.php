<?php

namespace Framework\Collector;

use Channel\Client;
use Channel\Server;
use Framework\Base\Application;
use Workerman\Timer;
use Workerman\Worker;

class Component implements \Framework\Base\Component
{

    private static $ip;
    private static $port;

    public static function provide()
    {
        $config = require BASE_DIR.'/config/collect.php';

        if (!$config['enable']) {
            return;
        }

        //不指定 channel 时将启动自己的 channel
        if ($config['channel_server'] === null) {
            new Server();
            $ip = '127.0.0.1';
            $port = 2206;
        } else {
            list($ip, $port) = explode(':', $config['channel_server']);
        }

        self::$ip = $ip;
        self::$port = $port;
    }

    public static function start()
    {
        if (!self::$ip) {
            return;
        }

        //延迟启动，减少启动时 Server 未启动而重连的现象
        Timer::add(1, function() {
            Client::connect(self::$ip, self::$port);

            //收到请求后运行，并通过事件反馈请求
            Client::on(Collector::class, function($event) {
                list($collector) = explode('@', $event);
                $workerInfo = Application::getInstance()->getWorkerInfo();

                Worker::log("[Collector] Worker {$workerInfo['id']} received $event request");

                /* @var $res Collector */
                $res = new $collector;
                $res->collect();
                $res->pid = posix_getpid();
                $res->workerId = $workerInfo['id'];
                $res->workerName = $workerInfo['name'];

                Client::publish($event, $res);
            });
        }, [], false);
    }

}