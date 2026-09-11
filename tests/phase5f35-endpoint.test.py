#!/usr/bin/env python3
"""Run the generated card against a disposable PHP application; no vCenter or DB."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
R=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('generator',R/'tools/build-phase5f35-vmcard.py');g=importlib.util.module_from_spec(spec);spec.loader.exec_module(g)
STUB=r'''<?php
$events=[];session_start();
register_shutdown_function(function(){echo "\nRESULT:".json_encode(['status'=>http_response_code()?:200,'events'=>$GLOBALS['events']]);});
$POST=json_decode(getenv('POST_DATA'));$_SERVER['REQUEST_METHOD']=getenv('METHOD');
function disable_php_errors(){}
class Database_PDO{}
class Crypto {function csrf_cookie(...$args){return ($args[2]??'')==='good';}}
class User {
 public $Crypto;function __construct($db){$this->Crypto=new Crypto;}
 function check_user_session(){$GLOBALS['events'][]='session';if(getenv('CASE')==='session'){http_response_code(403);exit;}}
 function is_admin($x){$GLOBALS['events'][]='admin';return getenv('CASE')!=='admin';}
}
class IntegrationSecretCipher {static function from_keyring_file(){$GLOBALS['events'][]='cipher';return new self;}}
class IntegrationVmwareVcenterConfigStore {
 function __construct(...$args){}
 function load_runtime_config($id,$enabled){$GLOBALS['events'][]='store';if(!$enabled)throw new RuntimeException('enabled check missing');if(getenv('CASE')==='disabled')throw new RuntimeException('disabled SECRET');return ['endpoint'=>'https://saved.invalid','username'=>'PRIVATE_USER','password'=>'SECRET','verify_tls'=>false];}
}
class IntegrationVmwareSnapshotService {
 static function fromRuntime($app,$id,$r){$GLOBALS['events'][]='cache';if($r['endpoint']!=='https://saved.invalid'||$r['verify_tls']!==false)throw new RuntimeException('wrong source');return new self;}
 static function denied($e){return strpos($e->getMessage(),'403')!==false;}
 function enabled(){return true;}
 function read($domain,$refresh=false,$force=false){$GLOBALS['events'][]='core';if(session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('session lock retained');
  if(in_array(getenv('CASE'),['denied','busy']))throw new RuntimeException(getenv('CASE')==='denied'?'snapshot_access_denied':'snapshot_refresh_busy');
  return ['payload'=>['ok'=>true,'topology'=>['datacenters'=>[],'clusters'=>[],'hosts'=>[],'vms'=>[['external_id'=>'vm-1','name'=>'<img src=x>']]],'datastores'=>['datastores'=>[]]],'cache'=>['state'=>'fresh','age_seconds'=>2,'cache_snapshot_written'=>false]];
 }
}
class IntegrationVmwareVcenterVmDetail {function read_detail(...$args){$GLOBALS['events'][]='detail';return ['vm'=>['external_id'=>'vm-1','name'=>'<img src=x>','disks'=>[]],'guest_identity'=>[],'tools'=>[]];}}
class IntegrationVmwareVcenterDatastoreResolver {function resolve_vm_detail($detail,...$args){return $detail;}}
class IntegrationVmwareVcenterGuestNetwork {function read_vm_interfaces(...$args){$GLOBALS['events'][]='guest';if(getenv('CASE')==='guest_failure')throw new RuntimeException('invalid NIC SECRET');if(getenv('CASE')==='guest_denied')throw new RuntimeException('HTTP 403 SECRET');return ['vms'=>[]];}}
class IntegrationVmwareVcenterMetadata {function read_vm_tags(...$args){$GLOBALS['events'][]='tags';return ['tag_count'=>0,'category_count'=>0,'tags'=>[]];}}
class IntegrationPhpIpamAddressLinkResolver {function __construct(...$args){}}
'''
with tempfile.TemporaryDirectory() as d:
 root=Path(d);(root/'functions/classes').mkdir(parents=True)
 for rel,data in g.generate().items():
  p=root/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
  assert b'->read_topology(' not in data and b'->read_inventory(' not in data
  assert b'Server-Timing:' in data and b'Cache-Control: no-store' in data
  assert b'PHPIPAM_CUSTOM_VMWARE_PHASE5F24_OPTIONAL_GUEST_NETWORK_V1' in data
 (root/'functions/functions.php').write_text(STUB)
 names=['IntegrationVmwareVcenter','IntegrationSecretCipher','IntegrationVmwareVcenterConfigStore','IntegrationVmwareVcenterTopology','IntegrationVmwareVcenterDatastore','IntegrationVmwareVcenterDatastoreResolver','IntegrationVmwareVcenterVmDetail','IntegrationVmwareVcenterMetadata','IntegrationVmwareVcenterGuestNetwork','IntegrationPhpIpamAddressLinkResolver','IntegrationVmwareSnapshotCache','IntegrationVmwareSnapshotService','IntegrationVmwareVcenterDirectRestTransport']
 for name in names:(root/'functions/classes'/('class.'+name+'.php')).write_text('<?php // fixture\n')
 for phase,name in [('phase5d','IntegrationVmwareVcenterExplorer'),('phase5f35','IntegrationVmwareVmCardContext')]:
  shutil.copyfile(R/'layers'/phase/'functions/classes'/('class.'+name+'.php'),root/'functions/classes'/('class.'+name+'.php'))
 def call(case='',post=None,method='POST'):
  post={'config_id':'1','vm_id':'vm-1','csrf_cookie':'good','include_tags':'1'} if post is None else post
  result=subprocess.run(['php',str(root/'app/integrations/vmware/phase5f35-vm-card-result.php')],env=dict(os.environ,CASE=case,POST_DATA=json.dumps(post),METHOD=method),text=True,capture_output=True,check=True)
  assert not result.stderr,result.stderr
  body,info=result.stdout.split('\nRESULT:');assert 'SECRET' not in body and 'PRIVATE_USER' not in body
  return body,json.loads(info)
 for case in ('session','admin','disabled'):
  body,x=call(case);assert 'cache' not in x['events']
 for field,value in [('csrf_cookie','wrong'),('config_id',[]),('vm_id',[]),('vm_id','vm-1/other'),('include_tags','2'),('force_refresh',{})]:
  body,x=call(post={'config_id':'1','vm_id':'vm-1','csrf_cookie':'good',field:value});assert 'cache' not in x['events']
 body,x=call(method='GET');assert x['status']==405 and 'cipher' not in x['events']
 for case,status in [('denied',403),('busy',503),('guest_denied',403)]:
  body,x=call(case);assert x['status']==status,(case,x);assert '<img' not in body
 body,x=call();assert x['status']==200 and x['events']==['session','admin','cipher','store','cache','core','detail','guest','tags']
 assert 'data-cache-state="fresh"' in body and 'возраст 2 с' in body and '&lt;img src=x&gt;' in body
 body,x=call('guest_failure');assert x['status']==200 and 'unavailable' in body
 body,x=call(post={'config_id':'1','vm_id':'vm-1','csrf_cookie':'good','endpoint':'https://attacker.invalid','verify_tls':'1'});assert x['status']==200 and 'tags' not in x['events']
print('PHASE5F35_ENDPOINT_GUARDS_RENDER_SESSION_OK')
