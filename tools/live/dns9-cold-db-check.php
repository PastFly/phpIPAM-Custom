<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0) exit(2);
try {
    require_once '/var/www/html/functions/functions.php';
    $db=new Database_PDO();
    if(!is_callable([$db,'isConnected']) || !is_callable([$db,'beginTransaction']) || !is_callable([$db,'rollBack'])) {
        echo "DNS9_COLD_DB_JSON={\"supported\":false}\n"; exit(0);
    }
    $before=$db->isConnected();
    if(!is_bool($before)){echo "DNS9_COLD_DB_JSON={\"supported\":false}\n";exit(0);}
    $began=false;$nullError=false;$rolledBack=false;
    try {$began=$db->beginTransaction()===true;}
    catch(Throwable $e) {$nullError=($e instanceof Error && str_contains($e->getMessage(),'beginTransaction() on null'));}
    finally {if($began){try {$rolledBack=$db->rollBack()===true;}catch(Throwable $e){$rolledBack=false;}}}
    echo 'DNS9_COLD_DB_JSON='.json_encode(['supported'=>true,'before_connected'=>$before,'began'=>$began,
        'null_error'=>$nullError,'rollback_ok'=>$rolledBack],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) {fwrite(STDERR,"DNS9_COLD_DB_ERROR=probe_failed\n");exit(1);}
