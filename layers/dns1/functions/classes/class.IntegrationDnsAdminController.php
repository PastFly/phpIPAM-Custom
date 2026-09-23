<?php
declare(strict_types=1);

/** Host supplies trusted auth context and a lazy service factory; never pass raw $_POST as $request. */
final class IntegrationDnsAdminController
{
    private Closure $factory;
    public function __construct(callable $factory) { $this->factory=Closure::fromCallable($factory); }
    private static function response(int $status,array $body): array
    {
        return ['status'=>$status,'headers'=>['Cache-Control'=>'no-store','Content-Type'=>'application/json; charset=UTF-8','X-Content-Type-Options'=>'nosniff'],'body'=>$body];
    }
    public function handle(#[SensitiveParameter] array $request): array
    {
        // These flags are resolved by the phpIPAM bridge, never from the nested form payload.
        if (($request['method']??null)!=='POST') return self::response(405,['ok'=>false,'error'=>'method_not_allowed']);
        if (($request['authenticated']??null)!==true) return self::response(401,['ok'=>false,'error'=>'session_required']);
        if (($request['admin']??null)!==true || !is_int($request['actor_id']??null) || $request['actor_id']<1) return self::response(403,['ok'=>false,'error'=>'admin_required']);
        if (($request['https']??null)!==true || ($request['csrf_valid']??null)!==true) return self::response(403,['ok'=>false,'error'=>'request_denied']);
        $in=$request['input']??null;
        if (!is_array($in) || array_diff(array_keys($in),['action','data']) || !is_string($in['action']??null)) return self::response(400,['ok'=>false,'error'=>'fields_invalid']);
        $action=$in['action'];
        if (!in_array($action,['snapshot','save_profile','save_connection','revoke_profile','delete_profile','delete_connection','register_collector','save_assignment','revoke_collector','queue_test_connection','queue_list_zones','set_collection_enabled'],true)) return self::response(400,['ok'=>false,'error'=>'action_not_supported']);
        try {
            if (strlen(json_encode($in,JSON_THROW_ON_ERROR))>16384) return self::response(413,['ok'=>false,'error'=>'request_too_large']);
            $data=$in['data']??[]; if (!is_array($data)) throw new DomainException('fields_invalid');
            if ($action==='snapshot' && $data!==[]) throw new DomainException('fields_invalid');
            if (in_array($action,['revoke_profile','delete_profile','delete_connection'],true)) {
                $allowed=$action==='revoke_profile'?['id','expected_revision','acknowledge_connections']:['id','expected_revision'];
                if (array_diff(array_keys($data),$allowed) || !is_string($data['id']??null) || !is_int($data['expected_revision']??null) ||
                    ($action==='revoke_profile' && !is_array($data['acknowledge_connections']??null))) throw new DomainException('fields_invalid');
            }
            if ($action==='register_collector') {
                if (array_keys($data)!==['name'] || !is_string($data['name'])) throw new DomainException('fields_invalid');
            }
            if ($action==='save_assignment') {
                if (array_diff(array_keys($data),['connection_id','collector_id','expected_revision']) ||
                    array_diff(['connection_id','collector_id','expected_revision'],array_keys($data)) ||
                    !is_string($data['connection_id']) || !is_string($data['collector_id']) ||
                    !is_int($data['expected_revision'])) throw new DomainException('fields_invalid');
            }
            if ($action==='revoke_collector') {
                if (array_diff(array_keys($data),['id','expected_revision']) ||
                    array_diff(['id','expected_revision'],array_keys($data)) ||
                    !is_string($data['id']) || !is_int($data['expected_revision'])) throw new DomainException('fields_invalid');
            }
            if (in_array($action,['queue_test_connection','queue_list_zones'],true)) {
                if (array_keys($data)!==['id'] || !is_string($data['id'])) throw new DomainException('fields_invalid');
            }
            if ($action==='set_collection_enabled') {
                if (array_diff(array_keys($data),['id','expected_revision','enabled']) ||
                    array_diff(['id','expected_revision','enabled'],array_keys($data)) ||
                    !is_string($data['id']) || !is_int($data['expected_revision']) || !is_bool($data['enabled'])) {
                    throw new DomainException('fields_invalid');
                }
            }
            $service=($this->factory)(); $actor=$request['actor_id'];
            $result=match($action) {
                'snapshot'=>$service->snapshot(),
                'save_profile'=>$service->saveProfile($data,$actor),
                'save_connection'=>$service->saveConnection($data,$actor),
                'revoke_profile'=>$service->revokeProfile($data['id'],$data['expected_revision'],$data['acknowledge_connections'],$actor),
                'delete_profile'=>$service->deleteProfile($data['id'],$data['expected_revision'],$actor),
                'delete_connection'=>$service->deleteConnection($data['id'],$data['expected_revision'],$actor),
                'register_collector'=>$service->registerCollector($data['name'],$actor),
                'save_assignment'=>$service->saveCollectorAssignment($data['connection_id'],$data['collector_id'],$data['expected_revision'],$actor),
                'revoke_collector'=>$service->revokeCollector($data['id'],$data['expected_revision'],$actor),
                'queue_test_connection'=>$service->queueControlJob($data['id'],'test_connection',$actor),
                'queue_list_zones'=>$service->queueControlJob($data['id'],'list_zones',$actor),
                'set_collection_enabled'=>$service->setCollectionEnabled($data['id'],$data['expected_revision'],$data['enabled'],$actor),
            };
            return self::response(200,['ok'=>true,'data'=>$result]);
        } catch (DomainException $e) {
            $code=$e->getMessage();
            $allowed=['fields_invalid','id_invalid','actor_invalid','name_invalid','username_invalid','password_invalid','password_action_invalid',
                'password_required_for_username_change','revision_conflict','profile_impact_conflict','profile_in_use','profile_unavailable',
                'profile_share_confirmation_required','server_invalid','interval_invalid','credential_mode_invalid','not_found','connection_must_be_disabled','config_limit','version_exhausted',
                'assignment_unavailable','collector_unavailable','collector_limit','job_type_invalid','job_already_pending','job_limit','connection_unavailable'];
            if (!in_array($code,$allowed,true)) return self::response(503,['ok'=>false,'error'=>'configuration_unavailable']);
            $status=$code==='not_found'?404:(in_array($code,['revision_conflict','profile_impact_conflict','profile_in_use','profile_share_confirmation_required','job_already_pending'],true)?409:400);
            return self::response($status,['ok'=>false,'error'=>$code]);
        } catch (Throwable $e) {
            // Never return/log raw exception messages: keyring/SQL/runtime errors may contain secrets.
            return self::response(503,['ok'=>false,'error'=>'configuration_unavailable']);
        }
    }
}
