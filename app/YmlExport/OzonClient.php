<?php
declare(strict_types=1);
namespace App\YmlExport;
use RuntimeException;

final class OzonClient
{
    private const BASE='https://api-seller.ozon.ru';
    public function __construct(private string $clientId,private string $apiKey,private $transport=null) {}
    public function warehouses(): array
    {
        $cursor='';$warehouses=[];
        for($page=0;$page<100;$page++) {
            $response=$this->request('/v2/warehouse/list',['cursor'=>$cursor,'limit'=>100,'warehouse_ids'=>[]]);
            $data=$response['result']??$response;
            if(!is_array($data))throw new RuntimeException('Ozon вернул неожиданный формат списка складов.');
            $rows=$data['warehouses']??(array_is_list($data)?$data:null);
            if(!is_array($rows))throw new RuntimeException('Ozon вернул неожиданный формат списка складов.');
            foreach($rows as $row) {
                $id=(int)($row['warehouse_id']??0);if($id<=0)throw new RuntimeException('В ответе Ozon отсутствует warehouse_id.');
                $warehouses[$id]=['warehouse_id'=>$id,'name'=>(string)($row['name']??$row['warehouse_name']??$id)];
            }
            $next=(string)($data['next_cursor']??$data['cursor']??$response['next_cursor']??$response['cursor']??'');
            $hasNext=(bool)($data['has_next']??$response['has_next']??($next!==''&&$next!==$cursor&&$rows!==[]));
            if(!$hasNext)return array_values($warehouses);
            if($next===''||$next===$cursor)throw new RuntimeException('Ozon не вернул новый курсор списка складов.');
            $cursor=$next;
        }
        throw new RuntimeException('Превышено число страниц списка складов Ozon.');
    }
    public function stocks(array $stocks): array
    {
        if($stocks===[]||count($stocks)>100)throw new RuntimeException('В одном запросе допустимо от 1 до 100 остатков.');
        return $this->request('/v2/products/stocks',['stocks'=>$stocks]);
    }
    private function request(string $path,array $body): array
    {
        if($this->clientId===''||$this->apiKey===''||preg_match('/[\r\n\x00]/',$this->clientId.$this->apiKey))throw new RuntimeException('Не настроены данные API Ozon.');
        if($this->transport!==null)$response=($this->transport)($path,$body);
        else {
            $curl=curl_init(self::BASE.$path);$buffer='';
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Client-Id: '.$this->clientId,'Api-Key: '.$this->apiKey],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$buffer):int {if(strlen($buffer)+strlen($chunk)>2097152)return 0;$buffer.=$chunk;return strlen($chunk);}]);
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
            if($ok===false)throw new RuntimeException('Не удалось связаться с Ozon. Проверьте исходящий HTTPS-доступ к api-seller.ozon.ru.');
            $response=['status'=>$status,'body'=>$buffer];
        }
        try{$data=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new RuntimeException('Ozon вернул некорректный JSON (HTTP '.(int)$response['status'].').');}
        if($response['status']<200||$response['status']>=300) {
            $message=(string)($data['message']??'Ошибка запроса');
            $message=str_replace([$this->apiKey,$this->clientId],['[скрыто]','[скрыто]'],$message);
            throw new RuntimeException('Ozon HTTP '.(int)$response['status'].': '.mb_substr($message,0,400));
        }
        if(!is_array($data))throw new RuntimeException('Ozon вернул неожиданный ответ.');
        array_walk_recursive($data,function(&$value):void {if(is_string($value))$value=str_replace($this->apiKey,'[скрыто]',$value);});
        return $data;
    }
}
