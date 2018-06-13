<?php
/**
 * Created by PhpStorm.
 * User: Mr.Zhou
 * Date: 2018/4/12
 * Time: 下午4:02
 */

namespace Padchat;

use Padchat\Core\Api;
use Padchat\Core\Client;
use Padchat\Core\Ioc as PadchatDi;
use AsyncClient\WebSocket;
use Padchat\Core\Logger;
use Padchat\Core\Receive;
use AsyncClient\SwooleProcess;
use Padchat\Core\TaskIoc;
use League\CLImate\CLImate as Cli;

class Bootstrap
{
    private $callFunc;
    private $config;
    private $redis;

    public function __construct()
    {
        $this->init();
        $this->config = include_once BASE_PATH . "/app/config.php";

    }

    /**
     * 初始化服务
     */
    public function init()
    {
        $accounts = file_get_contents(BASE_PATH . "/account.json");
        $accounts = json_decode($accounts, true);
        $this->callFunc = function (int $pid = 0, $index = 0) use ($accounts) {
            /** 注册配置信息 */
            PadchatDi::getDefault()->set('config', function () {
                return json_decode(json_encode($this->config));
            });
            /** 注册多彩打印 */
            PadchatDi::getDefault()->set('cli', function () {
                return new Cli;
            });
            /** 账号密码登录 */
            TaskIoc::getDefault()->set('account', $this->config['server']['is_account'] && !empty($accounts[$index]) ? $accounts[$index] : []);
            /** 注册websocket服务类 */
            PadchatDi::getDefault()->set('websocket', function () {
                $config = PadchatDi::getDefault()->get('config');
                return new WebSocket($config->server->host, $config->server->port);
            });
            /** redis注册 */
            PadchatDi::getDefault()->set('redis', function () {
                if (!$this->config['server']['cache'])
                    return false;
                $config = $this->config['redis'];
                $redis = new \Redis();
                $redis->pconnect($config['host'], $config['port']);
                !empty($config['auth']) && $redis->auth($config['auth']);
                return $redis;
            });
            /** 注册api接口类 */
            PadchatDi::getDefault()->set('api', function () {
                return new Api();
            });
            /** 注册websocket数据回调类 */
            PadchatDi::getDefault()->set('callback', function () {
                return new Callback();
            });
            /** 注入消息处理类 */
            PadchatDi::getDefault()->set('receive', function () {
                return new Receive();
            });
            /** 注入消息处理类 */
            PadchatDi::getDefault()->set('client', function () {
                return new Client();
            });
            /** 注入日志类 */
            PadchatDi::getDefault()->set('log', function () use ($pid) {
                return new Logger($pid);
            });
            /** websocket默认使用json数据发送 */
            PadchatDi::getDefault()->get('websocket')->setSendDataJsonEncode(false);
            /** 设置消息回调 */
            PadchatDi::getDefault()->get('websocket')->onMessage(function ($server, $frame) {
                /** 响应心跳 */
                if($frame->opcode === 9){
                    $server->push('',10);
                }

                $config = PadchatDi::getDefault()->get('config');
                if ($config->debug->cmd) {
                    PadchatDi::getDefault()->get('cli')->green("\n【响应数据】");
                    echo $frame->data . "\n";
                }
                if ($config->debug->response) {
                    PadchatDi::getDefault()->get('log')->responseDebug($frame->data);
                }
                PadchatDi::getDefault()->get('receive')->setParams(json_decode($frame->data));
                PadchatDi::getDefault()->get('callback')->handle();
            });
            /** 设置连接回调 */
            PadchatDi::getDefault()->get('websocket')->onConnect(function () use ($pid) {
                PadchatDi::getDefault()->get('api')->send('init');
                if ($pid) {
                    PadchatDi::getDefault()->get('cli')->green("\n启动padchat-php服务成功，pid: $pid");
                }
            });
            /** 连接服务 */
            PadchatDi::getDefault()->get('websocket')->connect();
        };
    }

    /**
     * 运行服务
     * @return array
     */
    public function run()
    {
        $call = $this->callFunc;
        $pid = [];
        /** 如果没开进程跑付，则阻塞运行。开进程可运行多个服务 */
        if (!$this->config['process']['status']) {
            $call();
            $pid[] = 0;
        } else {
            for ($i = 1; $i <= $this->config['process']['count']; $i++) {
                $process = new SwooleProcess();
                $process->init(function ($work) use ($call, $i) {
                    $call($work->pid, $i - 1);
                    //$work->exit();
                });
                $process->setProcessName('padchat-php-index-' . $i);
                $process->setDaemon(true);
                $pid[] = $pida = $process->run();
                sleep(1);
            }
        }
        return $pid;
    }

    public function stop()
    {

    }

    public function reload()
    {

    }
}