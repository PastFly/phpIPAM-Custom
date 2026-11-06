<?php
declare(strict_types=1);

/**
 * phpIPAM Database_PDO adapter for DNS3 collector-control state.
 * Depends on the DNS1 configuration guard/table set; never provisions schema.
 */
final class IntegrationDnsCollectorControlPhpIpamRepository implements IntegrationDnsCollectorControlRepository
{
    private bool $ownedTransaction=false;
    private object $db;

    private const COLLECTOR_COLUMNS=[
        'id','name','token_digest','revision','control_epoch','revoked',
        'created_at','created_by','updated_at','updated_by'
    ];
    private const ASSIGNMENT_COLUMNS=[
        'connection_id','collector_id','revision','assigned_at','assigned_by'
    ];
    private const JOB_COLUMNS=[
        'id','collector_id','connection_id','assignment_revision','job_type','state',
        'configuration_revision','binding_epoch','credential_version',
        'lease_id','lease_expires_at','attempt_count','credential_delivered_at','result_code','result_payload','finished_at',
        'created_at','expires_at','updated_at','updated_by'
    ];

    public function __construct(object $db)
    {
        foreach (['beginTransaction','commit','rollBack','getObjectsQuery','runQuery'] as $method) {
            if (!method_exists($db,$method)) throw new DomainException('control_unavailable');
        }
        $this->db=$db;
    }

    public function transaction(callable $work): mixed
    {
        if ($this->ownedTransaction) throw new DomainException('control_unavailable');
        $begun=false;
        try {
            // phpIPAM connects lazily, but beginTransaction() assumes a connection.
            if (method_exists($this->db,'isConnected')) {
                if (!is_callable([$this->db,'isConnected']) || !is_callable([$this->db,'connect'])) {
                    throw new DomainException('control_unavailable');
                }
                if ($this->db->isConnected()!==true) $this->db->connect();
                if ($this->db->isConnected()!==true) throw new DomainException('control_unavailable');
            }
            if ($this->db->beginTransaction()!==true) throw new DomainException('control_unavailable');
            $begun=true;
            $guard=$this->db->getObjectsQuery(
                'no_html_escape',
                'SELECT id FROM custom_dns_config_guard WHERE id = 1 FOR UPDATE',
                []
            );
            if (!is_array($guard) || count($guard)!==1 || (string)self::field($guard[0],'id')!=='1') {
                throw new DomainException('control_unavailable');
            }
            $this->ownedTransaction=true;
            $result=$work();
            $this->ownedTransaction=false;
            if ($this->db->commit()!==true) throw new DomainException('control_unavailable');
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
        if (!$this->ownedTransaction) throw new DomainException('control_unavailable');
    }

    private static function field(mixed $row,string $field): mixed
    {
        if (is_array($row)) return $row[$field]??null;
        if (is_object($row)) return $row->{$field}??null;
        return null;
    }

    private static function rowArray(mixed $row): array
    {
        if (is_array($row)) return $row;
        if (is_object($row)) return get_object_vars($row);
        throw new DomainException('control_unavailable');
    }

    private function selectRows(
        string $table,
        array $columns,
        int $limit,
        ?string $idColumn=null,
        ?string $id=null
    ): array {
        $this->guard();
        $sql='SELECT '.implode(',',$columns).' FROM '.$table;
        $params=[];
        if ($id!==null) {
            if ($idColumn===null) throw new DomainException('control_unavailable');
            $sql.=' WHERE '.$idColumn.' = ?';
            $params=[$id];
        } else {
            $sql.=' ORDER BY '.$columns[0].' LIMIT '.($limit+1);
        }
        $rows=$this->db->getObjectsQuery('no_html_escape',$sql,$params);
        if (!is_array($rows)) throw new DomainException('control_unavailable');
        if (count($rows)>$limit) throw new DomainException('control_limit');
        return array_map([self::class,'normalizeRow'],$rows);
    }

    private static function normalizeRow(mixed $row): array
    {
        $r=self::rowArray($row);
        foreach ([
            'revision','control_epoch','created_at','created_by','updated_at','updated_by',
            'assigned_at','assigned_by','assignment_revision','configuration_revision','binding_epoch',
            'lease_expires_at','attempt_count','credential_delivered_at','finished_at','created_at','expires_at'
        ] as $field) {
            if (array_key_exists($field,$r) && $r[$field]!==null) $r[$field]=(int)$r[$field];
        }
        if (array_key_exists('credential_version',$r) && $r['credential_version']!==null) {
            $r['credential_version']=(int)$r['credential_version'];
        }
        if (array_key_exists('revoked',$r)) $r['revoked']=(bool)(int)$r['revoked'];
        return $r;
    }

    public function collectors(): array
    {
        return $this->selectRows('custom_dns_collectors',self::COLLECTOR_COLUMNS,64);
    }

    public function collector(string $id): ?array
    {
        return $this->selectRows('custom_dns_collectors',self::COLLECTOR_COLUMNS,1,'id',$id)[0]??null;
    }

    public function assignments(): array
    {
        return $this->selectRows('custom_dns_collector_assignments',self::ASSIGNMENT_COLUMNS,256);
    }

    public function assignment(string $connectionId): ?array
    {
        return $this->selectRows(
            'custom_dns_collector_assignments',
            self::ASSIGNMENT_COLUMNS,
            1,
            'connection_id',
            $connectionId
        )[0]??null;
    }

    public function jobs(): array
    {
        return $this->selectRows('custom_dns_control_jobs',self::JOB_COLUMNS,512);
    }

    /** An audit record is required before treating a job as automatically created. */
    public function autonomousJobIds(): array
    {
        $this->guard();
        $rows=$this->db->getObjectsQuery('no_html_escape',
            "SELECT DISTINCT j.id FROM custom_dns_control_jobs j ".
            "JOIN custom_dns_control_events e ON e.object_id=j.id ".
            "WHERE j.job_type='list_zones' AND e.action='autonomous_job_queued' AND e.actor_id=0 LIMIT 513",[]);
        if (!is_array($rows) || count($rows)>512) throw new DomainException('control_unavailable');
        $ids=[];
        foreach ($rows as $row) {
            $id=self::field($row,'id');
            if (!is_string($id) || !preg_match('/^[0-9a-f]{32}$/D',$id)) throw new DomainException('control_unavailable');
            $ids[$id]=true;
        }
        return $ids;
    }

    private function put(string $table,array $columns,array $row): void
    {
        $this->guard();
        if (array_diff(array_keys($row),$columns) || array_diff($columns,array_keys($row))) {
            throw new DomainException('fields_invalid');
        }
        $updates=implode(',',array_map(
            static fn($column)=>$column.'=VALUES('.$column.')',
            array_slice($columns,1)
        ));
        $sql='INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.
            implode(',',array_fill(0,count($columns),'?')).') ON DUPLICATE KEY UPDATE '.$updates;
        $values=array_map(
            static fn($column)=>is_bool($row[$column])?(int)$row[$column]:$row[$column],
            $columns
        );
        if ($this->db->runQuery($sql,$values)!==true) throw new DomainException('control_unavailable');
    }

    public function putCollector(array $row): void
    {
        $this->put('custom_dns_collectors',self::COLLECTOR_COLUMNS,$row);
    }

    public function putAssignment(array $row): void
    {
        $this->put('custom_dns_collector_assignments',self::ASSIGNMENT_COLUMNS,$row);
    }

    public function removeAssignment(string $connectionId): void
    {
        $this->guard();
        if ($this->db->runQuery(
            'DELETE FROM custom_dns_collector_assignments WHERE connection_id = ?',
            [$connectionId]
        )!==true) throw new DomainException('control_unavailable');
    }

    public function putJob(array $row): void
    {
        $this->put('custom_dns_control_jobs',self::JOB_COLUMNS,$row);
    }

    public function removeJob(string $id): void
    {
        $this->guard();
        if ($this->db->runQuery('DELETE FROM custom_dns_control_jobs WHERE id = ?',[$id])!==true) {
            throw new DomainException('control_unavailable');
        }
    }

    public function audit(array $event): void
    {
        $this->guard();
        if (array_keys($event)!==['action','object_id','actor_id','created_at']) {
            throw new DomainException('fields_invalid');
        }
        if ($this->db->runQuery(
            'INSERT INTO custom_dns_control_events (action,object_id,actor_id,created_at) VALUES (?,?,?,?)',
            array_values($event)
        )!==true) throw new DomainException('control_unavailable');

        $floor=$this->db->getObjectsQuery(
            'no_html_escape',
            'SELECT event_id FROM custom_dns_control_events ORDER BY event_id DESC LIMIT 1 OFFSET 9999',
            []
        );
        if (!is_array($floor)) throw new DomainException('control_unavailable');
        if ($floor) {
            $eventId=self::field($floor[0],'event_id');
            if (!is_int($eventId) && !(is_string($eventId)&&preg_match('/^[0-9]+$/D',$eventId))) {
                throw new DomainException('control_unavailable');
            }
            if ($this->db->runQuery(
                'DELETE FROM custom_dns_control_events WHERE event_id < ?',
                [(int)$eventId]
            )!==true) throw new DomainException('control_unavailable');
        }
    }
}
