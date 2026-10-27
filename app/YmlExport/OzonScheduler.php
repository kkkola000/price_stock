<?php
declare(strict_types=1);
namespace App\YmlExport;
use RuntimeException;

/** Unix timestamps, shared manual/automatic lock, and a fresh due check under that lock. */
final class OzonScheduler
{
    public static function nextDue(array $settings,array $state,?int $now=null): ?int
    {
        if(empty($settings['auto_enabled']))return null;
        $now=$now??time();
        if(($state['fingerprint']??'')!==OzonSettings::fingerprint($settings))return $now;
        $last=max((int)($state['last_started']??0),(int)($state['last_finished']??0));
        if($last===0)return $now;
        return max($last+max(1,(int)($settings['interval_seconds']??300)),(int)($state['retry_after']??0));
    }

    /** Caller must hold ozon-api.lock. Persist the attempt before making any external request. */
    public static function claim(array $settings,int $started): void
    {
        $old=OzonSettings::scheduleState();
        OzonSettings::write('ozon-api-schedule.json',[
            'fingerprint'=>OzonSettings::fingerprint($settings),'last_started'=>$started,
            'last_finished'=>($old['fingerprint']??'')===OzonSettings::fingerprint($settings)?(int)($old['last_finished']??0):0,
            'retry_after'=>0,'last_status'=>'running',
        ]);
    }

    public static function recordResult(array $settings,array $result,int $started,?int $finished=null): void
    {
        $finished=$finished??time();
        $ok=(int)($result['failed']??0)+(int)($result['not_sent']??0)===0&&empty($result['errors']);
        OzonSettings::write('ozon-api-schedule.json',[
            'fingerprint'=>OzonSettings::fingerprint($settings),'last_started'=>$started,'last_finished'=>$finished,
            'retry_after'=>$ok?0:$finished+max(60,(int)($settings['interval_seconds']??300)),
            'last_status'=>$ok?'ok':'error','source'=>$result['source']??'manual',
        ]);
    }

    public static function tick(?OzonClient $client=null,?int $now=null): array
    {
        $now=$now??time();$lock=null;$result=null;
        try {
            $settings=OzonSettings::get();
            $next=self::nextDue($settings,OzonSettings::scheduleState(),$now);
            if($next===null)return ['status'=>'disabled'];
            if($next>$now)return ['status'=>'not_due','next_run'=>$next];
            $lock=fopen(APP_STORAGE.'/ozon-api.lock','c');
            if($lock===false||!flock($lock,LOCK_EX|LOCK_NB))return ['status'=>'busy'];
            // Another process may have finished or settings may have changed while acquiring the lock.
            $settings=OzonSettings::get();$next=self::nextDue($settings,OzonSettings::scheduleState(),$now);
            if($next===null)return ['status'=>'disabled'];
            if($next>$now)return ['status'=>'not_due','next_run'=>$next];
            self::claim($settings,$now);
            try {
                $rows=OzonUpdater::prepare($settings);
                $result=OzonUpdater::send($client??new OzonClient($settings['client_id'],$settings['api_key']),$rows);
            }catch(\Throwable $exception){
                $result=['at'=>date('c'),'total'=>0,'success'=>0,'failed'=>0,'not_sent'=>0,'errors'=>[['offer_id'=>'Подготовка','message'=>$exception->getMessage()]]];
            }
            $result['source']='auto';$result['warehouse_id']=(int)$settings['warehouse_id'];$result['warehouse_name']=$settings['warehouse_name'];$result['client_id']=$settings['client_id'];
            // A test clock is fixed; production uses completion time so slow requests do not overlap.
            $finished=func_num_args()>=2?$now:time();
            self::recordResult($settings,$result,$now,$finished);
            OzonSettings::write('ozon-api-result.json',$result);
            $next=self::nextDue($settings,OzonSettings::scheduleState(),$finished);
            return ['status'=>empty($result['errors'])?'sent':'error','next_run'=>$next,'success'=>$result['success'],'failed'=>$result['failed'],'not_sent'=>$result['not_sent']];
        }catch(\Throwable $exception){
            return ['status'=>'error','message'=>$exception->getMessage()];
        }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    }
}
