<?php

/**
 * phpIPAM Custom — authenticated encryption helper for integration secrets.
 *
 * Phase 5B stores only encrypted secret envelopes in the database. Master keys
 * live outside the web root and outside Git. The envelope deliberately uses
 * hexadecimal encoding rather than base64 so source transport and runtime
 * secret representation remain unambiguous.
 */
class IntegrationSecretCipher
{
    const ENVELOPE_VERSION = 1;
    const ALGORITHM = 'A256GCM';
    const CIPHER = 'aes-256-gcm';
    const DEFAULT_KEYRING_PATH = '/etc/phpipam-custom/integrations.key';

    private $keys = array();
    private $currentKeyId;

    public function __construct(array $keyRing)
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new RuntimeException('PHP OpenSSL extension is required for integration secret encryption');
        }

        $this->load_keyring_array($keyRing);
    }

    public static function from_keyring_file($path = self::DEFAULT_KEYRING_PATH, $enforcePermissions = true)
    {
        $path = (string)$path;
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('Integration keyring path must be absolute');
        }
        if (is_link($path)) {
            throw new RuntimeException('Integration keyring must not be a symbolic link');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Integration keyring is not a readable regular file');
        }

        if ($enforcePermissions) {
            $perms = @fileperms($path);
            if ($perms === false) {
                throw new RuntimeException('Unable to inspect integration keyring permissions');
            }
            $mode = $perms & 0777;

            // Owner read/write and optional group read are acceptable. World
            // access, any group write/execute, or any execute bit is rejected.
            if (($mode & 0007) !== 0 || ($mode & 0030) !== 0 || ($mode & 0100) !== 0) {
                throw new RuntimeException('Integration keyring permissions are too broad; use 0600 or 0640');
            }
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('Integration keyring is empty or unreadable');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Integration keyring is not valid JSON');
        }

        return new self($decoded);
    }

    public static function generate_key_hex()
    {
        return bin2hex(random_bytes(32));
    }

    public function current_key_id()
    {
        return $this->currentKeyId;
    }

    public function encrypt($plaintext, $context = '')
    {
        $plaintext = (string)$plaintext;
        $context = (string)$context;
        $keyId = $this->currentKeyId;
        $key = $this->keys[$keyId];
        $iv = random_bytes(12);
        $tag = '';
        $aad = $this->aad($context);

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Integration secret encryption failed');
        }

        $envelope = array(
            'v' => self::ENVELOPE_VERSION,
            'alg' => self::ALGORITHM,
            'kid' => $keyId,
            'iv' => bin2hex($iv),
            'tag' => bin2hex($tag),
            'ct' => bin2hex($ciphertext)
        );

        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Integration secret envelope serialization failed');
        }

        return $json;
    }

    public function decrypt($envelope, $context = '')
    {
        $parsed = $this->parse_envelope($envelope);
        $keyId = $parsed['kid'];
        if (!array_key_exists($keyId, $this->keys)) {
            throw new RuntimeException('Integration secret references an unavailable key id');
        }

        $plaintext = openssl_decrypt(
            hex2bin($parsed['ct']),
            self::CIPHER,
            $this->keys[$keyId],
            OPENSSL_RAW_DATA,
            hex2bin($parsed['iv']),
            hex2bin($parsed['tag']),
            $this->aad((string)$context)
        );

        if ($plaintext === false) {
            throw new RuntimeException('Integration secret authentication/decryption failed');
        }

        return $plaintext;
    }

    public function envelope_key_id($envelope)
    {
        $parsed = $this->parse_envelope($envelope);
        return $parsed['kid'];
    }

    public function needs_rotation($envelope)
    {
        return $this->envelope_key_id($envelope) !== $this->currentKeyId;
    }

    public function reencrypt($envelope, $context = '')
    {
        $plaintext = $this->decrypt($envelope, $context);
        try {
            return $this->encrypt($plaintext, $context);
        } finally {
            if (function_exists('sodium_memzero')) {
                @sodium_memzero($plaintext);
            }
        }
    }

    private function load_keyring_array(array $keyRing)
    {
        $version = isset($keyRing['version']) ? (int)$keyRing['version'] : 0;
        if ($version !== 1) {
            throw new InvalidArgumentException('Unsupported integration keyring version');
        }

        $current = isset($keyRing['current_key_id']) ? trim((string)$keyRing['current_key_id']) : '';
        if (!$this->valid_key_id($current)) {
            throw new InvalidArgumentException('Integration keyring current_key_id is invalid');
        }

        $keys = isset($keyRing['keys']) && is_array($keyRing['keys']) ? $keyRing['keys'] : array();
        if (!array_key_exists($current, $keys)) {
            throw new InvalidArgumentException('Integration keyring current key is missing');
        }

        $normalized = array();
        foreach ($keys as $keyId => $hex) {
            $keyId = (string)$keyId;
            $hex = strtolower(trim((string)$hex));
            if (!$this->valid_key_id($keyId)) {
                throw new InvalidArgumentException('Integration keyring contains an invalid key id');
            }
            if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
                throw new InvalidArgumentException('Integration key must be exactly 32 bytes encoded as 64 hexadecimal characters');
            }
            $binary = hex2bin($hex);
            if ($binary === false || strlen($binary) !== 32) {
                throw new InvalidArgumentException('Integration key decoding failed');
            }
            $normalized[$keyId] = $binary;
        }

        $this->keys = $normalized;
        $this->currentKeyId = $current;
    }

    private function parse_envelope($envelope)
    {
        $decoded = json_decode((string)$envelope, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Integration secret envelope is malformed');
        }

        $required = array('v', 'alg', 'kid', 'iv', 'tag', 'ct');
        foreach ($required as $field) {
            if (!array_key_exists($field, $decoded)) {
                throw new RuntimeException('Integration secret envelope is incomplete');
            }
        }

        if ((int)$decoded['v'] !== self::ENVELOPE_VERSION || (string)$decoded['alg'] !== self::ALGORITHM) {
            throw new RuntimeException('Unsupported integration secret envelope version or algorithm');
        }

        $keyId = (string)$decoded['kid'];
        if (!$this->valid_key_id($keyId)) {
            throw new RuntimeException('Integration secret envelope key id is invalid');
        }

        $iv = strtolower((string)$decoded['iv']);
        $tag = strtolower((string)$decoded['tag']);
        $ct = strtolower((string)$decoded['ct']);

        if (!preg_match('/^[0-9a-f]{24}$/', $iv)) {
            throw new RuntimeException('Integration secret envelope nonce is invalid');
        }
        if (!preg_match('/^[0-9a-f]{32}$/', $tag)) {
            throw new RuntimeException('Integration secret envelope authentication tag is invalid');
        }
        if ($ct !== '' && (!preg_match('/^[0-9a-f]+$/', $ct) || (strlen($ct) % 2) !== 0)) {
            throw new RuntimeException('Integration secret envelope ciphertext is invalid');
        }

        return array(
            'v' => self::ENVELOPE_VERSION,
            'alg' => self::ALGORITHM,
            'kid' => $keyId,
            'iv' => $iv,
            'tag' => $tag,
            'ct' => $ct
        );
    }

    private function valid_key_id($keyId)
    {
        return is_string($keyId) && (bool)preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId);
    }

    private function aad($context)
    {
        return 'phpipam-custom|integration-secret|v1|' . (string)$context;
    }
}
