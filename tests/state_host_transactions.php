<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');define('MOLAVI_REFERENCE_BRIDGE_TOKEN',str_repeat('s',40));
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-state-test-'.bin2hex(random_bytes(6)));
$GLOBALS['options']=[];
function add_action(...$args){}
function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
class WP_Error {function __construct(public $code,public $message,public $data=[]){}}
class Request {function __construct(public $token=''){}function get_header($name){return $this->token;}}
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";}
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-state-host.php';
check(molavi_state_permission(new Request()) instanceof WP_Error,'state API rejects missing token');
check(molavi_state_permission(new Request(hash_hmac('sha256','molavi-state-v1',MOLAVI_REFERENCE_BRIDGE_TOKEN)))===true,'state token is scoped to the state host');
$root=molavi_state_root();$source=$root.'/source.sqlite3';$db=new SQLite3($source);
$db->exec('CREATE TABLE data (id TEXT PRIMARY KEY,value TEXT NOT NULL)');$db->exec("INSERT INTO data VALUES ('pending','must survive')");$db->close();
$contents=file_get_contents($source);$import=['store'=>'pipeline','id'=>str_repeat('a',32),'sha256'=>hash('sha256',$contents),'size'=>strlen($contents),'offset'=>0,'chunk'=>base64_encode($contents)];
$receipt=molavi_state_import($import);
check($receipt['complete']&&$receipt['inventory']['tables']['data']['rows']===1,'verified import preserves existing records');
check(molavi_state_import($import)['complete'],'import response loss can be retried without replacing authority');
$body=['store'=>'pipeline','id'=>str_repeat('b',32),'mode'=>'preview','observed_at'=>microtime(true),'steps'=>[
 ['sql'=>'SELECT value FROM data WHERE id=?','params'=>['pending']],
 ['sql'=>'UPDATE data SET value=? WHERE id=?','params'=>['completed','pending']]]];
$preview=molavi_state_transaction($body);
$db=molavi_state_open('pipeline');check($db->querySingle("SELECT value FROM data WHERE id='pending'")==='must survive','preview never persists partial writes');$db->close();
foreach($preview['tokens'] as $i=>$token)$body['steps'][$i]['expected']=$token;
$body['mode']='commit';$committed=molavi_state_transaction($body);
check($committed['committed'],'complete validated program commits atomically');
check(molavi_state_transaction($body)['replayed'],'lost commit response returns idempotent receipt');
$body['id']=str_repeat('c',32);
try{molavi_state_transaction($body);check(false,'stale transaction rejected');}catch(DomainException $e){check(true,'changed read preconditions reject stale write');}
$db=molavi_state_open('pipeline');check($db->querySingle("SELECT value FROM data WHERE id='pending'")==='completed','rejected write preserves current state');
$db->exec("CREATE TRIGGER stamp AFTER INSERT ON data BEGIN UPDATE data SET value=CAST(julianday('now') AS TEXT) WHERE id=NEW.id; END");$db->close();
$body=['store'=>'pipeline','id'=>str_repeat('d',32),'mode'=>'preview','observed_at'=>microtime(true),'steps'=>[
 ['sql'=>'INSERT INTO data VALUES (?,?)','params'=>['clock','initial']],['sql'=>'SELECT value FROM data WHERE id=?','params'=>['clock']]]];
$preview=molavi_state_transaction($body);foreach($preview['tokens'] as $i=>$token)$body['steps'][$i]['expected']=$token;
usleep(20000);$body['mode']='commit';check(molavi_state_transaction($body)['committed'],'transaction clock keeps trigger previews deterministic');
$lease=['store'=>'pipeline','name'=>'publication','owner'=>str_repeat('e',32),'action'=>'acquire'];
check(molavi_state_lease($lease)['acquired'],'WordPress grants single-writer publication lease');
$competitor=$lease;$competitor['owner']=str_repeat('f',32);check(!molavi_state_lease($competitor)['acquired'],'competing executor cannot publish under another lease');
$lease['action']='release';molavi_state_lease($lease);check(molavi_state_lease($competitor)['acquired'],'released lease can be acquired by another executor');
$inventory=molavi_state_inventory('pipeline');check(!isset($inventory['tables']['molavi_state_commits'])&&$inventory['tables']['data']['rows']===2,'inventory separates transport metadata from business records');
$now=time();$message=['id'=>'synthetic','content'=>'کالای نمونه','url'=>'https://example.invalid/post/1'];
$payload=molavi_state_json(['batch_id'=>'old','messages'=>[$message],'expected_count'=>1,'transmission_complete'=>true]);
$db=molavi_state_open('pipeline');$db->exec('CREATE TABLE jobs (id TEXT PRIMARY KEY,kind TEXT,state TEXT,updated_at REAL,payload TEXT)');
$db->exec('CREATE TABLE inbox (id TEXT PRIMARY KEY,batch_id TEXT,payload TEXT)');
foreach(['done','needs_review'] as $status){
 $s=$db->prepare('INSERT INTO jobs VALUES (?,?,?,?,?)');foreach([$status,'batch',$status,$now-31*86400,$payload] as $i=>$value)$s->bindValue($i+1,$value,is_int($value)?SQLITE3_INTEGER:SQLITE3_TEXT);$s->execute();$s->close();
 $s=$db->prepare('INSERT INTO inbox VALUES (?,?,?)');$s->bindValue(1,$status);$s->bindValue(2,$status);$s->bindValue(3,molavi_state_json($message));$s->execute();$s->close();
}$db->close();
update_option('molavi_pipeline_payload_retention_days','30',false);
check(molavi_state_retention_days()===30,'retention accepts the string returned by WordPress option storage');
$compacted=molavi_state_housekeeping($now);check($compacted===['jobs_compacted'=>1,'inbox_compacted'=>1],'WordPress performs completed-payload housekeeping locally');
$db=molavi_state_open('pipeline');$summary=json_decode($db->querySingle("SELECT payload FROM inbox WHERE id='done'"),true);
check($summary['content_sha256']===hash('sha256',$message['content'])&&$summary['content_length']===11&&$summary['url']===$message['url'],'compaction retains source hash, Unicode length and source link');
check($db->querySingle("SELECT payload FROM jobs WHERE id='needs_review'")===$payload,'review-needed business payloads remain intact');$db->close();
check(molavi_state_housekeeping($now)===['jobs_compacted'=>0,'inbox_compacted'=>0],'native housekeeping retries are idempotent');
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);
