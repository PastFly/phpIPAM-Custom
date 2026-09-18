<?php
declare(strict_types=1);

/**
 * Read-only DNS1 connection/profile context reader used inside an already-open
 * shared config-guard transaction. It never starts/commits/rolls back a transaction
 * and never fetches credential ciphertext.
 */
final class IntegrationDnsMachineContextReader
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
            $row=self::one($rows);
            if($row===null) return null;

            $id=(string)($row['id']??'');
            $server=(string)($row['server']??'');
            $revision=filter_var($row['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
            $epoch=filter_var($row['binding_epoch']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
            $mode=$row['credential_mode']??null;

            if($id!==$connectionId || !is_string($mode) || !in_array($mode,['profile','collector_identity'],true) ||
               $revision===false || $epoch===false ||
               strlen($server)<1 || strlen($server)>253 ||
               !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$server)) {
                return null;
            }

            $credentialVersion=null;
            if($mode==='profile') {
                $profileId=$row['profile_id']??null;
                if(!is_string($profileId)||!preg_match('/^[0-9a-f]{32}$/D',$profileId)) return null;
                $profiles=$this->db->getObjectsQuery(
                    'no_html_escape',
                    'SELECT id,secret_version FROM custom_dns_credential_profiles WHERE id = ? AND secret_envelope IS NOT NULL LIMIT 2',
                    [$profileId]
                );
                if(!is_array($profiles)) throw new DomainException('control_unavailable');
                $profile=self::one($profiles);
                if($profile===null || (string)($profile['id']??'')!==$profileId) return null;
                $credentialVersion=filter_var(
                    $profile['secret_version']??null,
                    FILTER_VALIDATE_INT,
                    ['options'=>['min_range'=>1,'max_range'=>2147483647]]
                );
                if($credentialVersion===false) return null;
            } elseif(($row['profile_id']??null)!==null) {
                return null;
            }

            return [
                'id'=>$connectionId,
                'server'=>$server,
                'revision'=>(int)$revision,
                'binding_epoch'=>(int)$epoch,
                'credential_mode'=>$mode,
                'credential_version'=>$credentialVersion===null?null:(int)$credentialVersion
            ];
        } catch(DomainException $e) {
            throw $e;
        } catch(Throwable $e) {
            throw new DomainException('control_unavailable');
        }
    }
}
