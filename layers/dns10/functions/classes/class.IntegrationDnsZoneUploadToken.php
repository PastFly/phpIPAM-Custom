<?php
declare(strict_types=1);

/** Cryptographic primitive only; callers enforce source scope, revision and revocation. */
final class IntegrationDnsZoneUploadToken
{
    private const DOMAIN="phpipam-dns10-upload-v1\0";

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }

    private static function digest(string $id,#[SensitiveParameter] string $secret): string
    {
        return hash('sha256',self::DOMAIN.$id."\0".$secret);
    }

    private static function parse(#[SensitiveParameter] string $token): array
    {
        if (!preg_match('/^dnsup1\.([0-9a-f]{32})\.([A-Za-z0-9_-]{43})$/D',$token,$matches)) {
            throw new DomainException('upload_auth_failed');
        }
        $secret=base64_decode(strtr($matches[2],'-_','+/').'=',true);
        if (!is_string($secret) || strlen($secret)!==32 || !hash_equals(self::encode($secret),$matches[2])) {
            throw new DomainException('upload_auth_failed');
        }
        return [$matches[1],$secret];
    }

    /** One-time trusted issuance; store only token_id and token_digest. */
    public static function issue(): array
    {
        $id=bin2hex(random_bytes(16));
        $secret=random_bytes(32);
        return ['token_id'=>$id,'token'=>'dnsup1.'.$id.'.'.self::encode($secret),'token_digest'=>self::digest($id,$secret)];
    }

    public static function identify(#[SensitiveParameter] string $token): string
    {
        return self::parse($token)[0];
    }

    public static function verify(#[SensitiveParameter] string $token,mixed $storedId,mixed $storedDigest): bool
    {
        if (!is_string($storedId) || !preg_match('/^[0-9a-f]{32}$/D',$storedId) ||
            !is_string($storedDigest) || !preg_match('/^[0-9a-f]{64}$/D',$storedDigest)) return false;
        try {[$id,$secret]=self::parse($token);} catch (DomainException $_) {return false;}
        return hash_equals($storedId,$id) && hash_equals($storedDigest,self::digest($id,$secret));
    }
}
