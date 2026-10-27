<?php
declare(strict_types=1);
namespace App\YmlExport;
use RuntimeException;
use InvalidArgumentException;

/** Credentials stay outside the document root and Git; never return the key to the browser. */
final class OzonSettings
{
    public static function get(): array
    {
        $path=APP_STORAGE.'/ozon-api.json';
        if(!is_file($path))return ['client_id'=>'','api_key'=>'','feed_id'=>0,'warehouse_id'=>0,'warehouse_name'=>'','warehouses'=>[]];
        $value=json_decode((string)file_get_contents($path),true);
        if(!is_array($value))throw new RuntimeException('Настройки Ozon повреждены. Сохраните их заново.');
        return $value;
    }
    public static function save(array $input): array
    {
        $old=self::get();$client=trim((string)($input['client_id']??''));$key=trim((string)($input['api_key']??''));
        if($key===''&&$client!==($old['client_id']??''))throw new InvalidArgumentException('При смене Client-Id укажите API-Key этого аккаунта.');
        if($key==='')$key=(string)($old['api_key']??'');
        if(!preg_match('/^[0-9]+$/',$client)||$key===''||strlen($key)>2048||preg_match('/[\r\n\x00]/',$key))throw new InvalidArgumentException('Укажите Client-Id и API-Key Ozon.');
        $feed=(int)($input['feed_id']??0);
        if(FeedRepository::find($feed)===null)throw new InvalidArgumentException('Выберите источник товаров — ссылку фида.');
        $changed=$client!==($old['client_id']??'')||$key!==($old['api_key']??'');
        $warehouses=$changed?[]:($old['warehouses']??[]);
        $id=$changed?0:(int)($input['warehouse_id']??0);$name='';
        if($id>0) {
            foreach($warehouses as $warehouse)if((int)$warehouse['warehouse_id']===$id)$name=$warehouse['name'];
            if($name==='')throw new InvalidArgumentException('Выберите склад из списка Ozon. Сначала загрузите список складов.');
        }
        $settings=['client_id'=>$client,'api_key'=>$key,'feed_id'=>$feed,'warehouse_id'=>$id,'warehouse_name'=>$name,'warehouses'=>$warehouses];
        self::write('ozon-api.json',$settings);return $settings;
    }
    public static function storeWarehouses(array $warehouses, ?string $expectedFingerprint=null): void
    {
        $settings=self::get();
        if($expectedFingerprint!==null&&!hash_equals($expectedFingerprint,self::fingerprint($settings)))throw new RuntimeException('Настройки Ozon изменились во время загрузки. Повторите запрос.');
        $settings['warehouses']=$warehouses;
        $ids=array_map(static fn($w)=>(int)$w['warehouse_id'],$warehouses);
        if(!in_array((int)($settings['warehouse_id']??0),$ids,true)){$settings['warehouse_id']=0;$settings['warehouse_name']='';}
        foreach($warehouses as $warehouse)if((int)$warehouse['warehouse_id']===(int)($settings['warehouse_id']??0))$settings['warehouse_name']=$warehouse['name'];
        self::write('ozon-api.json',$settings);
    }
    public static function write(string $file,array $data): void
    {
        if(!in_array($file,['ozon-api.json','ozon-api-result.json'],true))throw new RuntimeException('Неверный файл настроек.');
        $temp=tempnam(APP_STORAGE,'ozon-');if($temp===false)throw new RuntimeException('Нет прав на запись в storage.');
        try {
            chmod($temp,0600);
            if(file_put_contents($temp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false||!rename($temp,APP_STORAGE.'/'.$file))throw new RuntimeException('Не удалось сохранить настройки Ozon.');
        }finally{if(is_file($temp))@unlink($temp);}
    }
    public static function lastResult(): ?array
    {
        $file=APP_STORAGE.'/ozon-api-result.json';return is_file($file)?json_decode((string)file_get_contents($file),true):null;
    }
    public static function fingerprint(array $settings): string
    {
        return hash('sha256',json_encode([$settings['client_id'],$settings['api_key'],$settings['warehouse_id'],$settings['feed_id']],JSON_THROW_ON_ERROR));
    }
}
