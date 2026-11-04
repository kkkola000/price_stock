<?php
declare(strict_types=1);
namespace App\Support;

/** Server-side session access; code changes revoke all previous grants. */
final class PurchaseAccess
{
    private static function hash(): string
    {
        $path=APP_STORAGE.'/purchase-access.json';
        if(!is_file($path))return '';
        $data=json_decode((string)file_get_contents($path),true);
        if(!is_array($data)||!is_string($data['hash']??null))throw new \RuntimeException('Не удалось прочитать настройку доступа.');
        return $data['hash'];
    }
    public static function configured(): bool {return self::hash()!=='';}
    public static function allowed(): bool
    {
        $hash=self::hash();
        return $hash!==''&&hash_equals(hash('sha256',$hash),(string)($_SESSION['purchase_access']??''));
    }
    public static function hide(): void {unset($_SESSION['purchase_access']);}
    public static function save(string $code,bool $disable=false): void
    {
        if(!$disable&&(mb_strlen($code)<4||strlen($code)>128))throw new \InvalidArgumentException('Код должен содержать от 4 до 128 символов.');
        $tmp=tempnam(APP_STORAGE,'purchase-');
        if($tmp===false)throw new \RuntimeException('Нет прав на запись в storage.');
        try {
            chmod($tmp,0600);
            $hash=$disable?'':password_hash($code,PASSWORD_DEFAULT);
            if(file_put_contents($tmp,json_encode(['hash'=>$hash],JSON_THROW_ON_ERROR))===false||!rename($tmp,APP_STORAGE.'/purchase-access.json'))throw new \RuntimeException('Не удалось сохранить код доступа.');
        }finally{if(is_file($tmp))unlink($tmp);}
        self::hide();
    }
    public static function unlock(string $code): bool
    {
        // Shared IP counter prevents bypass by opening new sessions. Never store the submitted code.
        $path=APP_STORAGE.'/tmp/purchase-limit-'.hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'local')).'.json';
        $lock=fopen($path,'c+');
        if($lock===false||!flock($lock,LOCK_EX))throw new \RuntimeException('Не удалось проверить доступ.');
        try {
            $state=json_decode(stream_get_contents($lock),true)?:[];$now=time();
            if((int)($state['until']??0)<=$now)$state=['attempts'=>0,'until'=>$now+300];
            if((int)$state['attempts']>=5)throw new \OverflowException('Слишком много попыток. Повторите через 5 минут.');
            $hash=self::hash();$ok=$hash!==''&&strlen($code)<=128&&password_verify($code,$hash);
            $state['attempts']=$ok?0:(int)$state['attempts']+1;
            rewind($lock);ftruncate($lock,0);fwrite($lock,json_encode($state,JSON_THROW_ON_ERROR));
            if($ok){session_regenerate_id(true);$_SESSION['purchase_access']=hash('sha256',$hash);}
            return $ok;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
