<?php
declare(strict_types=1);

/** DNS-only secret envelopes. This class has no HTTP, database or provisioning side effects. */
final class IntegrationDnsCipher
{
    public const KEY_PATH = '/etc/phpipam-custom/dns-credentials.key';
    private array $keys;
    public function __construct(private string $active, #[SensitiveParameter] array $keys)
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') ||
            !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $active) || !isset($keys[$active]) || count($keys)>4) {
            throw new DomainException('keyring_unavailable');
        }
        foreach ($keys as $id=>$key) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D',$id) || !is_string($key) || strlen($key)!==32) {
                throw new DomainException('keyring_unavailable');
            }
        }
        $this->keys=$keys;
    }
    public function __debugInfo(): array { return ['keyring'=>'[redacted]']; }
    public function __serialize(): array { throw new LogicException('keyring_not_serializable'); }
    public function __destruct() { foreach ($this->keys as &$key) sodium_memzero($key); unset($key); }

    /** Path is server-owned bootstrap input, never request data. No permission bypass exists. */
    public static function fromKeyringFile(string $path=self::KEY_PATH): self
    {
        try {
            if ($path==='' || $path[0]!=='/' || in_array('..',explode('/',$path),true)) throw new RuntimeException();
            for ($parent=$path; $parent!=='/'; $parent=dirname($parent)) {
                clearstatcache(true,$parent); if (is_link($parent)) throw new RuntimeException();
            }
            $before=@lstat($path);
            if ($before===false || ($before['mode'] & 0170000)!==0100000 ||
                ($before['mode'] & 07777)!==0640 || $before['uid']!==0 || $before['gid']!==33 ||
                $before['nlink']!==1 || $before['size']<1 || $before['size']>4096) throw new RuntimeException();
            $f=@fopen($path,'rb'); if ($f===false) throw new RuntimeException();
            try {
                $opened=fstat($f); $raw=stream_get_contents($f,4097); $after=fstat($f);
                clearstatcache(true,$path); $named=lstat($path);
                foreach (['dev','ino','uid','gid','mode','nlink','size','mtime','ctime'] as $field) {
                    if ($opened[$field]!==$before[$field] || $after[$field]!==$before[$field] || $named[$field]!==$before[$field]) throw new RuntimeException();
                }
                if (!is_string($raw) || strlen($raw)!==$before['size']) throw new RuntimeException();
            } finally { fclose($f); }
            $ring=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            if (!is_array($ring) || count($ring)!==3 || ($ring['version']??null)!==1 || !is_string($ring['active']??null) || !is_array($ring['keys']??null)) throw new RuntimeException();
            $keys=[];
            foreach ($ring['keys'] as $id=>$hex) {
                if (!is_string($hex) || !preg_match('/^[0-9a-f]{64}$/D',$hex)) throw new RuntimeException();
                $keys[$id]=hex2bin($hex);
            }
            return new self($ring['active'],$keys);
        } catch (Throwable $e) { throw new DomainException('keyring_unavailable'); }
        finally { if (isset($raw) && is_string($raw)) sodium_memzero($raw); }
    }
    private function context(string $profile,string $username,int $version): string
    {
        if (!preg_match('/^[0-9a-f]{32}$/D',$profile) || $username==='' || strlen($username)>255 || $version<1 || $version>2147483647) {
            throw new DomainException('secret_unavailable');
        }
        return json_encode(['phpipam-custom:dns-credential:v1',$profile,$username,$version],JSON_THROW_ON_ERROR);
    }
    public function seal(#[SensitiveParameter] string $password,string $profile,string $username,int $version): string
    {
        if ($password==='' || strlen($password)>4096) throw new DomainException('password_invalid');
        $nonce=random_bytes(24);
        $ct=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($password,$this->context($profile,$username,$version),$nonce,$this->keys[$this->active]);
        return json_encode(['v'=>1,'alg'=>'XChaCha20-Poly1305','kid'=>$this->active,'nonce'=>bin2hex($nonce),'ct'=>bin2hex($ct)],JSON_THROW_ON_ERROR);
    }
    /** Internal crypto primitive; deliberately NOT exposed by the admin service/controller. */
    public function open(#[SensitiveParameter] string $envelope,string $profile,string $username,int $version): string
    {
        try {
            if (strlen($envelope)>9000) throw new RuntimeException();
            $e=json_decode($envelope,true,8,JSON_THROW_ON_ERROR);
            if (!is_array($e) || count($e)!==5 || ($e['v']??null)!==1 || ($e['alg']??null)!=='XChaCha20-Poly1305' ||
                !is_string($e['kid']??null) || !isset($this->keys[$e['kid']]) || !is_string($e['nonce']??null) ||
                !preg_match('/^[0-9a-f]{48}$/D',$e['nonce']) || !is_string($e['ct']??null) ||
                strlen($e['ct'])<34 || strlen($e['ct'])>8224 || !preg_match('/^(?:[0-9a-f]{2})+$/D',$e['ct'])) throw new RuntimeException();
            $value=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(hex2bin($e['ct']),$this->context($profile,$username,$version),hex2bin($e['nonce']),$this->keys[$e['kid']]);
            if (!is_string($value)) throw new RuntimeException();
            return $value;
        } catch (Throwable $e) { throw new DomainException('secret_unavailable'); }
    }
}
