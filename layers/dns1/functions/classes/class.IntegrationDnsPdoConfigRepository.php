<?php
declare(strict_types=1);

/** MySQL/MariaDB-only adapter. Provisioning/migrations are intentionally not performed here. */
final class IntegrationDnsPdoConfigRepository implements IntegrationDnsConfigRepository
{
    private bool $ownedTransaction=false;
    private const PROFILE_COLUMNS=['id','name','username','secret_envelope','secret_version','revision','updated_at','updated_by'];
    private const CONNECTION_COLUMNS=['id','provider','name','server','interval_seconds','credential_mode','profile_id','collector_id','enabled','status','revision','binding_epoch','updated_at','updated_by'];
    public function __construct(private PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new DomainException('configuration_unavailable');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }
    public function transaction(callable $work): mixed
    {
        if ($this->ownedTransaction || $this->db->inTransaction()) throw new DomainException('configuration_unavailable');
        $this->db->beginTransaction();
        try {
            // Serialize all bounded admin edits, including profile fanout and impact-confirmation checks.
            $lock=$this->db->query('SELECT id FROM custom_dns_config_guard WHERE id = 1 FOR UPDATE')->fetchColumn();
            if ((string)$lock!=='1') throw new DomainException('configuration_unavailable');
            $this->ownedTransaction=true; $result=$work(); $this->db->commit(); return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        } finally { $this->ownedTransaction=false; }
    }
    private function guard(): void
    {
        if (!$this->ownedTransaction || !$this->db->inTransaction()) throw new DomainException('configuration_unavailable');
    }
    private function rows(bool $profile,?string $id=null): array
    {
        $this->guard();
        $table=$profile?'custom_dns_credential_profiles':'custom_dns_connections';
        $cols=$profile?self::PROFILE_COLUMNS:self::CONNECTION_COLUMNS;
        $sql='SELECT '.implode(',',$cols).' FROM '.$table.($id===null?' ORDER BY id LIMIT '.($profile?'129':'257'):' WHERE id = ?');
        $q=$this->db->prepare($sql); $q->execute($id===null?[]:[$id]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>($profile?128:256)) throw new DomainException('config_limit');
        foreach ($rows as &$r) {
            foreach (['revision','secret_version','interval_seconds','binding_epoch','updated_at','updated_by'] as $n) if (array_key_exists($n,$r)) $r[$n]=(int)$r[$n];
            if (!$profile) $r['enabled']=(bool)(int)$r['enabled'];
        }
        unset($r); return $rows;
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
        $update=implode(',',array_map(static fn($c)=>$c.'=VALUES('.$c.')',array_slice($cols,1)));
        $q=$this->db->prepare('INSERT INTO '.$table.' ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).') ON DUPLICATE KEY UPDATE '.$update);
        $q->execute(array_map(static fn($c)=>is_bool($row[$c])?(int)$row[$c]:$row[$c],$cols));
    }
    public function putProfile(array $row): void { $this->put(true,$row); }
    public function putConnection(array $row): void { $this->put(false,$row); }
    public function removeProfile(string $id): void { $this->guard(); $q=$this->db->prepare('DELETE FROM custom_dns_credential_profiles WHERE id = ?'); $q->execute([$id]); }
    public function removeConnection(string $id): void { $this->guard(); $q=$this->db->prepare('DELETE FROM custom_dns_connections WHERE id = ?'); $q->execute([$id]); }
    public function audit(array $event): void
    {
        $this->guard();
        if (array_keys($event)!==['action','object_id','actor_id','created_at']) throw new DomainException('fields_invalid');
        $q=$this->db->prepare('INSERT INTO custom_dns_config_events (action,object_id,actor_id,created_at) VALUES (?,?,?,?)');
        $q->execute(array_values($event));
        $floor=$this->db->query('SELECT event_id FROM custom_dns_config_events ORDER BY event_id DESC LIMIT 1 OFFSET 999')->fetchColumn();
        if ($floor!==false) { $q=$this->db->prepare('DELETE FROM custom_dns_config_events WHERE event_id < ?'); $q->execute([$floor]); }
    }
}
