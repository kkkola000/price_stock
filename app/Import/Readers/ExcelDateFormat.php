<?php
declare(strict_types=1);
namespace App\Import\Readers;

/** Display calendar dates using the workbook's format, without the server timezone. */
final class ExcelDateFormat
{
    public const BUILTIN = [14=>'mm-dd-yy',15=>'d-mmm-yy',16=>'d-mmm',17=>'mmm-yy',
        18=>'h:mm AM/PM',19=>'h:mm:ss AM/PM',20=>'h:mm',21=>'h:mm:ss',22=>'m/d/yy h:mm'];

    public static function render(string $raw, string $format, bool $date1904 = false): ?string
    {
        if(!is_numeric($raw)||!is_finite((float)$raw))return null;
        $format=explode(';',$format)[0];
        // Locale and colour annotations are not display characters.
        $format=preg_replace('/\[[^\]]*\]/u','',$format)??$format;
        preg_match_all('/"[^"]*"|\\\\.|AM\/PM|[a-zA-Z]+|./u',$format,$matches);
        $tokens=$matches[0];$hasDate=false;
        foreach($tokens as $token) {
            if(preg_match('/^(d{1,4}|m{1,4}|y{2}|y{4}|h{1,2}|s{1,2}|AM\/PM)$/i',$token))$hasDate=true;
            elseif(preg_match('/^[a-zA-Z0-9#?%@_*]+$/',$token))return null;
        }
        if(!$hasDate)return null;
        $serial=(float)$raw;
        if($serial<0||$serial>2958465)return null;
        $days=(int)floor($serial);
        $seconds=(int)round(($serial-$days)*86400);
        $base=$date1904?'1904-01-01':'1899-12-31';
        if(!$date1904&&$days>=60)$days--; // Excel's fictitious 29 February 1900.
        $date=(new \DateTimeImmutable($base,new \DateTimeZone('UTC')))->modify('+'.$days.' days')->modify('+'.$seconds.' seconds');
        $out='';$ampm=stripos($format,'AM/PM')!==false;
        foreach($tokens as $i=>$token) {
            if(str_starts_with($token,'"')){$out.=substr($token,1,-1);continue;}
            if(str_starts_with($token,'\\')){$out.=substr($token,1);continue;}
            $lower=strtolower($token);
            // m after h or before s is minutes, otherwise calendar month.
            $before=strtolower(implode('',array_slice($tokens,0,$i)));
            $after=strtolower(implode('',array_slice($tokens,$i+1)));
            $minute=in_array($lower,['m','mm'],true)&&(preg_match('/h{1,2}:?$/',$before)||preg_match('/^:?s/',$after));
            $php=match($lower){
                'd'=>'j','dd'=>'d','ddd'=>'D','dddd'=>'l',
                'm'=>$minute?'i':'n','mm'=>$minute?'i':'m','mmm'=>'M','mmmm'=>'F',
                'yy'=>'y','yyyy'=>'Y','h'=>$ampm?'g':'G','hh'=>$ampm?'h':'H',
                's','ss'=>'s','am/pm'=>'A',default=>null,
            };
            $out.=$php===null?$token:($lower==='m'&&$minute?(string)(int)$date->format('i'):$date->format($php));
        }
        return $out;
    }
}
