<?php
/**
 * worker服务中  新创建一个进程去执行命令
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 16-8-22
 * Time: 下午6:04
 */

namespace Lib;

class Process
{
    /**
     * @var \SplQueue
     */
    static protected $logs = null;

    const PROCESS_START = 0;//程序开始运行
    const PROCESS_STOP = 1;//程序结束运行

    public $task;
    static public $process_list = [];
    private static $process_stdout = [];
    private static $task_list = [];
    private static $max_stdout = 10240;


    /**
     * 注册信号
     */
    public static function signal()
    {
        if (\Swoole\Coroutine::getCid() !== -1) {
            return self::coSignalRegister();
        }
        return self::signalRegister();
    }

    /**
     * 异步版信号监听
     */
    private static function signalRegister() {
        \swoole_process::signal(SIGCHLD, function ($sig) {
            //必须为false，非阻塞模式
            while ($ret = \swoole_process::wait(false)) {
                $pid = $ret['pid'];
                if (isset(self::$task_list[$pid])){
                    $task = self::$task_list[$pid];
                    self::$task_list[$pid]["status"] = self::PROCESS_STOP;
                    self::$task_list[$pid]["end"] = microtime(true);
                    self::$task_list[$pid]["code"] = $ret["code"];
                    self::log($task["runid"], $task["taskId"], "进程运行完成,输出值",
                        isset(self::$process_stdout[$pid]) ? self::$process_stdout[$pid] : "");
                    swoole_event_del($task["pipe"]);
                    self::$process_list[$pid]->close();
                    unset(self::$process_list[$pid]);
                    unset(self::$process_stdout[$pid]);
                }
            }
        });
        return true;
    }

    /**
     * 协程版信号监听
     */
    private static function coSignalRegister() {
        // 这里用的不是fork，所以主进程没有信号，使用定时器进行监听
        \Swoole\Timer::tick(80, function(){
            // 轮询效率较低，先用吧，后面改成事件驱动
            foreach (self::$process_list as $pid => $proc) {
                if (self::$task_list[$pid]["status"] !== self::PROCESS_STOP) {
                    continue;
                }
                $task = self::$task_list[$pid];
                self::log($task["runid"], $task["taskId"], "进程运行完成,输出值",
                    isset(self::$process_stdout[$pid]) ? self::$process_stdout[$pid] : "");
                echo "unset->$pid \n";
                unset(self::$process_list[$pid]);
                unset(self::$process_stdout[$pid]);
            }

        });
        return true;
    }

    /**
     * 判断超时
     */
    public static function timeout()
    {
        if (!empty( self::$task_list)){
            foreach ( self::$task_list as $pid=>$task){
                if (!empty($task["timeout"]) && ($task["start"])+($task["timeout"]) < time()){
                    if (\swoole_process::kill($pid,0)){
                        \swoole_process::kill($pid,SIGTERM);
                        self::log($task["runid"], $task["taskId"], "该任务已经执行超时,已发送结束任务信号");
                    }
                }
            }
        }
    }

    /**
     * 检查agent是否可以结束
     */
    public static function check_exit()
    {
        if (Agent::$is_close){
            if (count(self::$task_list) == 0 && count(self::$process_list) == 0){
                echo date("Y-m-d H:i:s")." 服务端已发送强制关闭命令,任务处理完毕,结束进程\n";
                exit();
            }
        }
    }

    /**
     * 通知中心任务执行结果
     * @return bool
     */
    public static function notify()
    {
        //上报任务执行日志
        self::pushLog();
        //上报进程运行状态
        if (count(self::$task_list) > 0) {
            $procs = [];
            foreach (self::$task_list as $pid => $process) {
                if ($process["status"] == self::PROCESS_STOP) {
                    $procs[$pid] = [
                        "taskId" => $process["taskId"],
                        "runid" => $process["runid"],
                        "start" => $process["start"],
                        "end" => $process["end"],
                        "code" => $process["code"],
                    ];
                }
            }
            if (empty($procs)) {
                goto end;
            }
            $ret = Agent::$client->call("App\\Agent::notify", $procs);
            if (empty($ret)) {
                $error = Agent::$client->getError();
                echo("tasks通知中心服失败,code:" . $error['code'] . ",msg:" . $error['message'] . "\n");
                self::check_exit();
                return false;
            }
            foreach ($procs as $pid => $v) {
                unset(self::$task_list[$pid]);
            }
        }
        end:
        self::check_exit();
        return true;
    }

    /**
     * 创建一个子进程
     * @param $task
     * @return bool
     */
    public static function create_process($task)
    {
        $cls = new self();
        $cls->task = $task;
        $process = new \swoole_process(array($cls, "run"), true, true);
        $pid = $process->start();
        if ($pid) {
            swoole_event_add($process->pipe, function ($pipe) use ($pid) {
                if (!isset(self::$process_stdout[$pid])) {
                    self::$process_stdout[$pid] = "";
                }
                $tmp = self::$process_list[$pid]->read();
                $len = strlen(self::$process_stdout[$pid]);
                if ($len + strlen($tmp) <= self::$max_stdout) {
                    self::$process_stdout[$pid] .= $tmp;
                }
            });
            self::log($task["runid"], $task["id"], "进程开始执行", $task);
            self::$task_list[$pid] = [
                "taskId" => $task["id"],
                "runid" => $task["runid"],
                "timeout" => $task["timeout"],
                "status" => self::PROCESS_START,
                "start" => microtime(true),
                "pipe" => $process->pipe
            ];
            self::$process_list[$pid] = $process;
            return true;
        } else {
            self::log($task["runid"], $task["id"], "创建子进程失败", $task);
        }
        return false;
    }

    /**
     * 子进程执行的入口
     * @param $worker
     */
    public function run($worker)
    {
        foreach (self::$process_list as $p) {
            $p->close();
        }
        self::$process_list = [];
        $exec = $this->task["execute"];
        @$worker->name($exec . "#" . $this->task["id"]);
        $exec = explode(" ", trim($exec));
        $execfile = array_shift($exec);
        if (!self::changeUser($this->task["runuser"])) {
            echo "修改运行时用户失败\n";
            exit(101);
        }
        $worker->exec($execfile, $exec);
    }

    /**
     * @todo 修改运行时用户暂时为实现
     * @param $task
     * @return bool
     */
    static public function create_coroutine($task) {
        self::log($task["runid"], $task["id"], "协程开始执行", $task);
        $exec = $task["execute"];
        $descriptorspec = array(
            0 => array("pipe", "r"), //stdin (用fwrite写入数据给管道)
            1 => array("pipe", "w"), //stdout(用stream_get_contents获取管道输出)
            // 2 => ar并发任务超限ray("file", "/tmp/error-output.txt", "a")  //stderr(写入到文件)
        );
        $pipes = null;
        // echo $task['taskname'] . "\n";
        // 如果想要退出agent后，任务继续执行，就在命令后加&, 进程会脱离父进程被pid1接管
        $proc = proc_open($exec, $descriptorspec, $pipes);
        if(is_resource($proc)) {
            $info = proc_get_status($proc);
            \Co::create(function() use($proc, $pipes) {
                $info = proc_get_status($proc);
                echo $info['pid'] . "->\n";
                if (!isset(self::$process_stdout[$info['pid']])) {
                    self::$process_stdout[$info['pid']] = "";
                }
                $tmp = stream_get_contents($pipes[1]);
                echo $info['pid'] . "->" . $tmp;
                $len = strlen(self::$process_stdout[$info['pid']]);
                if ($len + strlen($tmp) <= self::$max_stdout) {
                    self::$process_stdout[$info['pid']] .= $tmp;
                }
                self::$task_list[$info['pid']]["status"] = self::PROCESS_STOP;
                self::$task_list[$info['pid']]["end"] = microtime(true);
                self::$task_list[$info['pid']]["code"] = $info['exitcode'];
                fclose($pipes[0]);
                fclose($pipes[1]);
                proc_close($proc);
            });

            self::log($task["runid"], $task["id"], "进程开始执行", $task);
            self::$task_list[$info['pid']] = [
                "taskId" => $task["id"],
                "runid" => $task["runid"],
                "timeout" => $task["timeout"],
                "status" => self::PROCESS_START,
                "start" => microtime(true),
                "pipe" => $pipes
            ];
            self::$process_list[$info['pid']] = $proc;
            return true;
        } else {
            self::log($task["runid"], $task["id"], "创建子进程失败", $task);
        }

        return true;
    }

    /**
     * 修改运行时用户
     * @param $user
     * @return bool
     */
    static function changeUser($user)
    {
        if (!function_exists('posix_getpwnam')) {
            trigger_error(__METHOD__ . ": require posix extension.");
            return false;
        }
        $user = posix_getpwnam($user);
        if ($user) {
            posix_setuid($user['uid']);
            posix_setgid($user['gid']);
            return true;
        } else {
            return false;
        }
    }

    /**
     * 记录
     * @param $runid
     * @param $taskid
     * @param $explain
     * @param string $msg
     */
    private static function log($runid, $taskid, $explain, $msg = "")
    {
        if (self::$logs == null){
            self::$logs = new \SplQueue();
        }
        $log = [
            "taskid" => $taskid,
            "runid" => $runid,
            "explain" => $explain,
            "msg" => is_scalar($msg) ? $msg : json_encode($msg),
            "createtime" => date("Y-m-d H:i:s"),
        ];
        self::$logs->push($log);
    }

    /**
     * 推送日志消息到中心服
     */
    private static function pushLog()
    {
        if (!empty(self::$logs) && ($count = self::$logs->count()) > 0){
            $logs = [];
            for ($n = 1;$n <= $count;$n++){
                $log = self::$logs->shift();
                if (empty($log)){
                    break;
                }
                $logs[] = $log;
            }
            if (count($logs) > 0){
                Agent::$client->call("App\\Termlog::addLogs", $logs);
            }
        }
    }
}