<?php
declare(strict_types=1);
namespace App\YmlExport;
use RuntimeException;

final class OzonUpdater
{
    public static function prepare(array $settings): array
    {
        if((int)($settings['warehouse_id']??0)<=0)throw new RuntimeException('Выберите склад Ozon и сохраните настройки.');
        $feed=FeedRepository::find((int)($settings['feed_id']??0));if($feed===null)throw new RuntimeException('Источник товаров не найден.');
        $rows=FeedBuilder::apiStocks($feed);
        if($rows===[])throw new RuntimeException('Нет подтверждённых товаров для отправки. Проверьте склады выбранного фида и раздел «Товары».');
        return array_map(static fn($row)=>$row+['warehouse_id'=>(int)$settings['warehouse_id']],$rows);
    }
    public static function hash(array $rows): string {return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));}
    public static function send(OzonClient $client,array $rows): array
    {
        $result=['at'=>date('c'),'total'=>count($rows),'success'=>0,'failed'=>0,'not_sent'=>0,'errors'=>[]];$processed=0;
        foreach(array_chunk($rows,100)as $batch) {
            try {
                $response=$client->stocks($batch);$items=$response['result']??null;
                if(!is_array($items)||!array_is_list($items))throw new RuntimeException('Неожиданный формат результата обновления остатков Ozon.');
                $byId=[];foreach($items as $item){if(!is_array($item))throw new RuntimeException('Неожиданный формат позиции в ответе Ozon.');$byId[(string)($item['offer_id']??'')]=$item;}
                foreach($batch as $row) {
                    $item=$byId[$row['offer_id']]??null;
                    if($item!==null&&($item['updated']??false)===true&&empty($item['errors'])&&(!isset($item['warehouse_id'])||(int)$item['warehouse_id']===(int)$row['warehouse_id']))$result['success']++;
                    else {
                        $result['failed']++;
                        if(count($result['errors'])<100) {
                            $messages=array_map(static fn($e)=>is_array($e)?(string)($e['code']??'').': '.(string)($e['message']??''):(string)$e,(array)($item['errors']??[]));
                            $result['errors'][]=['offer_id'=>$row['offer_id'],'message'=>$messages!==[]?mb_substr(implode('; ',$messages),0,500):'Ozon не подтвердил обновление этой позиции.'];
                        }
                    }
                }
                $processed+=count($batch);
            } catch(\Throwable $exception) {
                $result['failed']+=count($batch);$processed+=count($batch);
                $result['errors'][]=['offer_id'=>'Запрос','message'=>$exception->getMessage()];
                break;
            }
        }
        $result['not_sent']=count($rows)-$processed;
        return $result;
    }
}
