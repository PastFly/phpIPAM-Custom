<?php
declare(strict_types=1);

/** Source-only selection adapter, composed with the shared guarded publication transaction. */
final class IntegrationDnsZoneSelectionPhpIpamRepository implements IntegrationDnsZoneSelectionRepository
{
    private IntegrationDnsZonePublicationPhpIpamRepository $base;
    private bool $owned=false;
    public function __construct(private object $db) {$this->base=new IntegrationDnsZonePublicationPhpIpamRepository($db);}
    public function transaction(callable $work): mixed
    {
        if($this->owned)throw new DomainException('selection_unavailable');
        return $this->base->transaction(function() use($work): mixed {
            $this->owned=true;
            try {return $work();} finally {$this->owned=false;}
        });
    }
    private function guard(): void {if(!$this->owned)throw new DomainException('selection_unavailable');}
    private function one(string $sql,array $params=[]): ?array
    {
        $this->guard();$rows=$this->db->getObjectsQuery('no_html_escape',$sql,$params);
        if(!is_array($rows)||count($rows)>1)throw new DomainException('selection_unavailable');
        if($rows===[])return null;
        $row=$rows[0];if(is_object($row))return get_object_vars($row);if(is_array($row))return $row;
        throw new DomainException('selection_unavailable');
    }
    private function write(string $sql,array $params=[]): void
    {
        $this->guard();if($this->db->runQuery($sql,$params)!==true)throw new DomainException('selection_unavailable');
    }
    private static function number(mixed $value,bool $nullable=false): ?int
    {
        if($value===null&&$nullable)return null;
        if(!is_int($value)&&!is_string($value))throw new DomainException('selection_unavailable');
        $text=(string)$value;
        if(!preg_match('/^(0|[1-9][0-9]*)$/D',$text))throw new DomainException('selection_unavailable');
        $number=filter_var($text,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>PHP_INT_MAX]]);
        if($number===false)throw new DomainException('selection_unavailable');return $number;
    }
    private static function flag(mixed $value): bool
    {
        $number=self::number($value);if($number!==0&&$number!==1)throw new DomainException('selection_unavailable');return $number===1;
    }
    private static function numbers(array $row,array $keys,array $nullable=[]): array
    {
        foreach($keys as $key) {
            if(!array_key_exists($key,$row))throw new DomainException('selection_unavailable');
            $row[$key]=self::number($row[$key],in_array($key,$nullable,true));
        }
        return $row;
    }
    public function source(string $id): ?array
    {
        $this->guard();$source=$this->base->source($id);if($source===null)return null;
        $row=$this->one('SELECT interval_seconds FROM custom_dns_connections WHERE id=? LIMIT 2',[$id]);
        if($row===null)throw new DomainException('selection_unavailable');
        $source['interval_seconds']=self::number($row['interval_seconds']??null);return $source;
    }
    public function collector(string $id): ?array {$this->guard();return $this->base->collector($id);}
    public function assignment(string $sourceId): ?array {$this->guard();return $this->base->assignment($sourceId);}
    public function latestDiscovery(string $sourceId): ?array
    {
        $row=$this->one('SELECT id,connection_id,collector_id,assignment_revision,configuration_revision,binding_epoch,credential_version, '
            .'job_type,state,attempt_count,lease_id,lease_expires_at,credential_delivered_at,result_code,result_payload,finished_at '
            ."FROM custom_dns_control_jobs WHERE connection_id=? AND job_type='list_zones' AND state='succeeded' "
            .'ORDER BY finished_at DESC,id DESC LIMIT 1',[$sourceId]);
        if($row===null)return null;
        $raw=$row['result_payload']??null;
        if(!is_string($raw)||$raw===''||strlen($raw)>65536)throw new DomainException('discovery_unavailable');
        try {$payload=json_decode($raw,true,20,JSON_THROW_ON_ERROR);}
        catch(Throwable $_) {throw new DomainException('discovery_unavailable');}
        if(!is_array($payload)||count($payload)!==1||!array_key_exists('zones',$payload)||
            !is_array($payload['zones'])||!array_is_list($payload['zones'])||count($payload['zones'])>1024||
            json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)!==$raw)throw new DomainException('discovery_unavailable');
        unset($row['result_payload']);$row['zones']=$payload['zones'];
        return self::numbers($row,['assignment_revision','configuration_revision','binding_epoch','credential_version','attempt_count',
            'lease_expires_at','credential_delivered_at','finished_at'],['credential_version','lease_expires_at','credential_delivered_at','finished_at']);
    }
    private static function datasetRow(array $row): array
    {
        $row=self::numbers($row,['revision','created_at','created_by','updated_at','updated_by']);
        $row['selected']=self::flag($row['selected']??null);$row['collection_enabled']=self::flag($row['collection_enabled']??null);return $row;
    }
    private const DATASET_COLUMNS=['id','connection_id','zone_name','virtualization_instance','zone_scope','selected','collection_enabled',
        'revision','created_at','created_by','updated_at','updated_by'];
    public function dataset(string $id): ?array
    {
        $row=$this->one('SELECT '.implode(',',self::DATASET_COLUMNS).' FROM custom_dns_zone_datasets WHERE id=? LIMIT 2',[$id]);
        return $row===null?null:self::datasetRow($row);
    }
    public function datasetByZone(string $sourceId,string $zone): ?array
    {
        $row=$this->one('SELECT '.implode(',',self::DATASET_COLUMNS).' FROM custom_dns_zone_datasets '
            ."WHERE connection_id=? AND zone_name=? AND virtualization_instance='default' AND zone_scope='default' LIMIT 2",[$sourceId,$zone]);
        return $row===null?null:self::datasetRow($row);
    }
    public function selectedCount(string $sourceId): int
    {
        $row=$this->one('SELECT COUNT(*) AS n FROM custom_dns_zone_datasets WHERE connection_id=? AND selected=1',[$sourceId]);
        return self::number($row['n']??null);
    }
    public function putDataset(array $row): void
    {
        if(count($row)!==count(self::DATASET_COLUMNS)||array_diff(array_keys($row),self::DATASET_COLUMNS)||
            !is_string($row['id']??null)||!preg_match('/^[0-9a-f]{32}$/D',$row['id'])||
            !is_bool($row['selected']??null)||!is_bool($row['collection_enabled']??null)||
            (!($row['selected'])&&$row['collection_enabled']))throw new DomainException('selection_unavailable');
        $old=$this->dataset($row['id']);
        if($old===null) {
            if(($row['revision']??null)!==1||$row['selected']!==true||$row['collection_enabled']!==false||
                $this->datasetByZone($row['connection_id'],$row['zone_name'])!==null)throw new DomainException('selection_unavailable');
            $values=array_map(static fn($key)=>is_bool($row[$key])?(int)$row[$key]:$row[$key],self::DATASET_COLUMNS);
            $this->write('INSERT INTO custom_dns_zone_datasets ('.implode(',',self::DATASET_COLUMNS).') VALUES ('
                .implode(',',array_fill(0,count(self::DATASET_COLUMNS),'?')).')',$values);
        } else {
            foreach(['connection_id','zone_name','virtualization_instance','zone_scope','created_at','created_by'] as $key) {
                if($row[$key]!==$old[$key])throw new DomainException('selection_unavailable');
            }
            if(!is_int($row['revision']??null)||$old['revision']>=4294967295||$row['revision']!==$old['revision']+1) {
                throw new DomainException('selection_unavailable');
            }
            $this->write('UPDATE custom_dns_zone_datasets SET selected=?,collection_enabled=?,revision=?,updated_at=?,updated_by=? WHERE id=? AND revision=?',
                [(int)$row['selected'],(int)$row['collection_enabled'],$row['revision'],$row['updated_at'],$row['updated_by'],$row['id'],$old['revision']]);
        }
        if($this->dataset($row['id'])!==$row)throw new DomainException('selection_unavailable');
    }
    public function audit(array $event): void
    {
        if(count($event)!==4||array_diff(array_keys($event),['action','object_id','actor_id','created_at'])||
            !in_array($event['action']??null,['zone_selected','zone_deselected','zone_collection_enabled','zone_collection_disabled'],true)||
            !is_string($event['object_id']??null)||!preg_match('/^[0-9a-f]{32}$/D',$event['object_id'])||
            !is_int($event['actor_id']??null)||$event['actor_id']<1||!is_int($event['created_at']??null)||$event['created_at']<1) {
            throw new DomainException('selection_unavailable');
        }
        $this->write('INSERT INTO custom_dns_config_events (action,object_id,actor_id,created_at) VALUES (?,?,?,?)',
            [$event['action'],$event['object_id'],$event['actor_id'],$event['created_at']]);
        $floor=$this->one('SELECT event_id FROM custom_dns_config_events ORDER BY event_id DESC LIMIT 1 OFFSET 999');
        if($floor!==null) {
            $eventId=self::number($floor['event_id']??null);
            $this->write('DELETE FROM custom_dns_config_events WHERE event_id < ?',[$eventId]);
        }
    }
}
