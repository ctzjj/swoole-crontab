<?php


namespace Lib;


abstract class Server implements ServerInterface
{
    protected static $options = array();
    protected static $beforeStopCallback;
    protected static $beforeReloadCallback;

    public $server;

    /**
     * @var \Swoole\Client
     */
    protected $sw;
    protected $processName;

    static $optionKit;

    static $defaultOptions = array(
        'd|daemon' => '启用守护进程模式',
        'h|host:' => '中心服host',
        'p|port:' => '中心服port',
        'help' => '显示帮助界面',
    );

    public $configFromDefault = [];

    public function getConfig()
    {
        $host = "127.0.0.1";
        $port = 8901;
        if (isset($this->configFromDefault["host"])){
            $host = $this->configFromDefault["host"];
        }

        if (isset($this->configFromDefault["port"])){
            $port = $this->configFromDefault["port"];
        }
        return ["host"=>$host,"port"=>$port];
    }

    /**
     * 显示命令行指令
     */
    static public function start($startFunction)
    {
        if (!self::$optionKit) {
            Loader::addNameSpace('GetOptionKit', WEBPATH."/Lib/GetOptionKit/src/GetOptionKit");
            self::$optionKit = new \GetOptionKit\GetOptionKit;
        }

        $kit = self::$optionKit;
        foreach(self::$defaultOptions as $k => $v) {
            //解决Windows平台乱码问题
            if (PHP_OS == 'WINNT') {
                $v = iconv('utf-8', 'gbk', $v);
            }
            $kit->add($k, $v);
        }
        global $argv;
        $opt = $kit->parse($argv);

        if (isset($opt['help'])) {
            $kit->specs->printOptions("php {$argv[0]}");
            exit;
        }
        self::$options = $opt;
        $startFunction($opt);
    }

    /**
     * 设置进程名称
     * @param $name
     */
    function setProcessName($name)
    {
        $this->processName = $name;
    }

    protected static function setProcessTitle($title)
    {
        // >=php 5.5
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (extension_loaded('proctitle') && function_exists('setproctitle')) {
            @setproctitle($title);
        }
    }

    function setServer($server)
    {
        $this->server = $server;
        $server::$client = $this;
    }

    public function onWorkStart()
    {
        if (is_callable([$this->server,'onWorkStart'])){
            call_user_func([$this->server,'onWorkStart']);
        }
    }
    public function onConnect($client)
    {
        if (is_callable([$this->server,'onConnect'])){
            call_user_func([$this->server,'onConnect'], $client);
        }
    }

    public function onError($client)
    {
        if (is_callable([$this->server,'onError'])){
            call_user_func([$this->server,'onError'], $client);
        }
    }

    public function onReceive($client, $data)
    {
        if (is_callable([$this->server,'onReceive'])){
            if (!is_callable(["\\Lib\\SOAProtocol",'decode'])){
                throw new \Exception("protocol not found");
            }
            $data = SOAProtocol::decode($data);
            call_user_func([$this->server,'onReceive'], $client,$data);
        }
    }
    public function onClose($client)
    {
        if (is_callable([$this->server,'onClose'])){
            call_user_func([$this->server,'onClose'], $client);
        }
    }

    public function close()
    {
        if ($this->sw){
            $this->sw->close(true);
        }
    }

    public function call()
    {
        if (!$this->sw->isConnected()){
            return false;
        }
        $args = func_get_args();
        $function = $args[0];
        $params = array_slice($args, 1);
        $env = array_slice($args, 2);

        if (!is_callable(["\\Lib\\SOAProtocol",'encode'])){
            throw new \Exception("protocol not found");
        }
        return $this->sw->send(SOAProtocol::encode($function, $params, $env));
    }

    public function getError()
    {
        return [
            'code'=>$this->sw->errCode,
            'message'=>socket_strerror($this->sw->errCode),
        ];
    }
}