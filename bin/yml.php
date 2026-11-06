<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use App\YmlExport\CycleScheduler;
use App\Support\{Db,Config,TimezoneSettings};
foreach(array_slice($argv,1) as $argument)if(!in_array($argument,['--once','--watch','--help'],true)){fwrite(STDERR,"Используйте --once или --watch.\n");exit(1);}
$options=getopt('',['once','watch','help']);
if(isset($options['help'])){echo "php bin/yml.php --once\nphp bin/yml.php --watch\n";exit;}
if(isset($options['once'],$options['watch'])){fwrite(STDERR,"Выберите --once или --watch.\n");exit(1);}
$watch=isset($options['watch']);$previous='';
do{
    $zone=TimezoneSettings::current((string)Config::get('timezone','UTC'));Config::load(array_replace(Config::all(),['timezone'=>$zone]));date_default_timezone_set($zone);
    $result=CycleScheduler::tick();Db::disconnect();$status=$result['status'];
    if(!$watch||in_array($status,['ok','error'],true)||$status!==$previous&&$status!=='not_due')echo date('c').' YML: '.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    $previous=$status;if($watch)sleep(1);
}while($watch);
exit($status==='error'?1:0);
