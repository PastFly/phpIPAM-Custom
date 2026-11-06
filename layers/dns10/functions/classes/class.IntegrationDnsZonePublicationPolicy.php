<?php
declare(strict_types=1);

/** Pure ordering and capacity decision; caller supplies authenticated, serialized state. */
final class IntegrationDnsZonePublicationPolicy
{
    private const MAX_SEQUENCE=9007199254740991;
    private const MAX_BODY=4194304;
    private const MAX_ACCOUNTED=268435456;
    private const FREE_FLOOR=536870912;

    private static function invalid(): never {throw new DomainException('snapshot_policy_invalid');}

    private static function fields(array $value,array $keys): void
    {
        if (array_is_list($value) || count($value)!==count($keys) || array_diff(array_keys($value),$keys)) self::invalid();
    }

    private static function number(mixed $value,int $min,int $max): int
    {
        if (!is_int($value) || $value<$min || $value>$max) self::invalid();
        return $value;
    }

    private static function digest(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9a-f]{64}$/D',$value)) self::invalid();
        return $value;
    }

    public static function decide(array $state,array $incoming,mixed $accountedBytes,mixed $freeBytes): array
    {
        if (PHP_INT_SIZE<8) self::invalid();
        self::fields($state,['sequence','digest','current_bytes','previous_bytes']);
        self::fields($incoming,['sequence','digest','payload_bytes']);
        $sequence=self::number($state['sequence'],0,self::MAX_SEQUENCE);
        $current=self::number($state['current_bytes'],0,self::MAX_BODY);
        $previous=self::number($state['previous_bytes'],0,self::MAX_BODY);
        $used=self::number($accountedBytes,0,PHP_INT_MAX);
        $free=self::number($freeBytes,0,PHP_INT_MAX);
        if ($used<$current+$previous) self::invalid();
        if ($sequence===0) {
            if ($state['digest']!==null || $current!==0 || $previous!==0) self::invalid();
            $digest=null;
        } else {
            $digest=self::digest($state['digest']);
            if ($current===0 || ($sequence===1 && $previous!==0)) self::invalid();
        }
        $next=self::number($incoming['sequence'],1,self::MAX_SEQUENCE);
        $nextDigest=self::digest($incoming['digest']);
        $bytes=self::number($incoming['payload_bytes'],1,self::MAX_BODY);
        if ($next<$sequence) throw new DomainException('snapshot_out_of_order');
        if ($next===$sequence) {
            if ($digest===null || !hash_equals($digest,$nextDigest) || $bytes!==$current) {
                throw new DomainException('snapshot_sequence_conflict');
            }
            return ['decision'=>'replay','accounted_bytes_after'=>$used];
        }
        $retained=$used-$previous;
        if ($retained>self::MAX_ACCOUNTED-$bytes) throw new DomainException('snapshot_capacity_exceeded');
        if ($free<self::FREE_FLOOR || $free-self::FREE_FLOOR<$bytes) throw new DomainException('snapshot_disk_floor');
        return ['decision'=>'publish','accounted_bytes_after'=>$retained+$bytes];
    }
}
