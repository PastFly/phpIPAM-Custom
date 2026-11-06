<?php
declare(strict_types=1);

/**
 * Server-side autonomous job scheduler.
 * It queues only read-only list_zones jobs for enabled connections.
 * No credential decrypt, collector token access, provider call or DNS write occurs here.
 */
final class IntegrationDnsAutonomousScheduler
{
    private const PRESSURE_LIMIT=384;
    private const MIN_AUTOMATIC_HISTORY_SECONDS=3600;
    private Closure $clock;

    public function __construct(
        private IntegrationDnsCollectorControlRepository $repo,
        ?callable $clock=null
    ) {
        $this->clock=$clock===null ? static fn(): int => time() : Closure::fromCallable($clock);
    }

    private function now(): int
    {
        $v=($this->clock)();
        if(!is_int($v)||$v<1) throw new DomainException('scheduler_unavailable');
        return $v;
    }

    private static function id(mixed $v): string
    {
        if(!is_string($v)||!preg_match('/^[0-9a-f]{32}$/D',$v)) throw new DomainException('scheduler_context_invalid');
        return $v;
    }

    private static function context(array $c): array
    {
        $allowed=['id','enabled','interval_seconds','revision','binding_epoch','credential_mode','credential_version'];
        if(array_diff(array_keys($c),$allowed)||array_diff($allowed,array_keys($c))) {
            throw new DomainException('scheduler_context_invalid');
        }
        self::id($c['id']);
        if(!is_bool($c['enabled'])||
           !is_int($c['interval_seconds'])||$c['interval_seconds']<60||$c['interval_seconds']>3600||
           !is_int($c['revision'])||$c['revision']<1||
           !is_int($c['binding_epoch'])||$c['binding_epoch']<1||
           !in_array($c['credential_mode'],['profile','collector_identity'],true)) {
            throw new DomainException('scheduler_context_invalid');
        }
        if($c['credential_mode']==='profile') {
            if(!is_int($c['credential_version'])||$c['credential_version']<1) {
                throw new DomainException('scheduler_context_invalid');
            }
        } elseif($c['credential_version']!==null) {
            throw new DomainException('scheduler_context_invalid');
        }
        return $c;
    }

    /** Keep manual/unknown jobs and the latest terminal and successful result per connection. */
    private function trimAutomaticHistory(array $jobs,int $now): array
    {
        if (count($jobs)<self::PRESSURE_LIMIT || !is_callable([$this->repo,'autonomousJobIds'])) return [$jobs,0];
        $autonomous=$this->repo->autonomousJobIds();
        if (!is_array($autonomous)) throw new DomainException('scheduler_unavailable');
        $last=[]; $lastSuccess=[];
        foreach ($jobs as $job) {
            if (($job['job_type']??null)!=='list_zones' || !in_array($job['state']??null,['succeeded','failed','expired','obsolete'],true) ||
                !is_string($job['connection_id']??null) || !is_string($job['id']??null)) continue;
            $key=$job['connection_id'];
            $order=[(int)($job['created_at']??0),(string)$job['id']];
            if (!isset($last[$key]) || $order>$last[$key]['order']) $last[$key]=['id'=>$job['id'],'order'=>$order];
            if ($job['state']==='succeeded' && ($job['result_code']??null)==='ok' &&
                (!isset($lastSuccess[$key]) || $order>$lastSuccess[$key]['order'])) $lastSuccess[$key]=['id'=>$job['id'],'order'=>$order];
        }
        $protected=[];
        foreach (array_merge(array_values($last),array_values($lastSuccess)) as $row) $protected[$row['id']]=true;
        $candidates=[];
        foreach ($jobs as $index=>$job) {
            $id=$job['id']??null; $finished=$job['finished_at']??null;
            if (!is_string($id) || ($autonomous[$id]??false)!==true || isset($protected[$id]) ||
                ($job['job_type']??null)!=='list_zones' || !in_array($job['state']??null,['succeeded','failed','expired','obsolete'],true) ||
                !is_int($finished) || $finished<1 || $finished>$now-self::MIN_AUTOMATIC_HISTORY_SECONDS) continue;
            $candidates[]=['index'=>$index,'id'=>$id,'finished'=>$finished];
        }
        usort($candidates,static fn($a,$b)=>[$a['finished'],$a['id']]<=>[$b['finished'],$b['id']]);
        $pruned=0;
        foreach ($candidates as $candidate) {
            if (count($jobs)<self::PRESSURE_LIMIT) break;
            $this->repo->removeJob($candidate['id']);
            $this->repo->audit(['action'=>'autonomous_job_pruned','object_id'=>$candidate['id'],'actor_id'=>0,'created_at'=>$now]);
            unset($jobs[$candidate['index']]);
            $pruned++;
        }
        return [array_values($jobs),$pruned];
    }

    public function run(array $contexts): array
    {
        if(!array_is_list($contexts)||count($contexts)>256) throw new DomainException('scheduler_context_invalid');
        $normalized=[];
        foreach($contexts as $c) {
            if(!is_array($c)) throw new DomainException('scheduler_context_invalid');
            $c=self::context($c);
            if(isset($normalized[$c['id']])) throw new DomainException('scheduler_context_invalid');
            $normalized[$c['id']]=$c;
        }

        return $this->repo->transaction(function() use($normalized) {
            $now=$this->now();
            $jobs=[];
            foreach($this->repo->jobs() as $job) {
                $state=$job['state']??'';
                $finished=(int)($job['finished_at']??$job['updated_at']??0);
                if(in_array($state,['succeeded','failed','expired','obsolete'],true) && $finished<=$now-86400) {
                    $this->repo->removeJob($job['id']);
                    continue;
                }
                if($state==='pending' && (int)($job['expires_at']??0)<$now) {
                    $job['state']='expired';
                    $job['result_code']='job_expired';
                    $job['result_payload']=null;
                    $job['finished_at']=$now;
                    $job['updated_at']=$now;
                    $job['updated_by']=0;
                    $this->repo->putJob($job);
                } elseif($state==='running' && (int)($job['lease_expires_at']??0)<$now) {
                    $job['state']='expired';
                    $job['lease_id']=null;
                    $job['lease_expires_at']=null;
                    $job['result_code']='lease_expired';
                    $job['result_payload']=null;
                    $job['finished_at']=$now;
                    $job['updated_at']=$now;
                    $job['updated_by']=0;
                    $this->repo->putJob($job);
                }
                $jobs[]=$job;
            }
            [$jobs,$pressurePruned]=$this->trimAutomaticHistory($jobs,$now);
            $queued=[];
            $skippedDisabled=0;
            $skippedNoAssignment=0;
            $skippedCollector=0;
            $skippedActive=0;
            $skippedInterval=0;
            $skippedCapacity=0;

            foreach($normalized as $connectionId=>$ctx) {
                if($ctx['enabled']!==true) {
                    $skippedDisabled++;
                    continue;
                }

                $assignment=$this->repo->assignment($connectionId);
                if($assignment===null) {
                    $skippedNoAssignment++;
                    continue;
                }
                $collector=$this->repo->collector($assignment['collector_id']);
                if($collector===null||($collector['revoked']??true)!==false) {
                    $skippedCollector++;
                    continue;
                }

                $latestCreated=0;
                $active=false;
                foreach($jobs as $job) {
                    if(($job['connection_id']??null)!==$connectionId||($job['job_type']??null)!=='list_zones') continue;
                    $created=(int)($job['created_at']??0);
                    if($created>$latestCreated) $latestCreated=$created;
                    if(in_array($job['state']??null,['pending','running'],true) &&
                       (int)($job['expires_at']??0)>=$now) {
                        $active=true;
                    }
                }
                if($active) {
                    $skippedActive++;
                    continue;
                }
                if($latestCreated>0 && $latestCreated+$ctx['interval_seconds']>$now) {
                    $skippedInterval++;
                    continue;
                }
                if(count($jobs)+count($queued)>=512) {
                    $skippedCapacity++;
                    continue;
                }

                $id=bin2hex(random_bytes(16));
                $row=[
                    'id'=>$id,
                    'collector_id'=>$collector['id'],
                    'connection_id'=>$connectionId,
                    'assignment_revision'=>$assignment['revision'],
                    'job_type'=>'list_zones',
                    'state'=>'pending',
                    'configuration_revision'=>$ctx['revision'],
                    'binding_epoch'=>$ctx['binding_epoch'],
                    'credential_version'=>$ctx['credential_version'],
                    'lease_id'=>null,
                    'lease_expires_at'=>null,
                    'attempt_count'=>0,
                    'credential_delivered_at'=>null,
                    'result_code'=>null,
                    'result_payload'=>null,
                    'finished_at'=>null,
                    'created_at'=>$now,
                    'expires_at'=>$now+300,
                    'updated_at'=>$now,
                    'updated_by'=>0
                ];
                $this->repo->putJob($row);
                $this->repo->audit([
                    'action'=>'autonomous_job_queued',
                    'object_id'=>$id,
                    'actor_id'=>0,
                    'created_at'=>$now
                ]);
                $queued[]=$id;
            }

            return [
                'queued'=>$queued,
                'queued_count'=>count($queued),
                'skipped_disabled'=>$skippedDisabled,
                'skipped_no_assignment'=>$skippedNoAssignment,
                'skipped_collector'=>$skippedCollector,
                'skipped_active'=>$skippedActive,
                'skipped_interval'=>$skippedInterval,
                'skipped_capacity'=>$skippedCapacity,
                'pressure_pruned'=>$pressurePruned
            ];
        });
    }
}
