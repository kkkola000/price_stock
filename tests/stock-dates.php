<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Import\{ImportService,ValueParser};
use App\Import\Readers\{XlsxReader,XlsReader,ExcelDateFormat};
use App\Models\{SourceRepository,ProductRepository};
use App\YmlExport\{StockMapRepository,UploadImporter};
use App\View\Catalog;
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$paths=[];$ids=[];
try {
    foreach(['05.11','05.11.2026','05/11/2026','05-11-26'] as $raw){
        $stock=ValueParser::stock($raw,'в наличии','нет');
        check($stock['text']===$raw && $stock['qty']===null,'Date must stay text: '.$raw);
        check(StockMapRepository::resolve($stock['qty'],$stock['text'],[],0)===0,'Date is not numeric feed stock');
        check(StockMapRepository::resolve($stock['qty'],$stock['text'],[['pattern'=>$raw,'qty'=>7]],0)===7,'Explicit date mapping');
    }
    check(ValueParser::stock('10','есть','нет')['qty']===10,'Integer stock unchanged');
    check(ValueParser::stock('0','есть','нет')['qty']===0,'Zero stock unchanged');
    check(ValueParser::stock('5,5','есть','нет')['qty']===6,'Decimal stock unchanged');
    check(ExcelDateFormat::render('46331.0','dd.mm')==='05.11','Reported serial');
    check(ExcelDateFormat::render('46331','dd.mm.yyyy')==='05.11.2026','Full date');
    check(ExcelDateFormat::render('46331','dd\\.mm')==='05.11','Escaped punctuation');
    check(ExcelDateFormat::render('46331','[$-419]dd.mm')==='05.11','Locale annotation');
    check(ExcelDateFormat::render('44869','dd.mm',true)==='05.11','1904 epoch');
    check(ExcelDateFormat::render('46331.5','dd.mm.yyyy hh:mm')==='05.11.2026 12:00','Date-time');
    check(ExcelDateFormat::render('10','0.00')===null,'Numeric format not a date');
    $file='stock-dates-'.bin2hex(random_bytes(6));
    $xlsx=APP_STORAGE.'/uploads/'.$file.'.xlsx';$paths[]=$xlsx;
    $zip=new ZipArchive();check($zip->open($xlsx,ZipArchive::CREATE)===true,'Create XLSX');
    $zip->addFromString('xl/workbook.xml','<workbook><workbookPr date1904="0"/><sheets><sheet name="Stock"/></sheets></workbook>');
    $zip->addFromString('xl/styles.xml','<styleSheet><numFmts count="1"><numFmt numFmtId="164" formatCode="dd.mm"/></numFmts><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>date-stock</t></is></c><c r="B1" s="1"><v>46331.0</v></c><c r="C1" s="1"><f>B1</f><v>46331</v></c><c r="D1" t="inlineStr"><is><t>05.11</t></is></c><c r="E1"><v>10</v></c></row></sheetData></worksheet>');$zip->close();
    $rows=(new XlsxReader($xlsx))->preview();
    check($rows[0]===['date-stock','05.11','05.11','05.11','10'],'XLSX: formatted date, cached formula, text and quantity');

    // BIFF records exercise NUMBER, RK, MULRK and cached FORMULA with real XF/FORMAT metadata.
    $record=static fn(int $id,string $data): string=>pack('vv',$id,strlen($data)).$data;
    $reader=new XlsReader('unused');
    $globals=$record(0x0809,pack('vv',0x0600,5)).$record(0x0022,pack('v',0))
        .$record(0x041E,pack('vvC',164,5,0).'dd.mm').$record(0x00E0,pack('vv',0,164)).$record(0x000A,'');
    $sheet=$record(0x0203,pack('vvv',0,0,0).pack('e',46331.0))
        .$record(0x027E,pack('vvvV',0,1,0,(46331<<2)|2))
        .$record(0x00BD,pack('vvvVv',0,2,0,(46331<<2)|2,2))
        .$record(0x0006,pack('vvv',0,3,0).pack('e',46331.0)).$record(0x000A,'');
    (new ReflectionProperty(XlsReader::class,'stream'))->setValue($reader,$globals.$sheet);
    (new ReflectionMethod(XlsReader::class,'readGlobals'))->invoke($reader);
    $xlsRows=iterator_to_array((new ReflectionMethod(XlsReader::class,'readSheet'))->invoke($reader,strlen($globals)));
    check($xlsRows[0]===['05.11','05.11','05.11','05.11'],'XLS: NUMBER/RK/MULRK/FORMULA dates');
    $item=(new ReflectionMethod(UploadImporter::class,'buildItem'))->invoke(null,['SKU','Name',$xlsRows[0][0]],['sku'=>'A','name'=>'B','stock'=>'C']);
    check($item['stock_text']==='05.11'&&$item['stock_qty']===null,'Warehouse date stock');

    $csv=APP_STORAGE.'/uploads/'.$file.'.csv';$paths[]=$csv;file_put_contents($csv,"date-stock;05.11\n");
    foreach(['excel'=>$xlsx,'csv'=>$csv] as $type=>$path){
        $id=SourceRepository::create(['name'=>'Date stock test '.$file,'type'=>$type,'fetch_method'=>'upload','file_path'=>basename($path),
            'skip_rows'=>0,'csv_delimiter'=>';','mapping'=>['sku'=>'A','name'=>'A','stock'=>'B']]);$ids[]=$id;
        $source=SourceRepository::find($id);$preview=ImportService::preview($source);
        check($preview['mapped'][0]['stock_text']==='05.11'&&$preview['mapped'][0]['stock_qty']===null,$type.' preview');
        $result=ImportService::run($source);check($result['status']==='ok',$type.' import');
        $product=ProductRepository::search(['suppliers'=>[$id]])['items'][0];
        check($product['stock_text']==='05.11'&&$product['stock_qty']===null,$type.' stored date');
        check(str_contains(Catalog::card($product),'05.11'),$type.' rendered date');
    }
    echo "PASS: serial 46331.0 -> 05.11 in XLSX and XLS, formulas, CSV, preview/import/render, date excluded from numeric stock and explicit mappings preserved\n";
} finally {
    foreach($ids as $id)SourceRepository::delete($id);
    foreach($paths as $path)if(is_file($path))unlink($path);
}
