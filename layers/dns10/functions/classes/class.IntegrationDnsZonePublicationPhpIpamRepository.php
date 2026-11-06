<?php
declare(strict_types=1);

/** Source-only phpIPAM adapter; all DNS10 operations use the shared DNS configuration guard. */
final class IntegrationDnsZonePublicationPhpIpamRepository implements IntegrationDnsZonePublicationRepository
{
    private bool $owned=false;
    public function __construct(private object $db)
    {
        foreach (['beginTransaction','commit','rollBack','getObjectsQuery','runQuery'] as $method) {
            if (!is_callable([$db,$method])) throw new DomainException('snapshot_unavailable');
        }
    }

    public function transaction(callable $work): mixed
    {
        if ($this->owned) throw new DomainException('snapshot_unavailable');
        $begun=false;
        try {
            if (method_exists($this->db,'isConnected')) {
                if (!is_callable([$this->db,'isConnected']) || !is_callable([$this->db,'connect'])) throw new DomainException('snapshot_unavailable');
                if ($this->db->isConnected()!==true) $this->db->connect();
                if ($this->db->isConnected()!==true) throw new DomainException('snapshot_unavailable');
            }
            if ($this->db->beginTransaction()!==true) throw new DomainException('snapshot_unavailable');
            $begun=true;
            $guard=$this->db->getObjectsQuery('no_html_escape','SELECT id FROM custom_dns_config_guard WHERE id = 1 FOR UPDATE',[]);
            if (!is_array($guard) || count($guard)!==1 || (string)(self::row($guard[0])['id']??'')!=='1') throw new DomainException('snapshot_unavailable');
            $this->owned=true;$value=$work();$this->owned=false;
            if ($this->db->commit()!==true) throw new DomainException('snapshot_unavailable');
            return $value;
        } catch (Throwable $e) {
            $this->owned=false;
            if ($begun) {try {$this->db->rollBack();} catch (Throwable $_) {}}
            throw $e;
        }
    }

    private function guard(): void {if (!$this->owned) throw new DomainException('snapshot_unavailable');}
    private static function row(mixed $row): array
    {
        if (is_array($row)) return $row;
        if (is_object($row)) return get_object_vars($row);
        throw new DomainException('snapshot_unavailable');
    }
    private function one(string $sql,array $params=[]): ?array
    {
        $this->guard();$rows=$this->db->getObjectsQuery('no_html_escape',$sql,$params);
        if (!is_array($rows) || count($rows)>1) throw new DomainException('snapshot_unavailable');
        return $rows===[]?null:self::row($rows[0]);
    }
    private function write(string $sql,array $params=[]): void
    {
        $this->guard();if ($this->db->runQuery($sql,$params)!==true) throw new DomainException('snapshot_unavailable');
    }
    private static function number(mixed $value,bool $nullable=false): ?int
    {
        if ($value===null && $nullable) return null;
        if (!is_int($value) && !is_string($value)) throw new DomainException('snapshot_unavailable');
        $text=(string)$value;
        if (!preg_match('/^(0|[1-9][0-9]*)$/D',$text)) throw new DomainException('snapshot_unavailable');
        $number=filter_var($text,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>PHP_INT_MAX]]);
        if ($number===false) throw new DomainException('snapshot_unavailable');
        return $number;
    }
    private static function flag(mixed $value): bool
    {
        $number=self::number($value);
        if ($number!==0 && $number!==1) throw new DomainException('snapshot_unavailable');
        return $number===1;
    }
    private static function numbers(array $row,array $keys,array $nullable=[]): array
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key,$row)) throw new DomainException('snapshot_unavailable');
            $row[$key]=self::number($row[$key],in_array($key,$nullable,true));
        }
        return $row;
    }

    public function uploadToken(string $id): ?array
    {
        $row=$this->one('SELECT id,connection_id,collector_id,assignment_revision,token_digest,revoked FROM custom_dns_zone_upload_tokens WHERE id=? LIMIT 2',[$id]);
        if ($row===null) return null;
        $row=self::numbers($row,['assignment_revision']);$row['revoked']=self::flag($row['revoked']??null);return $row;
    }
    public function source(string $id): ?array
    {
        $row=$this->one('SELECT c.id,c.enabled,c.revision,c.binding_epoch,c.credential_mode,c.profile_id,p.secret_version, '
            .'CASE WHEN p.secret_envelope IS NOT NULL AND OCTET_LENGTH(p.secret_envelope)>0 THEN 1 ELSE 0 END AS secret_present '
            .'FROM custom_dns_connections c LEFT JOIN custom_dns_credential_profiles p ON p.id=c.profile_id WHERE c.id=? LIMIT 2',[$id]);
        if ($row===null) return null;
        $mode=$row['credential_mode']??null;
        if ($mode==='collector_identity' && ($row['profile_id']??null)===null) $credential=null;
        elseif ($mode==='profile' && is_string($row['profile_id']??null) && self::flag($row['secret_present']??null)) {
            $credential=self::number($row['secret_version']??null);
            if ($credential===0) throw new DomainException('snapshot_unavailable');
        } else return null;
        return ['id'=>$row['id']??null,'enabled'=>self::flag($row['enabled']??null),
            'revision'=>self::number($row['revision']??null),'binding_epoch'=>self::number($row['binding_epoch']??null),
            'credential_version'=>$credential];
    }
    public function collector(string $id): ?array
    {
        $row=$this->one('SELECT id,revoked FROM custom_dns_collectors WHERE id=? LIMIT 2',[$id]);
        if ($row===null) return null;$row['revoked']=self::flag($row['revoked']??null);return $row;
    }
    public function assignment(string $sourceId): ?array
    {
        $row=$this->one('SELECT connection_id,collector_id,revision FROM custom_dns_collector_assignments WHERE connection_id=? LIMIT 2',[$sourceId]);
        return $row===null?null:self::numbers($row,['revision']);
    }
    public function dataset(string $id): ?array
    {
        $row=$this->one('SELECT id,connection_id,zone_name AS zone,selected,revision FROM custom_dns_zone_datasets WHERE id=? LIMIT 2',[$id]);
        if ($row===null) return null;$row=self::numbers($row,['revision']);$row['selected']=self::flag($row['selected']??null);return $row;
    }
    public function job(string $id): ?array
    {
        $row=$this->one('SELECT j.id,j.connection_id,j.collector_id,b.dataset_id,b.dataset_revision,j.job_type,j.state,j.attempt_count, '
            .'j.lease_id,j.lease_expires_at,j.expires_at,j.credential_delivered_at,j.finished_at,j.result_code, '
            .'j.configuration_revision,j.binding_epoch,j.assignment_revision,j.credential_version '
            .'FROM custom_dns_control_jobs j JOIN custom_dns_zone_job_datasets b ON b.job_id=j.id AND b.connection_id=j.connection_id WHERE j.id=? LIMIT 2',[$id]);
        if ($row===null) return null;
        return self::numbers($row,['dataset_revision','attempt_count','lease_expires_at','expires_at','credential_delivered_at',
            'finished_at','configuration_revision','binding_epoch','assignment_revision','credential_version'],
            ['lease_expires_at','credential_delivered_at','finished_at','credential_version']);
    }
    private function generation(string $datasetId,string $id): array
    {
        $row=$this->one('SELECT id,dataset_id,collector_id,job_id,sequence_no,dataset_revision,configuration_revision,binding_epoch, '
            .'assignment_revision,credential_version,payload_sha256,payload_bytes,record_count '
            .'FROM custom_dns_zone_generations WHERE dataset_id=? AND id=? LIMIT 2',[$datasetId,$id]);
        if ($row===null) throw new DomainException('snapshot_unavailable');
        return self::numbers($row,['sequence_no','dataset_revision','configuration_revision','binding_epoch','assignment_revision',
            'credential_version','payload_bytes','record_count'],['credential_version']);
    }
    public function head(string $datasetId): array
    {
        $row=$this->one('SELECT current_generation_id,previous_generation_id FROM custom_dns_zone_heads WHERE dataset_id=? LIMIT 2',[$datasetId]);
        if ($row===null) return ['current'=>null,'previous'=>null];
        foreach (['current_generation_id','previous_generation_id'] as $key) {
            if (!array_key_exists($key,$row) || ($row[$key]!==null && (!is_string($row[$key]) || !preg_match('/^[0-9a-f]{32}$/D',$row[$key])))) {
                throw new DomainException('snapshot_unavailable');
            }
        }
        return ['current'=>$row['current_generation_id']===null?null:$this->generation($datasetId,$row['current_generation_id']),
                'previous'=>$row['previous_generation_id']===null?null:$this->generation($datasetId,$row['previous_generation_id'])];
    }
    public function generationCount(string $datasetId): int
    {
        $row=$this->one('SELECT COUNT(*) AS n FROM custom_dns_zone_generations WHERE dataset_id=?',[$datasetId]);
        return self::number($row['n']??null);
    }
    public function accountedBytes(): int
    {
        $row=$this->one('SELECT COALESCE(SUM(payload_bytes),0) AS n FROM custom_dns_zone_generations');
        return self::number($row['n']??null);
    }

    public function insertGeneration(array $generation,array $records): void
    {
        $cols=['id','dataset_id','collector_id','job_id','run_id','sequence_no','dataset_revision','configuration_revision','binding_epoch',
            'assignment_revision','credential_version','payload_sha256','payload_bytes','record_count','started_at','finished_at','received_at'];
        if (count($generation)!==count($cols) || array_diff(array_keys($generation),$cols) || !array_is_list($records) ||
            count($records)!==($generation['record_count']??null)) throw new DomainException('snapshot_unavailable');
        $this->write('INSERT INTO custom_dns_zone_generations ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')',
            array_map(static fn($key)=>$generation[$key],$cols));
        $keys=['owner','display_owner','type','record_class','rdata','display_rdata','ttl_seconds','source_aging_timestamp'];
        foreach (array_chunk($records,100) as $chunk) {
            $values=[];
            foreach ($chunk as $row) {
                if (!is_array($row) || count($row)!==count($keys) || array_diff(array_keys($row),$keys)) throw new DomainException('snapshot_unavailable');
                $values[]=$generation['id'];foreach ($keys as $key) $values[]=$row[$key];
            }
            $this->write('INSERT INTO custom_dns_zone_records (generation_id,owner,display_owner,record_type,record_class,rdata,display_rdata,ttl_seconds,source_aging_timestamp) VALUES '
                .implode(',',array_fill(0,count($chunk),'(?,?,?,?,?,?,?,?,?)')),$values);
        }
    }
    public function setHead(string $datasetId,string $currentId,?string $previousId): void
    {
        $this->write('INSERT INTO custom_dns_zone_heads (dataset_id,current_generation_id,previous_generation_id) VALUES (?,?,?) '
            .'ON DUPLICATE KEY UPDATE current_generation_id=VALUES(current_generation_id),previous_generation_id=VALUES(previous_generation_id)',
            [$datasetId,$currentId,$previousId]);
        $row=$this->one('SELECT current_generation_id,previous_generation_id FROM custom_dns_zone_heads WHERE dataset_id=? LIMIT 2',[$datasetId]);
        if ($row===null || ($row['current_generation_id']??null)!==$currentId || ($row['previous_generation_id']??null)!==$previousId) {
            throw new DomainException('snapshot_unavailable');
        }
    }
    public function removeGeneration(string $generationId): void
    {
        $before=$this->one('SELECT id FROM custom_dns_zone_generations WHERE id=? LIMIT 2',[$generationId]);
        if ($before===null) throw new DomainException('snapshot_unavailable');
        $this->write('DELETE FROM custom_dns_zone_generations WHERE id=?',[$generationId]);
        if ($this->one('SELECT id FROM custom_dns_zone_generations WHERE id=? LIMIT 2',[$generationId])!==null) throw new DomainException('snapshot_unavailable');
    }
    public function completeJob(string $jobId,#[SensitiveParameter] string $leaseId,int $now): void
    {
        $this->write("UPDATE custom_dns_control_jobs SET state='succeeded',lease_id=NULL,lease_expires_at=NULL,result_code='ok', "
            ."result_payload=NULL,finished_at=?,updated_at=?,updated_by=0 WHERE id=? AND state='running' AND lease_id=? AND lease_expires_at>=? AND expires_at>=?",
            [$now,$now,$jobId,$leaseId,$now,$now]);
        $job=$this->job($jobId);
        if ($job===null || ($job['state']??null)!=='succeeded' || ($job['lease_id']??null)!==null ||
            ($job['lease_expires_at']??null)!==null || ($job['result_code']??null)!=='ok' || ($job['finished_at']??null)!==$now) {
            throw new DomainException('snapshot_unavailable');
        }
        $countRow=$this->one('SELECT COUNT(*) AS n FROM custom_dns_zone_sync_events');$count=self::number($countRow['n']??null);
        if ($count>1000) throw new DomainException('snapshot_unavailable');
        if ($count===1000) {
            $oldest=$this->one('SELECT event_id FROM custom_dns_zone_sync_events ORDER BY event_id ASC LIMIT 1');
            $oldId=self::number($oldest['event_id']??null);
            $this->write('DELETE FROM custom_dns_zone_sync_events WHERE event_id=?',[$oldId]);
        }
        $this->write("INSERT INTO custom_dns_zone_sync_events (dataset_id,job_id,action,actor_id,created_at) VALUES (?,?,'snapshot_published',0,?)",
            [$job['dataset_id'],$jobId,$now]);
    }
}
