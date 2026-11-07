<?php
declare(strict_types=1);
/** Test-only transactional store. NOT evidence of MySQL execution, locking or durability. */
final class DnsMemoryRepository implements IntegrationDnsConfigRepository
{
    public array $profiles=[],$connections=[],$events=[];
    public ?int $failAt=null;
    private int $writes=0;
    private bool $inTransaction=false;
    public function transaction(callable $work): mixed
    {
        if ($this->inTransaction) throw new LogicException('nested_transaction');
        $old=[$this->profiles,$this->connections,$this->events]; $this->writes=0; $this->inTransaction=true;
        try { return $work(); }
        catch (Throwable $e) { [$this->profiles,$this->connections,$this->events]=$old; throw $e; }
        finally { $this->inTransaction=false; }
    }
    private function guard(bool $write=false): void
    {
        if (!$this->inTransaction) throw new LogicException('outside_transaction');
        if ($write && ++$this->writes===$this->failAt) throw new RuntimeException('injected_write_failure');
    }
    public function joinControlTransaction(object $control,callable $work): mixed
    {
        if($this->inTransaction || !is_callable([$control,'ownsTransactionFor']) || !$control->ownsTransactionFor($this)) {
            throw new DomainException('configuration_unavailable');
        }
        $old=[$this->profiles,$this->connections,$this->events];$this->writes=0;$this->inTransaction=true;
        try {return $work();}
        catch(Throwable $e) {[$this->profiles,$this->connections,$this->events]=$old;throw $e;}
        finally {$this->inTransaction=false;}
    }
    public function profiles(): array { $this->guard(); return array_values($this->profiles); }
    public function profile(string $id): ?array { $this->guard(); return $this->profiles[$id]??null; }
    public function putProfile(array $row): void { $this->guard(true); $this->profiles[$row['id']]=$row; }
    public function removeProfile(string $id): void { $this->guard(true); unset($this->profiles[$id]); }
    public function connections(): array { $this->guard(); return array_values($this->connections); }
    public function connection(string $id): ?array { $this->guard(); return $this->connections[$id]??null; }
    public function putConnection(array $row): void { $this->guard(true); $this->connections[$row['id']]=$row; }
    public function removeConnection(string $id): void { $this->guard(true); unset($this->connections[$id]); }
    public function audit(array $event): void { $this->guard(true); $this->events[]=$event; $this->events=array_slice($this->events,-1000); }
}
