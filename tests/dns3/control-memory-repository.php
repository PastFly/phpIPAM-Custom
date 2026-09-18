<?php
declare(strict_types=1);

final class Dns3MemoryRepository implements IntegrationDnsCollectorControlRepository
{
    public array $collectors=[],$assignments=[],$jobs=[],$events=[];
    public ?int $failAt=null;
    private int $writes=0;
    private bool $in=false;

    public function transaction(callable $work): mixed {
        if($this->in) throw new DomainException('control_unavailable');
        $old=[$this->collectors,$this->assignments,$this->jobs,$this->events];
        $this->in=true; $this->writes=0;
        try { return $work(); }
        catch(Throwable $e) { [$this->collectors,$this->assignments,$this->jobs,$this->events]=$old; throw $e; }
        finally { $this->in=false; }
    }
    private function guard(bool $write=false): void {
        if(!$this->in) throw new LogicException('outside_transaction');
        if($write && ++$this->writes===$this->failAt) throw new RuntimeException('injected_write_failure');
    }
    public function collectors(): array { $this->guard(); return array_values($this->collectors); }
    public function collector(string $id): ?array { $this->guard(); return $this->collectors[$id]??null; }
    public function putCollector(array $row): void { $this->guard(true); $this->collectors[$row['id']]=$row; }
    public function assignments(): array { $this->guard(); return array_values($this->assignments); }
    public function assignment(string $connectionId): ?array { $this->guard(); return $this->assignments[$connectionId]??null; }
    public function putAssignment(array $row): void { $this->guard(true); $this->assignments[$row['connection_id']]=$row; }
    public function removeAssignment(string $connectionId): void { $this->guard(true); unset($this->assignments[$connectionId]); }
    public function jobs(): array { $this->guard(); return array_values($this->jobs); }
    public function putJob(array $row): void { $this->guard(true); $this->jobs[$row['id']]=$row; }
    public function removeJob(string $id): void { $this->guard(true); unset($this->jobs[$id]); }
    public function audit(array $event): void { $this->guard(true); $this->events[]=$event; }
}
