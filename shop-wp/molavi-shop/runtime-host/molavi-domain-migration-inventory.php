<?php
/** Plugin Name: Molavi Workflow Migration Inventory */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_inventory_digest(array $rows, array $fields): string {
    $canonical = [];
    foreach ($rows as $row) {
        $canonical[] = implode("\x1f",array_map(static fn($field)=>(string)($row[$field]??''),$fields));
    }
    sort($canonical,SORT_STRING);
    return hash('sha256',implode("\n",$canonical));
}

/** Keep the legacy diagnostic contract while reading the active WordPress store. */
function molavi_domain_migration_inventory(string $store): array {
    $file = molavi_state_path($store);
    if (!is_file($file)) return ['available'=>false,'file_bytes'=>0,'tables'=>(object)[],'error'=>'database_unavailable'];
    $db = new SQLite3($file,SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    try {
        $db->exec('BEGIN');
        $tables = [];
        foreach (molavi_domain_rows($db,"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $row) {
            $name = $row['name'];
            $quoted = '"'.str_replace('"','""',$name).'"';
            $tables[$name] = (int)$db->querySingle('SELECT COUNT(*) FROM '.$quoted);
        }
        $conflicts = isset($tables['sheet_sync_conflicts']) ? molavi_domain_rows($db,
            'SELECT conflict_id,product_id,source_revision,state,choice FROM sheet_sync_conflicts ORDER BY conflict_id') : [];
        $conflictCounts = [];
        foreach ($conflicts as $row) {
            $state = (string)molavi_domain_py_value($row['state'],'unknown');
            $conflictCounts[$state] = ($conflictCounts[$state]??0)+1;
        }
        $commands = isset($tables['supplier_price_verification_commands']) ? molavi_domain_rows($db,
            'SELECT command_id,offer_id,source_revision,verified_at FROM supplier_price_verification_commands ORDER BY command_id') : [];
        $evidence = isset($tables['supplier_price_evidence']) ? molavi_domain_rows($db,
            'SELECT offer_id,source_revision,verified_at,method FROM supplier_price_evidence ORDER BY offer_id') : [];
        return [
            'available'=>true,'file_bytes'=>filesize($file),
            'page_size'=>(int)$db->querySingle('PRAGMA page_size'),
            'page_count'=>(int)$db->querySingle('PRAGMA page_count'),
            'freelist_pages'=>(int)$db->querySingle('PRAGMA freelist_count'),
            'tables'=>(object)$tables,
            'job_states'=>isset($tables['jobs'])?molavi_domain_rows($db,'SELECT kind,state,COUNT(*) AS row_count FROM jobs GROUP BY kind,state ORDER BY kind,state'):[],
            'sheet_conflicts'=>['counts_by_state'=>(object)$conflictCounts,'rows'=>$conflicts,
                'digest'=>molavi_domain_inventory_digest($conflicts,['conflict_id','product_id','source_revision','state','choice'])],
            'supplier_price_verification_commands'=>['count'=>count($commands),'rows'=>$commands,
                'digest'=>molavi_domain_inventory_digest($commands,['command_id','offer_id','source_revision','verified_at'])],
            'supplier_price_evidence'=>['count'=>count($evidence),'rows'=>$evidence,
                'digest'=>molavi_domain_inventory_digest($evidence,['offer_id','source_revision','verified_at','method'])],
        ];
    } finally {
        $db->close();
    }
}
function molavi_domain_migration_inventory_api(string $actor): array {
    return ['ok'=>true,'actor'=>$actor,'pipeline'=>molavi_domain_migration_inventory('pipeline'),
        'reference'=>molavi_domain_migration_inventory('reference')];
}
