#!/usr/bin/env python3
"""Execute controller guards in PHP with a disposable application and stubbed loaders."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
R=Path(__file__).resolve().parents[1]
STUB=r'''<?php
$events=[];
register_shutdown_function(function(){ echo "\nEVENTS:".json_encode($GLOBALS['events']); });
$POST=json_decode(getenv('POST_DATA')); $_SERVER['REQUEST_METHOD']=getenv('METHOD');
function disable_php_errors() {}
class Database_PDO {}
class C { function csrf_cookie(...$a) { return ($a[2]??'')==='good'; } }
class User {
 public $Crypto; function __construct($db){$this->Crypto=new C;}
 function check_user_session(){ $GLOBALS['events'][]='session'; if(getenv('CASE')==='session'){http_response_code(403);echo '{"ok":false,"error":"session_required"}';exit;}}
 function is_admin($x){$GLOBALS['events'][]='admin';return getenv('CASE')!=='admin';}
}
class IntegrationSecretCipher { static function from_keyring_file(){ $GLOBALS['events'][]='cipher'; return new self; } }
class IntegrationVmwareVcenterConfigStore {
 function __construct(...$a){}
 function load_runtime_config($id,$enabled){
 $GLOBALS['events'][]='enabled_store'; if(!$enabled)throw new RuntimeException('enabled guard missing');
 if(getenv('CASE')==='disabled')throw new RuntimeException('connector disabled PRIVATE upstream body');
 return ['endpoint'=>'https://server-supplied.invalid','username'=>'server-user','password'=>'PRIVATE','verify_tls'=>false];
 }
}
class IntegrationVmwareSnapshotService {
 static function fromRuntime($app,$id,$r){
 $GLOBALS['events'][]='cache_construct'; if($r['endpoint']!=='https://server-supplied.invalid'||$r['verify_tls']!==false)throw new RuntimeException('wrong source'); return new self;
 }
 function enabled(){return true;}
 function status(){return ['ok'=>true,'cache_enabled'=>true,'domains'=>[]];}
 function read($domain,$refresh,$force){$GLOBALS['events'][]='cache_read';
 if(getenv('CASE')==='denied')throw new RuntimeException('snapshot_access_denied');
 if(getenv('CASE')==='busy')throw new RuntimeException('snapshot_refresh_busy');
 return ['payload'=>['ok'=>true,'rows'=>[['vm_external_id'=>'vm-1','name'=>'fixture']]],'cache'=>['domain'=>$domain,'state'=>'fresh','cache_snapshot_written'=>false]];
 }
}
'''
with tempfile.TemporaryDirectory() as tmp:
    root=Path(tmp)
    shutil.copytree(R/'layers/phase5f34/app',root/'app')
    (root/'functions/classes').mkdir(parents=True)
    (root/'functions/functions.php').write_text(STUB)
    names=['IntegrationVmwareVcenter','IntegrationSecretCipher','IntegrationVmwareVcenterConfigStore','IntegrationVmwareSnapshotCache','IntegrationVmwareSnapshotService','IntegrationVmwareVcenterDirectTransport','IntegrationVmwareVcenterFaultAwareTransport','IntegrationVmwareVcenterDirectRestTransport','IntegrationVmwareVcenterTopology','IntegrationVmwareVcenterDatastore','IntegrationVmwareVcenterExplorer','IntegrationVmwareVcenterFolderInventory','IntegrationVmwareVcenterObjectFolders','IntegrationVmwareVcenterVmSearchFacts','IntegrationVmwareVcenterVmTagIndex']
    for name in names: (root/'functions/classes'/('class.'+name+'.php')).write_text('<?php // fixture\n')
    def call(case='',post=None,method='POST',path='app/integrations/vmware/cache/enriched-search-result.php'):
        data={'config_id':'1','csrf_cookie':'good'} if post is None else post
        env=dict(os.environ,CASE=case,POST_DATA=json.dumps(data),METHOD=method)
        result=subprocess.run(['php',str(root/path)],env=env,text=True,capture_output=True,check=True)
        assert not result.stderr,result.stderr
        body,events=result.stdout.split('\nEVENTS:')
        assert 'PRIVATE' not in body and 'server-user' not in body
        return json.loads(body),json.loads(events)
    for case,want in [('session','session_required'),('admin','administrative_privileges_required')]:
        x,e=call(case);assert x['error']==want and 'cipher' not in e and 'cache_construct' not in e
    for payload in [{'config_id':'1','csrf_cookie':'wrong'}, {'config_id':[],'csrf_cookie':'good'}, {'config_id':'../x','csrf_cookie':'good'}, {'config_id':'0','csrf_cookie':'good'}, {'config_id':'1','csrf_cookie':'good','force_refresh':[]}, {'config_id':'1','csrf_cookie':'good','force_refresh':'2'}]:
        x,e=call(post=payload);assert not x['ok'] and 'cache_construct' not in e
    x,e=call(method='GET');assert x['error']=='post_required' and 'cipher' not in e
    x,e=call('disabled');assert not x['ok'] and 'cache_construct' not in e
    x,e=call(post={'config_id':'1','csrf_cookie':'good','endpoint':'https://client-controlled.invalid','verify_tls':'1'})
    assert x['ok'] and x['cache']['state']=='fresh' and x['verify_tls'] is False
    assert e==['session','admin','cipher','enabled_store','cache_construct','cache_read']
    for case,want in [('denied','snapshot_access_denied'),('busy','snapshot_refresh_busy')]:
        x,e=call(case);assert x['error']==want
    x,e=call(path='app/tools/search/cache/vmware-global-result.php');assert x['ok'] and x['cache']['domain']=='search'
    x,e=call(path='app/integrations/vmware/cache/status.php');assert x['ok'] and 'cache_read' not in e
print('PHASE5F34_CONTROLLER_GUARDS_OK')
