#!/usr/bin/env python3
from pathlib import Path
p = Path(__file__).resolve().parents[1] / 'layers/phase5h9/functions/classes/class.IntegrationVmwareFastReaderConfigLoader.php'
s = p.read_text(); n = 0
def ok(value, label):
    global n
    n += 1
    if not value: raise AssertionError(label)

for token in [
    'final class IntegrationVmwareFastReaderConfigLoader',
    "public const PATH = '/etc/phpipam-custom/vmware-fast-reader.json'",
    'private const MAX_BYTES = 4096',
    'IntegrationVmwareInventoryStore::noLinks($path)',
    'return @lstat($path) !== false;',
    'IntegrationVmwareSnapshotService::readPrivate(',
    'self::MAX_BYTES',
    '            0,',
    '            0640,',
    'posix_getegid()',
    'if (!$present) return IntegrationVmwareFastReaderConfig::disabled();',
    'json_decode($raw, true, 16, JSON_THROW_ON_ERROR)',
    'IntegrationVmwareFastReaderConfig::fromArray($decoded)',
    "throw new RuntimeException('fast_reader_configuration_invalid'",
]: ok(token in s, 'required:' + token)

for token in [
    '$_GET', '$_POST', '$_REQUEST', '$_ENV', 'getenv(', 'filter_input(',
    'HTTP_', 'QUERY_STRING', 'argv', 'php://input',
    'IntegrationVmwareVcenter', 'PropertyCollector', 'QueryPerf', 'curl_', 'SoapClient',
    'IntegrationSecretCipher', 'secret_envelope', 'username', 'password', 'endpoint',
    'file_put_contents', 'fopen(', 'unlink(', 'mkdir(', 'rename(', '->publish(', '->update(',
    'system(', 'exec(', 'shell_exec(', 'proc_open(',
]: ok(token not in s, 'forbidden:' + token)

ok(s.index('IntegrationVmwareInventoryStore::noLinks($path)') < s.index('return @lstat($path) !== false;'), 'links_before_missing_probe')
ok(s.index('if (!$present) return IntegrationVmwareFastReaderConfig::disabled();') < s.index('$raw = $read(self::PATH);'), 'missing_before_read')
ok(s.count('IntegrationVmwareSnapshotService::readPrivate(') == 1, 'one_secure_read_site')
ok(s.count("'/etc/phpipam-custom/vmware-fast-reader.json'") == 1, 'one_fixed_path_literal')
print(f'PHASE5H9_CONFIG_LOADER_BOUNDARY_ASSERTIONS={n}')
print('PHASE5H9_CONFIG_ROOT_WWWDATA_0640_READPRIVATE_OK')
print('PHASE5H9_CONFIG_NO_REQUEST_ENV_PROVIDER_WRITE_DEPENDENCY_OK')
