<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/12/11
 * Time: 1:04 PM
 */

namespace EasySwoole\EasySwoole\Actor;


use EasySwoole\EasySwoole\Trigger;
use Swoole\Coroutine\Channel;

abstract class AbstractActor
{
    private $hasDoExit = false;
    private $actorId;
    private $args;
    private $channel;
    private $tickList = [];
    abstract function onStart();
    abstract function onMessage($msg);
    abstract function onExit();

    final function __construct(string $actorId,Channel $channel,$args)
    {
        $this->actorId = $actorId;
        $this->args = $args;
        $this->channel = $channel;
    }

    function actorId()
    {
        return $this->actorId;
    }

    /*
     * 请用该方法来添加定时器，方便退出的时候自动清理定时器
     */
    function tick($time,callable $callback)
    {
        $id = swoole_timer_tick($time,$callback);
        $this->tickList[$id] = $id;
    }

    function getArgs()
    {
        return $this->args;
    }

    function getChannel():Channel
    {
        return $this->channel;
    }

    function __run()
    {
        try{
            $this->onStart();
        }catch (\Throwable $throwable){
            Trigger::getInstance()->throwable($throwable);
        }
        while (!$this->hasDoExit){
            $array = $this->channel->pop(0.1);
            if(!empty($array)){
                $msg = $array['msg'];
                if($msg == 'exit'){
                    $reply = $this->exitHandler();
                }else{
                    $reply = $this->onMessage($msg);
                }
                if($array['reply']){
                    $conn = $array['connection'];
                    fwrite($conn,Protocol::pack(serialize($reply)));
                    fclose($conn);
                }
            }
        }
    }

    /*
     * 一个actor可以自杀
     */
    protected function exit()
    {
        $this->channel->push([
            'msg'=>'exit',
            'reply'=>false
        ]);
    }

    private function exitHandler()
    {
        $reply = null;
        try{
            //清理定时器
            foreach ($this->tickList as $tickId){
                swoole_timer_clear($tickId);
            }
            $this->hasDoExit = true;
            $this->channel->close();
            $reply = $this->onExit();
            if($reply === null){
                $reply = true;
            }
        }catch (\Throwable $throwable){
            Trigger::getInstance()->throwable($throwable);
        }
        return $reply;
    }

    public static function invoke():?ActorClient
    {
        return ActorManager::getInstance()->actorClient(static::class);
    }
}