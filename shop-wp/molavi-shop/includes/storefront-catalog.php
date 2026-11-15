<?php
/** Bounded public product rendering over a cached, non-personal catalog index. */
declare(strict_types=1);
namespace Molavi\Elementor;
if (!defined('ABSPATH')) { exit; }

function molavi_storefront_search_key(string $value): string {
    $plain=wp_strip_all_tags($value);
    $value=strtr(function_exists('mb_strtolower')?mb_strtolower($plain,'UTF-8'):strtolower($plain),[
        'ي'=>'ی','ى'=>'ی','ك'=>'ک','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',"\u{200c}"=>' ']);
    return trim((string)preg_replace('/\s+/u',' ',$value));
}
function molavi_storefront_public_index(): array {
    $cached=get_transient('molavi_public_catalog_v1');
    if(is_array($cached))return $cached;
    $rows=[];
    foreach(wc_get_products(['status'=>'publish','limit'=>-1,'return'=>'objects']) as $product){
        if(!$product instanceof \WC_Product||!$product->is_purchasable()||!$product->is_in_stock())continue;
        $id=$product->get_id();$category=molavi_storefront_product_category($product);
        $date=$product->get_date_created();
        $rows[(string)$id]=['id'=>$id,'name'=>molavi_storefront_card_product_name($product),
            'search'=>molavi_storefront_search_key($product->get_name().' '.$category.' '.(string)$product->get_meta('_molavi_packaging')),
            'category'=>$category,'created'=>$date?$date->getTimestamp():0];
    }
    set_transient('molavi_public_catalog_v1',$rows,60);
    return $rows;
}
function molavi_storefront_catalog_page(array $params=[]): array {
    $index=molavi_storefront_public_index();$quote=\molavi_quote();
    $query=isset($params['molavi_q'])&&is_scalar($params['molavi_q'])?molavi_storefront_search_key((function_exists('mb_substr')?mb_substr((string)$params['molavi_q'],0,100):substr((string)$params['molavi_q'],0,200))):'';
    $category=isset($params['category'])&&is_scalar($params['category'])?sanitize_text_field((string)$params['category']):'';
    $sort=isset($params['sort'])&&is_string($params['sort'])?$params['sort']:'newest';
    $page=max(1,min(10000,(int)($params['catalog_page']??1)));
    $favorites=null;
    if(isset($params['ids'])&&is_string($params['ids']))$favorites=array_slice(array_filter(array_map('absint',explode(',',$params['ids']))),0,1000);
    $rows=array_values(array_filter($index,static function(array $row) use($query,$category,$favorites): bool {
        return ($query===''||strpos($row['search'],$query)!==false)
            && ($category===''||$row['category']===$category)
            && ($favorites===null||in_array($row['id'],$favorites,true));
    }));
    usort($rows,static function(array $a,array $b) use($sort,$quote): int {
        if(in_array($sort,['cheap','dear'],true)){
            $difference=(int)($quote['units'][(string)$a['id']]??0)<=>(int)($quote['units'][(string)$b['id']]??0);
            if($difference!==0)return $sort==='dear'?-$difference:$difference;
        }
        if($sort==='name')return strcmp($a['name'],$b['name']);
        return ($b['created']<=>$a['created'])?:($b['id']<=>$a['id']);
    });
    $products=[];
    foreach(array_slice($rows,($page-1)*24,24) as $row){
        $product=wc_get_product($row['id']);
        // Recheck active eligibility after the index cache, before presenting a purchase action.
        if($product instanceof \WC_Product&&$product->is_purchasable()&&$product->is_in_stock())$products[]=$product;
    }
    $categories=array_values(array_unique(array_filter(array_column($index,'category'))));sort($categories,SORT_STRING);
    return ['products'=>$products,'quote'=>$quote,'page'=>$page,'total'=>count($rows),
        'has_more'=>$page*24<count($rows),'categories'=>$categories];
}
function molavi_catalog_public_rows(array $ids): array {
    $map=\molavi_catalog_map();$public=[];
    foreach(array_unique(array_map('absint',$ids)) as $id){
        $row=$map[(string)$id]??null;$product=wc_get_product($id);
        if(!$row||!$product instanceof \WC_Product)continue;
        $image=wp_get_attachment_image_url($product->get_image_id(),'woocommerce_thumbnail');
        $public[(string)$id]=array_intersect_key($row,array_flip(['name','min','pack_size','household_unit','shop_unit','household_threshold_units','shop_threshold_units','shelf','discountable','household_max_discount','shop_max_discount']))
            +['image'=>$image?:wc_placeholder_img_src('woocommerce_thumbnail')];
    }
    return $public;
}
function molavi_storefront_catalog_ajax(): void {
    $params=wp_unslash($_GET);
    $result=molavi_storefront_catalog_page(is_array($params)?$params:[]);
    ob_start();foreach($result['products'] as $product)molavi_render_storefront_card($product,$result['quote']);$html=(string)ob_get_clean();
    $ids=array_map(static function($p){return $p->get_id();},$result['products']);
    nocache_headers();wp_send_json_success(['html'=>$html,'catalog'=>molavi_catalog_public_rows($ids),
        'page'=>$result['page'],'total'=>$result['total'],'has_more'=>$result['has_more'],'buyer_type'=>\molavi_buyer_type()]);
}
add_action('wc_ajax_molavi_catalog',__NAMESPACE__.'\\molavi_storefront_catalog_ajax');
add_action('wc_ajax_nopriv_molavi_catalog',__NAMESPACE__.'\\molavi_storefront_catalog_ajax');
function molavi_invalidate_public_index(): void {delete_transient('molavi_public_catalog_v1');}
add_action('woocommerce_update_product',__NAMESPACE__.'\\molavi_invalidate_public_index');
add_action('woocommerce_new_product',__NAMESPACE__.'\\molavi_invalidate_public_index');
add_action('woocommerce_product_set_stock_status',__NAMESPACE__.'\\molavi_invalidate_public_index');
