<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/7/10
 * Time: 23:39
 */

namespace Lib;

use Lib;

class AsyncServer extends Server
{

    /**
     * @param callable $function
     */
    static function beforeStop(callable $function)
    {
        self::$beforeStopCallback = $function;
    }

    /**
     * @param callable $function
     */
    static function beforeReload(callable $function)
    {
        self::$beforeReloadCallback = $function;
    }
    /**
     * 自动创建对象
     * @return AsyncServer
     */
    static public function autoCreate()
    {
        return new self();
    }

    function run($setting)
    {
        if (!empty(self::$options['daemon'])) {
            \swoole_process::daemon(true,false);
        }
        if (!empty($this->processName)){
            self::setProcessTitle($this->processName);
        }
        $this->sw = new \swoole_client(SWOOLE_SOCK_TCP,SWOOLE_SOCK_ASYNC);
        $this->sw->on("Connect",[$this,"onConnect"]);
        $this->sw->on("Error",[$this,"onError"]);
        $this->sw->on("Receive",[$this,"onReceive"]);
        $this->sw->on("Close",[$this,"onClose"]);
        $this->sw->set($setting);
        $this->onWorkStart();
    }

    public function connect()
    {
        if ($this->sw->isConnected()){
            return true;
        }
        $config = $this->getConfig();
        echo "connect=>host:".$config["host"]." port:".$config["port"]."\n";
        $res = $this->sw->connect($config["host"],$config["port"],30);
        //https://wiki.swoole.com/wiki/page/30.html 修复Agent和Center网络断开重连连接不上的问题
        if($res === false){
            $this->close();
        }
        return $res;
    }

}