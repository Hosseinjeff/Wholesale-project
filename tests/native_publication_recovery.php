<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-recovery-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
foreach(['state-host','domain-core','domain-schemas','domain-reference-parser','domain-gateway','domain-publication-recovery','domain-jobs'] as $file)
 require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$root=molavi_state_root();$initial=new SQLite3($root.'/pipeline.sqlite3');$initial->close();$count=0;
function recover_same($actual,$expected,$label){global $count;if(molavi_domain_json($actual)!==molavi_domain_json($expected))throw new RuntimeException($label.' '.molavi_domain_json(['actual'=>$actual,'expected'=>$expected]));$count++;}
function recovery_seed($seed){$db=molavi_domain_db();foreach(['jobs','publication_holds','publication_hold_resolutions','sheet_projection_state'] as $table)$db->exec('DELETE FROM '.$table);foreach($seed as $step)molavi_domain_sql($db,$step['sql'],$step['params']);$db->close();}
function recovery_state(){return molavi_domain_read('pipeline',static fn($db)=>[
 'jobs'=>array_map('array_values',molavi_domain_rows($db,'SELECT id,state,error FROM jobs ORDER BY id')),
 'holds'=>array_map('array_values',molavi_domain_rows($db,'SELECT hold_id,reason FROM publication_holds ORDER BY hold_id')),
 'resolutions'=>array_map('array_values',molavi_domain_rows($db,'SELECT hold_id,reason,actor_id,note FROM publication_hold_resolutions ORDER BY id'))]);}
function molavi_domain_sheets($names,$fresh=false){return $GLOBALS['recovery_tables'];}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/native-publication-recovery-parity.json'),true,256,JSON_THROW_ON_ERROR);
foreach($fixture['proofs'] as $case){recovery_seed($case['seed']);recover_same(molavi_domain_batch_sources_verified('batch:synthetic-a',$case['tables']),$case['expected'],'source proof/'.$case['name']);}
foreach($fixture['api'] as $case){recovery_seed($case['seed']);$GLOBALS['recovery_tables']=$case['tables'];
 try{$actual=molavi_domain_publication_holds_api($case['method'],$case['body'],'wp:7');$status=200;}catch(MolaviWorkflowError $e){$actual=['ok'=>false,'error'=>$e->getMessage()];$status=$e->status;}
 recover_same($status,$case['status'],'hold API status/'.$case['name']);recover_same($actual,$case['expected'],'hold API body/'.$case['name']);recover_same(recovery_state(),$case['after'],'hold API atomic state/'.$case['name']);}
foreach($fixture['ledger'] as $case){recovery_seed($case['seed']);try{$actual=molavi_domain_reconcile_failed(['publish:synthetic-p'],['batch:synthetic-a'],'wp:7',str_repeat('a',64),$case['sync'],1000.5);}catch(MolaviWorkflowError $e){$actual=['error'=>true];}
 recover_same($actual,$case['expected'],'reconcile ledger/'.$case['name']);recover_same(recovery_state(),$case['after'],'reconcile ledger state/'.$case['name']);}
function molavi_domain_publish_shop($tables,$imagesOnly=false,$rounds=8){return molavi_domain_lock('publication',static function(){if(!empty($GLOBALS['change_sheet']))$GLOBALS['recovery_tables']['Shop']=[['Unlock Target',3000000]];return $GLOBALS['sync'];});}
foreach($fixture['backlog_api'] as $case){recovery_seed($case['seed']);$GLOBALS['recovery_tables']=$case['tables'];$GLOBALS['sync']=$case['sync'];$GLOBALS['change_sheet']=$case['change_sheet'];
 try{$actual=molavi_domain_reconcile_backlog_api($case['body'],'wp:7');$status=200;}catch(MolaviWorkflowError $e){$actual=array_merge(['ok'=>false,'error'=>$e->getMessage()],$e->details);$status=$e->status;}
 recover_same($status,$case['status'],'backlog API status/'.$case['name']);recover_same($actual,$case['expected'],'backlog API body/'.$case['name']);
 $jobs=molavi_domain_read('pipeline',static fn($db)=>array_map('array_values',molavi_domain_rows($db,"SELECT id,state,error FROM jobs WHERE id IN ('publish:synthetic-p','batch:synthetic-a') ORDER BY id")));
 recover_same($jobs,$case['existing_jobs'],'backlog API existing receipts/'.$case['name']);}
$intruder=['store'=>'pipeline','name'=>'publication','owner'=>str_repeat('f',32),'action'=>'acquire'];
$nested=molavi_domain_lock('publication',static fn()=>molavi_domain_lock('publication',static fn()=>molavi_state_lease($intruder)['acquired']));
recover_same($nested,false,'nested publication work retains its one shared lease');recover_same(molavi_state_lease($intruder)['acquired'],true,'outer callback releases the lease after nested work');$intruder['action']='release';molavi_state_lease($intruder);
foreach([['0.0',-1],['0',0],[0.5,0],[true,1],['invalid',-1],[null,0],['۱_۰',10]] as [$value,$expected])recover_same(molavi_domain_recovery_images_left($value),$expected,'strict image receipt conversion');
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native publication recovery '.$count." cases\n";
