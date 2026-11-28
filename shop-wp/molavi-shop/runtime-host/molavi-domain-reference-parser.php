<?php
/** Plugin Name: Molavi Native Structured Reference Parser */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_normalize_name(string $value): string {
    $value=strtr(molavi_domain_digits($value),['ي'=>'ی','ك'=>'ک']);
    $value=preg_replace('/[^\pL\pN_\s]/u',' ',$value);
    $value=trim(preg_replace('/\s+/u',' ',$value));
    return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
}
function molavi_domain_measure_pattern(string $kind): string {
    $number='(?P<number>(?:(?:\d{1,3}(?:,\d{3})+|\d{1,6})(?:\s*(?:/|⁄)\s*\d{1,4}|\s*[.٫]\s*\d{1,4})?|(?<!\w)(?:یک|دو|سه|چهار|پنج|شش|هفت|هشت|نه|ده)(?!\w)))';
    $unit=$kind==='volume'?'milliliters?|millilitres?|liters?|litres?|میلی\s*[\x{200c}\s]*لیتر|میلیلیتر|میلی\s*لیتری|سی\s*[\x{200c}\s]*سی|لیتر(?:ی)?|لیتری|میل|مل|ml|cc|l|ل'
        :'milligrams?|kilograms?|grams?|میلی\s*[\x{200c}\s]*گرم|میلیگرم|کیلو\s*[\x{200c}\s]*گرمی|کیلو\s*[\x{200c}\s]*گرم|کیلوگرم|کیلویی|کیلو|گرمی|گرم|mg|kg|g|گ';
    return '~(*UCP)(?<!\d)'.$number.'\s*(?P<unit>'.$unit.')(?![\w])~ui';
}
function molavi_domain_fraction(string $raw): ?array {
    $raw=str_replace([' ',',','ي','ك'],['','','ی','ک'],molavi_domain_digits($raw));
    $words=['یک'=>1,'دو'=>2,'سه'=>3,'چهار'=>4,'پنج'=>5,'شش'=>6,'هفت'=>7,'هشت'=>8,'نه'=>9,'ده'=>10];
    if(isset($words[$raw]))return [$words[$raw],1];
    if(str_contains($raw,'⁄')){$parts=explode('⁄',$raw,2);$numerator=(int)$parts[0];$denominator=(int)$parts[1];}
    else{$raw=str_replace(['/','٫'],'.',$raw);if(!preg_match('/^\d+(?:\.\d+)?$/D',$raw))return null;
        $parts=explode('.',$raw,2);$denominator=isset($parts[1])?10**strlen($parts[1]):1;$numerator=(int)str_replace('.','',$raw);}
    if($denominator<=0||$numerator<=0||$numerator/$denominator>1000000)return null;
    $a=$numerator;$b=$denominator;while($b){$next=$a%$b;$a=$b;$b=$next;}
    return [intdiv($numerator,$a),intdiv($denominator,$a)];
}
function molavi_domain_fraction_display(array $fraction): string {
    [$n,$d]=$fraction;$reduced=$d;while($reduced%2===0)$reduced=intdiv($reduced,2);while($reduced%5===0)$reduced=intdiv($reduced,5);
    if($reduced!==1)return $n.'/'.$d;
    if($d===1)return (string)$n;
    $out=(string)intdiv($n,$d).'.';$remainder=$n%$d;
    while($remainder){$remainder*=10;$out.=(string)intdiv($remainder,$d);$remainder%=$d;}
    return rtrim($out,'.');
}
function molavi_domain_measure_unit(string $raw,string $kind): ?string {
    $key=strtolower(preg_replace('/[\s\x{200c}]+/u','',molavi_domain_digits($raw)));
    $units=$kind==='volume'?[ 'لیتر'=>['ل','l','لیتر','لیتری','liter','liters','litre','litres'],
        'میل'=>['میلیلیتر','میل','مل','ml','cc','سیسی','milliliter','milliliters','millilitre','millilitres'] ]
        :['کیلوگرم'=>['کیلو','کیلوگرم','کیلوگرمی','کیلویی','kg','kilogram','kilograms'],
          'میلی‌گرم'=>['میلیگرم','mg','milligram','milligrams'],'گرم'=>['گرم','گرمی','گ','g','gram','grams']];
    foreach($units as $label=>$aliases)if(in_array($key,$aliases,true))return $label;return null;
}
function molavi_domain_measurement_matches(string $text,string $kind): array {
    preg_match_all(molavi_domain_measure_pattern($kind),$text,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);$out=[];
    foreach($matches as $match){$value=molavi_domain_fraction($match['number'][0]);$unit=molavi_domain_measure_unit($match['unit'][0],$kind);
        if($value!==null&&$unit!==null)$out[]=['start'=>$match[0][1],'length'=>strlen($match[0][0]),'display'=>molavi_domain_fraction_display($value).' '.$unit];}
    return $out;
}
function molavi_domain_extract_measurement(string $text,string $kind): string {
    $source=strtr(molavi_domain_digits($text),['ي'=>'ی','ك'=>'ک']);$values=[];
    foreach(molavi_domain_measurement_matches($source,$kind) as $match)if(!in_array($match['display'],$values,true))$values[]=$match['display'];
    return implode('، ',$values);
}
function molavi_domain_clean_title(string $text): string {
    $matches=array_merge(molavi_domain_measurement_matches($text,'volume'),molavi_domain_measurement_matches($text,'weight'));
    usort($matches,static fn($a,$b)=>$b['start']<=>$a['start']);
    foreach($matches as $match)$text=substr_replace($text,$match['display'],$match['start'],$match['length']);
    $text=molavi_domain_digits($text);
    $text=preg_replace('~[（(]\s*\d{1,4}\s*ف\s*[）)]~u',' ',$text);
    // Pack formats (iteration 1): `Nع` may be glued to a following digit
    // (`48ع20بسته`), and تیمز titles use `Nتا` / `Nبسته` with no `N عددی`
    // at all — the lookahead must allow a following digit (it is the
    // carton count), only a following letter blocks the match.
    $text=preg_replace('~(*UCP)(?<!\d)\d{1,4}\s*(?:عددی|عدد|ع|تا)(?=$|[^\pL])~u',' ',$text);
    $text=preg_replace('~(*UCP)(?:کارتن|باکس|بسته)\s*\d{1,4}(?=$|[^\w])~u',' ',$text);
    $text=preg_replace('~(*UCP)(?<!\d)\d{1,4}\s*(?:بسته|کارتن|باکس)(?=$|[^\pL])~u',' ',$text);
    $text=preg_replace('~[^\pL\pN_\s.\-]~u',' ',$text);
    return trim(preg_replace('/\s+/u',' ',$text));
}
function molavi_domain_title_count(string $text,bool $set=false): ?int {
    $text=molavi_domain_digits($text);$pattern=$set?'~(*UCP)(?<!\d)(\d{1,3})\s*تایی(?=$|[^\w])~u':'~(*UCP)(?<!\d)(\d{1,4})\s*(?:عددی|عدد|ع|تا)(?=$|[^\pL])~u';
    if(preg_match_all($pattern,$text,$matches))return (int)end($matches[1]);
    if(!$set&&preg_match('~(*UCP)(?:کارتن|باکس|بسته)\s*(\d{1,4})(?=$|[^\w])~u',$text,$match))return (int)$match[1];
    // تیمز pack format: the carton count follows the pack word
    // (`20تا12بسته`, `48ع20بسته` when no `N عددی` matched above) — the
    // number BEFORE بسته/کارتن/باکس is the pack count; the carton count
    // itself has no column and is not stored.
    if(!$set&&preg_match('~(*UCP)(?<!\d)(\d{1,4})\s*(?:بسته|کارتن|باکس)(?=$|[^\pL])~u',$text,$match))return (int)$match[1];
    return null;
}
function molavi_domain_reference_price_value($raw,int $minor,string $currency): ?int {
    if($raw===null||$raw===''||is_bool($raw)||!is_scalar($raw)||!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<=0)return null;
    $value=(float)$raw/(10**$minor)/($currency==='IRR'?10:1);
    if($value>PHP_INT_MAX)return null;
    $price=(int)round($value,0,PHP_ROUND_HALF_UP);
    return $price<1?null:$price;
}
/** Two-price model for the Rezvan Store API `prices` object.
 *  `price` is the effective price and still hard-fails when missing/invalid.
 *  `regular_price`/`sale_price` fail soft (null) when absent or malformed.
 *  On sale (sale < regular and price == sale): supply is the sale price and
 *  regular is the regular price. Otherwise the supply price is `price` and
 *  the API regular price is null; a genuine sale the effective price does
 *  not reflect, or a sale price at/above regular, is flagged for review
 *  instead of silently picking a value. A sale equal to regular is no sale. */
function molavi_domain_reference_price_model($prices): array {
    if(!is_array($prices))molavi_domain_fail('woocommerce_prices_missing',400);
    $currency=strtoupper(trim((string)($prices['currency_code']??'')));if(!in_array($currency,['IRR','IRT'],true))molavi_domain_fail('woocommerce_currency_unsupported',400);
    $minor=$prices['currency_minor_unit']??0;if(!is_int($minor)||$minor<0||$minor>4)molavi_domain_fail('woocommerce_currency_precision_invalid',400);
    $raw=$prices['price']??null;if($raw===null||$raw===''||is_bool($raw))molavi_domain_fail('woocommerce_prices_missing',400);
    $price=molavi_domain_reference_price_value($raw,$minor,$currency);
    if($price===null)molavi_domain_fail('woocommerce_price_invalid',400);
    $regularRaw=$prices['regular_price']??null;$saleRaw=$prices['sale_price']??null;
    $regular=molavi_domain_reference_price_value($regularRaw,$minor,$currency);
    $sale=molavi_domain_reference_price_value($saleRaw,$minor,$currency);
    $regularMalformed=$regularRaw!==null&&$regularRaw!==''&&$regular===null;
    $saleMalformed=$saleRaw!==null&&$saleRaw!==''&&$sale===null;
    $anomaly='';
    if($regular!==null&&$sale!==null){
        if($sale<$regular){if($price!==$sale)$anomaly='price_mismatch';}
        elseif($sale>$regular)$anomaly='sale_not_below_regular';
    }elseif($sale!==null){if($price!==$sale)$anomaly='price_mismatch';}
    elseif($regular!==null){if($price!==$regular)$anomaly='price_mismatch';}
    $onSale=$anomaly===''&&$regular!==null&&$sale!==null&&$sale<$regular;
    return ['price'=>$price,'currency'=>$currency,'supply_pack'=>$price,'regular_pack'=>$onSale?$regular:null,
        'anomaly'=>$anomaly,'regular_malformed'=>$regularMalformed,'sale_malformed'=>$saleMalformed];
}
function molavi_domain_reference_price($prices): array {
    $model=molavi_domain_reference_price_model($prices);
    return [$model['price'],$model['currency']];
}
function molavi_domain_reference_code(array $product): string {
    $id=$product['id']??null;if(!is_int($id)||$id<1)molavi_domain_fail('woocommerce_product_id_invalid',400);
    $sku=$product['sku']??null;if($sku===null||!is_scalar($sku)||is_bool($sku))molavi_domain_fail('woocommerce_sku_invalid',400);
    $sku=trim((string)$sku);return $sku!==''?$sku:'woo-'.$id;
}
function molavi_domain_reference_row(array $product,int $ordinal,string $timestamp): array {
    $code=molavi_domain_reference_code($product);$name=$product['name']??null;
    if(!is_string($name)||trim($name)==='')molavi_domain_fail('woocommerce_product_title_missing',400);
    $name=trim($name);$clean=molavi_domain_clean_title($name);$key=molavi_domain_normalize_name($clean);
    if($key==='')molavi_domain_fail('woocommerce_product_title_missing',400);
    $model=molavi_domain_reference_price_model($product['prices']??null);
    [$price,$currency]=[$model['price'],$model['currency']];
    if($model['regular_malformed'])error_log('[molavi-native] reference_price_malformed product='.$code.' field=regular_price');
    if($model['sale_malformed'])error_log('[molavi-native] reference_price_malformed product='.$code.' field=sale_price');
    $count=molavi_domain_title_count($name)?:0;$sets=molavi_domain_title_count($name,true)?:0;
    $consumer='';if(preg_match_all('~[（(]\s*(\d{1,4})\s*ف\s*[）)]~u',molavi_domain_digits($name),$matches))$consumer=(int)end($matches[1])*1000;
    $categories=[];foreach(is_array($product['categories']??null)?$product['categories']:[] as $category)
        if(is_array($category)&&is_string($category['name']??null)&&trim($category['name'])!==''&&!in_array(trim($category['name']),$categories,true))$categories[]=trim($category['name']);
    $attributes=['brand'=>'','variant'=>''];
    foreach(is_array($product['attributes']??null)?$product['attributes']:[] as $item){if(!is_array($item))continue;
        $label=molavi_domain_normalize_name(str_replace('_',' ',(string)(($item['name']??'')?:($item['taxonomy']??''))));
        $value=$item['value']??null;if($value===null||$value==='')$value=$item['terms']??null;
        if(is_array($value)){$values=[];foreach($value as $term){if(is_array($term))$term=($term['name']??'')?:($term['slug']??'');if(is_scalar($term)&&!is_bool($term)&&trim((string)$term)!==''&&!in_array(trim((string)$term),$values,true))$values[]=trim((string)$term);}$value=implode('، ',$values);}
        elseif(is_scalar($value)&&!is_bool($value))$value=trim((string)$value);else $value='';
        if($value==='')continue;
        if(in_array($label,['brand','brands','برند','نام برند'],true)||str_ends_with($label,' brand'))$attributes['brand']=$value;
        elseif(in_array($label,['variant','flavor','taste','نوع','طعم','مدل','رنگ'],true))$attributes['variant']=$value;
    }
    $regularPack=$model['regular_pack'];
    $regularUnit=($regularPack!==null&&$count)?intdiv($regularPack+intdiv($count,2),$count):'';
    return [$name,$clean,$key,$price,$count?intdiv($price+intdiv($count,2),$count):'',$consumer,$count?:'',$count?'عدد':'',
        molavi_domain_extract_measurement($name,'volume'),molavi_domain_extract_measurement($name,'weight'),$sets?:'',
        $attributes['variant'],$attributes['brand'],implode(' / ',$categories),'',(string)$ordinal,$code,'TOMAN',
        'rezvanpakhsh_woocommerce_store_api',molavi_domain_reference_source(),$timestamp,
        $model['supply_pack'],$count?intdiv($model['supply_pack']+intdiv($count,2),$count):'',
        $regularPack??'',$regularUnit,$model['anomaly']];
}
