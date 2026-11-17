<?php
/** Plugin Name: Molavi Private State Host
 * WordPress owns the durable stores. Python receives query results, never database files.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_state_permission($request) {
    if(!defined('MOLAVI_REFERENCE_BRIDGE_TOKEN')||!defined('MOLAVI_STATE_ROOT')||!class_exists('SQLite3'))
        return new WP_Error('state_unconfigured','State host unavailable',['status'=>503]);
    $expected=hash_hmac('sha256','molavi-state-v1',MOLAVI_REFERENCE_BRIDGE_TOKEN);
    return hash_equals($expected,(string)$request->get_header('x-molavi-state-token'))
        ?true:new WP_Error('state_forbidden','Access denied',['status'=>403]);
}
function molavi_state_root():string {
    $root=rtrim((string)MOLAVI_STATE_ROOT,'/');
    if($root===''||$root[0]!=='/'||strpos($root,'/public_html')!==false)
        throw new RuntimeException('Private state directory must be outside the web root');
    if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('State directory unavailable');
    return $root;
}
function molavi_state_path(string $store):string {
    if(!in_array($store,['pipeline','reference'],true))throw new InvalidArgumentException('Invalid state store');
    return molavi_state_root().'/'.$store.'.sqlite3';
}
function molavi_state_open(string $store):SQLite3 {
    $path=molavi_state_path($store);
    if(!is_file($path))throw new RuntimeException('State store has not been imported');
    $db=new SQLite3($path,SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);$db->busyTimeout(15000);
    $db->exec('CREATE TABLE IF NOT EXISTS molavi_state_commits (id TEXT PRIMARY KEY,digest TEXT NOT NULL,created_at INTEGER NOT NULL,receipt TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS molavi_state_locks (name TEXT PRIMARY KEY,owner TEXT NOT NULL,expires INTEGER NOT NULL)');
    $db->exec("CREATE TABLE IF NOT EXISTS molavi_state_executors (kind TEXT PRIMARY KEY,host TEXT NOT NULL CHECK(host IN ('railway','wordpress')),updated_at REAL NOT NULL)");
    chmod($path,0600);return $db;
}
function molavi_state_json($value):string {
    $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
    return $json;
}
function molavi_state_statement(SQLite3 $db,array $step):array {
    $sql=$step['sql']??null;
    if(!is_string($sql)||strlen($sql)>200000||preg_match('/\b(ATTACH|DETACH|load_extension|writable_schema|VACUUM)\b/i',$sql))
        throw new InvalidArgumentException('Unsupported statement');
    if(!empty($step['script'])){
        $db->exec($sql);return ['columns'=>[],'rows'=>[],'rowcount'=>-1,'lastrowid'=>0];
    }
    $groups=!empty($step['many'])?($step['params']??[]):[($step['params']??[])];
    if(!is_array($groups)||count($groups)>20000)throw new InvalidArgumentException('Invalid parameters');
    $columns=[];$rows=[];$changed=0;
    foreach($groups as $params){
        if(!is_array($params))throw new InvalidArgumentException('Invalid parameters');
        $statement=$db->prepare($sql);
        foreach(array_values($params) as $index=>$value){
            if(!is_scalar($value)&&$value!==null)throw new InvalidArgumentException('Scalar parameters required');
            $type=$value===null?SQLITE3_NULL:(is_int($value)||is_bool($value)?SQLITE3_INTEGER:(is_float($value)?SQLITE3_FLOAT:SQLITE3_TEXT));
            $statement->bindValue($index+1,$value,$type);
        }
        $result=$statement->execute();
        if($result instanceof SQLite3Result){
            $columns=[];for($i=0;$i<$result->numColumns();$i++)$columns[]=$result->columnName($i);
            if($columns)while(($row=$result->fetchArray(SQLITE3_NUM))!==false)$rows[]=$row;
            $result->finalize();
        }
        $changed+=$db->changes();$statement->close();
    }
    $write=(bool)preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i',$sql);
    return ['columns'=>$columns,'rows'=>$rows,'rowcount'=>$write?$changed:-1,'lastrowid'=>$db->lastInsertRowID()];
}
/** Preview pending statements in a rollback transaction; commit the complete validated program atomically. */
function molavi_state_transaction(array $body):array {
    $store=(string)($body['store']??'');$mode=$body['mode']??'';$steps=$body['steps']??null;
    $id=(string)($body['id']??'');
    if(!in_array($mode,['preview','commit'],true)||!is_array($steps)||count($steps)>2000||!preg_match('/^[a-f0-9]{32}$/',$id))
        throw new InvalidArgumentException('Invalid transaction');
    $material=array_map(static function($step){unset($step['expected']);return $step;},$steps);
    $clock=$body['observed_at']??null;
    if(!is_numeric($clock)||abs((float)$clock-time())>86400)throw new InvalidArgumentException('Invalid transaction clock');
    $digest=hash('sha256',molavi_state_json([$material,$clock]));$db=molavi_state_open($store);$active=false;
    $db->createFunction('julianday',static function($value)use($clock){return (($value==='now'?(float)$clock:strtotime((string)$value))/86400)+2440587.5;},1);
    try {
        $db->exec('BEGIN IMMEDIATE');$active=true;
        $saved=$db->querySingle("SELECT digest,receipt FROM molavi_state_commits WHERE id='".$id."'",true);
        if(is_array($saved)&&isset($saved['digest'])){
            if(!hash_equals((string)$saved['digest'],$digest))throw new DomainException('Commit identity was reused');
            $db->exec('ROLLBACK');return ['ok'=>true,'committed'=>true,'replayed'=>true,'cursors'=>json_decode($saved['receipt'],true)];
        }
        $last=['columns'=>[],'rows'=>[],'rowcount'=>-1,'lastrowid'=>0];$tokens=[];$cursors=[];
        foreach($steps as $step){
            if(!is_array($step))throw new InvalidArgumentException('Invalid statement');
            $last=molavi_state_statement($db,$step);$token=hash('sha256',molavi_state_json($last));
            if(isset($step['expected'])&&!hash_equals((string)$step['expected'],$token))
                throw new DomainException('State changed during transaction; retry the operation');
            $tokens[]=$token;
            $cursors[]=['rowcount'=>$last['rowcount'],'lastrowid'=>$last['lastrowid']];
        }
        if($mode==='commit'){
            $s=$db->prepare('INSERT INTO molavi_state_commits VALUES (?,?,?,?)');
            $s->bindValue(1,$id,SQLITE3_TEXT);$s->bindValue(2,$digest,SQLITE3_TEXT);$s->bindValue(3,time(),SQLITE3_INTEGER);$s->bindValue(4,molavi_state_json($cursors),SQLITE3_TEXT);$s->execute();$s->close();
            $db->exec('COMMIT');
        }else $db->exec('ROLLBACK');
        return ['ok'=>true,'committed'=>$mode==='commit','result'=>$mode==='preview'?$last:null,'tokens'=>$tokens,'cursors'=>$cursors];
    } catch(Throwable $e){
        if($db->lastErrorCode()===19)throw new UnexpectedValueException('Constraint conflict');
        throw $e;
    } finally {if($active)try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}$db->close();}
}
function molavi_state_lease(array $body):array {
    $name=(string)($body['name']??'');$owner=(string)($body['owner']??'');$action=$body['action']??'';
    if(!in_array($name,['publication','activation','refresh'],true)||!preg_match('/^[a-f0-9]{32}$/',$owner)||!in_array($action,['acquire','release'],true))
        throw new InvalidArgumentException('Invalid lease');
    $db=molavi_state_open((string)($body['store']??''));
    try {
        $db->exec('BEGIN IMMEDIATE');
        if($action==='release'){
            $s=$db->prepare('DELETE FROM molavi_state_locks WHERE name=? AND owner=?');$s->bindValue(1,$name);$s->bindValue(2,$owner);$s->execute();
            $db->exec('COMMIT');return ['ok'=>true,'released'=>true];
        }
        $s=$db->prepare('INSERT INTO molavi_state_locks VALUES (?,?,?) ON CONFLICT(name) DO UPDATE SET owner=excluded.owner,expires=excluded.expires WHERE molavi_state_locks.expires<? OR molavi_state_locks.owner=excluded.owner');
        foreach([$name,$owner,time()+1800,time()] as $i=>$value)$s->bindValue($i+1,$value,is_int($value)?SQLITE3_INTEGER:SQLITE3_TEXT);
        $s->execute();$acquired=$db->changes()===1;$db->exec('COMMIT');return ['ok'=>true,'acquired'=>$acquired];
    } finally {try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}$db->close();}
}
function molavi_state_inventory(string $store):array {
    $db=molavi_state_open($store);$tables=[];
    try {
        $result=$db->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'molavi_state_%' ORDER BY name");
        while(($row=$result->fetchArray(SQLITE3_ASSOC))!==false){
            $quoted='"'.str_replace('"','""',$row['name']).'"';
            $tables[$row['name']]=['rows'=>(int)$db->querySingle('SELECT count(*) FROM '.$quoted),'sql'=>$row['sql']];
        }
        return ['store'=>$store,'file_bytes'=>filesize(molavi_state_path($store)),'tables'=>$tables];
    } finally {$db->close();}
}
function molavi_state_due_work():bool {
    if(!defined('MOLAVI_STATE_ROOT')||!get_option('molavi_state_active',false))return false;
    $db=molavi_state_open('pipeline');
    try {
        // A live executor already drains its queue; only recover idle/expired work.
        if((int)$db->querySingle("SELECT count(*) FROM pipeline_workers WHERE worker_id NOT LIKE 'wordpress:%' AND state!='stopped' AND heartbeat_at>".(time()-45))>0)return false;
        return (bool)$db->querySingle("SELECT 1 FROM inbox WHERE batch_id IS NULL UNION ALL SELECT 1 FROM jobs WHERE kind NOT IN (SELECT kind FROM molavi_state_executors WHERE host='wordpress') AND ((state='queued' AND available<=".time().") OR (state='running' AND expires<=".time().")) LIMIT 1");
    } finally {$db->close();}
}
function molavi_state_source_summary(array $source):array {
    $content=(string)($source['content']??'');$summary=['compacted'=>true];
    foreach(['id','channel','channel_username','timestamp','source_timestamp_basis','source_created_at','source_update_at','url','media_type'] as $key)
        $summary[$key]=(string)($source[$key]??'');
    $summary['has_media']=(bool)($source['has_media']??false);
    $summary['content_length']=preg_match_all('/./us',$content);
    $summary['content_sha256']=$content!==''?hash('sha256',$content):'';
    return $summary;
}
function molavi_state_retention_days():?int {
    $days=filter_var(get_option('molavi_pipeline_payload_retention_days',null),FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>30,'max_range'=>365]]);
    return $days===false?null:$days;
}
function molavi_state_housekeeping(?int $clock=null):array {
    $days=molavi_state_retention_days();
    if($days===null)throw new RuntimeException('Verified retention configuration required');
    $now=$clock??time();$cutoff=$now-$days*86400;$jobs=0;$inbox=0;$db=molavi_state_open('pipeline');
    try {
        $db->exec('BEGIN IMMEDIATE');
        $select=$db->prepare("SELECT id,payload FROM jobs WHERE kind='batch' AND state='done' AND updated_at<? ORDER BY updated_at LIMIT 1000");
        $select->bindValue(1,$cutoff,SQLITE3_INTEGER);$rows=$select->execute();$candidates=[];
        while(($row=$rows->fetchArray(SQLITE3_ASSOC))!==false)$candidates[]=$row;
        $rows->finalize();$select->close();
        foreach($candidates as $row){
            $payload=json_decode($row['payload']?:'{}',false,128,JSON_THROW_ON_ERROR);
            if(!is_object($payload)||!empty($payload->compacted))continue;
            $messages=is_array($payload->messages??null)?$payload->messages:[];$summaries=[];
            foreach($messages as $message)if(is_object($message))$summaries[]=molavi_state_source_summary((array)$message);
            $compact=['batch_id'=>(string)($payload->batch_id??''),'expected_count'=>(int)($payload->expected_count??count($messages)),
                'transmission_complete'=>(bool)($payload->transmission_complete??false),'mode'=>(string)($payload->mode??'batch_ingest'),
                'compacted'=>true,'messages'=>$summaries];
            $s=$db->prepare('UPDATE jobs SET payload=? WHERE id=?');$s->bindValue(1,molavi_state_json($compact));$s->bindValue(2,$row['id']);$s->execute();$s->close();$jobs++;
            $s=$db->prepare('SELECT id,payload FROM inbox WHERE batch_id=?');$s->bindValue(1,$row['id']);$result=$s->execute();$sources=[];
            while(($entry=$result->fetchArray(SQLITE3_ASSOC))!==false)$sources[]=$entry;
            $result->finalize();$s->close();
            foreach($sources as $entry){
                $source=json_decode($entry['payload']?:'{}',false,128,JSON_THROW_ON_ERROR);
                if(!is_object($source)||!empty($source->compacted))continue;
                $s=$db->prepare('UPDATE inbox SET payload=? WHERE id=?');$s->bindValue(1,molavi_state_json(molavi_state_source_summary((array)$source)));$s->bindValue(2,$entry['id']);$s->execute();$s->close();$inbox++;
            }
        }
        $db->exec('DELETE FROM molavi_state_commits WHERE created_at<'.($now-7*86400));
        $db->exec('COMMIT');return ['jobs_compacted'=>$jobs,'inbox_compacted'=>$inbox];
    } finally {try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}$db->close();}
}
function molavi_state_import(array $body):array {
    $store=(string)($body['store']??'');$target=molavi_state_path($store);
    $id=(string)($body['id']??'');$expected=(string)($body['sha256']??'');$size=$body['size']??0;$offset=$body['offset']??-1;
    if(!preg_match('/^[a-f0-9]{32}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$expected)||!is_int($size)||$size<1||$size>1073741824||!is_int($offset)||$offset<0)
        throw new InvalidArgumentException('Invalid import');
    if(is_file($target)){
        $receipt=get_option('molavi_state_import_'.$store,[]);
        if(($receipt['sha256']??'')===$expected)return ['ok'=>true,'complete'=>true,'receipt'=>$receipt];
        throw new DomainException('An authoritative store already exists');
    }
    $chunk=base64_decode((string)($body['chunk']??''),true);
    if($chunk===false||strlen($chunk)>1048576||$offset+strlen($chunk)>$size)throw new InvalidArgumentException('Invalid import chunk');
    $incoming=molavi_state_root().'/incoming-'.$store.'-'.$id;
    $guard=fopen(molavi_state_root().'/import-'.$store.'.lock','c');
    if(!$guard||!flock($guard,LOCK_EX))throw new RuntimeException('Import lock unavailable');
    $file=fopen($incoming,'c+b');if(!$file)throw new RuntimeException('Import unavailable');
    try {
        fseek($file,0,SEEK_END);$current=ftell($file);
        if($current!==$offset){
            if($current===$offset+strlen($chunk)){fseek($file,$offset);if(fread($file,strlen($chunk))!==$chunk)throw new DomainException('Import chunk mismatch');}
            else throw new DomainException('Import offset mismatch');
        }elseif(fwrite($file,$chunk)!==strlen($chunk))throw new RuntimeException('Incomplete write');
        fflush($file);chmod($incoming,0600);
        clearstatcache(true,$incoming);
        if(filesize($incoming)!==$size)return ['ok'=>true,'complete'=>false,'offset'=>$offset+strlen($chunk)];
        if(!hash_equals($expected,hash_file('sha256',$incoming)))throw new DomainException('Import checksum mismatch');
        $test=new SQLite3($incoming,SQLITE3_OPEN_READONLY);
        try {if($test->querySingle('PRAGMA quick_check')!=='ok')throw new DomainException('Invalid database');}
        finally {$test->close();}
        // Keep an immutable verified transfer copy on WordPress for rollback.
        $backup=molavi_state_root().'/transfer-'.$store.'-'.$id.'.sqlite3';
        if(!copy($incoming,$backup))throw new RuntimeException('Transfer backup unavailable');chmod($backup,0600);
        if(!rename($incoming,$target))throw new RuntimeException('Store activation failed');
        $receipt=['id'=>$id,'sha256'=>$expected,'bytes'=>$size,'imported_at'=>time()];
        update_option('molavi_state_import_'.$store,$receipt,false);
        return ['ok'=>true,'complete'=>true,'receipt'=>$receipt,'inventory'=>molavi_state_inventory($store)];
    } finally {fclose($file);flock($guard,LOCK_UN);fclose($guard);}
}
add_action('rest_api_init',static function():void {
    foreach(['transaction','import','lease'] as $action)register_rest_route('molavi-state/v1','/'.$action,[
        'methods'=>'POST','permission_callback'=>'molavi_state_permission','callback'=>static function($request)use($action){
            try {
                $raw=$request->get_body();if(strlen($raw)>67108864)throw new InvalidArgumentException('Request too large');
                if($request->get_header('x-molavi-state-encoding')==='gzip'){$raw=gzdecode($raw,67108864);if($raw===false)throw new InvalidArgumentException('Invalid encoding');}
                $body=json_decode($raw,true,128,JSON_THROW_ON_ERROR);if(!is_array($body))throw new InvalidArgumentException('Invalid body');
                foreach(($body['locks']??[]) as $lease){
                    $refreshed=molavi_state_lease($lease+['action'=>'acquire']);
                    if(!$refreshed['acquired'])throw new DomainException('State lease was lost');
                }
                if($action==='transaction')return molavi_state_transaction($body);
                return $action==='lease'?molavi_state_lease($body):molavi_state_import($body);
            }catch(UnexpectedValueException $e){return new WP_Error('state_integrity','Constraint conflict',['status'=>409]);
            }catch(DomainException $e){return new WP_Error('state_conflict',$e->getMessage(),['status'=>409]);}
            catch(InvalidArgumentException|JsonException $e){return new WP_Error('state_request','Invalid state request',['status'=>400]);}
            catch(Throwable $e){error_log('[molavi-state] '.get_class($e));return new WP_Error('state_unavailable','State operation unavailable',['status'=>503]);}
        }]);
    register_rest_route('molavi-state/v1','/inventory',['methods'=>'GET','permission_callback'=>'molavi_state_permission',
        'callback'=>static function(){try {return ['ok'=>true,'stores'=>array_map('molavi_state_inventory',['pipeline','reference']),
            'receipts'=>['pipeline'=>get_option('molavi_state_import_pipeline',[]),'reference'=>get_option('molavi_state_import_reference',[])]];}
            catch(Throwable $e){return new WP_Error('state_unavailable','State inventory unavailable',['status'=>503]);}}]);
    register_rest_route('molavi-state/v1','/activate',['methods'=>'POST','permission_callback'=>'molavi_state_permission',
        'callback'=>static function($request){$body=$request->get_json_params();
            if(!is_bool($body['enabled']??null))return new WP_Error('state_request','Invalid activation',['status'=>400]);
            try {if($body['enabled'])foreach(['pipeline','reference'] as $store)molavi_state_inventory($store);
                update_option('molavi_state_active',$body['enabled'],false);return ['ok'=>true,'active'=>$body['enabled']];
            }catch(Throwable $e){return new WP_Error('state_unavailable','State stores unavailable',['status'=>503]);}}]);
});
