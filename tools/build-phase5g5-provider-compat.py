#!/usr/bin/env python3
"""Build reviewed collector variants, without changing pinned legacy/runtime files.

The output is for a future receipt-aware worker upgrade. Running this module does
not deploy, change the current release or invoke a collector.
"""
from pathlib import Path
import argparse
import hashlib

ROOT = Path(__file__).resolve().parents[1]
PINS = {
    'layers/phase5f/functions/classes/class.IntegrationVmwareVcenterPerformance.php': '426c8c32632037d2b2ceaac23ef11eb79a06edd2',
    'layers/phase5c/functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php': 'f4db8a61a937424ce93ec9e5cd00a58ca77bdddd',
}


def replace_once(text, old, new):
    if text.count(old) != 1:
        raise RuntimeError('provider_compat_anchor_drift')
    return text.replace(old, new, 1)


def source(root, path):
    raw = (root / path).read_bytes()
    blob = hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()
    if blob != PINS[path]:
        raise RuntimeError('provider_compat_source_drift:' + path)
    return raw.decode('utf-8')


def performance(text):
    text = replace_once(text, 'class IntegrationVmwareVcenterPerformance\n', 'class IntegrationVmwareInventoryPerformance\n')
    text = replace_once(text, '        $verifyTls = (bool)$verifyTls;', "        if ($verifyTls !== true) throw new RuntimeException('mirror_strict_tls_required');")
    text = replace_once(text, "            if (!$provider['current_supported'] || $provider['refresh_rate'] === null || $provider['refresh_rate'] < 1 || $provider['refresh_rate'] > 300) {\n                throw new RuntimeException('Selected vCenter entity does not expose bounded real-time performance statistics');\n            }", """            if (!$provider['current_supported']) {
                return $this->availability_result($entityType, $entityId, $provider, array(), array(),
                    array('cpu.usage.average' => 'provider_not_realtime', 'mem.usage.average' => 'provider_not_realtime'), $verifyTls, $started);
            }
            if ($provider['refresh_rate'] === null || $provider['refresh_rate'] < 1 || $provider['refresh_rate'] > 300) {
                throw new RuntimeException('Invalid real-time performance refresh rate');
            }""")
    text = replace_once(text, """            $selected = array();
            foreach (array('cpu.usage.average', 'mem.usage.average') as $name) {
                $id = (int)$counterMap[$name]['id'];
                if (!isset($available[$id]) || !$available[$id]) throw new RuntimeException('Aggregate performance metric is unavailable for selected entity: ' . $name);
                $selected[$name] = $counterMap[$name];
            }
""", """            $selected = array(); $unavailable = array();
            foreach (array('cpu.usage.average', 'mem.usage.average') as $name) {
                $id = (int)$counterMap[$name]['id'];
                if (!isset($available[$id]) || !$available[$id]) {
                    // A successful capability response is not a transport error.
                    // Never sum device/core instances or substitute zero for the aggregate.
                    $unavailable[$name] = 'aggregate_metric_unavailable';
                    continue;
                }
                $selected[$name] = $counterMap[$name];
            }
            if ($selected === array()) {
                return $this->availability_result($entityType, $entityId, $provider, $selected,
                    array(), $unavailable, $verifyTls, $started);
            }
""")
    begin = "            return array(\n                'entity_type' => $entityType,"
    end = "                'duration_ms' => (int)round((microtime(true) - $started) * 1000)\n            );"
    start = text.index(begin); stop = text.index(end, start) + len(end)
    text = text[:start] + """            return $this->availability_result($entityType, $entityId, $provider, $selected,
                $series, $unavailable, $verifyTls, $started);""" + text[stop:]
    helper = """    private function availability_result($entityType, $entityId, $provider, $selected, $series, $unavailable, $verifyTls, $started)
    {
        $reason = null;
        if (!$provider['current_supported']) $reason = 'provider_not_realtime';
        elseif ($selected === array()) $reason = 'aggregate_metrics_unavailable';
        elseif ($unavailable !== array()) $reason = 'some_aggregate_counters_unavailable';
        elseif (count($series) < 2) $reason = 'insufficient_samples';
        $counterStatus = array();
        foreach (array('cpu.usage.average', 'mem.usage.average') as $name) {
            $counterStatus[$name] = isset($selected[$name]) ? 'available'
                : (($unavailable[$name] ?? '') === 'provider_not_realtime' ? 'provider_not_realtime' : 'aggregate_unavailable');
        }
        return array(
            'counter_status' => $counterStatus,
            'supported' => count($series) >= 2, 'reason' => $reason,
            'entity_type' => $entityType, 'entity_external_id' => $entityId,
            'provider' => $provider, 'counters' => $selected, 'unavailable_counters' => $unavailable,
            'samples' => $series, 'sample_count' => count($series),
            'property_method' => 'RetrieveProperties', 'verify_tls' => (bool)$verifyTls,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000)
        );
    }

"""
    text = replace_once(text, '    private function parse_provider_summary(DOMDocument $doc)', helper + '    private function parse_provider_summary(DOMDocument $doc)')
    text = replace_once(text, "        return array(\n            'current_supported' => $this->bool_value($this->child_text($returnval, 'currentSupported')) === true,", """        $supported = $this->bool_value($this->child_text($returnval, 'currentSupported'));
        if ($supported === null) throw new RuntimeException('Invalid performance provider capability');
        return array(
            'current_supported' => $supported,""")
    text = replace_once(text, "        if (!isset($valuesByName['cpu.usage.average']) || !isset($valuesByName['mem.usage.average'])) return array();", """        foreach ($selected as $name => $counter) {
            if (!isset($valuesByName[$name])) {
                throw new RuntimeException('vCenter performance result omitted a requested counter');
            }
        }""")
    text = replace_once(text, "'cpu_percent' => $valuesByName['cpu.usage.average'][$i],", "'cpu_percent' => $valuesByName['cpu.usage.average'][$i] ?? null,")
    text = replace_once(text, "'memory_percent' => $valuesByName['mem.usage.average'][$i]", "'memory_percent' => $valuesByName['mem.usage.average'][$i] ?? null")
    # Do not classify an unrelated/ambiguous/malformed SOAP body as valid absence.
    text = replace_once(text, '        $doc = new DOMDocument();\n        $previous', """        if (stripos((string)$xml, '<!DOCTYPE') !== false || stripos((string)$xml, '<!ENTITY') !== false) {
            throw new RuntimeException('Forbidden performance XML declaration');
        }
        $doc = new DOMDocument();
        $previous""")
    text = replace_once(text, "        return $doc;\n    }", """        $expected = explode('(', $operation, 2)[0] . 'Response';
        $nodes = $xpath->query('/*[local-name()="Envelope" and namespace-uri()="http://schemas.xmlsoap.org/soap/envelope/"]/*[local-name()="Body"]/*');
        if ($nodes->length !== 1 || $nodes->item(0)->localName !== $expected || $nodes->item(0)->namespaceURI !== 'urn:vim25') {
            throw new RuntimeException('Unexpected performance SOAP response operation');
        }
        return $doc;
    }""")
    text = replace_once(text, "        foreach ($selected as $name => $counter) {\n            if (!isset($valuesByName[$name]))", "        if ($timestamps === array() && $valuesByName === array()) return array();\n        foreach ($selected as $name => $counter) {\n            if (!isset($valuesByName[$name]))")
    text = replace_once(text, "$values[] = preg_match('/^-?[0-9]+$/', $raw) ? ((int)$raw / 100.0) : null;", "$values[] = preg_match('/^[0-9]+$/', $raw) ? ((int)$raw / 100.0) : null;")
    # Malformed available entries cannot stand in for a legitimate empty list.
    text = replace_once(text, "            if ($id === null) continue;\n            $instance = $this->child_text($metric, 'instance');", """            if ($id === null || $id < 1) throw new RuntimeException('Invalid available performance counter');
            $instanceNode = $xpath->query('./*[local-name()="instance"]', $metric);
            if ($instanceNode->length !== 1) throw new RuntimeException('Invalid available performance instance');
            $instance = $this->child_text($metric, 'instance');""")
    return text


def guest(text):
    text = replace_once(text, 'class IntegrationVmwareVcenterGuestNetwork\n', 'class IntegrationVmwareInventoryGuestNetwork\n')
    text = replace_once(text, '        $verifyTls = (bool)$verifyTls;', "        if ($verifyTls !== true) throw new RuntimeException('mirror_strict_tls_required');")
    text = replace_once(text, """        $nic = isset($row['nic']) ? trim((string)$row['nic']) : '';
        if ($nic === '' || strlen($nic) > 1024 || preg_match('/[\\x00-\\x1F\\x7F]/', $nic)) {
            throw new RuntimeException('vCenter guest NIC has an invalid external id');
        }""", """        // vSphere guest interfaces can have no backing virtual Ethernet device.
        // Keep their MAC/IP observations, but never fabricate a hardware NIC id.
        $nic = null;
        if (array_key_exists('nic', $row) && $row['nic'] !== null) {
            if (!is_string($row['nic'])) throw new RuntimeException('vCenter guest NIC has an invalid external id');
            $nic = trim($row['nic']);
            if ($nic === '' || strlen($nic) > 1024 || preg_match('/[\\x00-\\x1F\\x7F]/', $row['nic'])) {
                throw new RuntimeException('vCenter guest NIC has an invalid external id');
            }
        }""")
    text = replace_once(text, "            'nic_external_id' => $nic,", "            'nic_external_id' => $nic,\n            'hardware_association' => $nic === null ? 'unassociated' : 'reported',")
    return text


def generate(root=ROOT):
    paths = list(PINS)
    return {
        'class.IntegrationVmwareInventoryPerformance.php': performance(source(root, paths[0])).encode(),
        'class.IntegrationVmwareInventoryGuestNetwork.php': guest(source(root, paths[1])).encode(),
    }


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True, help='New disposable directory; never a live application/release directory')
    output = parser.parse_args().output.absolute()
    if any(output == p or p in output.parents for p in [Path('/var/www'), Path('/opt/phpipam-custom'), Path('/etc/phpipam-custom'), Path('/var/lib/phpipam-custom')]):
        raise SystemExit('Live runtime output is not allowed')
    if any(p.is_symlink() for p in (output, *output.parents)):
        raise SystemExit('Symlink output paths are not allowed')
    result = generate()
    output.mkdir(mode=0o700, parents=False, exist_ok=False)
    for name, data in result.items(): (output / name).write_bytes(data)
    print('PHASE5G5_COMPAT_GENERATED=2; DEPLOYMENT_PERFORMED=0')
