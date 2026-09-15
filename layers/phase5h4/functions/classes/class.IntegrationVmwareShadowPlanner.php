<?php
/** Pure planning/normalization for a non-serving Phase5H shadow cycle. */
final class IntegrationVmwareShadowPlanner
{
    private const METRIC_MAP = [
        'cpu.usage.average' => 'cpu_percent',
        'mem.usage.average' => 'memory_percent',
        'disk.usage.average' => 'disk_kib_per_second',
    ];

    public static function roster(array $current): array
    {
        if (!empty($current['blocked']) || !is_array($current['inventory']['objects'] ?? null)) {
            throw new RuntimeException('shadow_inventory_unavailable');
        }
        $entities = []; $membership = [];
        foreach (['vm','host','datastore'] as $kind) {
            $records = $current['inventory']['objects'][$kind] ?? null;
            if (!is_array($records) || count($records) > 2000) throw new RuntimeException('shadow_inventory_shape');
            $membership[$kind] = [];
            foreach ($records as $id => $record) {
                if (!is_string($id) || !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id) ||
                    !is_array($record) || ($record['data']['id'] ?? null) !== $id || ($record['data']['ok'] ?? null) !== true) {
                    throw new RuntimeException('shadow_inventory_shape');
                }
                $entities[] = ['kind'=>$kind,'id'=>$id]; $membership[$kind][] = $id;
            }
            sort($membership[$kind], SORT_STRING);
        }
        if ($entities === []) throw new RuntimeException('shadow_inventory_empty');
        return ['entities'=>$entities,'membership'=>$membership,'authoritative'=>false,
            'source_generation'=>$current['generation'] ?? null,'source_published_at'=>$current['published_at'] ?? null];
    }

    public static function metricPlans(array $entities, callable $telemetryRead, ?array $capabilities, int $now): array
    {
        if (!array_is_list($entities) || count($entities) > 2000) throw new InvalidArgumentException('shadow_entities_invalid');
        $plans=[]; $due=[]; $unsupported=[];
        foreach ($entities as $entity) {
            if (!is_array($entity) || !in_array($entity['kind'] ?? null,['vm','host'],true) ||
                !is_string($entity['id'] ?? null) || !preg_match('/^'.$entity['kind'].'-[0-9]{1,20}$/D',$entity['id'])) continue;
            $kind=$entity['kind']; $id=$entity['id'];
            $cached=IntegrationVmwareCapabilityCache::get($capabilities,$kind,$id,$now);
            if (is_array($cached)) {
                if (($cached['current_supported'] ?? null) === false) { $unsupported[]=['kind'=>$kind,'id'=>$id,'reason'=>'provider_not_realtime']; continue; }
                $counters=[];
                foreach (self::METRIC_MAP as $name=>$_) if (($cached['counters'][$name] ?? null)==='available') $counters[]=$name;
                if ($counters!==[]) { $plans[]=['kind'=>$kind,'id'=>$id,'interval_id'=>$cached['refresh_rate'],'counters'=>$counters]; continue; }
            }
            $parts=$telemetryRead($id);
            if (!is_array($parts)) { $due[]=['kind'=>$kind,'id'=>$id]; continue; }
            $performance=$parts['performance'] ?? null;
            $data=is_array($performance) ? ($performance['data'] ?? null) : null;
            $provider=is_array($data) ? ($data['provider'] ?? null) : null;
            $interval=is_array($provider) ? ($provider['refresh_rate'] ?? null) : null;
            $supported=is_array($provider) ? ($provider['current_supported'] ?? null) : null;
            if ($supported === false) { $unsupported[]=['kind'=>$kind,'id'=>$id,'reason'=>'provider_not_realtime']; continue; }
            if (!is_int($interval) || $interval<1 || $interval>300) { $due[]=['kind'=>$kind,'id'=>$id]; continue; }
            $counters=[];
            $status=is_array($data) ? ($data['counter_status'] ?? null) : null;
            if (is_array($status)) {
                foreach (['cpu.usage.average','mem.usage.average'] as $name) if (($status[$name] ?? null)==='available') $counters[]=$name;
            } else {
                $defined=is_array($data) ? ($data['counters'] ?? null) : null;
                foreach (['cpu.usage.average','mem.usage.average'] as $name) if (is_array($defined) && isset($defined[$name])) $counters[]=$name;
            }
            if ($counters===[]) { $due[]=['kind'=>$kind,'id'=>$id]; continue; }
            $plans[]=['kind'=>$kind,'id'=>$id,'interval_id'=>$interval,'counters'=>$counters];
        }
        return ['plans'=>$plans,'capability_due'=>$due,'unsupported'=>$unsupported];
    }

    public static function normalizeMetrics(array $batch, array $plans, int $now): array
    {
        if (($batch['schema'] ?? null)!==1 || !is_array($batch['objects'] ?? null) || !array_is_list($plans)) {
            throw new InvalidArgumentException('shadow_metric_batch_invalid');
        }
        $wanted=[];
        foreach ($plans as $plan) {
            if (!is_array($plan) || !is_string($plan['id'] ?? null) || !is_array($plan['counters'] ?? null)) throw new InvalidArgumentException('shadow_metric_plan_invalid');
            $wanted[$plan['id']]=$plan;
        }
        $out=[];
        foreach ($wanted as $id=>$plan) {
            $row=$batch['objects'][$id] ?? null;
            if (!is_array($row) || ($row['id'] ?? null)!==$id || ($row['kind'] ?? null)!==($plan['kind'] ?? null)) throw new RuntimeException('shadow_metric_result_missing');
            $state=$row['state'] ?? null;
            if (!in_array($state,['returned','empty','partial','not_returned'],true)) throw new RuntimeException('shadow_metric_state_invalid');
            $record=['kind'=>$plan['kind'],'id'=>$id,'state'=>$state==='not_returned'?'not_returned':($state==='partial'?'partial':'ok'),
                'attempted_at'=>is_int($row['collected_at'] ?? null)?$row['collected_at']:$now,'series'=>[]];
            if ($state!=='not_returned') {
                $missing=array_fill_keys($row['missing_counters'] ?? [],true);
                foreach ($plan['counters'] as $canonical) {
                    if (isset($missing[$canonical])) continue;
                    $name=self::METRIC_MAP[$canonical] ?? null;
                    if ($name===null) throw new RuntimeException('shadow_metric_counter_invalid');
                    $points=[];
                    foreach ($row['samples'] ?? [] as $sample) {
                        if (!is_array($sample) || !is_int($sample['observed_at'] ?? null) || !is_array($sample['values'] ?? null) ||
                            !array_key_exists($canonical,$sample['values'])) throw new RuntimeException('shadow_metric_sample_invalid');
                        $points[]=['observed_at'=>$sample['observed_at'],'value'=>$sample['values'][$canonical]];
                    }
                    $record['series'][$name]=$points;
                }
            }
            $out[]=$record;
        }
        return $out;
    }

    public static function unsupportedRecords(array $unsupported, int $now): array
    {
        $out=[];
        foreach ($unsupported as $row) {
            if (!is_array($row) || !in_array($row['kind'] ?? null,['vm','host'],true) || !is_string($row['id'] ?? null)) throw new RuntimeException('shadow_unsupported_invalid');
            $out[]=['kind'=>$row['kind'],'id'=>$row['id'],'state'=>'unsupported','attempted_at'=>$now,'series'=>[]];
        }
        return $out;
    }
}
