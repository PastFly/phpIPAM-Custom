<?php
declare(strict_types=1);

/** Pure validation only; authentication, strict JSON and publication belong to the caller. */
final class IntegrationDnsZoneSnapshotValidator
{
    private const MAX_SEQUENCE=9007199254740991;
    private const MAX_RECORDS=10000;
    private const TYPES=['A','AAAA','CNAME','PTR'];
    private const ENVELOPE=['schema_version','source_id','dataset_id','zone','virtualization_instance','zone_scope',
        'configuration_revision','binding_epoch','run_id','sequence','started_at','finished_at',
        'supported_types','complete','record_count','records'];
    private const CONTEXT=['source_id','dataset_id','zone','configuration_revision','binding_epoch'];
    private const RECORD=['owner','display_owner','type','record_class','rdata','display_rdata','ttl_seconds','source_aging_timestamp'];

    private static function invalid(): never {throw new DomainException('snapshot_invalid');}

    private static function fields(array $input,array $expected): void
    {
        if (array_is_list($input) || count($input)!==count($expected) || array_diff(array_keys($input),$expected)) self::invalid();
    }

    private static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9a-f]{32}$/D',$value)) self::invalid();
        return $value;
    }

    private static function integer(mixed $value,int $min,int $max): int
    {
        if (!is_int($value) || $value<$min || $value>$max) self::invalid();
        return $value;
    }

    private static function name(mixed $value,bool $wildcard): string
    {
        if (!is_string($value) || $value==='' || $value!==trim($value) || strlen($value)>254) self::invalid();
        if ($value==='.') return '.';
        $name=strtolower($value);
        if (str_ends_with($name,'.')) $name=substr($name,0,-1);
        if ($name==='' || strlen($name)>253) self::invalid();
        foreach (explode('.',$name) as $index=>$label) {
            if ($label==='*') {
                if (!$wildcard || $index!==0) self::invalid();
            } elseif ($label==='' || strlen($label)>63 || !preg_match('/^[a-z0-9_-]+$/D',$label)) self::invalid();
        }
        return $name.'.';
    }

    private static function display(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>1024 || preg_match('/[\x00-\x1f\x7f]/',$value) || preg_match('//u',$value)!==1) self::invalid();
        return $value;
    }

    private static function address(mixed $value,int $flag): string
    {
        if (!is_string($value) || filter_var($value,FILTER_VALIDATE_IP,$flag)===false) self::invalid();
        $binary=@inet_pton($value);
        if (!is_string($binary)) self::invalid();
        $address=@inet_ntop($binary);
        if (!is_string($address)) self::invalid();
        return strtolower($address);
    }

    private static function record(array $row,string $zone): array
    {
        self::fields($row,self::RECORD);
        $owner=self::name($row['owner'],true);
        if ($zone!=='.' && $owner!==$zone && !str_ends_with($owner,'.'.$zone)) self::invalid();
        $type=$row['type'];
        if (!is_string($type) || !in_array($type,self::TYPES,true) || $row['record_class']!=='IN') self::invalid();
        $rdata=match($type) {
            'A'=>self::address($row['rdata'],FILTER_FLAG_IPV4),
            'AAAA'=>self::address($row['rdata'],FILTER_FLAG_IPV6),
            'CNAME','PTR'=>self::name($row['rdata'],true),
        };
        $aging=$row['source_aging_timestamp'];
        if ($aging!==null) self::integer($aging,0,self::MAX_SEQUENCE);
        return ['owner'=>$owner,'display_owner'=>self::display($row['display_owner']),'type'=>$type,'record_class'=>'IN',
            'rdata'=>$rdata,'display_rdata'=>self::display($row['display_rdata']),
            'ttl_seconds'=>self::integer($row['ttl_seconds'],0,4294967295),'source_aging_timestamp'=>$aging];
    }

    public static function validate(array $snapshot,array $context,int $now): array
    {
        if (PHP_INT_SIZE<8) self::invalid();
        self::integer($now,1,self::MAX_SEQUENCE-300);
        self::fields($context,self::CONTEXT);
        self::fields($snapshot,self::ENVELOPE);
        $source=self::id($context['source_id']);
        $dataset=self::id($context['dataset_id']);
        $zone=self::name($context['zone'],false);
        $revision=self::integer($context['configuration_revision'],1,4294967295);
        $epoch=self::integer($context['binding_epoch'],1,4294967295);
        if ($snapshot['schema_version']!==1 || self::id($snapshot['source_id'])!==$source ||
            self::id($snapshot['dataset_id'])!==$dataset || self::name($snapshot['zone'],false)!==$zone ||
            $snapshot['virtualization_instance']!=='default' || $snapshot['zone_scope']!=='default' ||
            $snapshot['configuration_revision']!==$revision || $snapshot['binding_epoch']!==$epoch ||
            !is_string($snapshot['run_id']) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$snapshot['run_id']) ||
            $snapshot['supported_types']!==self::TYPES || $snapshot['complete']!==true) self::invalid();
        self::integer($snapshot['sequence'],1,self::MAX_SEQUENCE);
        $started=self::integer($snapshot['started_at'],1,$now+300);
        $finished=self::integer($snapshot['finished_at'],1,$now+300);
        if ($finished<$started) self::invalid();
        $count=self::integer($snapshot['record_count'],0,self::MAX_RECORDS);
        if (!is_array($snapshot['records']) || !array_is_list($snapshot['records']) || count($snapshot['records'])!==$count) self::invalid();
        $records=[]; $identities=[];
        foreach ($snapshot['records'] as $row) {
            if (!is_array($row)) self::invalid();
            $record=self::record($row,$zone);
            $key=$record['owner']."\0".$record['type']."\0".$record['rdata'];
            if (isset($identities[$key])) self::invalid();
            $identities[$key]=true;
            $records[]=$record;
        }
        $snapshot['zone']=$zone;
        $snapshot['records']=$records;
        return $snapshot;
    }
}
