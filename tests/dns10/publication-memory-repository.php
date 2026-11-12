<?php
declare(strict_types=1);

/** Test-only guarded, rollback-capable repository. No production database access. */
final class Dns10PublicationMemoryRepository implements IntegrationDnsZonePublicationRepository
{
    public array $tokens=[],$sources=[],$collectors=[],$assignments=[],$datasets=[],$jobs=[],$generations=[],$records=[],$heads=[];
    public ?int $failAt=null;
    public ?int $accountedOverride=null;
    public ?Closure $afterInsert=null;
    private bool $inTransaction=false;
    private int $writes=0;
    public function transaction(callable $work): mixed {
        if ($this->inTransaction) throw new DomainException('upload_unavailable');
        $before=[$this->tokens,$this->sources,$this->collectors,$this->assignments,$this->datasets,$this->jobs,$this->generations,$this->records,$this->heads];
        $this->inTransaction=true;$this->writes=0;
        try {$value=$work();$this->inTransaction=false;return $value;}
        catch (Throwable $e) {
            [$this->tokens,$this->sources,$this->collectors,$this->assignments,$this->datasets,$this->jobs,$this->generations,$this->records,$this->heads]=$before;
            $this->inTransaction=false;throw $e;
        }
    }
    private function guard(): void {if(!$this->inTransaction)throw new DomainException('upload_unavailable');}
    private function write(): void {$this->guard();++$this->writes;if($this->failAt===$this->writes)throw new DomainException('injected_failure');}
    public function uploadToken(string $id): ?array {$this->guard();return $this->tokens[$id]??null;}
    public function source(string $id): ?array {$this->guard();return $this->sources[$id]??null;}
    public function collector(string $id): ?array {$this->guard();return $this->collectors[$id]??null;}
    public function assignment(string $id): ?array {$this->guard();return $this->assignments[$id]??null;}
    public function dataset(string $id): ?array {$this->guard();return $this->datasets[$id]??null;}
    public function job(string $id): ?array {$this->guard();return $this->jobs[$id]??null;}
    public function head(string $id): array {
        $this->guard();$head=$this->heads[$id]??['current'=>null,'previous'=>null];
        foreach (['current','previous'] as $key) {
            if ($head[$key]!==null && !isset($this->generations[$head[$key]])) throw new DomainException('snapshot_unavailable');
        }
        return ['current'=>$head['current']===null?null:($this->generations[$head['current']]??null),
                'previous'=>$head['previous']===null?null:($this->generations[$head['previous']]??null)];
    }
    public function accountedBytes(): int {
        $this->guard();if($this->accountedOverride!==null)return $this->accountedOverride;
        return array_sum(array_column($this->generations,'payload_bytes'));
    }
    public function generationCount(string $datasetId): int {
        $this->guard();return count(array_filter($this->generations,static fn($g)=>($g['dataset_id']??null)===$datasetId));
    }
    public function insertGeneration(array $generation,array $records): void {
        $this->write();$id=$generation['id'];if(isset($this->generations[$id]))throw new DomainException('upload_unavailable');
        $this->generations[$id]=$generation;$this->records[$id]=$records;
        if ($this->afterInsert!==null) ($this->afterInsert)();
    }
    public function setHead(string $datasetId,string $currentId,?string $previousId): void {
        $this->write();$this->heads[$datasetId]=['current'=>$currentId,'previous'=>$previousId];
    }
    public function removeGeneration(string $generationId): void {
        $this->write();unset($this->generations[$generationId],$this->records[$generationId]);
    }
    public function completeJob(string $jobId,#[SensitiveParameter] string $leaseId,int $now): void {
        $this->write();$job=$this->jobs[$jobId]??null;
        if (!is_array($job)||($job['state']??null)!=='running'||($job['lease_id']??null)!==$leaseId)throw new DomainException('snapshot_job_invalid');
        $this->jobs[$jobId]['state']='succeeded';$this->jobs[$jobId]['lease_id']=null;
        $this->jobs[$jobId]['lease_expires_at']=null;$this->jobs[$jobId]['finished_at']=$now;
        $this->jobs[$jobId]['result_code']='ok';
    }
}
