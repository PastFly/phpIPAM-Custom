<?php
declare(strict_types=1);

/**
 * Machine poll/result service. No credential decryption or delivery.
 * Every poll/result transition revalidates collector, assignment and current connection versions.
 */
final class IntegrationDnsCollectorMachineService
{
    private Closure $connectionResolver;
    private Closure $clock;

    public function __construct(
        private IntegrationDnsCollectorControlRepository $repo,
        callable $connectionResolver,
        ?callable $clock=null
    ) {
        $this->connectionResolver=Closure::fromCallable($connectionResolver);
        $this->clock=$clock===null ? static fn(): int => time() : Closure::fromCallable($clock);
    }

    private function now(): int
    {
        $v=($this->clock)();
        if(!is_int($v)||$v<1) throw new DomainException('control_unavailable');
        return $v;
    }

    private static function id(mixed $v): string
    {
        if(!is_string($v)||!preg_match('/^[0-9a-f]{32}$/D',$v)) throw new DomainException('id_invalid');
        return $v;
    }

    private static function tokenDigest(string $token): string
    {
        return hash('sha256','phpipam-custom|dns-control|v1|'.$token);
    }

    private function collectorFromToken(string $token): array
    {
        if(!preg_match('/^dnsctl1\.([0-9a-f]{32})\.[A-Za-z0-9_-]{43}$/D',$token,$m)) {
            throw new DomainException('collector_auth_failed');
        }
        $row=$this->repo->collector($m[1]);
        if($row===null||($row['revoked']??true)!==false||!is_string($row['token_digest']??null)||
           !hash_equals($row['token_digest'],self::tokenDigest($token))) {
            throw new DomainException('collector_auth_failed');
        }
        return $row;
    }

    private function context(string $connectionId): array
    {
        $c=($this->connectionResolver)($connectionId);
        if(!is_array($c)||($c['id']??null)!==$connectionId||
           !is_string($c['server']??null)||$c['server']===''||
           !is_int($c['revision']??null)||$c['revision']<1||
           !is_int($c['binding_epoch']??null)||$c['binding_epoch']<1||
           !in_array($c['credential_mode']??null,['profile','collector_identity'],true)) {
            throw new DomainException('connection_unavailable');
        }
        if($c['credential_mode']==='profile' &&
           (!is_int($c['credential_version']??null)||$c['credential_version']<1)) {
            throw new DomainException('connection_unavailable');
        }
        return $c;
    }

    private static function terminal(array $j,int $now,string $code): array
    {
        $j['state']=$code==='lease_expired'?'expired':'obsolete';
        $j['lease_id']=null;
        $j['lease_expires_at']=null;
        $j['result_code']=$code;
        $j['result_payload']=null;
        $j['finished_at']=$now;
        $j['updated_at']=$now;
        $j['updated_by']=0;
        return $j;
    }

    private function pruneAndExpire(array $jobs,int $now): array
    {
        $kept=[];
        foreach($jobs as $j) {
            // A newer deployment may introduce job types this worker cannot safely interpret.
            if (!in_array($j['job_type']??null,['test_connection','list_zones'],true)) {$kept[]=$j;continue;}
            $state=$j['state']??'';
            if(in_array($state,['succeeded','failed','expired','obsolete'],true) &&
               (int)($j['finished_at']??$j['updated_at']??0) <= $now-86400) {
                $this->repo->removeJob($j['id']);
                continue;
            }
            if($state==='pending' && (int)($j['expires_at']??0)<$now) {
                $j=self::terminal($j,$now,'job_expired'); $j['state']='expired'; $this->repo->putJob($j);
            } elseif($state==='running' && (int)($j['lease_expires_at']??0)<$now) {
                $j=self::terminal($j,$now,'lease_expired'); $this->repo->putJob($j);
            }
            $kept[]=$j;
        }
        return $kept;
    }

    private function currentOrObsolete(array $job,array $collector,int $now): array
    {
        $assignment=$this->repo->assignment($job['connection_id']);
        if($assignment===null||($assignment['collector_id']??null)!==$collector['id']||
           ($assignment['revision']??null)!==($job['assignment_revision']??null)) {
            $this->repo->putJob(self::terminal($job,$now,'assignment_changed'));
            return ['error'=>'assignment_changed'];
        }
        $ctx=$this->context($job['connection_id']);
        $cred=$ctx['credential_mode']==='profile'?$ctx['credential_version']:null;
        if($ctx['revision']!==$job['configuration_revision']||
           $ctx['binding_epoch']!==$job['binding_epoch']||
           $cred!==$job['credential_version']) {
            $this->repo->putJob(self::terminal($job,$now,'job_obsolete'));
            return ['error'=>'job_obsolete'];
        }
        return ['assignment'=>$assignment,'context'=>$ctx];
    }

    public function poll(#[SensitiveParameter] string $token): ?array
    {
        $out=$this->repo->transaction(function() use($token) {
            $collector=$this->collectorFromToken($token);
            $now=$this->now();
            $jobs=$this->pruneAndExpire($this->repo->jobs(),$now);
            usort($jobs,static fn($a,$b)=>[$a['created_at'],$a['id']]<=>[$b['created_at'],$b['id']]);
            foreach($jobs as $job) {
                if(($job['collector_id']??null)!==$collector['id']||($job['state']??null)!=='pending') continue;
                if(!in_array($job['job_type']??null,['test_connection','list_zones'],true)) continue;
                $current=$this->currentOrObsolete($job,$collector,$now);
                if(isset($current['error'])) return $current;
                $lease=bin2hex(random_bytes(16));
                $job['state']='running';
                $job['lease_id']=$lease;
                $job['lease_expires_at']=min($job['expires_at'],$now+120);
                $job['attempt_count']=(int)$job['attempt_count']+1;
                $job['updated_at']=$now;
                $job['updated_by']=0;
                $this->repo->putJob($job);
                $ctx=$current['context'];
                return ['work'=>[
                    'id'=>$job['id'],'job_type'=>$job['job_type'],'server'=>$ctx['server'],
                    'configuration_revision'=>$job['configuration_revision'],
                    'binding_epoch'=>$job['binding_epoch'],'credential_version'=>$job['credential_version'],
                    'assignment_revision'=>$job['assignment_revision'],
                    'lease_id'=>$lease,'lease_expires_at'=>$job['lease_expires_at']
                ]];
            }
            return ['work'=>null];
        });
        if(isset($out['error'])) throw new DomainException($out['error']);
        return $out['work'];
    }

    private static function sanitizeResult(array $input,string $type): array
    {
        if(!in_array($type,['test_connection','list_zones'],true)) throw new DomainException('result_invalid');
        $allowed=$type==='list_zones'?['ok','error_code','zones']:['ok','error_code'];
        if(array_diff(array_keys($input),$allowed)||!is_bool($input['ok']??null)||
           !array_key_exists('error_code',$input)) throw new DomainException('result_invalid');
        if($input['ok']===false) {
            $codes=['network','timeout','authentication','authorization','configuration','provider','unknown'];
            if(!is_string($input['error_code'])||!in_array($input['error_code'],$codes,true)) throw new DomainException('result_invalid');
            if(array_key_exists('zones',$input)) throw new DomainException('result_invalid');
            return ['state'=>'failed','code'=>$input['error_code'],'payload'=>null];
        }
        if($input['error_code']!==null) throw new DomainException('result_invalid');
        if($type==='test_connection') return ['state'=>'succeeded','code'=>'ok','payload'=>null];

        $zones=$input['zones']??null;
        if(!is_array($zones)||!array_is_list($zones)||count($zones)>1024) throw new DomainException('result_invalid');
        $clean=[];
        foreach($zones as $z) {
            if(!is_array($z)||array_diff(array_keys($z),['name','zone_type','ds_integrated','reverse_lookup'])||
               !is_string($z['name']??null)||$z['name']===''||strlen($z['name'])>255||
               !is_string($z['zone_type']??null)||strlen($z['zone_type'])>32||
               !is_bool($z['ds_integrated']??null)||!is_bool($z['reverse_lookup']??null)) {
                throw new DomainException('result_invalid');
            }
            $clean[]=['name'=>strtolower(rtrim($z['name'],'.')),'zone_type'=>$z['zone_type'],
                'ds_integrated'=>$z['ds_integrated'],'reverse_lookup'=>$z['reverse_lookup']];
        }
        $payload=json_encode(['zones'=>$clean],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(strlen($payload)>65536) throw new DomainException('result_invalid');
        return ['state'=>'succeeded','code'=>'ok','payload'=>$payload];
    }

    public function submitResult(#[SensitiveParameter] string $token,string $jobId,string $leaseId,array $input): array
    {
        self::id($jobId); self::id($leaseId);
        $out=$this->repo->transaction(function() use($token,$jobId,$leaseId,$input) {
            $collector=$this->collectorFromToken($token);
            $now=$this->now();
            $job=null;
            foreach($this->repo->jobs() as $candidate) if(($candidate['id']??null)===$jobId){$job=$candidate;break;}
            if($job===null||($job['collector_id']??null)!==$collector['id']) throw new DomainException('job_not_found');
            if(!in_array($job['job_type']??null,['test_connection','list_zones'],true)) throw new DomainException('result_invalid');
            if(($job['state']??null)!=='running') throw new DomainException('job_not_running');
            if(!is_string($job['lease_id']??null)||!hash_equals($job['lease_id'],$leaseId)) throw new DomainException('lease_invalid');
            if((int)($job['lease_expires_at']??0)<$now||(int)($job['expires_at']??0)<$now) {
                $this->repo->putJob(self::terminal($job,$now,'lease_expired'));
                return ['error'=>'job_expired'];
            }
            $current=$this->currentOrObsolete($job,$collector,$now);
            if(isset($current['error'])) return $current;

            $clean=self::sanitizeResult($input,$job['job_type']);
            $job['state']=$clean['state'];
            $job['lease_id']=null; $job['lease_expires_at']=null;
            $job['result_code']=$clean['code']; $job['result_payload']=$clean['payload'];
            $job['finished_at']=$now; $job['updated_at']=$now; $job['updated_by']=0;
            $this->repo->putJob($job);
            return ['result'=>['id'=>$job['id'],'state'=>$job['state'],'result_code'=>$job['result_code'],'finished_at'=>$now]];
        });
        if(isset($out['error'])) throw new DomainException($out['error']);
        return $out['result'];
    }
}
