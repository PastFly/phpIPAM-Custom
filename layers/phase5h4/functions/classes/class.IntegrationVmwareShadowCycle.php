<?php
/**
 * One bounded non-serving shadow pass. Upstream reads finish and authorization is rechecked
 * before any fast-domain publication occurs.
 */
final class IntegrationVmwareShadowCycle
{
    public static function run(#[SensitiveParameter] array $runtime, array $current, callable $authorized,
        callable $telemetryRead, IntegrationVmwareFastStore $fastStore, ?callable $transport = null,
        int $capabilityBudget = 12, ?callable $clock = null): array
    {
        if ($capabilityBudget < 0 || $capabilityBudget > 24) throw new InvalidArgumentException('shadow_capability_budget_invalid');
        $clock ??= static fn(): int => time();
        $startedWall = microtime(true); $started = $clock();
        $roster = IntegrationVmwareShadowPlanner::roster($current);
        $metricEntities = array_values(array_filter($roster['entities'], static fn(array $e): bool => in_array($e['kind'], ['vm','host'], true)));
        $capabilities = $fastStore->read('capabilities');
        $planning = IntegrationVmwareShadowPlanner::metricPlans($metricEntities, $telemetryRead, $capabilities, $started);
        $session = new IntegrationVmwareBatchSession($runtime, $authorized, $transport, 128, 120);
        $wireResult = $session->run(function (IntegrationVmwareBatchSession $session) use (
            $roster, $planning, $capabilities, $capabilityBudget, $started
        ): array {
            $inventory = (new IntegrationVmwareBatchInventory($session, 50))->read($roster['entities']);
            $performance = new IntegrationVmwareBatchPerformance($session, 25);
            $cache = $capabilities; $plans = $planning['plans']; $unsupported = $planning['unsupported'];
            $revalidated = 0; $stillDue = [];
            foreach ($planning['capability_due'] as $entity) {
                if ($revalidated >= $capabilityBudget) { $stillDue[] = $entity; continue; }
                ++$revalidated;
                $provider = $performance->provider($entity['kind'], $entity['id']);
                if (($provider['current_supported'] ?? null) !== true) {
                    $cache = IntegrationVmwareCapabilityCache::put($cache, $entity['kind'], $entity['id'],
                        ['current_supported'=>false,'refresh_rate'=>null,'counters'=>[]], $started, 900);
                    $unsupported[] = ['kind'=>$entity['kind'],'id'=>$entity['id'],'reason'=>'provider_not_realtime'];
                    continue;
                }
                $interval = $provider['interval_id'] ?? null;
                if (!is_int($interval)) throw new RuntimeException('shadow_provider_interval_invalid');
                $availability = $performance->availability($entity['kind'], $entity['id'], $interval);
                $statuses = [];
                foreach (['cpu.usage.average','mem.usage.average','disk.usage.average'] as $counter) {
                    $statuses[$counter] = !empty($availability['aggregate_available'][$counter]) ? 'available' : 'aggregate_unavailable';
                }
                $cache = IntegrationVmwareCapabilityCache::put($cache, $entity['kind'], $entity['id'],
                    ['current_supported'=>true,'refresh_rate'=>$interval,'counters'=>$statuses], $started, 900);
                $selected = [];
                foreach ($statuses as $counter=>$status) if ($status === 'available') $selected[] = $counter;
                if ($selected !== []) $plans[] = ['kind'=>$entity['kind'],'id'=>$entity['id'],'interval_id'=>$interval,'counters'=>$selected];
                else $unsupported[] = ['kind'=>$entity['kind'],'id'=>$entity['id'],'reason'=>'aggregate_metrics_unavailable'];
            }
            $metricBatch = $plans === [] ? ['schema'=>1,'objects'=>[]] : $performance->read($plans, 6);
            return ['inventory'=>$inventory,'metric_batch'=>$metricBatch,'plans'=>$plans,'unsupported'=>$unsupported,
                'capabilities'=>$cache,'capability_revalidated'=>$revalidated,'capability_still_due'=>$stillDue];
        });
        // BatchSession::run has completed Logout and final binding guard before this point.
        if ($authorized() !== true) throw new RuntimeException('shadow_binding_changed_before_publish');
        $publishNow = $clock();
        $propertyResult = $fastStore->update('properties', static fn(?array $old): array =>
            IntegrationVmwareFastDomain::mergeProperties($old, $wireResult['inventory'], $publishNow));
        $metricRecords = IntegrationVmwareShadowPlanner::normalizeMetrics($wireResult['metric_batch'], $wireResult['plans'], $publishNow);
        $metricRecords = array_merge($metricRecords, IntegrationVmwareShadowPlanner::unsupportedRecords($wireResult['unsupported'], $publishNow));
        $metricResult = $fastStore->update('metrics', static fn(?array $old): array =>
            IntegrationVmwareFastDomain::mergeMetrics($old, $metricRecords, $publishNow));
        if (is_array($wireResult['capabilities'])) $fastStore->publish('capabilities', $wireResult['capabilities']);
        $stats = $session->statistics();
        return ['state'=>'shadow_complete','started_at'=>$started,'published_at'=>$publishNow,
            'wall_ms'=>(int)round((microtime(true)-$startedWall)*1000),
            'roster_counts'=>[
                'vm'=>count($roster['membership']['vm']), 'host'=>count($roster['membership']['host']),
                'datastore'=>count($roster['membership']['datastore'])],
            'property_objects'=>count($wireResult['inventory']['objects']),
            'metric_plans'=>count($wireResult['plans']),
            'capability_revalidated'=>$wireResult['capability_revalidated'],
            'capability_still_due'=>count($wireResult['capability_still_due']),
            'unsupported'=>count($wireResult['unsupported']),
            'requests'=>$stats['requests'],'response_bytes'=>$stats['response_bytes'],
            'peak_bytes'=>memory_get_peak_usage(true),
            'property_publish_state'=>$propertyResult['state'],'metric_publish_state'=>$metricResult['state'],
            'website_served_from_shadow'=>false];
    }
}
