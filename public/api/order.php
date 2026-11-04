<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
use App\Support\{Csrf,PurchaseAccess};
use App\Models\OrderPurchasePrices;
header('Cache-Control: no-store, private');
header('Vary: Cookie');
header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
if(!Csrf::validate($_POST['csrf_token']??null))json_response(['ok'=>false,'error'=>'Сессия устарела. Обновите страницу.'],419);
try {
    $action=(string)($_POST['action']??'');
    if($action==='unlock'){
        if(!PurchaseAccess::unlock((string)($_POST['code']??'')))json_response(['ok'=>false,'error'=>'Неверный код.'],403);
        json_response(['ok'=>true,'unlocked'=>true]);
    }
    if($action==='hide'){PurchaseAccess::hide();json_response(['ok'=>true,'unlocked'=>false]);}
    if($action==='status')json_response(['ok'=>true,'unlocked'=>PurchaseAccess::allowed(),'configured'=>PurchaseAccess::configured()]);
    if(!in_array($action,['prices','export'],true))throw new InvalidArgumentException('Неизвестное действие.');
    $items=json_decode((string)($_POST['items']??'[]'),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($items)||!array_is_list($items)||count($items)>10000)throw new InvalidArgumentException('Некорректный список заказа.');
    foreach($items as $item)if(!is_array($item)||!isset($item['id'])||!is_scalar($item['id']))throw new InvalidArgumentException('Некорректный товар.');
    if($action==='prices')json_response(['ok'=>true,'prices'=>(object)OrderPurchasePrices::forIds(array_column($items,'id'))]);
    // Ignore client flags and prices: only the current server session controls the exported column.
    $unlocked=PurchaseAccess::allowed();
    $prices=$unlocked?OrderPurchasePrices::forIds(array_column($items,'id')):[];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zakaz.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
    $columns=['Товар','Количество'];if($unlocked)$columns[]='Закупочная цена';
    fputcsv($out,$columns,';','"','');
    foreach($items as $item){
        $name=mb_substr((string)($item['name']??''),0,500);
        if(preg_match('/^[=+@\-\t\r]/u',$name))$name="'".$name;
        $row=[$name,max(1,min(9999,(int)($item['qty']??1)))];
        if($unlocked){
            $cost=$prices[(string)(int)$item['id']]??'—';
            $row[]=preg_match('/^[=+@\-\t\r]/u',$cost)?"'".$cost:$cost;
        }
        fputcsv($out,$row,';','"','');
    }
    fclose($out);exit;
}catch(OverflowException $exception){json_response(['ok'=>false,'error'=>$exception->getMessage()],429);
}catch(DomainException $exception){json_response(['ok'=>false,'error'=>$exception->getMessage()],403);
}catch(InvalidArgumentException|JsonException $exception){json_response(['ok'=>false,'error'=>'Некорректные данные заказа.'],422);
}catch(Throwable $exception){error_log('[order-api] '.$exception->getMessage());json_response(['ok'=>false,'error'=>'Не удалось обработать заказ. Попробуйте ещё раз.'],500);}
