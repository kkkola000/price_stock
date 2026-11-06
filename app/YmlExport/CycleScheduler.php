<?php
declare(strict_types=1);
namespace App\YmlExport;

final class CycleScheduler
{
    public static function nextDue(array $settings,array $state,?int $now=null): ?int
    {
        if(empty($settings['enabled']))return null;
        $now=$now??time();$last=max((int)($state['started']??0),(int)($state['finished']??0));
        return $last===0?$now:max($last+(int)$settings['interval_seconds'],(int)($state['retry_after']??0));
    }
    /** $work and $now allow a deterministic test without external API calls. */
    public static function tick(bool $force=false,?callable $work=null,?int $now=null): array
    {
        $clock=$now!==null;$now=$now??time();$lock=null;
        $empty=['uploads'=>0,'failed'=>0,'feeds'=>0,'offers'=>0,'ozon'=>['status'=>'disabled']];
        try{
            $settings=CycleSettings::get();$next=self::nextDue($settings,CycleSettings::state(),$now);
            if(!$force&&$next===null)return $empty+['status'=>'disabled'];
            if(!$force&&$next>$now)return $empty+['status'=>'not_due','next_run'=>$next];
            $lock=fopen(APP_STORAGE.'/yml-cycle.lock','c');
            if($lock===false||!flock($lock,LOCK_EX|LOCK_NB))return $empty+['status'=>'busy'];
            $settings=CycleSettings::get();$state=CycleSettings::state();$next=self::nextDue($settings,$state,$now);
            if(!$force&&$next===null)return $empty+['status'=>'disabled'];
            if(!$force&&$next>$now)return $empty+['status'=>'not_due','next_run'=>$next];
            CycleSettings::write('yml-cycle-state.json',['started'=>$now,'finished'=>(int)($state['finished']??0),'status'=>'running']);
            try{
                $stats=$work!==null?$work():AutoUpdater::execute(true);
                $ok=(int)$stats['failed']===0&&!in_array($stats['ozon']['status']??'',['error','busy','backoff'],true);
                $state=['started'=>$now,'finished'=>$clock?$now:time(),'status'=>$ok?'ok':'error','result'=>$stats];
            }catch(\Throwable $exception){
                $stats=$empty;$state=['started'=>$now,'finished'=>$clock?$now:time(),'status'=>'error','error'=>$exception->getMessage()];
            }
            $state['retry_after']=$state['status']==='error'?$state['finished']+max(60,(int)$settings['interval_seconds']):0;
            CycleSettings::write('yml-cycle-state.json',$state);
            return $stats+['status'=>$state['status'],'next_run'=>self::nextDue($settings,$state,$state['finished']),'message'=>$state['error']??''];
        }catch(\Throwable $exception){return $empty+['status'=>'error','message'=>$exception->getMessage()];}
        finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    }
}
