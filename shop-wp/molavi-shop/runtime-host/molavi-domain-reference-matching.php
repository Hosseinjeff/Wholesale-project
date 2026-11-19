<?php
/** Plugin Name: Molavi Native Reference Candidate Matching */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_match_name($value): string {
    $text=(string)molavi_domain_py_value($value);$matches=array_merge(molavi_domain_measurement_matches($text,'volume'),molavi_domain_measurement_matches($text,'weight'));
    usort($matches,static fn($a,$b)=>$b['start']<=>$a['start']);foreach($matches as $match)$text=substr_replace($text,$match['display'],$match['start'],$match['length']);
    return molavi_domain_normalize_name($text);
}
function molavi_domain_match_number($value): int|float {
    if(is_bool($value))return 0;$raw=molavi_domain_digits(molavi_domain_py_value($value,0));$raw=preg_replace('/(?<=\d)_(?=\d)/u','',$raw);
    return is_numeric($raw)&&is_finite((float)$raw)&&(float)$raw>0?(float)$raw:0;
}
function molavi_domain_match_package(array $product): float {
    if($value=molavi_domain_match_number($product['package_count']??null))return $value;
    $text=(string)molavi_domain_py_value($product['packaging']??null,molavi_domain_py_value($product['name']??null));
    return preg_match('/(*UCP)(?<!\d)(\d{1,4})\s*(?:عددی|تایی|عدد|ف|تکه|قوطی|بسته)/u',molavi_domain_digits($text),$m)?(float)$m[1]:0.0;
}
function molavi_domain_offer_attributes(array $offer): array {
    $map=['name'=>'Product Name','brand'=>'Brand','category'=>'Category','variation_type'=>'Variation Type','package_count'=>'Package Count','packaging'=>'Packaging','volume'=>'Volume','weight'=>'Weight'];$out=[];
    foreach($map as $key=>$field)$out[$key]=molavi_domain_py_value($offer[$field]??null);return $out;
}
function molavi_domain_reference_signature(array $product): string {
    $text=static fn($value)=>molavi_domain_normalize_name((string)molavi_domain_py_value($value));$pack=molavi_domain_match_package($product);
    return molavi_domain_hash([molavi_domain_match_name(molavi_domain_py_value($product['clean_name']??null,$product['name']??'')),
        $text($product['brand']??null),$text($product['category']??null),$text(molavi_domain_py_value($product['variant']??null,$product['variation_type']??'')),
        $pack?molavi_domain_cell_text($pack):'',$text($product['volume']??null),$text($product['weight']??null)]);
}
/** RapidFuzz ratio is normalized Indel similarity: twice the Unicode LCS length. */
function molavi_domain_match_ratio(string $left,string $right): float {
    if($left===$right)return 1.0;$a=preg_split('//u',$left,-1,PREG_SPLIT_NO_EMPTY);$b=preg_split('//u',$right,-1,PREG_SPLIT_NO_EMPTY);
    if(!$a||!$b)return 0.0;if(count($a)<count($b))[$a,$b]=[$b,$a];$width=count($b);
    // Most product names fit one signed-safe machine word. The same LCS is then
    // computed with integer bit operations instead of a quadratic cell matrix.
    if(PHP_INT_SIZE>=8&&$width<=60){$masks=[];foreach($b as $index=>$character)$masks[$character]=($masks[$character]??0)|(1<<$index);$state=0;
        foreach($a as $character){$x=($masks[$character]??0)|$state;$y=($state<<1)|1;$state=$x&~($x-$y);}
        $length=0;while($state){$state&=$state-1;$length++;}return 2.0*$length/(count($a)+$width);}
    $previous=array_fill(0,$width+1,0);
    foreach($a as $character){$row=[0];for($i=1;$i<=$width;$i++)$row[$i]=$character===$b[$i-1]?$previous[$i-1]+1:max($previous[$i],$row[$i-1]);$previous=$row;}
    return 2.0*$previous[$width]/(count($a)+$width);
}
function molavi_domain_matching_reference(array $row): array {
    $reference=['reference_id'=>(string)molavi_domain_py_value($row[16],$row[2]),'name'=>molavi_domain_cell_text($row[0]),'clean_name'=>molavi_domain_cell_text(molavi_domain_py_value($row[1],$row[0]))];
    foreach(['unit_price'=>4,'package_price'=>3,'consumer_price'=>5,'package_count'=>6] as $key=>$index)$reference[$key]=molavi_domain_match_number($row[$index]);
    foreach(['unit_label'=>7,'volume'=>8,'weight'=>9,'variant'=>11,'brand'=>12,'category'=>13] as $key=>$index)$reference[$key]=(string)molavi_domain_py_value($row[$index]);return $reference;
}
function molavi_domain_match_values(array $product): array {
    $text=static fn($value)=>molavi_domain_normalize_name((string)molavi_domain_py_value($value));$pack=molavi_domain_match_package($product);
    return ['name'=>molavi_domain_match_name(molavi_domain_py_value($product['clean_name']??null,$product['name']??'')),
        'brand'=>$text($product['brand']??null),'category'=>$text($product['category']??null),'variant'=>$text(molavi_domain_py_value($product['variant']??null,$product['variation_type']??'')),
        'package_count'=>$pack?molavi_domain_cell_text($pack):'','volume'=>molavi_domain_match_name($product['volume']??null),'weight'=>molavi_domain_match_name($product['weight']??null)];
}
function molavi_domain_matching_index(array $rows): array {
    // One request-scoped snapshot, shared by every card in this response.
    static $lastRows=null,$prepared=[];if($lastRows!==null&&$rows===$lastRows)return $prepared;
    $lastRows=$rows;$prepared=[];
    foreach($rows as $row){
        if(!is_array($row)||count($row)!==21)continue;
        $reference=molavi_domain_matching_reference($row);
        if($reference['reference_id']==='')continue;
        $values=molavi_domain_match_values($reference);
        // The reference side uses the structured count. Title inference is for supplier offers.
        $values['package_count']=$reference['package_count']?molavi_domain_cell_text($reference['package_count']):'';
        $title=molavi_domain_normalize_name(preg_replace('/[（(][^）)]*[）)]/u',' ',$reference['name']));
        $prepared[]=[$reference,$values,' '.$title.' '];
    }
    return $prepared;
}
function molavi_domain_reference_candidates(array $product,array $rows,int $limit=10): array {
    $weights=['name'=>.45,'brand'=>.14,'category'=>.12,'variant'=>.10,'package_count'=>.07,'volume'=>.06,'weight'=>.06];$incoming=molavi_domain_match_values($product);if($incoming['name']==='')return [];
    if(preg_match('/^(?:در )?\d+ (?:طعم|مزه|رنگ|مدل|نوع)(?: (?:موجود|مختلف|متنوع))?$/uD',$incoming['variant']))$incoming['variant']='';
    $sources=[];foreach(['name','brand','category','variant','volume','weight'] as $key){$value=$product[$key]??null;if($key==='name')$value=molavi_domain_py_value($product['clean_name']??null,$value);
        if($key==='variant')$value=molavi_domain_py_value($value,$product['variation_type']??null);$sources[$key]=trim((string)molavi_domain_py_value($value));}$sources['package_count']=$incoming['package_count'];
    $labels=['name'=>'نام','brand'=>'برند','category'=>'دسته','variant'=>'طعم/مدل','package_count'=>'تعداد در بسته','volume'=>'حجم','weight'=>'وزن'];$scored=[];
    $brand=$incoming['brand'];$brandInSupplierTitle=$brand!==''&&str_contains(' '.$incoming['name'].' ',' '.$brand.' ');
    $identityMatches=[];
    foreach(molavi_domain_matching_index($rows) as [$reference,$right,$catalogTitle]){
        if($brandInSupplierTitle&&$right['brand']==='') {
            $catalogBrand=str_replace([' طرح '.$brand.' ',' سبک '.$brand.' ',' شبیه '.$brand.' '],' ',$catalogTitle);
            if(str_contains($catalogBrand,' '.$brand.' ')) {
                $right['brand']=$brand;
                $reference['brand']=$sources['brand'];
            }
        }
        $sameBrand=$brand!==''&&$right['brand']===$brand;
        if($sameBrand&&$incoming['variant']!==''&&$right['variant']===''
            &&str_contains(' '.$right['name'].' ',' '.$incoming['variant'].' ')) {
            $right['variant']=$incoming['variant'];$reference['variant']=$sources['variant'];
        }
        $components=[];$weighted=0.0;$denominator=0.0;
        foreach($weights as $key=>$weight){$left=$incoming[$key];if($left==='')continue;$denominator+=$weight;$score=$right[$key]!==''?molavi_domain_match_ratio($left,$right[$key]):0.0;
            $components[$key]=round($score,4,PHP_ROUND_HALF_EVEN);$weighted+=$weight*$score;}
        $score=$denominator?$weighted/$denominator:0.0;
        if(!$sameBrand&&($score<.35||($components['name']??0)<.35))continue;
        $comparisons=[];
        foreach($labels as $key=>$label){$source=$sources[$key];$normalized=$incoming[$key];$value=trim(molavi_domain_cell_text(molavi_domain_py_value($reference[$key]??null)));
            if($normalized==='')$state='not_provided';elseif($value==='')$state='reference_missing';
            elseif($key==='name'){$similarity=$components[$key]??0;$state=$similarity>=.92?'match':($similarity>=.72?'close':'conflict');}
            elseif($key==='package_count')$state=molavi_domain_match_number($normalized)===molavi_domain_match_number($value)?'match':'conflict';
            elseif(in_array($key,['volume','weight'],true))$state=$normalized===molavi_domain_match_name($value)?'match':'conflict';
            else $state=$normalized===molavi_domain_normalize_name($value)?'match':'conflict';
            $comparisons[]=['key'=>$key,'label'=>$label,'source'=>$source,'reference'=>$value,'state'=>$state];}
        $candidate=$reference+['score'=>round($score,4,PHP_ROUND_HALF_EVEN),'component_scores'=>$components,'name_similarity'=>round($components['name']??0,4,PHP_ROUND_HALF_EVEN),'comparisons'=>$comparisons];
        $supplier=molavi_domain_match_number(molavi_domain_py_value($product['supplier_price']??null,molavi_domain_py_value($product['detected_price']??null,molavi_domain_py_value($product['sale_price']??null,$product['price']??null))));
        if($supplier){$candidate['supplier_price']=$supplier;$candidate['price_role_hint']=molavi_domain_reference_role_hint($supplier,$reference);}
        if($sameBrand) $identityMatches[]=$candidate;else $scored[]=$candidate;
    }
    $ranked=$identityMatches?:$scored;
    $count=$identityMatches&&$brandInSupplierTitle&&!($incoming['variant']||$incoming['volume']||$incoming['weight'])
        ? molavi_domain_match_number($incoming['package_count']) : 0;
    usort($ranked,static function(array $a,array $b) use($count): int {
        $packOrder=$count ? (($b['package_count']===$count)<=>($a['package_count']===$count)) : 0;
        return $packOrder?:($b['score']<=>$a['score'])?:strcmp($a['reference_id'],$b['reference_id']);
    });
    return array_slice($ranked,0,max(1,min($limit,20)));
}
function molavi_domain_reference_role_hint($advertised,array $reference): array {
    $price=molavi_domain_match_number($advertised);$unit=molavi_domain_match_number($reference['unit_price']??null);$package=molavi_domain_match_number($reference['package_price']??null);
    if(!$price||!$unit||!$package||$unit===$package)return ['role'=>'needs_review','reason'=>'insufficient_reference'];
    $unitDistance=abs($price-$unit)/$unit;$packageDistance=abs($price-$package)/$package;
    if(min($unitDistance,$packageDistance)>.35||abs($unitDistance-$packageDistance)<.15)return ['role'=>'needs_review','reason'=>'ambiguous_or_distant'];
    return ['role'=>$unitDistance<$packageDistance?'unit':'package','reason'=>'reference_scale_hint','supplier_price'=>$price,'unit_distance'=>$unitDistance,'package_distance'=>$packageDistance];
}
