<?php
declare(strict_types=1);

/** Test-only transactional administrative dataset fixture. */
final class Dns10SelectionMemoryRepository implements IntegrationDnsZoneSelectionRepository
{
    public array $sources=[],$collectors=[],$assignments=[],$discoveries=[],$datasets=[],$events=[],$observations=[];
    public ?int $failAt=null;
    public ?int $countOverride=null;
    private bool $inTransaction=false;private int $writes=0;
    public function transaction(callable $work): mixed {
        if($this->inTransaction)throw new DomainException('selection_unavailable');
        $before=[$this->sources,$this->collectors,$this->assignments,$this->discoveries,$this->datasets,$this->events,$this->observations];
        $this->inTransaction=true;$this->writes=0;
        try {$value=$work();$this->inTransaction=false;return $value;}
        catch(Throwable $e) {[$this->sources,$this->collectors,$this->assignments,$this->discoveries,$this->datasets,$this->events,$this->observations]=$before;$this->inTransaction=false;throw $e;}
    }
    private function guard(bool $write=false): void {
        if(!$this->inTransaction)throw new DomainException('selection_unavailable');
        if($write && ++$this->writes===$this->failAt)throw new DomainException('injected_failure');
    }
    public function source(string $id): ?array {$this->guard();return $this->sources[$id]??null;}
    public function collector(string $id): ?array {$this->guard();return $this->collectors[$id]??null;}
    public function assignment(string $sourceId): ?array {$this->guard();return $this->assignments[$sourceId]??null;}
    public function latestDiscovery(string $sourceId): ?array {$this->guard();return $this->discoveries[$sourceId]??null;}
    public function dataset(string $id): ?array {$this->guard();return $this->datasets[$id]??null;}
    public function datasetByZone(string $sourceId,string $zone): ?array {
        $this->guard();$found=[];
        foreach($this->datasets as $row)if(($row['connection_id']??null)===$sourceId && ($row['zone_name']??null)===$zone)$found[]=$row;
        if(count($found)>1)throw new DomainException('selection_unavailable');return $found[0]??null;
    }
    public function selectedCount(string $sourceId): int {
        $this->guard();if($this->countOverride!==null)return $this->countOverride;
        return count(array_filter($this->datasets,static fn($row)=>($row['connection_id']??null)===$sourceId && ($row['selected']??null)===true));
    }
    public function putDataset(array $row): void {
        $this->guard(true);$old=$this->datasets[$row['id']]??null;
        if($old!==null && (($old['connection_id']??null)!==$row['connection_id'] || ($old['zone_name']??null)!==$row['zone_name']))throw new DomainException('selection_unavailable');
        $this->datasets[$row['id']]=$row;
    }
    public function audit(array $event): void {$this->guard(true);$this->events[]=$event;}
}
