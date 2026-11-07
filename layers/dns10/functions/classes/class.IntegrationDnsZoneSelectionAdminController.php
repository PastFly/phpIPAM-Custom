<?php
declare(strict_types=1);

/** Source-only controller; security context MUST be supplied by a trusted host bridge. */
final class IntegrationDnsZoneSelectionAdminController
{
    private Closure $factory;
    public function __construct(callable $factory) {$this->factory=Closure::fromCallable($factory);}
    private static function response(int $status,array $body): array
    {
        return ['status'=>$status,'headers'=>['Cache-Control'=>'no-store','Content-Type'=>'application/json; charset=UTF-8',
            'X-Content-Type-Options'=>'nosniff'],'body'=>$body];
    }
    private static function keys(array $data,array $expected): bool
    {
        return count($data)===count($expected)&&array_diff(array_keys($data),$expected)===[];
    }
    public function handle(#[SensitiveParameter] array $request): array
    {
        if(($request['method']??null)!=='POST')return self::response(405,['ok'=>false,'error'=>'method_not_allowed']);
        if(($request['authenticated']??null)!==true)return self::response(401,['ok'=>false,'error'=>'session_required']);
        if(($request['admin']??null)!==true||!is_int($request['actor_id']??null)||$request['actor_id']<1) {
            return self::response(403,['ok'=>false,'error'=>'admin_required']);
        }
        if(($request['https']??null)!==true||($request['csrf_valid']??null)!==true)return self::response(403,['ok'=>false,'error'=>'request_denied']);
        $input=$request['input']??null;
        if(!is_array($input)||!self::keys($input,['action','data'])||!is_string($input['action'])||!is_array($input['data'])) {
            return self::response(400,['ok'=>false,'error'=>'fields_invalid']);
        }
        try {if(strlen(json_encode($input,JSON_THROW_ON_ERROR))>16384)return self::response(413,['ok'=>false,'error'=>'request_too_large']);}
        catch(Throwable $_) {return self::response(400,['ok'=>false,'error'=>'fields_invalid']);}
        $action=$input['action'];$data=$input['data'];
        if($action==='select_zone') {
            if(!self::keys($data,['connection_id','zone','selected','expected_revision','discovery_id'])||
                !is_string($data['connection_id'])||!is_string($data['zone'])||!is_bool($data['selected'])||
                ($data['expected_revision']!==null&&!is_int($data['expected_revision']))||
                ($data['discovery_id']!==null&&!is_string($data['discovery_id']))||
                ($data['selected']&&$data['discovery_id']===null))return self::response(400,['ok'=>false,'error'=>'fields_invalid']);
            $requiresReauth=$data['selected'];
        } elseif($action==='set_zone_collection') {
            if(!self::keys($data,['id','expected_revision','enabled','discovery_id'])||!is_string($data['id'])||
                !is_int($data['expected_revision'])||!is_bool($data['enabled'])||
                ($data['discovery_id']!==null&&!is_string($data['discovery_id']))||
                ($data['enabled']&&$data['discovery_id']===null))return self::response(400,['ok'=>false,'error'=>'fields_invalid']);
            $requiresReauth=$data['enabled'];
        } else return self::response(400,['ok'=>false,'error'=>'action_not_supported']);
        if($requiresReauth&&($request['reauthenticated']??null)!==true)return self::response(403,['ok'=>false,'error'=>'reauthentication_required']);
        try {
            $service=($this->factory)();$actor=$request['actor_id'];
            $result=$action==='select_zone'
                ?$service->setSelected($data['connection_id'],$data['zone'],$data['selected'],$data['expected_revision'],$data['discovery_id'],$actor)
                :$service->setCollectionEnabled($data['id'],$data['expected_revision'],$data['enabled'],$data['discovery_id'],$actor);
            if(!is_array($result)||!is_string($result['id']??null)||!preg_match('/^[0-9a-f]{32}$/D',$result['id'])||
                !is_string($result['zone_name']??null)||strlen($result['zone_name'])>254||
                !is_bool($result['selected']??null)||!is_bool($result['collection_enabled']??null)||
                !is_int($result['revision']??null)||$result['revision']<1)throw new RuntimeException('selection_unavailable');
            $output=[];foreach(['id','zone_name','selected','collection_enabled','revision'] as $key)$output[$key]=$result[$key];
            return self::response(200,['ok'=>true,'data'=>$output]);
        } catch(DomainException $e) {
            $code=$e->getMessage();
            if(in_array($code,['revision_conflict','discovery_unavailable','source_disabled','dataset_not_selected'],true))return self::response(409,['ok'=>false,'error'=>$code]);
            if($code==='dataset_not_found')return self::response(404,['ok'=>false,'error'=>$code]);
            if(in_array($code,['zone_invalid','zone_not_eligible','selection_limit'],true))return self::response(400,['ok'=>false,'error'=>$code]);
            return self::response(503,['ok'=>false,'error'=>'selection_unavailable']);
        } catch(Throwable $_) {return self::response(503,['ok'=>false,'error'=>'selection_unavailable']);}
    }
}
