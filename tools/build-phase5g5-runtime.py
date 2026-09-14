#!/usr/bin/env python3
"""Versioned recovery payload. Old layers, original release and its receipt are immutable inputs."""
from pathlib import Path
import importlib.util
import hashlib

ROOT = Path(__file__).resolve().parents[1]
RELEASE = '/opt/phpipam-custom/inventory-recovery5g5'
CONFIG = '/etc/phpipam-custom/vmware-inventory-recovery5g5.json'
DATA = '/var/lib/phpipam-custom/vmware-inventory-recovery5g5'


def module(name, path):
    s = importlib.util.spec_from_file_location(name, path); m = importlib.util.module_from_spec(s); s.loader.exec_module(m); return m


def once(text, old, new):
    if text.count(old) != 1: raise RuntimeError('recovery_anchor_drift:' + old[:70])
    return text.replace(old, new, 1)


def generate(package):
    """Transform an already source-verified 5G4 package; no writes/network in this builder."""
    compat = module('recovery_collectors', ROOT / 'tools/build-phase5g5-provider-compat.py')
    app, release = dict(package['app']), dict(package['release'])
    for name, data in compat.generate().items(): release['collectors/' + name] = data
    for rel in ['tools/phase5g5-recover.py', 'layers/phase5g5/functions/classes/class.IntegrationVmwareInventoryRecovery.php']:
        release[rel] = (ROOT / rel).read_bytes()
    context = 'functions/classes/class.IntegrationVmwareInventoryContext.php'
    text = release['layers/phase5g/' + context].decode()
    text = text.replace('/etc/phpipam-custom/vmware-inventory.json', CONFIG).replace('/var/lib/phpipam-custom/vmware-inventory', DATA)
    app[context] = release['layers/phase5g/' + context] = text.encode()
    source = 'layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php'
    text = once(release[source].decode(), 'new IntegrationVmwareVcenterGuestNetwork($this->rest)', 'new IntegrationVmwareInventoryGuestNetwork($this->rest)')
    text = once(text, '    public function discover(): array', '''    public function repairGuest(string $id): array
    {
        if (!preg_match('/^vm-[0-9]{1,20}$/D', $id)) throw new InvalidArgumentException('guest_id');
        return $this->run(fn() => (new IntegrationVmwareInventoryGuestNetwork($this->rest))
            ->read_vm_interfaces(...[...$this->args(), [$id], true]));
    }
    public function discover(): array''')
    release[source] = text.encode()
    metric = 'layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryMetricSource.php'
    release[metric] = once(release[metric].decode(), 'new IntegrationVmwareVcenterPerformance(', 'new IntegrationVmwareInventoryPerformance(').encode()
    sampler = 'layers/phase5g3/functions/classes/class.IntegrationVmwareInventorySampler.php'
    text = release[sampler].decode()
    text = once(text, 'int $interval = 300): array', 'int $interval = 300, bool $initial = false): array')
    text = text.replace('use ($units, $seconds, $interval)', 'use ($units, $seconds, $interval, $initial)')
    text = text.replace('use ($units, $seconds, $interval, $current, $ids, $jobs, $epoch)', 'use ($units, $seconds, $interval, $initial, $current, $ids, $jobs, $epoch)')
    text = once(text, '                $deadline = hrtime(true)', '''                if ($initial) {
                    $jobs = IntegrationVmwareInventoryRecovery::order($jobs, fn($id) => $this->metrics->read($id, $epoch), $now);
                    $state['cursor'] = 0;
                }
                $deadline = hrtime(true)''')
    text = once(text, '                    // Each entity/section owns its due time', '''                    if ($initial && IntegrationVmwareInventoryRecovery::metricReady(
                        $this->metrics->read($id, $epoch)[$section] ?? null, $kind, $id, $now)) {
                        $state['cursor'] = ($cursor + 1) % count($jobs);
                        continue;
                    }
                    // Each entity/section owns its due time''')
    release[sampler] = text.encode()
    worker = 'layers/phase5g/functions/classes/class.IntegrationVmwareInventoryWorker.php'
    text = release[worker].decode()
    # Keep old pending bytes in the parent seed copy. A child generation must use only corrected collectors.
    text = once(text, '                if ($pending !== null && (!is_int(', '''                if ($pending !== null && ($pending['collector_revision'] ?? '') !== '5g5') {
                    $this->store->discardPending(); $pending = null;
                }
                if ($pending !== null && (!is_int(''')
    text = once(text, "$pending = ['generation' =>", "$pending = ['collector_revision' => '5g5', 'generation' =>")
    release[worker] = text.encode()
    cycle = 'tools/vmware-inventory-cycle.php'
    text = release[cycle].decode().replace("['--status', '--tick']", "['--status', '--tick', '--bootstrap']")
    text = once(text, '    $telemetry = $context->telemetry();', '''    require_once(dirname(__DIR__) . '/layers/phase5g5/functions/classes/class.IntegrationVmwareInventoryRecovery.php');
    $telemetry = $context->telemetry();''')
    text = once(text, '        $source = new IntegrationVmwareInventorySource($runtime);', '''        foreach (['GuestNetwork', 'Performance'] as $name) require_once(dirname(__DIR__) . '/collectors/class.IntegrationVmwareInventory' . $name . '.php');
        $source = new IntegrationVmwareInventorySource($runtime);''')
    text = once(text, "        $inventory = (new IntegrationVmwareInventoryWorker($store, $source, [$context, 'current']))->tick(30, 30);", '''        $initial = $mode === '--bootstrap';
        $current = $store->read('current');
        if ($initial && IntegrationVmwareInventoryRecovery::usable($current, time())) {
            $inventory = IntegrationVmwareInventoryRecovery::repairGuests($store, [$source, 'repairGuest'], [$context, 'current']);
        } else {
            $inventory = (new IntegrationVmwareInventoryWorker($store, $source, [$context, 'current']))->tick(30, 30);
        }''')
    text = once(text, '->tick(20, 25);', '->tick(20, 25, 300, $initial);')
    release[cycle] = text.encode()
    guard = 'tools/phase5g4-worker.py'
    release[guard] = once(release[guard].decode(), "[['--tick', '--connector=1'],", "[['--bootstrap', '--connector=1'], ['--tick', '--connector=1'],").encode()
    inspect = 'tools/phase5g4-inspect.php'
    text = release[inspect].decode()
    text = once(text, '    $ctx = new IntegrationVmwareInventoryContext', "    require_once($release . '/layers/phase5g5/functions/classes/class.IntegrationVmwareInventoryRecovery.php');\n    $ctx = new IntegrationVmwareInventoryContext")
    text = once(text, 'IntegrationVmwareInventoryReadiness::inspect(', 'IntegrationVmwareInventoryRecovery::inspect(')
    release[inspect] = text.encode()
    # Visible capability reason; renderers already preserve null values as missing, not zero.
    views = 'app/integrations/vmware/mirror/native-views.php'
    text = app[views].decode()
    text = text.replace("phase5e_vmcard_h($iface['nic_external_id'])", "phase5e_vmcard_h($iface['nic_external_id'] ?? 'Без аппаратной привязки')")
    anchor = 'function render_performance($performance): string\n{'
    text = once(text, anchor, anchor + "\n    $availability = recovery_performance_notice($performance);")
    # Prefix inside captured output, only for this renderer.
    pos = text.index(anchor); capture = text.index('    try {', pos)
    text = text[:capture] + text[capture:].replace('    try {', '    try {\n    print $availability;', 1)
    host = text.index('function render_host('); capture = text.index('    try {', host)
    text = text[:capture] + text[capture:].replace('    try {', '    try {\n    print recovery_performance_notice($performance);', 1)
    text += '''
function recovery_performance_notice(array $performance): string
{
    $labels = ['cpu.usage.average' => 'CPU', 'mem.usage.average' => 'RAM']; $missing = [];
    foreach ($labels as $key => $label) {
        $state = $performance['counter_status'][$key] ?? null;
        if (in_array($state, ['aggregate_unavailable', 'provider_not_realtime'], true)) $missing[] = $label;
    }
    return $missing === [] ? '' : '<div class="small text-muted phase5g5-capability">vCenter не предоставляет агрегированные показатели ' . implode(' / ', $missing) . '. Отсутствующие значения не равны нулю.</div>';
}
'''
    app[views] = release['staged-app/' + views] = text.encode()
    # New receipt does not overwrite any parent source or unit; new fixed names run copied encrypted data.
    units = {}
    for mode, template in [('normal', 'phpipam-vmware-recovery5g5@.'), ('initial', 'phpipam-vmware-recovery5g5-initial@.')]:
        for suffix in ['service', 'timer']:
            raw = package['units']['/etc/systemd/system/phpipam-vmware-inventory@.' + suffix]
            raw = raw.replace(b'phpipam-vmware-inventory@', template[:-1].encode()).replace(b'/opt/phpipam-custom/inventory', RELEASE.encode()).replace(b'/etc/phpipam-custom/vmware-inventory.json', CONFIG.encode())
            if mode == 'initial': raw = raw.replace(b'--tick --connector=', b'--bootstrap --connector=')
            if suffix == 'service': raw = raw.replace(b'/var/lib/phpipam-custom/vmware-inventory', DATA.encode())
            units['/etc/systemd/system/' + template + suffix] = raw
    return dict(package, app=app, release=release, units=units)
