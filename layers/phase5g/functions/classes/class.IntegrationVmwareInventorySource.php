<?php
/** Worker-only adapter over reviewed read-only collectors. Never load from web endpoints. */
final class IntegrationVmwareInventorySource implements IntegrationVmwareInventoryProvider
{
    private array $runtime;
    private $rest;
    private $soap;
    private bool $denial = false;
    public function __construct(array $runtime)
    {
        if (PHP_SAPI !== 'cli' || ($runtime['verify_tls'] ?? null) !== true || empty($runtime['username']) || empty($runtime['password'])) {
            throw new RuntimeException('mirror_strict_tls_worker_required');
        }
        $runtime['endpoint'] = (new IntegrationVmwareVcenter())->normalize_endpoint($runtime['endpoint']);
        $this->runtime = $runtime;
        $rest = new IntegrationVmwareVcenterDirectRestTransport(8388608);
        $direct = new IntegrationVmwareVcenterDirectTransport(8388608);
        $this->rest = function ($method, $url, $headers, $username, $password, $tls, $connect, $timeout) use ($rest): array {
            if ($tls !== true || !str_starts_with($url, $this->runtime['endpoint'] . '/')) throw new RuntimeException('mirror_transport_scope');
            $path = parse_url($url, PHP_URL_PATH);
            if ($method !== 'GET' && !(in_array($method, ['POST', 'DELETE'], true) && in_array($path, ['/api/session', '/rest/com/vmware/cis/session'], true))) {
                throw new RuntimeException('mirror_transport_method');
            }
            $out = $rest($method, $url, $headers, $username, $password, true, min(8, $connect), min(20, $timeout));
            $this->observeDenial($out); return $out;
        };
        $checked = function ($method, $url, $headers, $body, $tls, $connect, $timeout) use ($direct): array {
            if ($tls !== true || $url !== $this->runtime['endpoint'] . '/sdk' || $method !== 'POST') throw new RuntimeException('mirror_transport_scope');
            if (!preg_match('/<(RetrieveServiceContent|Login|Logout|RetrieveProperties|QueryNetworkHint)\s+xmlns="urn:vim25"[>\s]/', $body)) {
                throw new RuntimeException('mirror_soap_operation');
            }
            $out = $direct($method, $url, $headers, $body, true, min(8, $connect), min(20, $timeout));
            $this->observeDenial($out); return $out;
        };
        $this->soap = new IntegrationVmwareVcenterFaultAwareTransport($checked);
    }
    private function observeDenial(array $response): void
    {
        if (in_array((int)($response['status'] ?? 0), [401, 403], true) ||
            preg_match('/<(?:[A-Za-z0-9_]+:)?(?:NotAuthenticated|NoPermission)(?:Fault)?\b|xsi:type=["\x27](?:[A-Za-z0-9_]+:)?(?:NotAuthenticated|NoPermission)(?:Fault)?["\x27]/i', (string)($response['body'] ?? ''))) {
            $this->denial = true;
            throw new RuntimeException('mirror_access_denied');
        }
    }
    private function run(callable $callback): array
    {
        $this->denial = false;
        try { return $callback(); }
        finally { if ($this->denial) throw new RuntimeException('mirror_access_denied'); }
    }
    private function args(): array
    {
        return [$this->runtime['endpoint'], $this->runtime['username'], $this->runtime['password']];
    }
    private function optional(callable $callback): array
    {
        try { return ['state' => 'ok', 'observed_at' => time(), 'data' => $callback()]; }
        catch (Throwable $e) {
            if ($e->getMessage() === 'mirror_process_deadline') throw $e;
            if ($this->denial || IntegrationVmwareInventoryWorker::denied($e)) throw new RuntimeException('mirror_access_denied');
            // An unavailable optional section is explicit, not an empty collection proving absence.
            return ['state' => 'unavailable', 'observed_at' => time(), 'data' => null, 'error' => 'optional_read_failed'];
        }
    }
    public function discover(): array
    {
        return $this->run(function (): array {
            $a = $this->args();
            $topology = (new IntegrationVmwareVcenterTopology($this->rest, 8, 20, 8388608, 10000, 800))->read_topology(...[...$a, true]);
            $datastores = (new IntegrationVmwareVcenterDatastore($this->rest))->read_inventory(...[...$a, array_column($topology['datacenters'], 'external_id'), true]);
            (new IntegrationVmwareVcenterExplorer(100))->build($topology, $datastores);
            return ['ok' => true, 'ids' => ['vm' => array_column($topology['vms'], 'external_id'),
                'host' => array_column($topology['hosts'], 'external_id'), 'datastore' => array_column($datastores['datastores'], 'external_id')],
                'core' => ['ok' => true, 'topology' => $topology, 'datastores' => $datastores]];
        });
    }
    public function collect(string $kind, string $id, array $seed): array
    {
        return $this->run(function () use ($kind, $id, $seed): array {
            $a = $this->args();
            if ($kind === 'domain') {
                if ($id === 'host_folders') {
                    $result = (new IntegrationVmwareVcenterFolderInventory(8, 20))->read_inventory(...[...$a, true]);
                    $result['partial'] = !empty($result['host_multi_folder_matches']) || !empty($result['cluster_multi_folder_matches']);
                    return $result;
                }
                if ($id === 'object_folders') return (new IntegrationVmwareVcenterObjectFolders($this->soap))->read_inventory(...[...$a, true]);
                if ($id === 'search') {
                    $result = phase5f34_build_search($this->runtime, (int)$this->runtime['id']);
                    $actual = array_column($result['rows'] ?? [], 'vm_external_id'); sort($actual, SORT_STRING);
                    if ($actual !== $seed['ids']['vm']) throw new RuntimeException('mirror_membership_changed');
                    return $result;
                }
                throw new InvalidArgumentException('mirror_unknown_domain');
            }
            if ($kind === 'datastore') {
                $detail = (new IntegrationVmwareVcenterDatastore($this->rest))->read_detail(...[...$a, $id, true]);
                return ['ok' => true, 'id' => $id, 'name' => $detail['datastore']['name'] ?? $id, 'detail' => $detail];
            }
            if ($kind === 'host') {
                $summary = (new IntegrationVmwareVcenterSoapReadOnly($this->soap))->read_host_summary(...[...$a, $id, true]);
                return ['ok' => true, 'id' => $id, 'name' => $summary['product']['name'] ?? $id,
                    'summary' => $summary, 'sections' => ['network_hints' => $this->optional(fn() =>
                        (new IntegrationVmwareVcenterHostNetworkHints($this->soap))->read_hints(...[...$a, $id, true]))]];
            }
            if ($kind !== 'vm') throw new InvalidArgumentException('mirror_unknown_kind');
            $core = $seed['core'];
            $explorer = new IntegrationVmwareVcenterExplorer(100);
            $context = $explorer->vm_context($explorer->build($core['topology'], $core['datastores']), $id);
            $detail = (new IntegrationVmwareVcenterVmDetail($this->rest))->read_detail(...[...$a, $id, true]);
            $detail = (new IntegrationVmwareVcenterDatastoreResolver())->resolve_vm_detail($detail,
                $core['datastores']['datastores'], $context['datacenter_external_id']);
            return ['ok' => true, 'id' => $id, 'name' => $detail['vm']['name'], 'context' => $context, 'detail' => $detail,
                'sections' => [
                    'guest_network' => $this->optional(fn() => (new IntegrationVmwareVcenterGuestNetwork($this->rest))->read_vm_interfaces(...[...$a, [$id], true])),
                    'guest_facts' => $this->optional(fn() => (new IntegrationVmwareVcenterVmGuestFacts($this->soap))->read_facts(...[...$a, $id, true])),
                    'annotation' => $this->optional(fn() => (new IntegrationVmwareVcenterVmAnnotation($this->soap))->read_annotation(...[...$a, $id, true])),
                    'network_path' => $this->optional(fn() => (new IntegrationVmwareVcenterVmNetworkPath($this->soap))->read_path(...[...$a, $id, true]))
                ]];
        });
    }
    public function __destruct() { if (isset($this->runtime['password'])) sodium_memzero($this->runtime['password']); }
}
