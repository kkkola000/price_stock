<?php
declare(strict_types=1);
namespace App\Support;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Local runtime preference, read before PHP/DB/scheduler initialization. No secrets are stored. */
final class TimezoneSettings
{
    public static function identifiers(): array
    {
        static $identifiers=null;
        if($identifiers===null) {
            $identifiers=array_values(array_filter(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),static function(string $identifier):bool {
                try {new DateTimeZone($identifier);return true;}catch(\Exception){return false;}
            }));
        }
        return $identifiers;
    }

    public static function current(string $fallback, ?string $directory=null): string
    {
        $path=($directory??APP_STORAGE).'/timezone.json';
        if(is_file($path)) {
            $data=json_decode((string)@file_get_contents($path),true);
            if(is_array($data)&&is_string($data['timezone']??null)&&in_array($data['timezone'],self::identifiers(),true))return $data['timezone'];
        }
        return in_array($fallback,self::identifiers(),true)?$fallback:'UTC';
    }

    public static function save(string $timezone, ?string $directory=null): void
    {
        if(!in_array($timezone,self::identifiers(),true))throw new InvalidArgumentException('Выберите корректный часовой пояс из списка.');
        $directory=$directory??APP_STORAGE;
        if(!is_dir($directory)||!is_writable($directory))throw new RuntimeException('Нет прав на запись в storage.');
        $temp=tempnam($directory,'timezone-');
        if($temp===false)throw new RuntimeException('Не удалось сохранить часовой пояс: проверьте права на storage.');
        try {
            if(file_put_contents($temp,json_encode(['timezone'=>$timezone],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Не удалось записать настройку часового пояса.');
            chmod($temp,0640);
            if(!rename($temp,$directory.'/timezone.json'))throw new RuntimeException('Не удалось применить настройку часового пояса.');
        } finally {if(is_file($temp))@unlink($temp);}
    }
}
