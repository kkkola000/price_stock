<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use App\Support\{Config,Db,TimezoneSettings};
use App\YmlExport\OzonScheduler;
foreach(array_slice($argv,1)as $argument)if(!in_array($argument,['--once','--watch','--help'],true)){fwrite(STDERR,"Неизвестный аргумент. Используйте --once или --watch.\n");exit(1);}
$options=getopt('',['once','watch','help']);
if(isset($options['once'],$options['watch'])){fwrite(STDERR,"Выберите --once или --watch.\n");exit(1);}
if(isset($options['help'])){echo "php bin/ozon.php --once  # проверить расписание один раз\nphp bin/ozon.php --watch # постоянно проверять интервал в секундах\n";exit(0);}
$watch=isset($options['watch']);$previous='';
do {
    $zone=TimezoneSettings::current((string)Config::get('timezone','UTC'));
    Config::load(array_replace(Config::all(),['timezone'=>$zone]));date_default_timezone_set($zone);
    $result=OzonScheduler::tick();Db::disconnect();
    $status=$result['status'];
    if(!$watch||in_array($status,['sent','error'],true)||$status!==$previous&&$status!=='not_due') {
        echo date('c').' Ozon: '.$status;
        if(isset($result['success']))echo ' · подтверждено '.$result['success'].' · не подтверждено '.$result['failed'].' · не отправлено '.$result['not_sent'];
        if(isset($result['message']))echo ' · '.$result['message'];
        if(isset($result['next_run']))echo ' · следующий запуск '.date('d.m.Y H:i:s',$result['next_run']);
        echo PHP_EOL;
    }
    $previous=$status;
    if($watch)sleep(1);
}while($watch);
exit($result['status']==='error'?1:0);
