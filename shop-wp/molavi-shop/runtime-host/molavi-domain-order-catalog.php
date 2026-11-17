<?php
/** Plugin Name: Molavi Native Order Catalog */
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Reuse the storefront's pricing core with the current authoritative Sheet settings. */
function molavi_domain_order_catalog(array $tables): array {
    if(!function_exists('molavi_band')||(new ReflectionFunction('molavi_band'))->getNumberOfParameters()<3)molavi_domain_fail('native_pricing_core_unavailable',503);
    $settings=molavi_domain_site_settings($tables['Shop']??[]);$products=[];
    foreach(molavi_domain_records($tables['Products']??[]) as $record){
        $status=strtolower(trim((string)($record['Status']??'')));if(in_array($status,['skipped','needs_review','not_product'],true))continue;
        $stock=preg_replace('/[\s_-]+/u',' ',strtolower(trim((string)($record['Stock Status']??''))));
        if(in_array($stock,['out of stock','outofstock','unavailable','sold out','ناموجود','اتمام موجودی'],true))continue;
        $name=trim((string)($record['Product Name']??''));$id=(string)molavi_domain_py_value($record['Product ID']??'');
        $cost=molavi_domain_positive_price($record['Sale Price']??null);$regular=molavi_domain_positive_price($record['Consumer Price']??null)?:molavi_domain_positive_price($record['Actual Price']??null);
        if($id===''||$name===''||$cost<=0||$regular<=$cost)continue;$band=molavi_band($cost,$regular,$settings);$discountable=$band['discountable'];
        $products[]=['product_id'=>$id,'name'=>$name,'channel'=>molavi_domain_py_value($record['Channel Username']??''),'category'=>molavi_domain_py_value($record['Category']??''),
            'regular'=>$regular,'floor'=>$band['floor'],'ceiling'=>$band['ceiling'],'opening_rate'=>$discountable?(float)(($regular-$band['ceiling'])/$regular):0.0,
            'depth_rate'=>$discountable?(float)(($band['ceiling']-$band['floor'])/$regular):0.0,'max_discount'=>$band['max'],'spread'=>$discountable?($regular-$cost)/$regular:0.0,
            'stock_status'=>molavi_domain_py_value($record['Stock Status']??'','Unknown')];
    }return $products;
}
function molavi_domain_substitute_key($value): string {
    $text=(string)molavi_domain_py_value($value);if(class_exists('Normalizer'))$text=Normalizer::normalize($text,Normalizer::FORM_KC);
    $text=function_exists('mb_convert_case')?mb_convert_case($text,MB_CASE_FOLD,'UTF-8'):strtolower($text);
    return strtr($text,['ي'=>'ی','ك'=>'ک','ۀ'=>'ه','ة'=>'ه']);
}
function molavi_domain_substitute_tokens($value): array {
    preg_match_all('/[\pL\pN_]+/u',molavi_domain_substitute_key($value),$matches);$tokens=[];
    foreach($matches[0] as $token)if(molavi_domain_strlen($token)>1&&!in_array($token,['and','or','the','with','in','of','عدد','عددی','تایی','بسته','باکس','کارتن'],true))$tokens[$token]=true;
    return $tokens;
}
function molavi_domain_owned_substitutes(array $target,int $shortfall,array $products,array $lots,array $excluded=[],int $limit=5): array {
    if($shortfall<=0)return [];$available=[];foreach($lots as $lot){$sku=trim((string)molavi_domain_py_value($lot['sku']??''));$units=$lot['available_units']??null;
        if($sku!==''&&is_int($units)&&$units>0)$available[$sku]=($available[$sku]??0)+$units;}
    $excluded[]=(string)molavi_domain_py_value($target['sku']??'');$targetTokens=molavi_domain_substitute_tokens($target['name']??'');$category=molavi_domain_substitute_key($target['category']??'');$candidates=[];
    foreach($products as $product){$sku=trim((string)molavi_domain_py_value($product['product_id']??''));$stock=$available[$sku]??0;
        if($sku===''||in_array($sku,$excluded,true)||$stock<$shortfall)continue;$tokens=molavi_domain_substitute_tokens($product['name']??'');$common=array_map('strval',array_keys(array_intersect_key($targetTokens,$tokens)));sort($common,SORT_STRING);
        $match=$category!==''&&$category===molavi_domain_substitute_key($product['category']??'');$union=count($targetTokens+$tokens);$score=$union?count($common)/$union:0.0;if($match)$score=max($score,0.35);if(!$common&&!$match)continue;
        $candidates[]=['sku'=>$sku,'name'=>molavi_domain_text($product['name']??'',200),'category'=>molavi_domain_text($product['category']??'',120),
            'available_units'=>$stock,'required_units'=>$shortfall,'price_floor_toman'=>max(0,(int)($product['floor']??0)),
            'price_ceiling_toman'=>max(0,(int)($product['ceiling']??0)),'match_score'=>round($score,3,PHP_ROUND_HALF_EVEN),'matched_terms'=>$common,'review_required'=>true];
    }
    usort($candidates,static fn($a,$b)=>($b['match_score']<=>$a['match_score'])?:((($a['price_floor_toman']?:1000000000000000000)<=>($b['price_floor_toman']?:1000000000000000000))?:strcmp($a['name'],$b['name'])?:strcmp($a['sku'],$b['sku'])));
    return array_slice($candidates,0,max(1,min($limit,10)));
}
