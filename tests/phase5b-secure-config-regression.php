<?php

require_once __DIR__ . '/../layers/phase5b/functions/classes/class.IntegrationSecretCipher.php';
require_once __DIR__ . '/../layers/phase5b/functions/classes/class.IntegrationVmwareVcenterConfigStore.php';

class Phase5BFakeDatabase
{
    public $rows = array();
    public $queries = array();
    private $nextId = 1;
    private $lastId = 0;

    public function getObjectsQuery($tableName, $query = null, $values = array(), $class = 'stdClass')
    {
        $this->queries[] = array($tableName, $query, $values);
        if ($tableName !== 'no_html_escape') {
            throw new RuntimeException('Phase 5B secret-bearing reads must use no_html_escape');
        }

        $values = array_values((array)$values);
        $matches = array();
        foreach ($this->rows as $row) {
            if (strpos($query, '`provider` = ?') !== false && $row['provider'] !== $values[0]) {
                continue;
            }

            if (strpos($query, '`endpoint` = ?') !== false) {
                $endpointIndex = 1;
                if ($row['endpoint'] !== $values[$endpointIndex]) {
                    continue;
                }
                if (strpos($query, '`id` <> ?') !== false && (int)$row['id'] === (int)$values[2]) {
                    continue;
                }
            }

            if (strpos($query, '`id` = ?') !== false) {
                $idIndex = 1;
                if ((int)$row['id'] !== (int)$values[$idIndex]) {
                    continue;
                }
            }

            $matches[] = (object)$row;
        }

        if (strpos($query, 'ORDER BY `name`, `id`') !== false) {
            usort($matches, function ($a, $b) {
                $cmp = strcmp((string)$a->name, (string)$b->name);
                return $cmp !== 0 ? $cmp : ((int)$a->id - (int)$b->id);
            });
        }

        if (strpos($query, 'LIMIT 1') !== false && count($matches) > 1) {
            $matches = array($matches[0]);
        }
        return $matches;
    }

    public function insertObject($tableName, $obj, $raw = false, $replace = false, $ignoreId = true)
    {
        if ($tableName !== IntegrationVmwareVcenterConfigStore::TABLE) {
            throw new RuntimeException('Unexpected table in fake database insert');
        }
        $row = (array)$obj;
        $row['id'] = $this->nextId++;
        $this->lastId = $row['id'];
        $row += array(
            'last_test_at' => null,
            'last_success_at' => null,
            'last_error_at' => null,
            'last_error_code' => null
        );
        $this->rows[$row['id']] = $row;
        return true;
    }

    public function updateObject($tableName, $obj, $primarykey = 'id', $primarykey2 = null)
    {
        if ($tableName !== IntegrationVmwareVcenterConfigStore::TABLE || $primarykey !== 'id') {
            throw new RuntimeException('Unexpected table/primary key in fake database update');
        }
        $values = (array)$obj;
        $id = isset($values['id']) ? (int)$values['id'] : 0;
        if ($id <= 0 || !isset($this->rows[$id])) {
            throw new RuntimeException('Fake database update target not found');
        }
        foreach ($values as $key => $value) {
            if ($key !== 'id') {
                $this->rows[$id][$key] = $value;
            }
        }
        return true;
    }

    public function lastInsertId()
    {
        return (string)$this->lastId;
    }
}

$passed = 0;
$failed = 0;

function p5_assert($condition, $label)
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failed++;
        echo "FAIL: $label\n";
    }
}

function p5_throws($callable, $label, $messagePart = null)
{
    try {
        call_user_func($callable);
    } catch (Exception $e) {
        $ok = $messagePart === null || strpos($e->getMessage(), $messagePart) !== false;
        p5_assert($ok, $label);
        return;
    }
    p5_assert(false, $label);
}

$keyOld = str_repeat('11', 32);
$keyNew = str_repeat('22', 32);
$cipherOld = new IntegrationSecretCipher(array(
    'version' => 1,
    'current_key_id' => 'k-old',
    'keys' => array('k-old' => $keyOld)
));
$cipher = new IntegrationSecretCipher(array(
    'version' => 1,
    'current_key_id' => 'k-new',
    'keys' => array('k-old' => $keyOld, 'k-new' => $keyNew)
));

p5_assert((bool)preg_match('/^[0-9a-f]{64}$/', IntegrationSecretCipher::generate_key_hex()), 'generated master key is 32-byte hex');

$secret = 'P@ss<word>&"phase5b';
$envelope = $cipher->encrypt($secret, 'vmware_vcenter');
p5_assert(strpos($envelope, $secret) === false, 'secret envelope does not contain plaintext');
p5_assert($cipher->decrypt($envelope, 'vmware_vcenter') === $secret, 'AES-GCM encrypt/decrypt round trip');
p5_assert($cipher->envelope_key_id($envelope) === 'k-new', 'envelope records current key id');
p5_assert($cipher->needs_rotation($envelope) === false, 'new envelope does not require rotation');

$oldEnvelope = $cipherOld->encrypt('old-secret', 'vmware_vcenter');
p5_assert($cipher->needs_rotation($oldEnvelope) === true, 'old-key envelope is detected for rotation');
$rotated = $cipher->reencrypt($oldEnvelope, 'vmware_vcenter');
p5_assert($cipher->envelope_key_id($rotated) === 'k-new', 'reencryption moves envelope to current key');
p5_assert($cipher->decrypt($rotated, 'vmware_vcenter') === 'old-secret', 'reencryption preserves secret value');
p5_throws(function () use ($cipher, $envelope) {
    $cipher->decrypt($envelope, 'different-provider');
}, 'AAD context mismatch fails closed', 'authentication/decryption');

$tampered = json_decode($envelope, true);
$tampered['ct'] = ($tampered['ct'] === '' ? '00' : (substr($tampered['ct'], 0, -2) . (substr($tampered['ct'], -2) === '00' ? '01' : '00')));
p5_throws(function () use ($cipher, $tampered) {
    $cipher->decrypt(json_encode($tampered), 'vmware_vcenter');
}, 'tampered ciphertext fails authentication', 'authentication/decryption');

p5_throws(function () use ($keyNew) {
    new IntegrationSecretCipher(array('version' => 1, 'current_key_id' => 'bad/key', 'keys' => array('bad/key' => $keyNew)));
}, 'invalid key id rejected');
p5_throws(function () {
    new IntegrationSecretCipher(array('version' => 1, 'current_key_id' => 'k1', 'keys' => array('k1' => 'deadbeef')));
}, 'short key rejected');

$tmpKey = tempnam(sys_get_temp_dir(), 'phase5b-key-');
file_put_contents($tmpKey, json_encode(array(
    'version' => 1,
    'current_key_id' => 'k1',
    'keys' => array('k1' => str_repeat('33', 32))
)));
chmod($tmpKey, 0640);
$fileCipher = IntegrationSecretCipher::from_keyring_file($tmpKey, true);
p5_assert($fileCipher->current_key_id() === 'k1', '0600/0640 external keyring is accepted');
chmod($tmpKey, 0644);
p5_throws(function () use ($tmpKey) {
    IntegrationSecretCipher::from_keyring_file($tmpKey, true);
}, 'world-readable keyring rejected', 'permissions are too broad');
@unlink($tmpKey);

$normalizer = function ($endpoint) {
    $endpoint = trim((string)$endpoint);
    if (!preg_match('#^https://#', $endpoint)) {
        $endpoint = 'https://' . $endpoint;
    }
    return rtrim(strtolower($endpoint), '/');
};

$db = new Phase5BFakeDatabase();
$store = new IntegrationVmwareVcenterConfigStore($db, $cipher, $normalizer);
$config = $store->create_config(array(
    'name' => 'Primary VC',
    'endpoint' => 'VCENTER01.RBANK.LOCAL/',
    'username' => 'svc_phpipam@rbank.local',
    'password' => 'first-password',
    'enabled' => true,
    'verify_tls' => true
), 7);

p5_assert((int)$config['id'] === 1, 'connector create returns id');
p5_assert($config['endpoint'] === 'https://vcenter01.rbank.local', 'connector endpoint normalized before storage');
p5_assert($config['enabled'] === true && $config['verify_tls'] === true, 'enabled/TLS metadata persisted');
p5_assert($config['has_credential'] === true, 'public config exposes credential presence only');
p5_assert(!array_key_exists('secret_envelope', $config) && !array_key_exists('secret_key_id', $config), 'public config never exposes encrypted envelope');
p5_assert(strpos($db->rows[1]['secret_envelope'], 'first-password') === false, 'database stores ciphertext rather than plaintext password');
p5_assert($db->rows[1]['secret_key_id'] === 'k-new', 'database stores envelope key id metadata');

$runtime = $store->load_runtime_config(1, true);
p5_assert($runtime['password'] === 'first-password', 'runtime config decrypts credential only on explicit load');
p5_assert($runtime['verify_tls'] === true, 'runtime config preserves TLS verification');

$beforeEnvelope = $db->rows[1]['secret_envelope'];
$updated = $store->update_config(1, array(
    'name' => 'Primary VC renamed',
    'password' => '',
    'verify_tls' => true,
    'enabled' => true
), 8);
p5_assert($db->rows[1]['secret_envelope'] === $beforeEnvelope, 'blank password on metadata edit retains encrypted credential');
p5_assert($updated['name'] === 'Primary VC renamed', 'metadata update succeeds while retaining credential');

$store->replace_credential(1, 'second-password', 9);
p5_assert($db->rows[1]['secret_envelope'] !== $beforeEnvelope, 'explicit credential replacement creates a new envelope');
p5_assert($store->load_runtime_config(1, true)['password'] === 'second-password', 'replacement credential decrypts correctly');

p5_throws(function () use ($store) {
    $store->create_config(array(
        'name' => 'Duplicate',
        'endpoint' => 'https://vcenter01.rbank.local',
        'username' => 'svc2',
        'password' => 'x',
        'verify_tls' => true
    ), 7);
}, 'duplicate provider/endpoint rejected', 'already exists');

p5_throws(function () use ($store) {
    $store->update_config(1, array('verify_tls' => false), 8);
}, 'persistent TLS disable requires explicit acknowledgement', 'explicit insecure-TLS acknowledgement');

$insecure = $store->update_config(1, array(
    'verify_tls' => false,
    'allow_insecure_tls' => true
), 8);
p5_assert($insecure['verify_tls'] === false, 'explicit insecure TLS override can be represented for owner-approved use');

$store->mark_test_failure(1, 'raw exception with password=oops');
p5_assert($db->rows[1]['last_error_code'] === 'unknown', 'raw/unknown error text is reduced to sanitized category');
p5_assert($db->rows[1]['last_error_at'] !== null, 'failed test records sanitized failure timestamp');
$store->mark_test_success(1);
p5_assert($db->rows[1]['last_error_code'] === null && $db->rows[1]['last_success_at'] !== null, 'successful test clears sanitized error state');

$deleted = $store->delete_credential(1, 10);
p5_assert($deleted['has_credential'] === false, 'explicit credential deletion removes envelope');
p5_assert($deleted['enabled'] === false, 'credential deletion automatically disables connector');
p5_throws(function () use ($store) {
    $store->load_runtime_config(1, false);
}, 'runtime load fails closed when stored credential is absent', 'no stored credential');
p5_throws(function () use ($store) {
    $store->update_config(1, array('enabled' => true, 'verify_tls' => true), 10);
}, 'connector cannot be enabled without a credential', 'cannot be enabled');

// Create an old-key row to prove explicit rotation without exposing plaintext.
$db2 = new Phase5BFakeDatabase();
$oldStore = new IntegrationVmwareVcenterConfigStore($db2, $cipherOld, $normalizer);
$oldStore->create_config(array(
    'name' => 'Rotation VC',
    'endpoint' => 'rotation-vc.local',
    'username' => 'svc',
    'password' => 'rotation-secret',
    'verify_tls' => true
), 1);
$oldCiphertext = $db2->rows[1]['secret_envelope'];
$newStore = new IntegrationVmwareVcenterConfigStore($db2, $cipher, $normalizer);
$publicBeforeRotation = $newStore->get_config(1);
p5_assert($publicBeforeRotation['credential_needs_rotation'] === true, 'public metadata reports key rotation requirement without exposing secret');
$newStore->rotate_credential(1, 2);
p5_assert($db2->rows[1]['secret_envelope'] !== $oldCiphertext && $db2->rows[1]['secret_key_id'] === 'k-new', 'explicit rotation replaces envelope and key id');
p5_assert($newStore->load_runtime_config(1, false)['password'] === 'rotation-secret', 'rotated credential remains decryptable');

$allRawReads = true;
foreach (array_merge($db->queries, $db2->queries) as $queryCall) {
    if ($queryCall[0] !== 'no_html_escape') {
        $allRawReads = false;
    }
}
p5_assert($allRawReads, 'all secret-bearing database reads bypass phpIPAM HTML escaping safely');

echo "\nPHASE5B_SECURE_CONFIG_REGRESSION: passed=$passed failed=$failed\n";
exit($failed === 0 ? 0 : 1);
