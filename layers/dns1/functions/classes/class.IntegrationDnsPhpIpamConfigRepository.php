<?php
declare(strict_types=1);

/**
 * phpIPAM Database_PDO adapter for DNS1 configuration storage.
 * Provisioning is external: this class never creates or alters tables.
 */
final class IntegrationDnsPhpIpamConfigRepository implements IntegrationDnsConfigRepository
{
    private bool $ownedTransaction=false;
    private object $db;

    private const PROFILE_COLUMNS=['id','name','username','secret_envelope','secret_version','revision','updated_at','updated_by'];
    private const CONNECTION_COLUMNS=['id','provider','name','server','interval_seconds','credential_mode','profile_id','collector_id','enabled','status','revision','binding_epoch','updated_at','updated_by'];

    public function __construct(object $db)
    {
        foreach (['beginTransaction','commit','rollBack','getObjectsQuery','runQuery'] as $method) {
            if (!method_exists($db,$method)) throw new DomainException('configuration_unavailable');
        }
        $this->db=$db;
    }

    public function transaction(callable $work): mixed
    {
        if ($this->ownedTransaction) throw new DomainException('configuration_unavailable');
        $begun=false;
        try {
            // phpIPAM's Database_PDO connects lazily, but beginTransaction()
            // itself assumes an existing PDO connection.
            if (method_exists($this->db,'isConnected')) {
                if (!is_callable([$this->db,'isConnected']) || !is_callable([$this->db,'connect'])) {
                    throw new DomainException('configuration_unavailable');
                }
                if ($this->db->isConnected()!==true) $this->db->connect();
                if ($this->db->isConnected()!==true) throw new DomainException('configuration_unavailable');
            }
            if ($this->db->beginTransaction()!==true) throw new DomainException('configuration_unavailable');
            $begun=true;
            $lock=$this->db->getObjectsQuery(
                'no_html_escape',
                'SELECT id FROM custom_dns_config_guard WHERE id = 1 FOR UPDATE',
                []
            );
            if (!is_array($lock) || count($lock)!==1 || (string)self::field($lock[0],'id')!=='1') {
                throw new DomainException('configuration_unavailable');
            }
            $this->ownedTransaction=true;
            $result=$work();
            $this->ownedTransaction=false;
            if ($this->db->commit()!==true) throw new DomainException('configuration_unavailable');
            return $result;
        } catch (Throwable $e) {
            $this->ownedTransaction=false;
            if ($begun) {
                try { $this->db->rollBack(); } catch (Throwable $_) {}
            }
            throw $e;
        }
    }

    private function guard(): void
    {
        if (!$this->ownedTransaction) throw new DomainException('configuration_unavailable');
    }

    private static function field(mixed $row,string $name): mixed
    {
        if (is_array($row)) return $row[$name]??null;
        if (is_object($row)) return $row->{$name}??null;
        return null;
    }

    private static function rowArray(mixed $row): array
    {
        if (is_array($row)) return $row;
        if (is_object($row)) return get_object_vars($row);
        throw new DomainException('configuration_unavailable');
    }

    private function rows(bool $profile,?string $id=null): array
    {
        $this->guard();
        $table=$profile?'custom_dns_credential_profiles':'custom_dns_connections';
        $cols=$profile?self::PROFILE_COLUMNS:self::CONNECTION_COLUMNS;
        $sql='SELECT '.implode(',',$cols).' FROM '.$table.
            ($id===null?' ORDER BY id LIMIT '.($profile?'129':'257'):' WHERE id = ?');
        $rows=$this->db->getObjectsQuery('no_html_escape',$sql,$id===null?[]:[$id]);
        if (!is_array($rows)) throw new DomainException('configuration_unavailable');
        if (count($rows)>($profile?128:256)) throw new DomainException('config_limit');

        $out=[];
        foreach ($rows as $row) {
            $r=self::rowArray($row);
            foreach (['revision','secret_version','interval_seconds','binding_epoch','updated_at','updated_by'] as $name) {
                if (array_key_exists($name,$r)) $r[$name]=(int)$r[$name];
            }
            if (!$profile) $r['enabled']=(bool)(int)($r['enabled']??0);
            $out[]=$r;
        }
        return $out;
    }

    public function profiles(): array { return $this->rows(true); }
    public function profile(string $id): ?array { return $this->rows(true,$id)[0]??null; }
    public function connections(): array { return $this->rows(false); }
    public function connection(string $id): ?array { return $this->rows(false,$id)[0]??null; }

    private function put(bool $profile,array $row): void
    {
        $this->guard();
        $table=$profile?'custom_dns_credential_profiles':'custom_dns_connections';
        $cols=$profile?self::PROFILE_COLUMNS:self::CONNECTION_COLUMNS;
        if (array_diff(array_keys($row),$cols) || array_diff($cols,array_keys($row))) throw new DomainException('fields_invalid');

        $updates=implode(',',array_map(static fn($c)=>$c.'=VALUES('.$c.')',array_slice($cols,1)));
        $sql='INSERT INTO '.$table.' ('.implode(',',$cols).') VALUES ('.
            implode(',',array_fill(0,count($cols),'?')).') ON DUPLICATE KEY UPDATE '.$updates;
        $values=array_map(static fn($c)=>is_bool($row[$c])?(int)$row[$c]:$row[$c],$cols);
        if ($this->db->runQuery($sql,$values)!==true) throw new DomainException('configuration_unavailable');
    }

    public function putProfile(array $row): void { $this->put(true,$row); }
    public function putConnection(array $row): void { $this->put(false,$row); }

    public function removeProfile(string $id): void
    {
        $this->guard();
        if ($this->db->runQuery('DELETE FROM custom_dns_credential_profiles WHERE id = ?',[$id])!==true) {
            throw new DomainException('configuration_unavailable');
        }
    }

    public function removeConnection(string $id): void
    {
        $this->guard();
        if ($this->db->runQuery('DELETE FROM custom_dns_connections WHERE id = ?',[$id])!==true) {
            throw new DomainException('configuration_unavailable');
        }
    }

    public function audit(array $event): void
    {
        $this->guard();
        if (array_keys($event)!==['action','object_id','actor_id','created_at']) throw new DomainException('fields_invalid');
        if ($this->db->runQuery(
            'INSERT INTO custom_dns_config_events (action,object_id,actor_id,created_at) VALUES (?,?,?,?)',
            array_values($event)
        )!==true) throw new DomainException('configuration_unavailable');

        $floor=$this->db->getObjectsQuery(
            'no_html_escape',
            'SELECT event_id FROM custom_dns_config_events ORDER BY event_id DESC LIMIT 1 OFFSET 999',
            []
        );
        if (!is_array($floor)) throw new DomainException('configuration_unavailable');
        if ($floor) {
            $eventId=self::field($floor[0],'event_id');
            if (!is_int($eventId) && !(is_string($eventId)&&preg_match('/^[0-9]+$/D',$eventId))) {
                throw new DomainException('configuration_unavailable');
            }
            if ($this->db->runQuery('DELETE FROM custom_dns_config_events WHERE event_id < ?',[(int)$eventId])!==true) {
                throw new DomainException('configuration_unavailable');
            }
        }
    }
}
