<?php
declare(strict_types=1);

/** Source-only administrative contract; a host route must authenticate, authorize and verify CSRF. */
final class IntegrationDnsZoneSelectionService
{
    private Closure $clock;
    public function __construct(private IntegrationDnsZoneSelectionRepository $repo,callable $clock)
    {
        $this->clock=Closure::fromCallable($clock);
    }
    private static function id(mixed $id): string
    {
        if(!is_string($id)||!preg_match('/^[0-9a-f]{32}$/D',$id))throw new DomainException('selection_unavailable');
        return $id;
    }
    private static function version(mixed $value): bool
    {
        return is_int($value)&&$value>=1&&$value<=4294967295;
    }
    private static function actor(int $actor): void {if($actor<1)throw new DomainException('actor_invalid');}
    private function now(): int
    {
        $now=($this->clock)();
        if(!is_int($now)||$now<1||$now>9007199254740691)throw new DomainException('selection_unavailable');
        return $now;
    }
    private static function zone(string $name): string
    {
        if($name===''||$name!==trim($name)||strlen($name)>254)throw new DomainException('zone_invalid');
        if($name==='.')return '.';
        $name=strtolower($name);if(str_ends_with($name,'.'))$name=substr($name,0,-1);
        if($name===''||strlen($name)>253)throw new DomainException('zone_invalid');
        foreach(explode('.',$name) as $label) {
            if($label===''||strlen($label)>63||!preg_match('/^[a-z0-9_-]+$/D',$label))throw new DomainException('zone_invalid');
        }
        return $name.'.';
    }
    private function source(string $sourceId): array
    {
        $source=$this->repo->source($sourceId);
        if(!is_array($source)||($source['id']??null)!==$sourceId||!is_bool($source['enabled']??null)||
            !self::version($source['revision']??null)||!self::version($source['binding_epoch']??null)||
            !array_key_exists('credential_version',$source)||($source['credential_version']!==null&&!self::version($source['credential_version']))||
            !is_int($source['interval_seconds']??null)||$source['interval_seconds']<60||$source['interval_seconds']>3600) {
            throw new DomainException('selection_unavailable');
        }
        return $source;
    }
    private static function dataset(array $row,string $sourceId,string $zone): array
    {
        if(!is_string($row['id']??null)||!preg_match('/^[0-9a-f]{32}$/D',$row['id'])||
            ($row['connection_id']??null)!==$sourceId||($row['zone_name']??null)!==$zone||
            ($row['virtualization_instance']??null)!=='default'||($row['zone_scope']??null)!=='default'||
            !is_bool($row['selected']??null)||!is_bool($row['collection_enabled']??null)||
            (!$row['selected']&&$row['collection_enabled'])||!self::version($row['revision']??null)) {
            throw new DomainException('selection_unavailable');
        }
        return $row;
    }
    private static function next(int $revision): int
    {
        if($revision>=4294967295)throw new DomainException('selection_unavailable');
        return $revision+1;
    }
    private function discovery(array $source,string $zone,?string $expectedId,int $now): void
    {
        if($expectedId===null || !preg_match('/^[0-9a-f]{32}$/D',$expectedId))throw new DomainException('discovery_unavailable');
        $assignment=$this->repo->assignment($source['id']);
        if(!is_array($assignment)||($assignment['connection_id']??null)!==$source['id']||
            !is_string($assignment['collector_id']??null)||!preg_match('/^[0-9a-f]{32}$/D',$assignment['collector_id'])||
            !self::version($assignment['revision']??null))throw new DomainException('discovery_unavailable');
        $collector=$this->repo->collector($assignment['collector_id']);
        $discovery=$this->repo->latestDiscovery($source['id']);
        if(!is_array($collector)||($collector['id']??null)!==$assignment['collector_id']||($collector['revoked']??null)!==false||
            !is_array($discovery)||($discovery['id']??null)!==$expectedId||($discovery['connection_id']??null)!==$source['id']||
            ($discovery['collector_id']??null)!==$assignment['collector_id']||($discovery['assignment_revision']??null)!==$assignment['revision']||
            ($discovery['configuration_revision']??null)!==$source['revision']||($discovery['binding_epoch']??null)!==$source['binding_epoch']||
            !array_key_exists('credential_version',$discovery)||$discovery['credential_version']!==$source['credential_version']||
            ($discovery['job_type']??null)!=='list_zones'||($discovery['state']??null)!=='succeeded'||
            ($discovery['attempt_count']??null)!==1||!array_key_exists('lease_id',$discovery)||$discovery['lease_id']!==null||
            !array_key_exists('lease_expires_at',$discovery)||$discovery['lease_expires_at']!==null||
            !is_int($discovery['credential_delivered_at']??null)||$discovery['credential_delivered_at']<1||($discovery['result_code']??null)!=='ok'||
            !is_int($discovery['finished_at']??null)||$discovery['finished_at']<1||$discovery['finished_at']>$now+300||
            $discovery['credential_delivered_at']>$discovery['finished_at']||
            $discovery['finished_at']<$now-max(900,3*$source['interval_seconds'])||
            !is_array($discovery['zones']??null)||!array_is_list($discovery['zones'])||count($discovery['zones'])>1024) {
            throw new DomainException('discovery_unavailable');
        }
        $matches=[];
        foreach($discovery['zones'] as $entry) {
            if(!is_array($entry)||count($entry)!==4||array_diff(array_keys($entry),['name','zone_type','ds_integrated','reverse_lookup'])||
                !is_string($entry['name']??null)||!is_string($entry['zone_type']??null)||strlen($entry['zone_type'])>32||
                !is_bool($entry['ds_integrated']??null)||!is_bool($entry['reverse_lookup']??null))throw new DomainException('discovery_unavailable');
            try {$name=$entry['name']===''?'.':self::zone($entry['name']);} catch(DomainException $_) {continue;}
            if($name===$zone)$matches[]=$entry;
        }
        if(count($matches)>1)throw new DomainException('discovery_unavailable');
        if(count($matches)!==1||!in_array(strtolower($matches[0]['zone_type']),['primary','secondary'],true)) {
            throw new DomainException('zone_not_eligible');
        }
    }
    private function save(array $row,string $action,int $actor,int $now): array
    {
        $this->repo->putDataset($row);
        $this->repo->audit(['action'=>$action,'object_id'=>$row['id'],'actor_id'=>$actor,'created_at'=>$now]);
        return $row;
    }

    public function setSelected(string $sourceId,string $zone,bool $selected,?int $expectedRevision,?string $discoveryId,int $actor): array
    {
        self::actor($actor);$sourceId=self::id($sourceId);$zone=self::zone($zone);
        return $this->repo->transaction(function() use($sourceId,$zone,$selected,$expectedRevision,$discoveryId,$actor): array {
            $source=$this->source($sourceId);$now=$this->now();$row=$this->repo->datasetByZone($sourceId,$zone);
            if($row!==null) {
                $row=self::dataset($row,$sourceId,$zone);
                if($expectedRevision!==$row['revision'])throw new DomainException('revision_conflict');
            } elseif($expectedRevision!==null)throw new DomainException('revision_conflict');
            if($selected) {
                $this->discovery($source,$zone,$discoveryId,$now);
                if($row!==null&&$row['selected'])return $row;
                $selectedCount=$this->repo->selectedCount($sourceId);
                if($selectedCount<0)throw new DomainException('selection_unavailable');
                if($selectedCount>=64)throw new DomainException('selection_limit');
                if($row===null) {
                    $row=['id'=>bin2hex(random_bytes(16)),'connection_id'=>$sourceId,'zone_name'=>$zone,
                        'virtualization_instance'=>'default','zone_scope'=>'default','selected'=>true,'collection_enabled'=>false,
                        'revision'=>1,'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor];
                } else {
                    $row['selected']=true;$row['collection_enabled']=false;$row['revision']=self::next($row['revision']);
                    $row['updated_at']=$now;$row['updated_by']=$actor;
                }
                return $this->save($row,'zone_selected',$actor,$now);
            }
            if($row===null)throw new DomainException('dataset_not_found');
            if(!$row['selected'])return $row;
            $row['selected']=false;$row['collection_enabled']=false;$row['revision']=self::next($row['revision']);
            $row['updated_at']=$now;$row['updated_by']=$actor;
            return $this->save($row,'zone_deselected',$actor,$now);
        });
    }

    public function setCollectionEnabled(string $datasetId,int $expectedRevision,bool $enabled,?string $discoveryId,int $actor): array
    {
        self::actor($actor);$datasetId=self::id($datasetId);
        return $this->repo->transaction(function() use($datasetId,$expectedRevision,$enabled,$discoveryId,$actor): array {
            $row=$this->repo->dataset($datasetId);
            if(!is_array($row)||!is_string($row['connection_id']??null)||!is_string($row['zone_name']??null))throw new DomainException('dataset_not_found');
            $sourceId=self::id($row['connection_id']);$zone=self::zone($row['zone_name']);$row=self::dataset($row,$sourceId,$zone);
            if($expectedRevision!==$row['revision'])throw new DomainException('revision_conflict');
            $now=$this->now();
            if($enabled) {
                if(!$row['selected'])throw new DomainException('dataset_not_selected');
                $source=$this->source($sourceId);
                if(!$source['enabled'])throw new DomainException('source_disabled');
                $this->discovery($source,$zone,$discoveryId,$now);
            }
            if($row['collection_enabled']===$enabled)return $row;
            $row['collection_enabled']=$enabled;$row['revision']=self::next($row['revision']);
            $row['updated_at']=$now;$row['updated_by']=$actor;
            return $this->save($row,$enabled?'zone_collection_enabled':'zone_collection_disabled',$actor,$now);
        });
    }
}
