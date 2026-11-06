<?php
declare(strict_types=1);

/** Source-only publication contract. Requires a shared-guard repository and trusted HTTP digest. */
final class IntegrationDnsZonePublicationService
{
    private Closure $clock;
    private Closure $freeBytes;

    public function __construct(private IntegrationDnsZonePublicationRepository $repo,callable $clock,callable $freeBytes)
    {
        $this->clock=Closure::fromCallable($clock);$this->freeBytes=Closure::fromCallable($freeBytes);
    }

    private static function id(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{32}$/D',$value)===1;
    }

    private static function version(mixed $value): bool
    {
        return is_int($value) && $value>=1 && $value<=4294967295;
    }

    /** Runs only while the repository owns the shared configuration guard. */
    private function authorize(#[SensitiveParameter] string $token): array
    {
        $id=IntegrationDnsZoneUploadToken::identify($token);
        $stored=$this->repo->uploadToken($id);
        if (!is_array($stored) || ($stored['id']??null)!==$id || ($stored['revoked']??null)!==false ||
            !self::id($stored['connection_id']??null) || !self::id($stored['collector_id']??null) ||
            !self::version($stored['assignment_revision']??null) ||
            !IntegrationDnsZoneUploadToken::verify($token,$id,$stored['token_digest']??null)) {
            throw new DomainException('upload_auth_failed');
        }
        $source=$this->repo->source($stored['connection_id']);
        $collector=$this->repo->collector($stored['collector_id']);
        $assignment=$this->repo->assignment($stored['connection_id']);
        if (!is_array($source) || ($source['id']??null)!==$stored['connection_id'] || ($source['enabled']??null)!==true ||
            !self::version($source['revision']??null) || !self::version($source['binding_epoch']??null) ||
            !array_key_exists('credential_version',$source) ||
            ($source['credential_version']!==null && !self::version($source['credential_version'])) ||
            !is_array($collector) || ($collector['id']??null)!==$stored['collector_id'] || ($collector['revoked']??null)!==false ||
            !is_array($assignment) || ($assignment['connection_id']??null)!==$stored['connection_id'] ||
            ($assignment['collector_id']??null)!==$stored['collector_id'] ||
            ($assignment['revision']??null)!==$stored['assignment_revision']) {
            throw new DomainException('upload_auth_failed');
        }
        return [$stored,$source,$assignment];
    }

    public function authenticateUpload(#[SensitiveParameter] string $token): void
    {
        $this->repo->transaction(function() use($token): void {$this->authorize($token);});
    }

    private static function generation(?array $row,string $datasetId): ?array
    {
        if ($row===null) return null;
        if (!self::id($row['id']??null) || ($row['dataset_id']??null)!==$datasetId ||
            !self::id($row['collector_id']??null) || !self::id($row['job_id']??null) ||
            !is_int($row['sequence_no']??null) || $row['sequence_no']<1 || $row['sequence_no']>9007199254740991 ||
            !is_string($row['payload_sha256']??null) || !preg_match('/^[0-9a-f]{64}$/D',$row['payload_sha256']) ||
            !is_int($row['payload_bytes']??null) || $row['payload_bytes']<1 || $row['payload_bytes']>4194304 ||
            !is_int($row['record_count']??null) || $row['record_count']<0 || $row['record_count']>10000 ||
            !self::version($row['dataset_revision']??null) || !self::version($row['configuration_revision']??null) ||
            !self::version($row['binding_epoch']??null) || !self::version($row['assignment_revision']??null) ||
            !array_key_exists('credential_version',$row) ||
            ($row['credential_version']!==null && !self::version($row['credential_version']))) {
            throw new DomainException('snapshot_unavailable');
        }
        return $row;
    }

    public function publishUpload(#[SensitiveParameter] string $token,#[SensitiveParameter] array $document,string $rawDigest,int $rawBytes): array
    {
        return $this->repo->transaction(function() use($token,$document,$rawDigest,$rawBytes): array {
            [$stored,$source,$assignment]=$this->authorize($token);
            $now=($this->clock)();
            if (!is_int($now) || $now<1 || $now>9007199254740691) throw new DomainException('snapshot_unavailable');
            $datasetId=$document['dataset_id']??null;
            if (!self::id($datasetId)) throw new DomainException('snapshot_invalid');
            $dataset=$this->repo->dataset($datasetId);
            if (!is_array($dataset) || ($dataset['id']??null)!==$datasetId || ($dataset['connection_id']??null)!==$source['id'] ||
                ($dataset['selected']??null)!==true || ($dataset['collection_enabled']??null)!==true ||
                !self::version($dataset['revision']??null) || !is_string($dataset['zone']??null)) {
                throw new DomainException('snapshot_unavailable');
            }
            $head=$this->repo->head($datasetId);
            if (count($head)!==2 || !array_key_exists('current',$head) || !array_key_exists('previous',$head) ||
                ($head['current']!==null && !is_array($head['current'])) || ($head['previous']!==null && !is_array($head['previous']))) {
                throw new DomainException('snapshot_unavailable');
            }
            $current=self::generation($head['current'],$datasetId);$previous=self::generation($head['previous'],$datasetId);
            if ($previous!==null && ($current===null || $current['id']===$previous['id'])) throw new DomainException('snapshot_unavailable');
            if ($this->repo->generationCount($datasetId)!==(int)($current!==null)+(int)($previous!==null) ||
                ($previous!==null && (!is_int($previous['sequence_no']??null) || !is_int($current['sequence_no']??null) ||
                    $previous['sequence_no']<1 || $previous['sequence_no']>=$current['sequence_no']))) {
                throw new DomainException('snapshot_unavailable');
            }
            $state=['sequence'=>$current['sequence_no']??0,'digest'=>$current['payload_sha256']??null,
                'current_bytes'=>$current['payload_bytes']??0,'previous_bytes'=>$previous['payload_bytes']??0];
            $incoming=['sequence'=>$document['sequence']??null,'digest'=>$rawDigest,'payload_bytes'=>$rawBytes];
            $used=$this->repo->accountedBytes();
            if ($current!==null && is_int($incoming['sequence']) && $incoming['sequence']<=$state['sequence']) {
                $decision=IntegrationDnsZonePublicationPolicy::decide($state,$incoming,$used,0);
                if ($decision['decision']!=='replay' || ($document['source_id']??null)!==$source['id'] ||
                    ($document['collector_id']??null)!==$stored['collector_id'] || ($document['job_id']??null)!==$current['job_id'] ||
                    ($current['collector_id']??null)!==$stored['collector_id'] || ($current['dataset_revision']??null)!==$dataset['revision'] ||
                    ($current['configuration_revision']??null)!==$source['revision'] || ($current['binding_epoch']??null)!==$source['binding_epoch'] ||
                    ($current['assignment_revision']??null)!==$assignment['revision'] ||
                    ($current['credential_version']??null)!==$source['credential_version']) throw new DomainException('snapshot_unavailable');
                return ['decision'=>'replay'];
            }
            $jobId=$document['job_id']??null;
            if (!self::id($jobId)) throw new DomainException('snapshot_invalid');
            $job=$this->repo->job($jobId);
            if (!is_array($job) || ($job['id']??null)!==$jobId || ($job['connection_id']??null)!==$source['id'] ||
                ($job['collector_id']??null)!==$stored['collector_id'] || ($job['dataset_id']??null)!==$datasetId ||
                ($job['job_type']??null)!=='collect_selected_zones' || ($job['state']??null)!=='running' ||
                ($job['attempt_count']??null)!==1 || !self::id($job['lease_id']??null) ||
                !is_int($job['lease_expires_at']??null) || $job['lease_expires_at']<$now ||
                !is_int($job['expires_at']??null) || $job['expires_at']<$now ||
                !is_int($job['credential_delivered_at']??null) || $job['credential_delivered_at']<1 ||
                ($job['finished_at']??null)!==null || ($job['result_code']??null)!==null ||
                ($job['dataset_revision']??null)!==$dataset['revision'] || ($job['configuration_revision']??null)!==$source['revision'] ||
                ($job['binding_epoch']??null)!==$source['binding_epoch'] || ($job['assignment_revision']??null)!==$assignment['revision'] ||
                !array_key_exists('credential_version',$job) || $job['credential_version']!==$source['credential_version']) {
                throw new DomainException('snapshot_job_invalid');
            }
            $context=['source_id'=>$source['id'],'dataset_id'=>$datasetId,'collector_id'=>$stored['collector_id'],
                'job_id'=>$jobId,'lease_id'=>$job['lease_id'],'zone'=>$dataset['zone'],
                'configuration_revision'=>$source['revision'],'binding_epoch'=>$source['binding_epoch'],
                'dataset_revision'=>$dataset['revision'],'assignment_revision'=>$assignment['revision'],
                'credential_version'=>$source['credential_version']];
            $normalized=IntegrationDnsZoneSnapshotValidator::validate($document,$context,$now);
            $decision=IntegrationDnsZonePublicationPolicy::decide($state,$incoming,$used,($this->freeBytes)());
            if ($decision['decision']!=='publish') throw new DomainException('snapshot_unavailable');
            $generation=['id'=>bin2hex(random_bytes(16)),'dataset_id'=>$datasetId,'collector_id'=>$stored['collector_id'],
                'job_id'=>$jobId,'run_id'=>$normalized['run_id'],'sequence_no'=>$normalized['sequence'],
                'dataset_revision'=>$dataset['revision'],'configuration_revision'=>$source['revision'],'binding_epoch'=>$source['binding_epoch'],
                'assignment_revision'=>$assignment['revision'],'credential_version'=>$source['credential_version'],
                'payload_sha256'=>$rawDigest,'payload_bytes'=>$rawBytes,'record_count'=>$normalized['record_count'],
                'started_at'=>$normalized['started_at'],'finished_at'=>$normalized['finished_at'],'received_at'=>$now];
            $this->repo->insertGeneration($generation,$normalized['records']);
            $this->repo->setHead($datasetId,$generation['id'],$current['id']??null);
            if ($previous!==null) $this->repo->removeGeneration($previous['id']);
            $commitNow=($this->clock)();
            if (!is_int($commitNow) || $commitNow<$now || $commitNow>9007199254740691 ||
                $job['lease_expires_at']<$commitNow || $job['expires_at']<$commitNow) {
                throw new DomainException('snapshot_job_invalid');
            }
            $remaining=($this->freeBytes)();
            if (!is_int($remaining) || $remaining<536870912) throw new DomainException('snapshot_disk_floor');
            $this->repo->completeJob($jobId,$job['lease_id'],$commitNow);
            return ['decision'=>'publish'];
        });
    }
}
