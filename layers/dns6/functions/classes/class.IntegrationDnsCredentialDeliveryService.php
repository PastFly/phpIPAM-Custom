<?php
declare(strict_types=1);

/**
 * One-shot credential delivery for an already-running leased DNS machine job.
 * The collector supplies only token + job id + lease id. Profile selection is server-side.
 */
final class IntegrationDnsCredentialDeliveryService
{
    private Closure $secureContextResolver;
    private Closure $decryptor;
    private Closure $clock;

    public function __construct(
        private IntegrationDnsCollectorControlRepository $repo,
        callable $secureContextResolver,
        callable $decryptor,
        ?callable $clock=null
    ) {
        $this->secureContextResolver=Closure::fromCallable($secureContextResolver);
        $this->decryptor=Closure::fromCallable($decryptor);
        $this->clock=$clock===null?static fn():int=>time():Closure::fromCallable($clock);
    }

    private function now(): int
    {
        $v=($this->clock)();
        if(!is_int($v)||$v<1) throw new DomainException('control_unavailable');
        return $v;
    }

    private static function id(string $v): string
    {
        if(!preg_match('/^[0-9a-f]{32}$/D',$v)) throw new DomainException('id_invalid');
        return $v;
    }

    private static function digest(string $token): string
    {
        return hash('sha256','phpipam-custom|dns-control|v1|'.$token);
    }

    private function collector(#[SensitiveParameter] string $token): array
    {
        if(!preg_match('/^dnsctl1\.([0-9a-f]{32})\.[A-Za-z0-9_-]{43}$/D',$token,$m)) {
            throw new DomainException('collector_auth_failed');
        }
        $row=$this->repo->collector($m[1]);
        if($row===null||($row['revoked']??true)!==false||!is_string($row['token_digest']??null)||
           !hash_equals($row['token_digest'],self::digest($token))) {
            throw new DomainException('collector_auth_failed');
        }
        return $row;
    }

    private static function obsolete(array $job,int $now,string $code): array
    {
        $job['state']='obsolete';
        $job['lease_id']=null;
        $job['lease_expires_at']=null;
        $job['result_code']=$code;
        $job['result_payload']=null;
        $job['finished_at']=$now;
        $job['updated_at']=$now;
        $job['updated_by']=0;
        return $job;
    }

    public function deliver(#[SensitiveParameter] string $token,string $jobId,string $leaseId): array
    {
        self::id($jobId); self::id($leaseId);
        $out=$this->repo->transaction(function() use($token,$jobId,$leaseId) {
            $collector=$this->collector($token);
            $now=$this->now();

            $job=null;
            foreach($this->repo->jobs() as $candidate) {
                if(($candidate['id']??null)===$jobId){$job=$candidate;break;}
            }
            if($job===null||($job['collector_id']??null)!==$collector['id']) throw new DomainException('job_not_found');
            if(($job['state']??null)!=='running') throw new DomainException('job_not_running');
            if(!is_string($job['lease_id']??null)||!hash_equals($job['lease_id'],$leaseId)) throw new DomainException('lease_invalid');
            if((int)($job['lease_expires_at']??0)<$now||(int)($job['expires_at']??0)<$now) {
                throw new DomainException('job_expired');
            }
            if(($job['credential_delivered_at']??null)!==null) throw new DomainException('credential_already_delivered');

            $assignment=$this->repo->assignment($job['connection_id']);
            if($assignment===null||($assignment['collector_id']??null)!==$collector['id']||
               ($assignment['revision']??null)!==($job['assignment_revision']??null)) {
                $this->repo->putJob(self::obsolete($job,$now,'assignment_changed'));
                return ['error'=>'assignment_changed'];
            }

            $ctx=($this->secureContextResolver)($job['connection_id']);
            if(!is_array($ctx)||($ctx['id']??null)!==$job['connection_id']||
               !is_int($ctx['revision']??null)||!is_int($ctx['binding_epoch']??null)||
               !in_array($ctx['credential_mode']??null,['profile','collector_identity'],true)) {
                $this->repo->putJob(self::obsolete($job,$now,'job_obsolete'));
                return ['error'=>'job_obsolete'];
            }
            $credVersion=$ctx['credential_mode']==='profile'?($ctx['secret_version']??null):null;
            if($ctx['revision']!==$job['configuration_revision']||
               $ctx['binding_epoch']!==$job['binding_epoch']||
               $credVersion!==$job['credential_version']) {
                $this->repo->putJob(self::obsolete($job,$now,'job_obsolete'));
                return ['error'=>'job_obsolete'];
            }

            $response=['credential_mode'=>$ctx['credential_mode']];
            if($ctx['credential_mode']==='profile') {
                if(!is_string($ctx['profile_id']??null)||!preg_match('/^[0-9a-f]{32}$/D',$ctx['profile_id'])||
                   !is_string($ctx['username']??null)||$ctx['username']===''||
                   !is_string($ctx['secret_envelope']??null)||$ctx['secret_envelope']===''||
                   !is_int($ctx['secret_version']??null)||$ctx['secret_version']<1) {
                    throw new DomainException('credential_unavailable');
                }
                $password=($this->decryptor)($ctx);
                if(!is_string($password)||$password==='') throw new DomainException('credential_unavailable');
                $response['username']=$ctx['username'];
                $response['password']=$password;
            }

            $job['credential_delivered_at']=$now;
            $job['updated_at']=$now;
            $job['updated_by']=0;
            $this->repo->putJob($job);
            $this->repo->audit(['action'=>'credential_delivered','object_id'=>$job['id'],'actor_id'=>0,'created_at'=>$now]);
            return ['response'=>$response];
        });
        if(isset($out['error'])) throw new DomainException($out['error']);
        return $out['response'];
    }
}
