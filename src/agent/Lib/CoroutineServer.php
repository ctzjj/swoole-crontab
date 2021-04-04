<?php


namespace Lib;


use mysql_xdevapi\Exception;

class CoroutineServer extends Server
{

    private static $errorTimes = 0;

    /**
     * 自动创建对象
     * @return CoroutineServer
     */
    static public function autoCreate()
    {
        return new self();
    }

    public function run($opt)
    {
        if (!empty(self::$options['daemon'])) {
            \swoole_process::daemon(true,false);
        }
        if (!empty($this->processName)){
            self::setProcessTitle($this->processName);
        }
        \Swoole\Runtime::enableCoroutine($flags = SWOOLE_HOOK_ALL);
        \Co\run(function() {
            $this->sw = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
            if (!$this->sw->connect($this->configFromDefault['host'], $this->configFromDefault['port'], 80)) {
                $this->onError($this->sw);
                return 0;
            }
            $this->onWorkStart();
            if ($this->sw->isConnected()) {
                $this->onConnect($this->server);
            } else {
                $this->onError($this->sw);
                return 0;
            }
            try {
                while (true) {
                    $data = $this->sw->recv(-1);
                    if (strlen($data) > 0) {
                        self::$errorTimes = 0;
                        $this->onReceive($this->sw, $data);
                    } else {
                        $this->onError($this->sw);
                        self::$errorTimes++;
                        // 失败30次尝试重新连接
                        if (self::$errorTimes >= 30) {
                            echo '错误次数过多，尝试重新连接！';
                            $this->sw->close(true);
                            $this->connect();
                        }
                        sleep(2);
                    }
                }
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        });


        // 不会执行到这里
        echo "Exited!";
    }

    public function connect()
    {
        self::$errorTimes = 0;
        if ($this->sw->isConnected()){
            return true;
        }
        $config = $this->getConfig();
        echo "connect=>host:".$config["host"]." port:".$config["port"]."\n";
        $res = $this->sw->connect($config["host"],$config["port"],80);
        //https://wiki.swoole.com/wiki/page/30.html 修复Agent和Center网络断开重连连接不上的问题
        if($res === false){
            $this->close();
            $this->onError($this->sw);
            return $res;
        }

        $this->onConnect($this->sw);
        return $res;
    }
}