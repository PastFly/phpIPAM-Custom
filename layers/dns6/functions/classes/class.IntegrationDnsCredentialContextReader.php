<?php
declare(strict_types=1);

/**
 * Internal server-side credential context reader.
 * It exposes profile selector/envelope only to the delivery service/decryptor path,
 * never to collector HTTP input or output.
 */
final class IntegrationDnsCredentialContextReader
{
    private object $db;

    public function __construct(object $db)
    {
        if(!method_exists($db,'getObjectsQuery')) throw new DomainException('control_unavailable');
        $this->db=$db;
    }

    private static function id(string $id): string
    {
        if(!preg_match('/^[0-9a-f]{32}$/D',$id)) throw new DomainException('id_invalid');
        return $id;
    }

    private static function one(array $rows): ?array
    {
        if(count($rows)===0) return null;
        if(count($rows)!==1) throw new DomainException('control_unavailable');
        $row=$rows[0];
        if(is_object($row)) return get_object_vars($row);
        if(is_array($row)) return $row;
        throw new DomainException('control_unavailable');
    }

    public function current(string $connectionId): ?array
    {
        self::id($connectionId);
        try {
            $rows=$this->db->getObjectsQuery(
                'no_html_escape',
                'SELECT id,server,revision,binding_epoch,credential_mode,profile_id FROM custom_dns_connections WHERE id = ? LIMIT 2',
                [$connectionId]
            );
            if(!is_array($rows)) throw new DomainException('control_unavailable');
            $connection=self::one($rows);
            if($connection===null) return null;

            $id=(string)($connection['id']??'');
            $server=(string)($connection['server']??'');
            $revision=filter_var($connection['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
            $epoch=filter_var($connection['binding_epoch']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
            $mode=$connection['credential_mode']??null;

            if($id!==$connectionId||$revision===false||$epoch===false||
               !is_string($mode)||!in_array($mode,['profile','collector_identity'],true)||
               strlen($server)<1||strlen($server)>253||
               !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$server)) {
                return null;
            }

            if($mode==='collector_identity') {
                if(($connection['profile_id']??null)!==null) return null;
                return [
                    'id'=>$connectionId,
                    'server'=>$server,
                    'revision'=>(int)$revision,
                    'binding_epoch'=>(int)$epoch,
                    'credential_mode'=>'collector_identity',
                    'profile_id'=>null,
                    'username'=>null,
                    'secret_version'=>null,
                    'secret_envelope'=>null
                ];
            }

            $profileId=$connection['profile_id']??null;
            if(!is_string($profileId)||!preg_match('/^[0-9a-f]{32}$/D',$profileId)) return null;

            $profiles=$this->db->getObjectsQuery(
                'no_html_escape',
                'SELECT id,username,secret_envelope,secret_version FROM custom_dns_credential_profiles WHERE id = ? AND secret_envelope IS NOT NULL LIMIT 2',
                [$profileId]
            );
            if(!is_array($profiles)) throw new DomainException('control_unavailable');
            $profile=self::one($profiles);
            if($profile===null||(string)($profile['id']??'')!==$profileId) return null;

            $username=$profile['username']??null;
            $envelope=$profile['secret_envelope']??null;
            $version=filter_var($profile['secret_version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
            if(!is_string($username)||$username===''||strlen($username)>255||
               !preg_match('/^(?:[^\s@\\\\]+@[^\s@\\\\]+|[^\s@\\\\]+\\\\[^\s@\\\\]+)$/uD',$username)||
               !is_string($envelope)||$envelope===''||strlen($envelope)>9000||$version===false) {
                return null;
            }

            return [
                'id'=>$connectionId,
                'server'=>$server,
                'revision'=>(int)$revision,
                'binding_epoch'=>(int)$epoch,
                'credential_mode'=>'profile',
                'profile_id'=>$profileId,
                'username'=>$username,
                'secret_version'=>(int)$version,
                'secret_envelope'=>$envelope
            ];
        } catch(DomainException $e) {
            throw $e;
        } catch(Throwable $e) {
            throw new DomainException('control_unavailable');
        }
    }
}
