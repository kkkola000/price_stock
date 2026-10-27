<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Support\TimezoneSettings;
$dir=sys_get_temp_dir().'/timezone-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);
function checkTimezone(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try {
    checkTimezone(TimezoneSettings::current('Europe/Moscow',$dir)==='Europe/Moscow','Config fallback');
    foreach(TimezoneSettings::identifiers() as $identifier)new DateTimeZone($identifier);
    TimezoneSettings::save('UTC',$dir);checkTimezone(TimezoneSettings::current('Europe/Moscow',$dir)==='UTC','Saved setting overrides config');
    $original=file_get_contents($dir.'/timezone.json');$rejected=false;
    try{TimezoneSettings::save('Not/A_Timezone',$dir);}catch(InvalidArgumentException){$rejected=true;}
    checkTimezone($rejected&&file_get_contents($dir.'/timezone.json')===$original,'Invalid identifier must not overwrite settings');
    file_put_contents($dir.'/timezone.json','{"timezone":false}');checkTimezone(TimezoneSettings::current('Europe/Moscow',$dir)==='Europe/Moscow','Corrupt setting fallback');
    TimezoneSettings::save('Asia/Almaty',$dir);checkTimezone(TimezoneSettings::current('UTC',$dir)==='Asia/Almaty','Second valid timezone');
    echo "PASS: timezone persistence, config fallback, invalid/corrupt values, no overwrite on validation error\n";
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
