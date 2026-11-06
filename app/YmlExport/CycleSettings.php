<?php
declare(strict_types=1);
namespace App\YmlExport;

final class CycleSettings
{
    public static function configured(): bool {return is_file(APP_STORAGE.'/yml-cycle.json');}
    public static function get(): array
    {
        $defaults=['enabled'=>false,'interval_seconds'=>300,'inactive_feeds'=>[]];
        if(!self::configured())return $defaults;
        $data=json_decode((string)file_get_contents(APP_STORAGE.'/yml-cycle.json'),true);
        if(!is_array($data))throw new \RuntimeException('Настройки расписания YML повреждены.');
        return $data+$defaults;
    }
    public static function save(array $input): void
    {
        $interval=filter_var($input['interval_seconds']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
        if($interval===false)throw new \InvalidArgumentException('Укажите целое число секунд от 1 до 2147483647.');
        $active=array_map('intval',(array)($input['active_feeds']??[]));
        $inactive=[];
        foreach(FeedRepository::list() as $feed)if(!in_array((int)$feed['id'],$active,true))$inactive[]=(int)$feed['id'];
        self::write('yml-cycle.json',['enabled'=>isset($input['enabled']),'interval_seconds'=>$interval,'inactive_feeds'=>$inactive]);
    }
    public static function feedActive(int $id): bool {return !in_array($id,array_map('intval',self::get()['inactive_feeds']),true);}
    public static function state(): array
    {
        $file=APP_STORAGE.'/yml-cycle-state.json';
        if(!is_file($file))return [];
        $data=json_decode((string)file_get_contents($file),true);
        if(!is_array($data))throw new \RuntimeException('Журнал расписания YML повреждён.');
        return $data;
    }
    public static function write(string $file,array $data): void
    {
        if(!in_array($file,['yml-cycle.json','yml-cycle-state.json'],true))throw new \InvalidArgumentException('Неверный файл расписания.');
        $temp=tempnam(APP_STORAGE,'yml-cycle-');if($temp===false)throw new \RuntimeException('Нет прав на storage.');
        try{chmod($temp,0600);if(file_put_contents($temp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false||!rename($temp,APP_STORAGE.'/'.$file))throw new \RuntimeException('Не удалось сохранить расписание.');}
        finally{if(is_file($temp))unlink($temp);}
    }
}
