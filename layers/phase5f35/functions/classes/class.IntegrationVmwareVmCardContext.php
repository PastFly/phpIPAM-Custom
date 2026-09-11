<?php
/** Reuse authorized shared infrastructure data; never cache per-user HTML or VM detail. */
final class IntegrationVmwareVmCardContext
{
    public static function read(IntegrationVmwareSnapshotService $service, string $vmId, bool $force = false): array
    {
        if (!preg_match('/^vm-[0-9]{1,20}$/D', $vmId)) throw new InvalidArgumentException('invalid_vm_id');
        $entry = $service->read('core', false, $force);
        $explorer = new IntegrationVmwareVcenterExplorer(100);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $data = $entry['payload'];
            if (($data['ok'] ?? false) !== true || !empty($data['partial']) ||
                !is_array($data['topology'] ?? null) || !is_array($data['datastores'] ?? null)) {
                throw new RuntimeException('snapshot_context_incomplete');
            }
            $projection = $explorer->build($data['topology'], $data['datastores']);
            if (isset($projection['vms'][$vmId])) {
                return ['context' => $explorer->vm_context($projection, $vmId),
                    'datastores' => $data['datastores'], 'cache' => $entry['cache']];
            }
            // A new VM may be absent from a still-valid snapshot. One bounded,
            // coalesced refresh only; denial/busy/backoff must not be bypassed.
            if ($attempt !== 0 || $force || !$service->enabled()) break;
            $entry = $service->read('core', false, true);
        }
        throw new RuntimeException('snapshot_vm_not_found');
    }
}
