<?php

/**
 * phpIPAM Custom — persistent VMware vCenter connector configuration store.
 *
 * This repository class is deliberately narrow:
 * - fixed provider: vmware_vcenter;
 * - encrypted credential envelopes only;
 * - no raw error bodies/messages persisted;
 * - blank password during metadata update retains the existing credential;
 * - an explicitly deleted credential automatically disables the connector.
 *
 * The database adapter is the existing phpIPAM Database_PDO-compatible object.
 */
class IntegrationVmwareVcenterConfigStore
{
    const TABLE = 'custom_integrations';
    const PROVIDER = 'vmware_vcenter';

    private $Database;
    private $cipher;
    private $endpointNormalizer;

    public function __construct($Database, IntegrationSecretCipher $cipher, $endpointNormalizer = null)
    {
        foreach (array('getObjectsQuery', 'insertObject', 'updateObject', 'lastInsertId') as $method) {
            if (!is_object($Database) || !method_exists($Database, $method)) {
                throw new InvalidArgumentException('VMware config store requires a phpIPAM-compatible database adapter');
            }
        }

        if ($endpointNormalizer === null) {
            if (!class_exists('IntegrationVmwareVcenter')) {
                throw new InvalidArgumentException('VMware endpoint normalizer is unavailable');
            }
            $client = new IntegrationVmwareVcenter();
            $endpointNormalizer = array($client, 'normalize_endpoint');
        }
        if (!is_callable($endpointNormalizer)) {
            throw new InvalidArgumentException('VMware endpoint normalizer must be callable');
        }

        $this->Database = $Database;
        $this->cipher = $cipher;
        $this->endpointNormalizer = $endpointNormalizer;
    }

    public function list_configs()
    {
        $rows = $this->Database->getObjectsQuery(
            'no_html_escape',
            'SELECT * FROM `' . self::TABLE . '` WHERE `provider` = ? ORDER BY `name`, `id`;',
            array(self::PROVIDER)
        );

        if (!is_array($rows)) {
            return array();
        }

        $result = array();
        foreach ($rows as $row) {
            $result[] = $this->public_row($row);
        }
        return $result;
    }

    public function get_config($id)
    {
        return $this->public_row($this->fetch_row($this->normalize_id($id)));
    }

    public function create_config(array $input, $actorId)
    {
        $actorId = $this->normalize_actor_id($actorId);
        $now = date('Y-m-d H:i:s');
        $name = $this->require_text($input, 'name', 128, 'Connector name is required');
        $endpoint = $this->normalize_endpoint(isset($input['endpoint']) ? $input['endpoint'] : '');
        $username = $this->require_text($input, 'username', 255, 'vCenter username is required');
        $password = isset($input['password']) ? (string)$input['password'] : '';
        if ($password === '') {
            throw new InvalidArgumentException('vCenter password is required when creating a connector');
        }
        if (strlen($password) > 4096) {
            throw new InvalidArgumentException('vCenter password exceeds the connector safety limit');
        }

        $verifyTls = $this->bool_value(isset($input['verify_tls']) ? $input['verify_tls'] : true, true);
        $enabled = $this->bool_value(isset($input['enabled']) ? $input['enabled'] : false, false);
        $this->require_explicit_insecure_tls_ack($verifyTls, $input);
        $this->assert_unique_endpoint($endpoint, null);

        $envelope = $this->cipher->encrypt($password, self::PROVIDER);
        $values = array(
            'provider' => self::PROVIDER,
            'name' => $name,
            'enabled' => $enabled ? 1 : 0,
            'endpoint' => $endpoint,
            'username' => $username,
            'verify_tls' => $verifyTls ? 1 : 0,
            'secret_envelope' => $envelope,
            'secret_key_id' => $this->cipher->envelope_key_id($envelope),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now
        );

        $this->Database->insertObject(self::TABLE, $values);
        $id = (int)$this->Database->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException('Unable to determine the new VMware connector id');
        }

        return $this->get_config($id);
    }

    public function update_config($id, array $input, $actorId)
    {
        $id = $this->normalize_id($id);
        $actorId = $this->normalize_actor_id($actorId);
        $existing = $this->row_to_array($this->fetch_row($id));

        $name = array_key_exists('name', $input)
            ? $this->require_text($input, 'name', 128, 'Connector name is required')
            : (string)$existing['name'];
        $endpoint = array_key_exists('endpoint', $input)
            ? $this->normalize_endpoint($input['endpoint'])
            : (string)$existing['endpoint'];
        $username = array_key_exists('username', $input)
            ? $this->require_text($input, 'username', 255, 'vCenter username is required')
            : (string)$existing['username'];
        $verifyTls = array_key_exists('verify_tls', $input)
            ? $this->bool_value($input['verify_tls'], true)
            : ((int)$existing['verify_tls'] === 1);
        $enabled = array_key_exists('enabled', $input)
            ? $this->bool_value($input['enabled'], false)
            : ((int)$existing['enabled'] === 1);

        $this->require_explicit_insecure_tls_ack($verifyTls, $input);
        $this->assert_unique_endpoint($endpoint, $id);

        $envelope = isset($existing['secret_envelope']) ? (string)$existing['secret_envelope'] : '';
        $keyId = isset($existing['secret_key_id']) ? (string)$existing['secret_key_id'] : '';
        $passwordProvided = array_key_exists('password', $input) && (string)$input['password'] !== '';

        if ($passwordProvided) {
            $password = (string)$input['password'];
            if (strlen($password) > 4096) {
                throw new InvalidArgumentException('vCenter password exceeds the connector safety limit');
            }
            $envelope = $this->cipher->encrypt($password, self::PROVIDER);
            $keyId = $this->cipher->envelope_key_id($envelope);
        }

        if ($enabled && $envelope === '') {
            throw new InvalidArgumentException('A VMware connector cannot be enabled without a stored credential');
        }

        $values = array(
            'id' => $id,
            'name' => $name,
            'enabled' => $enabled ? 1 : 0,
            'endpoint' => $endpoint,
            'username' => $username,
            'verify_tls' => $verifyTls ? 1 : 0,
            'updated_by' => $actorId,
            'updated_at' => date('Y-m-d H:i:s')
        );
        if ($passwordProvided) {
            $values['secret_envelope'] = $envelope;
            $values['secret_key_id'] = $keyId;
        }

        $this->Database->updateObject(self::TABLE, $values, 'id');
        return $this->get_config($id);
    }

    public function replace_credential($id, $password, $actorId)
    {
        $id = $this->normalize_id($id);
        $actorId = $this->normalize_actor_id($actorId);
        $this->fetch_row($id);
        $password = (string)$password;
        if ($password === '') {
            throw new InvalidArgumentException('Replacement vCenter password must not be empty');
        }
        if (strlen($password) > 4096) {
            throw new InvalidArgumentException('vCenter password exceeds the connector safety limit');
        }

        $envelope = $this->cipher->encrypt($password, self::PROVIDER);
        $this->Database->updateObject(self::TABLE, array(
            'id' => $id,
            'secret_envelope' => $envelope,
            'secret_key_id' => $this->cipher->envelope_key_id($envelope),
            'updated_by' => $actorId,
            'updated_at' => date('Y-m-d H:i:s')
        ), 'id');

        return $this->get_config($id);
    }

    public function delete_credential($id, $actorId)
    {
        $id = $this->normalize_id($id);
        $actorId = $this->normalize_actor_id($actorId);
        $this->fetch_row($id);

        $this->Database->updateObject(self::TABLE, array(
            'id' => $id,
            'enabled' => 0,
            'secret_envelope' => null,
            'secret_key_id' => null,
            'updated_by' => $actorId,
            'updated_at' => date('Y-m-d H:i:s')
        ), 'id');

        return $this->get_config($id);
    }

    public function rotate_credential($id, $actorId)
    {
        $id = $this->normalize_id($id);
        $actorId = $this->normalize_actor_id($actorId);
        $existing = $this->row_to_array($this->fetch_row($id));
        $envelope = isset($existing['secret_envelope']) ? (string)$existing['secret_envelope'] : '';
        if ($envelope === '') {
            throw new RuntimeException('VMware connector has no credential to rotate');
        }

        if (!$this->cipher->needs_rotation($envelope)) {
            return $this->public_row($existing);
        }

        $rotated = $this->cipher->reencrypt($envelope, self::PROVIDER);
        $this->Database->updateObject(self::TABLE, array(
            'id' => $id,
            'secret_envelope' => $rotated,
            'secret_key_id' => $this->cipher->envelope_key_id($rotated),
            'updated_by' => $actorId,
            'updated_at' => date('Y-m-d H:i:s')
        ), 'id');

        return $this->get_config($id);
    }

    public function load_runtime_config($id, $requireEnabled = true)
    {
        $id = $this->normalize_id($id);
        $row = $this->row_to_array($this->fetch_row($id));
        if ($requireEnabled && (int)$row['enabled'] !== 1) {
            throw new RuntimeException('VMware connector is disabled');
        }

        $envelope = isset($row['secret_envelope']) ? (string)$row['secret_envelope'] : '';
        if ($envelope === '') {
            throw new RuntimeException('VMware connector has no stored credential');
        }

        return array(
            'id' => $id,
            'provider' => self::PROVIDER,
            'name' => (string)$row['name'],
            'endpoint' => (string)$row['endpoint'],
            'username' => (string)$row['username'],
            'password' => $this->cipher->decrypt($envelope, self::PROVIDER),
            'verify_tls' => ((int)$row['verify_tls'] === 1)
        );
    }

    public function mark_test_success($id)
    {
        $id = $this->normalize_id($id);
        $this->fetch_row($id);
        $now = date('Y-m-d H:i:s');
        $this->Database->updateObject(self::TABLE, array(
            'id' => $id,
            'last_test_at' => $now,
            'last_success_at' => $now,
            'last_error_at' => null,
            'last_error_code' => null,
            'updated_at' => $now
        ), 'id');
        return true;
    }

    public function mark_test_failure($id, $errorCode)
    {
        $id = $this->normalize_id($id);
        $this->fetch_row($id);
        $now = date('Y-m-d H:i:s');
        $this->Database->updateObject(self::TABLE, array(
            'id' => $id,
            'last_test_at' => $now,
            'last_error_at' => $now,
            'last_error_code' => $this->sanitize_error_code($errorCode),
            'updated_at' => $now
        ), 'id');
        return true;
    }

    private function fetch_row($id)
    {
        $rows = $this->Database->getObjectsQuery(
            'no_html_escape',
            'SELECT * FROM `' . self::TABLE . '` WHERE `provider` = ? AND `id` = ? LIMIT 1;',
            array(self::PROVIDER, $id)
        );

        if (!is_array($rows) || count($rows) !== 1) {
            throw new RuntimeException('VMware connector was not found');
        }
        return $rows[0];
    }

    private function assert_unique_endpoint($endpoint, $excludeId)
    {
        if ($excludeId === null) {
            $query = 'SELECT `id` FROM `' . self::TABLE . '` WHERE `provider` = ? AND `endpoint` = ? LIMIT 1;';
            $params = array(self::PROVIDER, $endpoint);
        } else {
            $query = 'SELECT `id` FROM `' . self::TABLE . '` WHERE `provider` = ? AND `endpoint` = ? AND `id` <> ? LIMIT 1;';
            $params = array(self::PROVIDER, $endpoint, (int)$excludeId);
        }

        $rows = $this->Database->getObjectsQuery('no_html_escape', $query, $params);
        if (is_array($rows) && count($rows) > 0) {
            throw new InvalidArgumentException('A VMware connector for this vCenter endpoint already exists');
        }
    }

    private function public_row($row)
    {
        $data = $this->row_to_array($row);
        $envelope = isset($data['secret_envelope']) ? (string)$data['secret_envelope'] : '';
        $keyId = isset($data['secret_key_id']) ? (string)$data['secret_key_id'] : '';
        unset($data['secret_envelope']);
        unset($data['secret_key_id']);

        $data['enabled'] = isset($data['enabled']) && (int)$data['enabled'] === 1;
        $data['verify_tls'] = !isset($data['verify_tls']) || (int)$data['verify_tls'] === 1;
        $data['has_credential'] = ($envelope !== '');
        $data['credential_key_id'] = $keyId !== '' ? $keyId : null;
        $data['credential_needs_rotation'] = ($envelope !== '') ? $this->cipher->needs_rotation($envelope) : false;
        return $data;
    }

    private function row_to_array($row)
    {
        if (is_object($row)) {
            return get_object_vars($row);
        }
        if (is_array($row)) {
            return $row;
        }
        throw new RuntimeException('VMware connector database row has an invalid shape');
    }

    private function normalize_id($id)
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($id === false) {
            throw new InvalidArgumentException('Invalid VMware connector id');
        }
        return (int)$id;
    }

    private function normalize_actor_id($actorId)
    {
        $actorId = filter_var($actorId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($actorId === false) {
            throw new InvalidArgumentException('Invalid phpIPAM actor id');
        }
        return (int)$actorId;
    }

    private function normalize_endpoint($endpoint)
    {
        $normalized = call_user_func($this->endpointNormalizer, $endpoint);
        $normalized = trim((string)$normalized);
        if ($normalized === '' || strlen($normalized) > 255) {
            throw new InvalidArgumentException('Normalized vCenter endpoint is invalid or too long');
        }
        return $normalized;
    }

    private function require_text(array $input, $field, $maxLength, $emptyMessage)
    {
        $value = isset($input[$field]) ? trim((string)$input[$field]) : '';
        if ($value === '') {
            throw new InvalidArgumentException($emptyMessage);
        }
        if (strlen($value) > (int)$maxLength) {
            throw new InvalidArgumentException($field . ' exceeds the connector safety limit');
        }
        return $value;
    }

    private function bool_value($value, $default)
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'on' || $value === 'yes') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'off' || $value === 'no' || $value === '') {
            return false;
        }
        return (bool)$default;
    }

    private function require_explicit_insecure_tls_ack($verifyTls, array $input)
    {
        if ($verifyTls) {
            return;
        }
        $ack = isset($input['allow_insecure_tls']) ? $this->bool_value($input['allow_insecure_tls'], false) : false;
        if (!$ack) {
            throw new InvalidArgumentException('Persisting TLS verification disabled requires explicit insecure-TLS acknowledgement');
        }
    }

    private function sanitize_error_code($errorCode)
    {
        $errorCode = strtolower(trim((string)$errorCode));
        $allowed = array(
            'network',
            'timeout',
            'tls',
            'authentication',
            'authorization',
            'http',
            'invalid_response',
            'configuration',
            'unknown'
        );
        return in_array($errorCode, $allowed, true) ? $errorCode : 'unknown';
    }
}
