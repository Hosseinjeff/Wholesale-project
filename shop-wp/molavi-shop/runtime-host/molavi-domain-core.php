<?php
/** Plugin Name: Molavi Native Workflow Core */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class MolaviWorkflowError extends RuntimeException {
    public int $status;public array $details;
    public function __construct(string $code,int $status=409,array $details=[]){parent::__construct($code);$this->status=$status;$this->details=$details;}
}
function molavi_domain_fail(string $code,int $status=409,array $details=[]):void {throw new MolaviWorkflowError($code,$status,$details);}
function molavi_domain_now():string {return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');}
function molavi_domain_today():string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->format('Y-m-d');}
function molavi_domain_strlen(string $value):int {return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):(int)preg_match_all('/./us',$value);}
function molavi_domain_date($value):DateTimeImmutable {
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))molavi_domain_fail('invalid_date',400);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
    if(!$date||$date->format('Y-m-d')!==$value)molavi_domain_fail('invalid_date',400);
    return $date;
}
function molavi_domain_timestamp($value):?float {
    if(is_bool($value))return null;
    if(is_int($value)||is_float($value))return is_finite((float)$value)&&$value>=-62135596800&&$value<=253402300799.999999?(float)$value:null;
    $raw=trim((string)($value??''));if(ctype_digit($raw))return molavi_domain_timestamp((float)$raw);
    if(!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/',$raw))return null;
    try{return (float)(new DateTimeImmutable($raw))->format('U.u');}catch(Throwable $e){return null;}
}
function molavi_domain_digits($value):string {
    return strtr((string)($value??''),array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
}
function molavi_domain_number($value):float {
    $value=str_replace([',','٬',' '],'',molavi_domain_digits($value));
    return is_numeric($value)&&is_finite((float)$value)?(float)$value:0.0;
}
function molavi_domain_whole($value,bool $zero=false):int {
    if(!is_int($value)||$value<($zero?0:1))molavi_domain_fail('quantity_must_be_a_valid_whole_number',400);return $value;
}
/** Python-compatible JSON is needed for the existing revision/idempotency hashes. */
function molavi_domain_json($value,bool $spaces=false,bool $ascii=false,bool $sort=true):string {
    if(is_object($value)||is_array($value)){
        $object=is_object($value)||!array_is_list($value);$items=is_object($value)?get_object_vars($value):$value;
        if($object&&$sort)ksort($items,SORT_STRING);$parts=[];
        foreach($items as $key=>$item){$encoded=molavi_domain_json($item,$spaces,$ascii,$sort);
            $parts[]=$object?molavi_domain_json((string)$key,false,$ascii,false).($spaces?': ':':').$encoded:$encoded;}
        return ($object?'{':'[').implode($spaces?', ':',',$parts).($object?'}':']');
    }
    $encoded=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION
        |($ascii?0:JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS));
    if(!is_float($value)||$value==0.0)return $encoded;
    // Python and PHP use the same shortest decimal digits, with different
    // scientific-notation thresholds and exponent padding.
    preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/i',$encoded,$m);
    $fraction=$m[3]??'';$digits=$m[2].$fraction;$leading=strlen($digits)-strlen(ltrim($digits,'0'));
    $exponent=strlen($m[2])-$leading-1+(int)($m[4]??0);$digits=rtrim(substr($digits,$leading),'0');
    if($exponent>=16||$exponent< -4){$coefficient=$digits[0].(strlen($digits)>1?'.'.substr($digits,1):'');
        return $m[1].$coefficient.'e'.($exponent<0?'-':'+').str_pad((string)abs($exponent),2,'0',STR_PAD_LEFT);}
    if(!isset($m[4])||$m[4]==='')return $encoded;
    $point=$exponent+1;
    if($point<=0)return $m[1].'0.'.str_repeat('0',-$point).$digits;
    if($point>=strlen($digits))return $m[1].$digits.str_repeat('0',$point-strlen($digits)).'.0';
    return $m[1].substr($digits,0,$point).'.'.substr($digits,$point);
}
function molavi_domain_hash($value,bool $spaces=false,bool $ascii=false):string {return hash('sha256',molavi_domain_json($value,$spaces,$ascii));}
function molavi_domain_decode($value,$default=[]){if(!is_string($value)||$value==='')return $default;return json_decode($value,true,128,JSON_THROW_ON_ERROR);}
/** Python keeps the string "0" when using `value or ''`; PHP's ?: does not. */
function molavi_domain_py_value($value,$fallback='') {
    return $value===null||$value===false||$value===''||$value===[]
        ||((is_int($value)||is_float($value))&&$value==0)?$fallback:$value;
}
function molavi_domain_sql(SQLite3 $db,string $sql,array $params=[]):array {
    $statement=$db->prepare($sql);
    foreach(array_values($params) as $index=>$value){$type=$value===null?SQLITE3_NULL:(is_int($value)||is_bool($value)?SQLITE3_INTEGER:(is_float($value)?SQLITE3_FLOAT:SQLITE3_TEXT));$statement->bindValue($index+1,$value,$type);}
    $result=$statement->execute();$rows=[];
    if($result instanceof SQLite3Result){if($result->numColumns())while(($row=$result->fetchArray(SQLITE3_ASSOC))!==false)$rows[]=$row;$result->finalize();}
    $changed=$db->changes();$last=$db->lastInsertRowID();$statement->close();return ['rows'=>$rows,'changes'=>$changed,'last_id'=>$last];
}
function molavi_domain_rows(SQLite3 $db,string $sql,array $params=[]):array {return molavi_domain_sql($db,$sql,$params)['rows'];}
function molavi_domain_row(SQLite3 $db,string $sql,array $params=[]):?array {return molavi_domain_rows($db,$sql,$params)[0]??null;}
function molavi_domain_db(string $store='pipeline'):SQLite3 {
    static $ready=[];$db=molavi_state_open($store);
    if(empty($ready[$store])){
        foreach(molavi_domain_schema_sql($store) as $sql)$db->exec($sql);
        $migrations=$store==='pipeline'?[
            ['jobs','created_at','REAL'],['jobs','updated_at','REAL'],['inbox','received_at','REAL'],
            ['pipeline_workers','progress_at','REAL'],['stock_lots','purchase_date','TEXT'],
            ['stock_lot_events','expected_revision',"TEXT NOT NULL DEFAULT ''"],
            ['paid_order_plans','supplier_terms',"TEXT NOT NULL DEFAULT '{}'"],
            ['product_status_snapshots','schema_version','INTEGER NOT NULL DEFAULT 0'],
            ['supplier_price_evidence_history','note',"TEXT NOT NULL DEFAULT ''"],
        ]:[['reference_refresh_state','change_summary','TEXT']];
        foreach($migrations as [$table,$column,$definition]){
            $columns=array_column(molavi_domain_rows($db,'PRAGMA table_info('.$table.')'),'name');
            if(!in_array($column,$columns,true))$db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
        }$ready[$store]=true;
    }return $db;
}
function molavi_domain_read(string $store,callable $read){$db=molavi_domain_db($store);try{return $read($db);}finally{$db->close();}}
function molavi_domain_tx(string $store,callable $write){
    $db=molavi_domain_db($store);try{$db->exec('BEGIN IMMEDIATE');$result=$write($db);$db->exec('COMMIT');return $result;}
    catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}throw $e;}finally{$db->close();}
}
function molavi_domain_lock(string $name,callable $work,string $store='pipeline'){
    $lease=['store'=>$store,'name'=>$name,'owner'=>bin2hex(random_bytes(16)),'action'=>'acquire'];
    if(!molavi_state_lease($lease)['acquired'])molavi_domain_fail('workflow_busy_retry',409);
    try{return $work();}finally{$lease['action']='release';molavi_state_lease($lease);}
}
function molavi_domain_actor():string {
    $id=function_exists('get_current_user_id')?(int)get_current_user_id():0;
    if(!$id&&function_exists('get_users')){$admins=get_users(['role'=>'administrator','number'=>1,'orderby'=>'ID','order'=>'ASC','fields'=>'ID']);$id=(int)($admins[0]??0);}
    if(!$id)molavi_domain_fail('owner_required',403);return 'wp:'.$id;
}
function molavi_domain_records(array $rows):array {
    if(!$rows)return [];$headers=array_map('strval',$rows[0]);$records=[];
    foreach(array_slice($rows,1) as $row){if(!is_array($row))continue;$record=[];foreach($headers as $index=>$header)$record[$header]=$row[$index]??'';$records[]=$record;}return $records;
}
function molavi_domain_rows_from_records(array $headers,array $records):array {
    $out=[$headers];foreach($records as $record){$out[]=array_map(static fn($header)=>$record[$header]??'',$headers);}return $out;
}
function molavi_domain_queue(string $kind,string $key,array $payload):string {
    $id=$kind.':'.$key;$encoded=molavi_domain_json((object)$payload,true);$now=microtime(true);
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$kind,$encoded,$now){
        molavi_domain_sql($db,"INSERT OR IGNORE INTO jobs (id,kind,payload,state,available,created_at,updated_at) VALUES (?,?,?,'queued',?,?,?)",[$id,$kind,$encoded,$now,$now,$now]);
        $existing=molavi_domain_row($db,'SELECT kind,payload FROM jobs WHERE id=?',[$id]);
        if(!$existing||$existing['kind']!==$kind||molavi_domain_hash(molavi_domain_decode($existing['payload']))!==molavi_domain_hash(molavi_domain_decode($encoded)))molavi_domain_fail('job_identity_changed');
    });return $id;
}
