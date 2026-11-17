<?php
/** Plugin Name: Molavi Native Owner Workflow Outbox */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_outbox_domains(): array {
    $domains=[];
    foreach(['supplier_price'=>'/pipeline/supplier-price-evidence','sheet_conflict'=>'/pipeline/sheet-conflicts'] as $domain=>$path)
        if(molavi_domain_route_enabled('POST',$path))$domains[]=$domain;
    return $domains;
}
/** Reuse the existing MySQL decision/outbox and its receipt validation. */
function molavi_domain_drain_outbox(int $limit=3,float $budget=15.0): array {
    $result=['claimed'=>0,'delivered'=>0,'retried'=>0,'failed'=>0,'error'=>''];$domains=molavi_domain_outbox_domains();
    if(!$domains||!function_exists('molavi_owner_review_store_pending_commands')||!function_exists('molavi_owner_review_outbox_store_receipt'))return $result;
    if(!get_option('molavi_state_active',false)){$result['error']='native_state_inactive';return $result;}
    global $wpdb;$until=microtime(true)+$budget;
    for($index=0;$index<max(1,min(20,$limit));$index++){
        if(microtime(true)>=$until)break;
        $commands=molavi_owner_review_store_pending_commands(1,$domains);
        if($commands===null){$result['error']='outbox_claim_unavailable';break;}if(!$commands)break;
        $command=$commands[0];$result['claimed']++;
        $id=(string)($command['command_id']??'');$type=(string)($command['command_type']??'');$payload=$command['payload']??null;
        if(!preg_match('/^[a-f0-9]{64}$/D',$id)||!in_array($type,$domains,true)||!is_array($payload)){
            $state='failed';$receipt=['error'=>'outbox_command_invalid'];
        }else{
            $table=molavi_workflow_decisions_table();$outbox=molavi_workflow_outbox_table();
            $actorId=(int)$wpdb->get_var($wpdb->prepare("SELECT d.actor_id FROM {$outbox} o INNER JOIN {$table} d ON d.decision_id=o.decision_id WHERE o.command_id=%s",$id));
            $path=$type==='supplier_price'?'/pipeline/supplier-price-evidence':'/pipeline/sheet-conflicts';
            $applied=molavi_domain_dispatch('POST',$path,[],$payload,'wp:'.$actorId);
            $status=$applied['status'];$receipt=$applied['body'];
            if($status===200&&($receipt['ok']??false)===true)$state='delivered';
            else{$state=in_array($status,[408,425,429],true)||$status>=500?'queued':'failed';
                $receipt=['error'=>(string)($receipt['error']??'native_command_rejected')];}
        }
        if(!molavi_owner_review_outbox_store_receipt(['command_id'=>$id,'state'=>$state,'receipt'=>$receipt])){
            $result['error']='outbox_receipt_rejected';break;
        }
        $result[$state==='delivered'?'delivered':($state==='queued'?'retried':'failed')]++;
    }
    return $result;
}
