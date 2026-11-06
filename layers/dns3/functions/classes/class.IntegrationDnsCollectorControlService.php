<?php
declare(strict_types=1);

/**
 * Source-only collector registration/assignment and typed admin job queue.
 * No credential decryption/delivery, HTTP, Windows or DNS provider calls.
 */
final class IntegrationDnsCollectorControlService
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

    private static function actor(int $id): void
    {
        if ($id<1) throw new DomainException('actor_invalid');
    }

    private static function fields(array $input,array $allowed): void
    {
        if (array_diff(array_keys($input),$allowed)) throw new DomainException('fields_invalid');
    }

    private static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[0-9a-f]{32}$/D',$id)) throw new DomainException('id_invalid');
        return $id;
    }

    private static function name(mixed $value): string
    {
        if (!is_string($value) || $value!==trim($value) || $value==='' || strlen($value)>128 ||
            !preg_match('//u',$value) || preg_match('/[\x00-\x1f\x7f]/',$value)) {
            throw new DomainException('name_invalid');
        }
        return $value;
    }

    private static function next(int $value): int
    {
        if ($value<0 || $value>=2147483647) throw new DomainException('version_exhausted');
        return $value+1;
    }

    private function now(): int
    {
        $value=($this->clock)();
        if (!is_int($value) || $value<1) throw new DomainException('control_unavailable');
        return $value;
    }

    private static function digest(string $token): string
    {
        return hash('sha256','phpipam-custom|dns-control|v1|'.$token);
    }

    private static function token(string $collectorId): string
    {
        $secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        return 'dnsctl1.'.$collectorId.'.'.$secret;
    }

    private static function publicCollector(array $row): array
    {
        return array_intersect_key($row,array_flip([
            'id','name','revision','control_epoch','revoked','created_at','created_by','updated_at','updated_by'
        ]));
    }

    private static function publicAssignment(array $row): array
    {
        return array_intersect_key($row,array_flip([
            'connection_id','collector_id','revision','assigned_at','assigned_by'
        ]));
    }

    private static function publicJob(array $row): array
    {
        return array_intersect_key($row,array_flip([
            'id','collector_id','connection_id','assignment_revision','job_type','state','configuration_revision',
            'binding_epoch','credential_version','attempt_count','result_code','finished_at',
            'created_at','expires_at','updated_at','updated_by'
        ]));
    }

    private function event(string $action,string $objectId,int $actor): void
    {
        $this->repo->audit([
            'action'=>$action,
            'object_id'=>$objectId,
            'actor_id'=>$actor,
            'created_at'=>$this->now()
        ]);
    }

    private function context(string $connectionId): array
    {
        $context=($this->connectionResolver)($connectionId);
        if (!is_array($context) || ($context['id']??null)!==$connectionId ||
            !is_int($context['revision']??null) || $context['revision']<1 ||
            !is_int($context['binding_epoch']??null) || $context['binding_epoch']<1 ||
            !in_array($context['credential_mode']??null,['profile','collector_identity'],true)) {
            throw new DomainException('connection_unavailable');
        }
        if ($context['credential_mode']==='profile') {
            if (!is_int($context['credential_version']??null) || $context['credential_version']<1) {
                throw new DomainException('connection_unavailable');
            }
        }
        return $context;
    }

    public function registerCollector(array $input,int $actor): array
    {
        self::actor($actor);
        self::fields($input,['name']);
        $name=self::name($input['name']??null);
        return $this->repo->transaction(function() use($name,$actor) {
            if (count($this->repo->collectors())>=64) throw new DomainException('collector_limit');
            $id=bin2hex(random_bytes(16));
            $token=self::token($id);
            $now=$this->now();
            $row=[
                'id'=>$id,'name'=>$name,'token_digest'=>self::digest($token),
                'revision'=>1,'control_epoch'=>1,'revoked'=>false,
                'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor
            ];
            $this->repo->putCollector($row);
            $this->event('collector_registered',$id,$actor);
            return ['collector'=>self::publicCollector($row),'control_token'=>$token];
        });
    }

    public function authenticateCollectorToken(#[SensitiveParameter] string $token): array
    {
        if (!preg_match('/^dnsctl1\.([0-9a-f]{32})\.[A-Za-z0-9_-]{43}$/D',$token,$m)) {
            throw new DomainException('collector_auth_failed');
        }
        $id=$m[1];
        try {
            return $this->repo->transaction(function() use($id,$token) {
                $row=$this->repo->collector($id);
                if ($row===null || ($row['revoked']??true)!==false ||
                    !is_string($row['token_digest']??null) ||
                    !hash_equals($row['token_digest'],self::digest($token))) {
                    throw new DomainException('collector_auth_failed');
                }
                return self::publicCollector($row);
            });
        } catch (DomainException $e) {
            if ($e->getMessage()==='collector_auth_failed') throw $e;
            throw new DomainException('collector_auth_failed');
        } catch (Throwable $e) {
            throw new DomainException('collector_auth_failed');
        }
    }

    private static function zoneResult(array $job): ?array
    {
        if (($job['job_type']??null)!=='list_zones' || ($job['state']??null)!=='succeeded' ||
            ($job['result_code']??null)!=='ok' || !is_string($job['result_payload']??null) ||
            strlen($job['result_payload'])>65536) return null;
        try { $payload=json_decode($job['result_payload'],true,32,JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { return null; }
        if (!is_array($payload) || array_keys($payload)!==['zones'] ||
            !is_array($payload['zones']) || !array_is_list($payload['zones']) || count($payload['zones'])>1024) return null;
        $zones=[];
        foreach ($payload['zones'] as $zone) {
            if (!is_array($zone) || count($zone)!==4 || array_diff(array_keys($zone),['name','zone_type','ds_integrated','reverse_lookup']) ||
                !is_string($zone['name']??null) || strlen($zone['name'])>255 ||
                !is_string($zone['zone_type']??null) || strlen($zone['zone_type'])>32 ||
                !is_bool($zone['ds_integrated']??null) || !is_bool($zone['reverse_lookup']??null)) return null;
            $zones[]=['name'=>$zone['name'],'zone_type'=>$zone['zone_type'],
                'ds_integrated'=>$zone['ds_integrated'],'reverse_lookup'=>$zone['reverse_lookup']];
        }
        return self::publicJob($job)+['zones'=>$zones];
    }

    public function snapshot(bool $includeZoneResults=false): array
    {
        return $this->repo->transaction(function() use($includeZoneResults) {
            $jobs=$this->repo->jobs();
            $snapshot=[
                'collectors'=>array_map([self::class,'publicCollector'],$this->repo->collectors()),
                'assignments'=>array_map([self::class,'publicAssignment'],$this->repo->assignments()),
                'jobs'=>array_map([self::class,'publicJob'],$jobs)
            ];
            if ($includeZoneResults) {
                $snapshot['zone_results']=array_values(array_filter(array_map([self::class,'zoneResult'],$jobs),static fn($x)=>$x!==null));
            }
            return $snapshot;
        });
    }

    public function saveAssignment(array $input,int $actor): array
    {
        self::actor($actor);
        self::fields($input,['connection_id','collector_id','expected_revision']);
        $connectionId=self::id($input['connection_id']??null);
        $collectorId=self::id($input['collector_id']??null);
        $expected=$input['expected_revision']??null;
        if (!is_int($expected) || $expected<0) throw new DomainException('revision_conflict');
        $this->context($connectionId);

        return $this->repo->transaction(function() use($connectionId,$collectorId,$expected,$actor) {
            $collector=$this->repo->collector($collectorId);
            if ($collector===null || ($collector['revoked']??true)!==false) throw new DomainException('collector_unavailable');
            $old=$this->repo->assignment($connectionId);
            if ($expected!==($old['revision']??0)) throw new DomainException('revision_conflict');
            $row=[
                'connection_id'=>$connectionId,
                'collector_id'=>$collectorId,
                'revision'=>self::next($old['revision']??0),
                'assigned_at'=>$this->now(),
                'assigned_by'=>$actor
            ];
            $this->repo->putAssignment($row);
            $this->event($old===null?'collector_assigned':'collector_reassigned',$connectionId,$actor);
            return self::publicAssignment($row);
        });
    }

    public function queueJob(string $connectionId,string $jobType,int $actor): array
    {
        self::actor($actor);
        $connectionId=self::id($connectionId);
        if (!in_array($jobType,['test_connection','list_zones'],true)) throw new DomainException('job_type_invalid');
        $context=$this->context($connectionId);

        return $this->repo->transaction(function() use($connectionId,$jobType,$actor,$context) {
            $assignment=$this->repo->assignment($connectionId);
            if ($assignment===null) throw new DomainException('assignment_unavailable');
            $collector=$this->repo->collector($assignment['collector_id']);
            if ($collector===null || ($collector['revoked']??true)!==false) throw new DomainException('collector_unavailable');

            $now=$this->now();
            $jobs=$this->repo->jobs();
            if (count($jobs)>=512) throw new DomainException('job_limit');
            foreach ($jobs as $job) {
                if (($job['connection_id']??null)===$connectionId && ($job['job_type']??null)===$jobType &&
                    in_array($job['state']??null,['pending','running'],true) && (int)($job['expires_at']??0)>=$now) {
                    throw new DomainException('job_already_pending');
                }
            }

            $credentialVersion=$context['credential_mode']==='profile' ? $context['credential_version'] : null;
            $id=bin2hex(random_bytes(16));
            $row=[
                'id'=>$id,
                'collector_id'=>$assignment['collector_id'],
                'connection_id'=>$connectionId,
                'assignment_revision'=>$assignment['revision'],
                'job_type'=>$jobType,
                'state'=>'pending',
                'configuration_revision'=>$context['revision'],
                'binding_epoch'=>$context['binding_epoch'],
                'credential_version'=>$credentialVersion,
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
                'updated_by'=>$actor
            ];
            $this->repo->putJob($row);
            $this->event('control_job_queued',$id,$actor);
            return self::publicJob($row);
        });
    }

    public function revokeCollector(string $id,int $expectedRevision,int $actor): void
    {
        self::actor($actor);
        $id=self::id($id);
        if ($expectedRevision<1) throw new DomainException('revision_conflict');

        $this->repo->transaction(function() use($id,$expectedRevision,$actor) {
            $row=$this->repo->collector($id);
            if ($row===null) throw new DomainException('not_found');
            if (($row['revision']??null)!==$expectedRevision) throw new DomainException('revision_conflict');
            if (($row['revoked']??false)===true) throw new DomainException('collector_unavailable');

            $now=$this->now();
            $row['revoked']=true;
            $row['revision']=self::next($row['revision']);
            $row['control_epoch']=self::next($row['control_epoch']);
            $row['updated_at']=$now;
            $row['updated_by']=$actor;
            $this->repo->putCollector($row);

            foreach ($this->repo->assignments() as $assignment) {
                if (($assignment['collector_id']??null)===$id) {
                    $this->repo->removeAssignment($assignment['connection_id']);
                }
            }
            foreach ($this->repo->jobs() as $job) {
                if (($job['collector_id']??null)===$id && in_array($job['state']??null,['pending','running'],true)) {
                    $job['state']='obsolete';
                    $job['updated_at']=$now;
                    $job['updated_by']=$actor;
                    $this->repo->putJob($job);
                }
            }
            $this->event('collector_revoked',$id,$actor);
        });
    }
}
