<?php
/** Synthetic runtime for the customer UI; no WordPress database, network or order writes. */
declare(strict_types=1);
define('ABSPATH','/synthetic/');define('DAY_IN_SECONDS',86400);
$GLOBALS['filters']=[];$GLOBALS['transients']=[];$GLOBALS['products']=[];
function add_action($key,$callback,$priority=10,$accepted=1){$GLOBALS['filters'][$key][]=$callback;}
function add_filter($key,$callback,$priority=10,$accepted=1){add_action($key,$callback,$priority,$accepted);}
function is_admin(){return false;}function wp_doing_ajax(){return false;}function is_cart(){return $GLOBALS['test_is_cart']??false;}
function add_option($key,$value){}
function get_option($key,$default=false){return $default;}function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;}function delete_transient($key){unset($GLOBALS['transients'][$key]);}
function wp_strip_all_tags($text){return strip_tags((string)$text);}function sanitize_text_field($text){return strip_tags((string)$text);}
function wp_unslash($value){return $value;}function absint($n){return abs((int)$n);}function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return esc_html($s);}function esc_url($s){return esc_html($s);}function wp_kses_post($s){return $s;}
function get_post_field($field,$id){return $GLOBALS['products'][$id]->name??'';}function get_the_terms(){return [];}
function wc_get_products($args){return array_values($GLOBALS['products']);}function wc_get_product($id){return $GLOBALS['products'][(int)$id]??null;}
function home_url($path='/'){return 'http://molavi.test'.$path;}function wc_get_page_permalink($name){return home_url('/'.$name.'/');}
function molavi_product_min($p){return molavi_profile_order_unit($p,molavi_buyer_type());}
function molavi_digits($s){return strtr((string)$s,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹',','=>'٬']);}
function add_query_arg($key,$value,$url){return $url.'?'.$key.'='.$value;}
function wc_get_checkout_url(){return home_url('/checkout/');}function get_woocommerce_currency(){return 'IRT';}
function wp_get_attachment_image_url($id,$size){return false;}function wc_placeholder_img_src($size){return 'http://molavi.test/placeholder.svg';}
function check_ajax_referer($action,$field){if(($_POST[$field]??'')!=='test-nonce')throw new RuntimeException('invalid nonce');}
function nocache_headers(){}function __return_false(){return false;}
class Reply extends Exception{public $data;public $success;function __construct($data,$success){$this->data=$data;$this->success=$success;}}
function wp_send_json_success($data){throw new Reply($data,true);}function wp_send_json_error($data,$status=400){throw new Reply($data,false);}
class WC_Product{
 public $id;public $name;public $available=true;function __construct($id,$name){$this->id=$id;$this->name=$name;}
 function get_id(){return $this->id;}function get_name(){return $this->name;}function get_slug(){return 'product-'.$this->id;}
 function is_purchasable(){return $this->available;}function is_in_stock(){return $this->available;}function is_type($type){return $type==='simple';}
 function get_meta($key,$single=true){return ['_molavi_curve'=>'1','_molavi_shelf'=>100000,'_molavi_sale'=>90000,'_molavi_floor'=>80000,'_molavi_ceiling'=>95000,'_molavi_max'=>.2,'_molavi_discountable'=>'1','_molavi_pack_size'=>24,'_molavi_supplier'=>'','_molavi_category'=>'نوشیدنی','_molavi_packaging'=>'۲۴ عددی'][$key]??'';}
 function get_date_created(){return new DateTime('2026-11-01 +'.$this->id.' minutes');}function get_image_id(){return 0;}
 function get_image($size,$attrs=[]){return '<img src="http://molavi.test/placeholder.svg" width="280" height="280" loading="lazy" alt="'.esc_attr($this->name).'">';}
 function get_permalink(){return home_url('/product/'.$this->id.'/');}function get_regular_price(){return '100000';}function get_sale_price(){return '90000';}
}
class WC_Cart{
 public $lines=[];function get_cart(){return $this->lines;}function calculate_totals(){}
 function set_quantity($key,$qty,$refresh=false){$this->lines[$key]['quantity']=$qty;return true;}
 function set_cart_contents($lines){$this->lines=$lines;}
 function remove_cart_item($key){unset($this->lines[$key]);return true;}
 function add_to_cart($id,$qty){if(in_array($id,$GLOBALS['failed_add_ids']??[],true))return false;$this->lines[(string)$id]=['product_id'=>$id,'quantity'=>$qty,'data'=>wc_get_product($id)];return (string)$id;}
}
class FakeSession{public $buyer='household';public $values=[];function get($key,$default=null){return $key==='molavi_buyer_type'?$this->buyer:($this->values[$key]??$default);}function set($key,$value){$this->values[$key]=$value;}}
$wc=(object)['cart'=>new WC_Cart(),'session'=>new FakeSession()];function WC(){global $wc;return $wc;}
require __DIR__.'/../shop-wp/molavi-shop/includes/pricing.php';
require __DIR__.'/../shop-wp/molavi-shop/includes/storefront-renderer.php';
require __DIR__.'/../shop-wp/molavi-shop/includes/customer-experience.php';
require __DIR__.'/../shop-wp/molavi-shop/includes/storefront-catalog.php';
$map=[];
for($id=1;$id<=52;$id++){
 $name=$id===1?'نوشیدنی كیوي ۲۴ عددی':'کالای نمونه '.$id;
 $GLOBALS['products'][$id]=new WC_Product($id,$name);
 $map[(string)$id]=['name'=>$name,'min'=>12,'pack_size'=>24,'household_unit'=>4,'shop_unit'=>12,
 'household_threshold_units'=>1,'shop_threshold_units'=>4,'household_cap_ratio'=>.5,'shop_cap_ratio'=>1,
 'household_max_discount'=>.1,'shop_max_discount'=>.2,'shelf'=>100000,'sale'=>90000,'floor'=>80000,'ceiling'=>95000,'max'=>.2,'discountable'=>1,
 'tuna_threshold_candidate'=>false,'tuna_threshold_anchor'=>false];
}
set_transient('molavi_catalog_v5',$map,86400);
function test_assert($ok,$name){if(!$ok)throw new RuntimeException('FAILED: '.$name);echo 'PASS '.$name."\n";}
// Prices and quantities use the actual pricing.php functions.
$first=\Molavi\Elementor\molavi_storefront_catalog_page();
test_assert(count($first['products'])===24&&$first['total']===52&&$first['has_more'],'bounded first catalog page');
$third=\Molavi\Elementor\molavi_storefront_catalog_page(['catalog_page'=>3]);
test_assert(count($third['products'])===4&&!$third['has_more'],'bounded last catalog page');
$search=\Molavi\Elementor\molavi_storefront_catalog_page(['molavi_q'=>'کیوی 24']);
test_assert($search['total']===1&&$search['products'][0]->get_id()===1,'Persian Arabic and numeric search normalization');
$cheap=\Molavi\Elementor\molavi_storefront_catalog_page(['sort'=>'cheap']);test_assert(count($cheap['products'])===24,'price sorting uses server quote');
$delivery=molavi_delivery_preview(4999999);test_assert($delivery['remaining']===1&&$delivery['estimate']===250000,'shipping separate from discount progress');
test_assert(molavi_delivery_preview(5000000)['estimate']===0,'approved delivery threshold boundary');
$fields=['billing'=>['billing_email'=>['required'=>true],'billing_phone'=>['required'=>false]]];
foreach($GLOBALS['filters']['woocommerce_checkout_fields'] as $filter)$fields=$filter($fields);
test_assert(!$fields['billing']['billing_email']['required']&&$fields['billing']['billing_phone']['required'],'guest checkout fields');
$_POST=['nonce'=>'test-nonce','request_id'=>str_repeat('a',32),'items'=>json_encode(['1'=>5,'2'=>4,'999'=>4])];
$GLOBALS['products'][2]->available=false;
try{molavi_restore_shopping_list();}catch(Reply $r){
 test_assert($r->success&&$r->data['added']===1&&count($r->data['skipped'])===2,'restore skips unavailable or removed products');
 test_assert($r->data['quote']['qtys'][1]===8&&$r->data['adjusted']===[1],'restore applies current approved increment');
 test_assert(isset($r->data['catalog'][1])&&!isset($r->data['catalog'][1]['sale']),'restore provides public facts without supplier cost');
}
$before=WC()->cart->lines;
try{molavi_restore_shopping_list();}catch(Reply $r){test_assert(WC()->cart->lines===$before,'restoring same request twice does not double quantity');}
$_POST=['nonce'=>'test-nonce','request_id'=>str_repeat('b',32),'items'=>'{"1":3,"2":1.5}'];
try{molavi_restore_shopping_list();}catch(Reply $r){test_assert(!$r->success&&WC()->cart->lines===$before,'invalid list rejected before any cart write');}
$GLOBALS['products'][2]->available=true;
$_POST=['nonce'=>'test-nonce','request_id'=>str_repeat('c',32),'mode'=>'replace','items'=>'{"999":1}'];
try{molavi_restore_shopping_list();}catch(Reply $r){test_assert(!$r->success&&WC()->cart->lines===$before,'unavailable favorites preserve original cart');}
$_POST=['nonce'=>'test-nonce','request_id'=>str_repeat('d',32),'mode'=>'replace','items'=>'{"3":1,"2":1}'];
$GLOBALS['failed_add_ids']=[2];
try{molavi_restore_shopping_list();}catch(Reply $r){test_assert(!$r->success&&WC()->cart->lines===$before,'failed replacement rolls back partially added lines');}
$GLOBALS['failed_add_ids']=[];
try{molavi_restore_shopping_list();}catch(Reply $r){test_assert($r->success&&$r->data['quote']['qtys']==[2=>4,3=>4],'favorites create a new cart with current minimum quantities');}
$replaced=WC()->cart->lines;
try{molavi_restore_shopping_list();}catch(Reply $r){test_assert($r->success&&WC()->cart->lines===$replaced,'new-cart retries are idempotent');}
$_POST['mode']='merge';
try{molavi_restore_shopping_list();}catch(Reply $r){test_assert(!$r->success&&WC()->cart->lines===$replaced,'request token cannot change from replace to merge');}
$shippingFilter=$GLOBALS['filters']['woocommerce_cart_ready_to_calc_shipping'][0];
$GLOBALS['test_is_cart']=true;test_assert(!$shippingFilter(true),'shipping is deferred on the native cart');
$GLOBALS['test_is_cart']=false;test_assert($shippingFilter(true),'checkout retains native shipping calculation');
WC()->cart->lines=[];
if(getenv('MOLAVI_FIXTURE_DIR')){
 $dir=getenv('MOLAVI_FIXTURE_DIR');
 ob_start();\Molavi\Elementor\molavi_render_storefront();$html=ob_get_clean();
 file_put_contents($dir.'/storefront.html',$html);
 file_put_contents($dir.'/storefront.json',json_encode(['initial'=>molavi_quote(),'catalog'=>\Molavi\Elementor\molavi_catalog_public_rows(array_keys($map))],JSON_UNESCAPED_UNICODE));
}
