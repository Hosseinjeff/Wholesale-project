<?php
/** Plugin Name: Molavi Native Reference Workflow */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_reference_snapshot(string $id): ?array {
    return molavi_domain_read('reference',static function(SQLite3 $db)use($id):?array {
        $row=molavi_domain_row($db,'SELECT payload FROM reference_snapshots WHERE snapshot_id=?',[$id]);
        return $row?array_merge(molavi_domain_decode($row['payload']),['snapshot_id'=>$id]):null;
    });
}
function molavi_domain_reference_price_index(array $snapshot,bool $decimal=false): array {
    $index=[];foreach($snapshot['rows']??[] as $row){if(!is_array($row)||count($row)!==21)continue;
        $id=(string)molavi_domain_py_value($row[16],molavi_domain_py_value($row[2]));if(array_key_exists($id,$index))continue;
        $prices=[];foreach(['unit'=>4,'package'=>3] as $role=>$column){$value=$row[$column];
            if($decimal){$raw=is_bool($value)||!is_scalar($value)?'':str_replace([',','٬'],'',molavi_domain_digits($value));
                $prices[$role]=is_numeric($raw)&&is_finite((float)$raw)&&(float)$raw>0?(float)$raw:null;}
            else $prices[$role]=molavi_domain_positive_price($value)?:null;
        }$index[$id]=$prices;
    }return $index;
}
/** Carry only existing owner mappings whose current reference price is within 5%. */
function molavi_domain_inherit_reference_mappings(string $targetId,?string $clock=null): array {
    $data=molavi_domain_read('reference',static fn(SQLite3 $db)=>[
        'decisions'=>molavi_domain_rows($db,'SELECT * FROM reference_item_decisions ORDER BY sequence'),
        'inheritances'=>molavi_domain_rows($db,'SELECT * FROM reference_item_inheritances'),
        'snapshots'=>molavi_domain_rows($db,'SELECT snapshot_id,payload FROM reference_snapshots')]);
    if(!$data['decisions'])return [];
    $payloads=[];$times=[];foreach($data['snapshots'] as $row){$payloads[$row['snapshot_id']]=$row['payload'];$parsed=molavi_domain_decode($row['payload']);$times[$row['snapshot_id']]=(string)($parsed['extracted_at']??'');}
    unset($parsed,$data['snapshots']);if(!isset($payloads[$targetId]))return [];$targetTime=$times[$targetId];
    $cached=[];$chains=[];foreach($data['inheritances'] as $row){
        if($row['snapshot_id']===$targetId)$cached[$row['signature']]=$row;
        if(isset($times[$row['snapshot_id']])&&strcmp($times[$row['snapshot_id']],$targetTime)<0)$chains[$row['signature']][]=$row;
    }
    $latest=[];$explicit=[];foreach($data['decisions'] as $row){if($row['snapshot_id']===$targetId){$explicit[$row['signature']]=true;continue;}
        if(isset($times[$row['snapshot_id']])&&strcmp($times[$row['snapshot_id']],$targetTime)<0)$latest[$row['signature']]=$row;}
    $prices=[];$getPrice=static function(string $id,string $reference,string $role)use(&$prices,$payloads):?float {
        if(!isset($payloads[$id]))return null;if(!isset($prices[$id]))$prices[$id]=molavi_domain_reference_price_index(molavi_domain_decode($payloads[$id]),true);
        return $prices[$id][$reference][$role]??null;
    };
    $out=[];$now=$clock??molavi_domain_now();
    foreach($latest as $signature=>$decision){if(isset($explicit[$signature])||$decision['choice']!=='match'||$decision['reference_id']===''||!in_array($decision['price_role'],['unit','package'],true))continue;
        $source=$decision['snapshot_id'];$old=$getPrice($source,$decision['reference_id'],$decision['price_role']);$chain=$chains[$signature]??[];
        usort($chain,static fn($a,$b)=>strcmp($times[$a['snapshot_id']]??'',$times[$b['snapshot_id']]??''));
        if($chain){$last=end($chain);if(strcmp($times[$last['snapshot_id']]??'',$times[$source]??'')>0){
            if($last['state']!=='within_margin')continue;$source=$last['snapshot_id'];$old=$last['current_reference_price'];}}
        $new=$getPrice($targetId,$decision['reference_id'],$decision['price_role']);$ratio=$old!==null&&$new!==null&&$old>0?abs($new-$old)/$old:null;
        $state=$new===null?'reference_missing':($old===null?'reference_price_unavailable':($ratio<=0.05?'within_margin':'outside_margin'));
        $inheritance='inherit:'.hash('sha256',$targetId."\0".$signature."\0".$decision['decision_id']);$record=$cached[$signature]??null;
        if($record===null){molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,
            'INSERT OR IGNORE INTO reference_item_inheritances (inheritance_id,snapshot_id,source_snapshot_id,source_decision_id,signature,reference_id,price_role,prior_reference_price,current_reference_price,change_ratio,state,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$inheritance,$targetId,$source,$decision['decision_id'],$signature,$decision['reference_id'],$decision['price_role'],$old,$new,$ratio,$state,$now]));
            $record=['inheritance_id'=>$inheritance,'state'=>$state];}
        if($record['state']==='within_margin')$out[$signature]=['choice'=>'match','reference_id'=>$decision['reference_id'],'price_role'=>$decision['price_role'],
            'decision_id'=>$record['inheritance_id'],'source_decision_id'=>$decision['decision_id'],'source_snapshot_id'=>$source,'price_change_ratio'=>$ratio];
    }return $out;
}
/** Prepare only reference-related cell changes; supplier facts and manual edits remain intact. */
function molavi_domain_reference_product_changes(array $products,?array $previous,array $next,array $older=[],?array $affected=null,?string $clock=null): array {
    $counts=['checked'=>0,'retained'=>0,'needs_review'=>0];$updates=[];$id=(string)($next['snapshot_id']??'');if($id==='')return ['counts'=>$counts,'updates'=>[]];
    $nextPrices=molavi_domain_reference_price_index($next);$olderPrices=[];$now=$clock??molavi_domain_now();
    if($previous)$older[$previous['snapshot_id']]=$previous;
    foreach($products as $index=>$record){$status=strtolower(trim((string)($record['Status']??'')));$approval=(string)($record['Reference Approval State']??'');
        $reference=(string)($record['Reference Match ID']??'');$role=(string)($record['Reference Price Role']??'');$source=(string)($record['Reference Snapshot ID']??'');
        if($affected!==null&&!in_array($reference,$affected,true))continue;
        $approved=in_array($approval,['owner_approved','approved_compatible','approved_within_margin'],true)||($approval===''&&$reference!==''&&in_array($role,['unit','package'],true));
        if(!in_array($status,['imported','approved'],true)||!$approved||$reference===''||!in_array($role,['unit','package'],true)||$source===''||$source===$id)continue;
        if(!isset($olderPrices[$source]))$olderPrices[$source]=molavi_domain_reference_price_index($older[$source]??[]);
        $old=$olderPrices[$source][$reference][$role]??null;$new=$nextPrices[$reference][$role]??null;$ratio=$old&&$new?abs($new-$old)/$old:null;$counts['checked']++;
        if($ratio!==null&&$ratio<=0.05){$changes=['Reference Snapshot ID'=>$id,'Reference Approval State'=>'approved_within_margin',
            'Reference Price Change Percent'=>round($ratio*100,4,PHP_ROUND_HALF_EVEN),'Reference Price Checked At'=>$now,'Reference Review Reason'=>''];$counts['retained']++;}
        else{
            if($new===null){$state='reference_missing';$reason='مرجع کالا در نسخه جدید پیدا نشد؛ اطلاعات پیام اصلی را بررسی کنید.';}
            elseif($old===null){$state='price_unavailable';$reason='قیمت مرجع قبلی برای سنجش تغییر در دسترس نیست؛ اطلاعات پیام اصلی را بررسی کنید.';}
            else{$state='outside_margin';$reason='قیمت مرجع بیش از ۵٪ تغییر کرده؛ اطلاعات پیام اصلی را بررسی و به‌روزرسانی کنید.';}
            $changes=['Status'=>'needs_review','Reference Approval State'=>$state,'Reference Price Change Percent'=>$ratio!==null?round($ratio*100,4,PHP_ROUND_HALF_EVEN):'',
                'Reference Price Checked At'=>$now,'Reference Review Reason'=>$reason];$counts['needs_review']++;
        }
        $updates[]=['row'=>$index+2,'product_id'=>(string)($record['Product ID']??''),'fields'=>$changes];
    }return ['counts'=>$counts,'updates'=>$updates];
}
function molavi_domain_reference_project(array $snapshot,?array $previous,bool $incremental): array {
    $headers=molavi_domain_reference_headers();$rows=$snapshot['rows']??[];$id=(string)($snapshot['snapshot_id']??'');
    if(($snapshot['status']??null)!=='staged'||($snapshot['headers']??null)!==$headers||!$rows)molavi_domain_fail('active_reference_invalid');
    $projected=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT snapshot_id FROM reference_projection_state WHERE singleton=1')['snapshot_id']??null);
    if($projected===$id)return ['sheet'=>'ReferencePrices','row_count'=>count($rows),'verified'=>true,'snapshot_id'=>$id,'mode'=>'already_projected'];
    $summary=$snapshot['change_summary']??[];
    $delta=$incremental&&!empty($snapshot['source_product_ids'])&&!empty($previous['source_product_ids'])
        &&empty($summary['metadata_upgrade'])&&empty($summary['added_count'])&&empty($summary['removed_count'])&&empty($summary['order_changed'])
        &&$projected===($previous['snapshot_id']??null);
    if($delta){
        $byId=[];foreach($snapshot['source_product_ids'] as $index=>$source)$byId[(string)$source]=$index;
        $groups=['name'=>[0,1,2,4,5,6,7,8,9,10],'prices'=>[3,4],'sku'=>[16],'categories'=>[13],'attributes'=>[11,12]];$updates=[];
        foreach($summary['changed']??[] as $change){$source=(string)$change['source_product_id'];if(!array_key_exists($source,$byId))molavi_domain_fail('changed_reference_missing');
            $index=$byId[$source];$columns=[20=>true];foreach($change['nodes']??[] as $node)foreach($groups[$node]??[] as $column)$columns[$column]=true;
            foreach(array_keys($columns) as $column)$updates[]=['range'=>molavi_domain_sheet_column($column+1).($index+2),'values'=>[[$rows[$index][$column]]]];
        }
        foreach(array_chunk($updates,500) as $chunk)molavi_domain_sheet_cells('ReferencePrices',$chunk);
    }else{
        if(!isset(molavi_domain_sheet_properties()['ReferencePrices']))molavi_domain_sheet_ensure('ReferencePrices',$headers);
        $existing=molavi_domain_sheets(['ReferencePrices'],true,true)['ReferencePrices'];$current=array_slice($existing[0]??$headers,0,count($headers));
        $legacy=$headers;$legacy[15]='PDF Row Number';if($current!==$headers&&$current!==$legacy)molavi_domain_fail('reference_sheet_schema_changed');
        $table=[$headers,...$rows];$height=max(count($table),count($existing));while(count($table)<$height)$table[]=array_fill(0,count($headers),'');
        molavi_domain_sheet_cells('ReferencePrices',[['range'=>'A1:U'.$height,'values'=>$table]]);
    }
    $verified=molavi_domain_sheets(['ReferencePrices'],true,true)['ReferencePrices'];
    if(array_slice($verified[0]??[],0,21)!==$headers||count($verified)<count($rows)+1)molavi_domain_fail('reference_projection_receipt_mismatch',503);
    foreach($rows as $index=>$expected){$actual=array_pad($verified[$index+1]??[],21,'');
        if(molavi_domain_json(array_slice($actual,0,21))!==molavi_domain_json($expected))molavi_domain_fail('reference_projection_receipt_mismatch',503);}
    molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_projection_state VALUES (1,?)',[$id]));
    $result=['sheet'=>'ReferencePrices','row_count'=>count($rows),'verified'=>true,'snapshot_id'=>$id,'mode'=>$delta?'incremental':'full'];
    if($delta)$result['updated_products']=count($summary['changed']??[]);return $result;
}
function molavi_domain_reference_complete(array $snapshot,?array $previous,bool $incremental): array {
    $projection=molavi_domain_reference_project($snapshot,$previous,$incremental);$id=$snapshot['snapshot_id'];
    $counts=['checked'=>0,'retained'=>0,'needs_review'=>0];$captured=null;$state='complete';
    $reconciled=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT snapshot_id FROM reference_reconciliation_state WHERE singleton=1')['snapshot_id']??null);
    if($reconciled!==$id){
        try{
            molavi_domain_inherit_reference_mappings($id);$affected=$incremental?($snapshot['change_summary']['reconcile_reference_ids']??null):null;
            if(!$incremental||$affected){
                molavi_domain_sheet_ensure('Products',molavi_domain_product_headers());$tables=molavi_domain_sheets(['Products'],true);$products=molavi_domain_records($tables['Products']);$older=[];
                foreach($products as $record){$source=(string)($record['Reference Snapshot ID']??'');
                    if($source!==''&&$source!==$id&&$source!==($previous['snapshot_id']??null)&&!array_key_exists($source,$older))$older[$source]=molavi_domain_reference_snapshot($source)??[];}
                $changes=molavi_domain_lock('publication',static function()use($previous,$snapshot,$older,$affected):array {
                    $table=molavi_domain_sheets(['Products'],true)['Products'];$headers=$table[0]??[];
                    foreach(molavi_domain_records($table) as $record){$source=(string)($record['Reference Snapshot ID']??'');
                        if($source!==''&&$source!==$snapshot['snapshot_id']&&$source!==($previous['snapshot_id']??null)&&!array_key_exists($source,$older))$older[$source]=molavi_domain_reference_snapshot($source)??[];}
                    $changes=molavi_domain_reference_product_changes(molavi_domain_records($table),$previous,$snapshot,$older,$affected);
                    $cells=[];foreach($changes['updates'] as $update){
                        // Keep each product's reference fields in the same atomic Sheets request.
                        if($cells&&count($cells)+count($update['fields'])>500){molavi_domain_sheet_cells('Products',$cells);$cells=[];}
                        foreach($update['fields'] as $field=>$value){
                            $column=array_search($field,$headers,true);if($column===false)molavi_domain_fail('sheet_header_missing',409,['field'=>$field]);
                            $cells[]=['range'=>molavi_domain_sheet_column($column+1).$update['row'],'values'=>[[$value]]];
                        }
                    }
                    if($cells)molavi_domain_sheet_cells('Products',$cells);
                    if($changes['updates']){
                        $verified=molavi_domain_records(molavi_domain_sheets(['Products'],true,true)['Products']);
                        foreach($changes['updates'] as $update){$row=$verified[$update['row']-2]??[];
                            if((string)($row['Product ID']??'')!==$update['product_id'])molavi_domain_fail('reference_product_receipt_mismatch',503);
                            foreach($update['fields'] as $key=>$value){$actual=$row[$key]??'';
                                if(is_int($value)||is_float($value)){$matches=is_numeric($actual)&&(float)$actual===(float)$value;}
                                else $matches=(string)$actual===(string)$value;
                                if(!$matches)molavi_domain_fail('reference_product_receipt_mismatch',503);
                            }
                        }
                    }return $changes;
                });$counts=$changes['counts'];
                if($counts['checked']){
                    $fresh=molavi_domain_sheets(['Products','Offers','Shop'],true);$captured=molavi_domain_status_capture($fresh,'reference_snapshot:'.$id);
                    molavi_domain_observe_projection(molavi_domain_projection_digest($fresh));
                }
            }
            molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_reconciliation_state VALUES (1,?)',[$id]));
        }catch(Throwable $e){$state='failed';
            error_log('[molavi-native] reference_reconciliation code='.($e instanceof MolaviWorkflowError?$e->getMessage():get_class($e)));}
    }
    return ['projection'=>$projection,'product_reference_updates'=>$counts,'product_reconciliation_state'=>$state,'product_status_snapshot'=>$captured];
}
