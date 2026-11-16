<?php
declare(strict_types=1);
// Focused WordPress boundary harness: no products, orders or real accounts are used.
define('ABSPATH', __DIR__);
class WP_Error {
    public function __construct(private string $code, private string $message) {}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
}
class LibraryReply extends Exception {
    public function __construct(public bool $success,public array $data,public int $status){parent::__construct('API response');}
}
function add_action(...$args){} function add_filter(...$args){}
function is_wp_error($value){return $value instanceof WP_Error;}
function sanitize_text_field($value){return trim(strip_tags($value));}
function add_option($key,$value,...$args){if(isset($GLOBALS['options'][$key]))return false;$GLOBALS['options'][$key]=$value;return true;}
function get_option($key,$fallback){return $GLOBALS['options'][$key]??$fallback;}
function delete_option($key){unset($GLOBALS['options'][$key]);}
function nocache_headers(){} function wp_cache_delete(...$args){}
function get_current_user_id(){return $GLOBALS['user'];}
function check_ajax_referer(...$args){if(($_REQUEST['nonce']??'')!=='test')throw new LibraryReply(false,[],403);}
function wp_unslash($value){return $value;}
function wp_send_json_error($data,$status=400){throw new LibraryReply(false,$data,$status);}
function wp_send_json_success($data){throw new LibraryReply(true,$data,200);}
function get_user_meta($user,$key,$single){return $GLOBALS['meta'][$user]??'';}
function add_user_meta($user,$key,$next,$unique){if(isset($GLOBALS['meta'][$user]))return false;$GLOBALS['meta'][$user]=$next;return 1;}
function update_user_meta($user,$key,$next,$previous){
    if(!empty($GLOBALS['race'])){$GLOBALS['race']=false;$GLOBALS['meta'][$user]['favorites'][]='99';$GLOBALS['meta'][$user]['revision']++;return false;}
    if($GLOBALS['meta'][$user]!==$previous)return false;
    $GLOBALS['meta'][$user]=$next;return 1;
}
require __DIR__.'/../shop-wp/molavi-shop/includes/customer-experience.php';
$GLOBALS['user']=101;$GLOBALS['meta']=[];
function check($value,$message){if(!$value)throw new RuntimeException($message);}
function callLibrary($ops=null,$id=null,$nonce='test'){
    $_SERVER['REQUEST_METHOD']=$ops===null?'GET':'POST';$_REQUEST=['nonce'=>$nonce];
    $_POST=['operations'=>json_encode($ops,JSON_UNESCAPED_UNICODE),'request_id'=>$id??bin2hex(random_bytes(16))];
    try{molavi_account_library();}catch(LibraryReply $reply){return $reply;}
    throw new RuntimeException('Endpoint did not respond');
}
function cart($id){return ['id'=>$id,'name'=>'سبد '.$id,'items'=>['70'=>2,'68'=>3]];}
check(callLibrary()->data['carts']===[],'New account starts empty');
$GLOBALS['user']=0;check(callLibrary()->status===401,'Anonymous callers cannot use account storage');
$GLOBALS['user']=101;check(callLibrary(null,null,'bad')->status===403,'Account requests require a valid nonce');
$id=str_repeat('a',32);
$r=callLibrary([['type'=>'favorites_add','ids'=>['70']]],$id);check($r->success,'Device one saves favorites');
$r=callLibrary([['type'=>'favorites_add','ids'=>['68']]]);check($r->data['favorites']===['70','68'],'Device two additions merge');
$revision=$r->data['revision'];
$r=callLibrary([['type'=>'favorites_add','ids'=>['70']]],$id);check($r->data['revision']===$revision,'Retries are idempotent and return latest state');
check(callLibrary([['type'=>'favorites_remove','ids'=>['70']]],$id)->status===409,'Request ID cannot be reused with different operations');
$GLOBALS['race']=true;
$r=callLibrary([['type'=>'favorites_add','ids'=>['67']]]);check(in_array('99',$r->data['favorites'],true),'Concurrent server change survives retry');
$r=callLibrary([['type'=>'cart_save','cart'=>cart('weekly')],['type'=>'favorites_remove','ids'=>['70','68','67','99']]]);
check($r->success&&$r->data['favorites']===[]&&count($r->data['carts'])===1,'Bulk move saves a complete list and removes favorites atomically');
$r=callLibrary([['type'=>'cart_delete','id'=>'weekly']]);check($r->data['carts']===[],'Delete removes only the chosen cart');
$r=callLibrary([['type'=>'cart_save','cart'=>cart('weekly')]]);check($r->data['carts'][0]['items']['70']===2,'Undo restores saved quantities');
for($i=1;$i<10;$i++)check(callLibrary([['type'=>'cart_save','cart'=>cart('list'.$i)]])->success,'Save within capacity');
callLibrary([['type'=>'favorites_add','ids'=>['70']]]);$before=$GLOBALS['meta'][101];
$r=callLibrary([['type'=>'cart_save','cart'=>cart('overflow')],['type'=>'favorites_remove','ids'=>['70']]]);
check($r->status===409&&$GLOBALS['meta'][101]===$before,'Capacity rejects entire bulk move without losing an old cart or favorites');
$r=callLibrary([['type'=>'cart_rename','id'=>'weekly','name'=>'خرید هفتگی']]);check($r->success,'Rename persists');
check(callLibrary([['type'=>'cart_save','cart'=>['id'=>'bad','name'=>'bad','items'=>['70'=>-1]]]])->status===400,'Invalid quantities are rejected');
$GLOBALS['user']=202;check(callLibrary()->data['favorites']===[],'Other accounts cannot read this library');
check(count($GLOBALS['meta'][101]['carts'])===10,'Other account reads preserve the original library');
echo "customer library: auth, ownership, merge, retries, atomic transfer, capacity and undo passed\n";
