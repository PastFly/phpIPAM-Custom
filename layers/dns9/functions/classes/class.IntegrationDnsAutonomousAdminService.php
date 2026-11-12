<?php
declare(strict_types=1);

/**
 * DNS9 admin composition: DNS1 configuration + DNS3 collector control.
 * No provider calls, collector token disclosure or DNS writes.
 */
final class IntegrationDnsAutonomousAdminService
{
    public function __construct(
        private IntegrationDnsConfigService $config,
        private IntegrationDnsConfigRepository $configRepo,
        private IntegrationDnsCollectorControlService $control
    ) {}

    private static function actor(int $id): void
    {
        if($id<1) throw new DomainException('actor_invalid');
    }

    private static function id(mixed $id): string
    {
        if(!is_string($id)||!preg_match('/^[0-9a-f]{32}$/D',$id)) throw new DomainException('id_invalid');
        return $id;
    }

    private static function expected(mixed $value): int
    {
        if(!is_int($value)||$value<1) throw new DomainException('revision_conflict');
        return $value;
    }

    private static function nextVersion(int $value): int
    {
        if($value<1||$value>=2147483647) throw new DomainException('version_exhausted');
        return $value+1;
    }

    private function controlIndex(array $snapshot): array
    {
        $collectors=[];
        foreach($snapshot['collectors']??[] as $collector) {
            if(is_array($collector)&&isset($collector['id'])) $collectors[$collector['id']]=$collector;
        }

        $assignments=[];
        foreach($snapshot['assignments']??[] as $assignment) {
            if(is_array($assignment)&&isset($assignment['connection_id'])) {
                $assignments[$assignment['connection_id']]=$assignment;
            }
        }

        $latest=[];
        foreach($snapshot['jobs']??[] as $job) {
            if(!is_array($job)||!isset($job['connection_id'],$job['job_type'])) continue;
            $key=$job['connection_id'].'|'.$job['job_type'];
            $old=$latest[$key]??null;
            if($old===null||[(int)($job['created_at']??0),(string)($job['id']??'')] >
                [(int)($old['created_at']??0),(string)($old['id']??'')]) {
                $latest[$key]=$job;
            }
        }
        return [$collectors,$assignments,$latest];
    }

    private static function currentZoneResult(array $connection,?array $assignment,?array $collector,array $profiles,array $results): ?array
    {
        if ($assignment===null || $collector===null || ($collector['revoked']??true)!==false) return null;
        $credentialVersion=null;
        if (($connection['credential_mode']??null)==='profile') {
            $profile=$profiles[$connection['profile_id']??'']??null;
            if ($profile===null || ($profile['password_set']??false)!==true) return null;
            $credentialVersion=$profile['secret_version']??null;
            if (!is_int($credentialVersion) || $credentialVersion<1) return null;
        } elseif (($connection['credential_mode']??null)!=='collector_identity') return null;
        $latest=null;
        foreach ($results as $job) {
            if (!is_array($job) || ($job['connection_id']??null)!==$connection['id'] ||
                ($job['collector_id']??null)!==$collector['id'] ||
                ($job['assignment_revision']??null)!==$assignment['revision'] ||
                ($job['configuration_revision']??null)!==$connection['revision'] ||
                ($job['binding_epoch']??null)!==$connection['binding_epoch'] ||
                ($job['credential_version']??null)!==$credentialVersion) continue;
            if ($latest===null || [(int)($job['created_at']??0),(string)($job['id']??'')] >
                [(int)($latest['created_at']??0),(string)($latest['id']??'')]) $latest=$job;
        }
        return $latest;
    }

    public function snapshot(): array
    {
        $state=$this->config->snapshot();
        $control=$this->control->snapshot(true);
        [$collectors,$assignments,$latest]=$this->controlIndex($control);
        $profiles=[];
        foreach ($state['profiles'] as $profile) $profiles[$profile['id']]=$profile;

        $runtime=[];
        foreach($state['connections'] as $connection) {
            $id=$connection['id'];
            $assignment=$assignments[$id]??null;
            $collector=$assignment===null?null:($collectors[$assignment['collector_id']]??null);
            $runtime[$id]=[
                'assigned'=>$assignment!==null,
                'assignment_revision'=>$assignment['revision']??null,
                'collector_id'=>$collector['id']??null,
                'collector_name'=>$collector['name']??null,
                'collector_revoked'=>$collector['revoked']??null,
                'test_connection'=>$latest[$id.'|test_connection']??null,
                'list_zones'=>$latest[$id.'|list_zones']??null,
                'zone_result'=>self::currentZoneResult($connection,$assignment,$collector,$profiles,$control['zone_results']??[]),
            ];
        }

        $state['collector_control_available']=true;
        $state['collector_control']=[
            'collectors'=>$control['collectors'],
            'assignments'=>$control['assignments']
        ];
        $state['connection_runtime']=$runtime;
        return $state;
    }

    public function saveProfile(#[SensitiveParameter] array $in,int $actor): array
    {
        return $this->config->saveProfile($in,$actor);
    }

    public function revokeProfile(string $id,int $expected,array $ack,int $actor): void
    {
        $this->config->revokeProfile($id,$expected,$ack,$actor);
    }

    public function deleteProfile(string $id,int $expected,int $actor): void
    {
        $this->config->deleteProfile($id,$expected,$actor);
    }

    public function saveConnection(array $in,int $actor): array
    {
        return $this->config->saveConnection($in,$actor);
    }

    public function deleteConnection(string $id,int $expected,int $actor): void
    {
        $this->config->deleteConnection($id,$expected,$actor);
    }

    public function registerCollector(string $name,int $actor): array
    {
        self::actor($actor);
        return $this->control->registerCollector(['name'=>$name],$actor);
    }

    public function saveCollectorAssignment(string $connectionId,string $collectorId,int $expectedRevision,int $actor): array
    {
        self::actor($actor);
        self::id($connectionId);
        self::id($collectorId);
        if($expectedRevision<0) throw new DomainException('revision_conflict');
        $connection=$this->configRepo->transaction(function() use($connectionId): ?array {
            return $this->configRepo->connection($connectionId);
        });
        if($connection===null) throw new DomainException('not_found');
        if(($connection['enabled']??false)===true) throw new DomainException('connection_must_be_disabled');
        return $this->control->saveAssignment([
            'connection_id'=>$connectionId,
            'collector_id'=>$collectorId,
            'expected_revision'=>$expectedRevision
        ],$actor);
    }

    public function revokeCollector(string $id,int $expectedRevision,int $actor): void
    {
        self::actor($actor);
        self::id($id);

        $this->control->revokeCollector($id,$expectedRevision,$actor,function($controlRepo,$revoke) use($id,$actor): void {
            if(!is_callable([$this->configRepo,'joinControlTransaction'])) throw new DomainException('configuration_unavailable');
            $this->configRepo->joinControlTransaction($controlRepo,function() use($controlRepo,$revoke,$id,$actor): void {
                foreach($controlRepo->assignments() as $assignment) {
                    if(($assignment['collector_id']??null)!==$id || !is_string($assignment['connection_id']??null)) continue;
                    $connectionId=$assignment['connection_id'];
                    $row=$this->configRepo->connection($connectionId);
                    if($row===null) continue;
                    if(($row['enabled']??false)===true) {
                        $row['revision']=self::nextVersion((int)$row['revision']);
                        $row['binding_epoch']=self::nextVersion((int)$row['binding_epoch']);
                    }
                    $row['enabled']=false;
                    $row['status']='collector_revoked';
                    $row['updated_at']=time();
                    $row['updated_by']=$actor;
                    $this->configRepo->putConnection($row);
                    $this->configRepo->audit([
                        'action'=>'collection_disabled',
                        'object_id'=>$connectionId,
                        'actor_id'=>$actor,
                        'created_at'=>time()
                    ]);
                }
                $revoke();
            });
        });
    }

    public function queueControlJob(string $id,string $jobType,int $actor): array
    {
        self::actor($actor);
        self::id($id);
        if(!in_array($jobType,['test_connection','list_zones'],true)) {
            throw new DomainException('job_type_invalid');
        }
        return $this->control->queueJob($id,$jobType,$actor);
    }

    public function setCollectionEnabled(string $id,int $expected,bool $enabled,int $actor): array
    {
        self::actor($actor);
        self::id($id);
        self::expected($expected);

        $update=function() use($id,$expected,$enabled,$actor) {
            $row=$this->configRepo->connection($id);
            if($row===null) throw new DomainException('not_found');
            if(($row['revision']??null)!==$expected) throw new DomainException('revision_conflict');

            if($enabled && $row['credential_mode']==='profile') {
                $profileId=$row['profile_id']??null;
                if(!is_string($profileId)) throw new DomainException('profile_unavailable');
                $profile=$this->configRepo->profile($profileId);
                if($profile===null||($profile['secret_envelope']??null)===null) {
                    throw new DomainException('profile_unavailable');
                }
            }

            if(($row['enabled']??false)!==$enabled) {
                $row['revision']=self::nextVersion((int)$row['revision']);
                $row['binding_epoch']=self::nextVersion((int)$row['binding_epoch']);
            }
            $row['enabled']=$enabled;
            $row['status']=$enabled?'active':'disabled';
            $row['updated_at']=time();
            $row['updated_by']=$actor;
            $this->configRepo->putConnection($row);
            $this->configRepo->audit([
                'action'=>$enabled?'collection_enabled':'collection_disabled',
                'object_id'=>$id,
                'actor_id'=>$actor,
                'created_at'=>time()
            ]);
            return $row;
        };
        if(!$enabled) return $this->configRepo->transaction($update);
        return $this->control->withActiveAssignment($id,function($controlRepo) use($update): array {
            if(!is_callable([$this->configRepo,'joinControlTransaction'])) throw new DomainException('configuration_unavailable');
            return $this->configRepo->joinControlTransaction($controlRepo,$update);
        });
    }
}
