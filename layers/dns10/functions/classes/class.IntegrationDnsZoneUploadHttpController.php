<?php
declare(strict_types=1);

/** Source-only machine boundary. The injected service must reauthorize atomically during publication. */
final class IntegrationDnsZoneUploadHttpController
{
    private const MAX_BODY=4194304;
    private Closure $factory;

    public function __construct(callable $factory) {$this->factory=Closure::fromCallable($factory);}

    private static function response(int $status,array $body): array
    {
        return ['status'=>$status,'headers'=>['Cache-Control'=>'no-store','Content-Type'=>'application/json; charset=UTF-8',
            'X-Content-Type-Options'=>'nosniff'],'body'=>$body];
    }

    public function handle(#[SensitiveParameter] array $request): array
    {
        if (($request['method']??null)!=='POST') return self::response(405,['ok'=>false,'error'=>'method_not_allowed']);
        if (($request['https']??null)!==true) return self::response(403,['ok'=>false,'error'=>'https_required']);
        $type=$request['content_type']??null;
        if (!is_string($type) || strtolower(trim(explode(';',$type,2)[0]))!=='application/json') {
            return self::response(415,['ok'=>false,'error'=>'content_type_required']);
        }
        $authorization=$request['authorization']??null;
        if (!is_string($authorization) || !preg_match('/^Bearer (dnsup1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43})$/D',$authorization,$match)) {
            return self::response(401,['ok'=>false,'error'=>'upload_auth_failed']);
        }
        $raw=$request['raw_body']??null;
        if (!is_string($raw) || $raw==='') return self::response(400,['ok'=>false,'error'=>'request_invalid']);
        if (strlen($raw)>self::MAX_BODY) return self::response(413,['ok'=>false,'error'=>'request_too_large']);
        $token=$match[1];
        try {
            $service=($this->factory)();
            if (!is_object($service) || !is_callable([$service,'authenticateUpload']) || !is_callable([$service,'publishUpload'])) {
                throw new RuntimeException('service_unavailable');
            }
            $service->authenticateUpload($token);
            try {$document=self::decodeStrict($raw);}
            catch (Throwable $_) {return self::response(400,['ok'=>false,'error'=>'request_invalid']);}
            $result=$service->publishUpload($token,$document,hash('sha256',$raw),strlen($raw));
            if (!is_array($result) || count($result)!==1 || !array_key_exists('decision',$result) ||
                !in_array($result['decision'],['publish','replay'],true)) throw new RuntimeException('service_unavailable');
            return self::response(200,['ok'=>true,'decision'=>$result['decision']]);
        } catch (DomainException $e) {
            $code=$e->getMessage();
            if ($code==='upload_auth_failed') return self::response(401,['ok'=>false,'error'=>$code]);
            if (in_array($code,['snapshot_out_of_order','snapshot_sequence_conflict','snapshot_unavailable','snapshot_job_invalid'],true)) {
                return self::response(409,['ok'=>false,'error'=>$code]);
            }
            if ($code==='snapshot_invalid') return self::response(400,['ok'=>false,'error'=>$code]);
            return self::response(503,['ok'=>false,'error'=>'upload_unavailable']);
        } catch (Throwable $_) {
            return self::response(503,['ok'=>false,'error'=>'upload_unavailable']);
        } finally {
            if (function_exists('sodium_memzero')) @sodium_memzero($token);
        }
    }

    private static function decodeStrict(string $json): array
    {
        $i=0; self::scanValue($json,$i,0); self::skipWs($json,$i);
        if ($i!==strlen($json)) throw new DomainException('json_invalid');
        $value=json_decode($json,true,20,JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) throw new DomainException('json_invalid');
        return $value;
    }

    private static function skipWs(string $json,int &$i): void
    {
        $len=strlen($json);
        while ($i<$len && ($json[$i]===' ' || $json[$i]==="\n" || $json[$i]==="\r" || $json[$i]==="\t")) ++$i;
    }

    private static function scanValue(string $json,int &$i,int $depth): void
    {
        if ($depth>20) throw new DomainException('json_invalid');
        self::skipWs($json,$i);
        if ($i>=strlen($json)) throw new DomainException('json_invalid');
        $c=$json[$i];
        if ($c==='{') {self::scanObject($json,$i,$depth+1);return;}
        if ($c==='[') {self::scanArray($json,$i,$depth+1);return;}
        if ($c==='"') {self::scanString($json,$i);return;}
        $start=$i; $len=strlen($json);
        while ($i<$len && !str_contains(" \t\r\n,]}",$json[$i])) ++$i;
        if ($i===$start) throw new DomainException('json_invalid');
    }

    private static function scanObject(string $json,int &$i,int $depth): void
    {
        ++$i;self::skipWs($json,$i);$seen=[];
        if (($json[$i]??null)==='}') {++$i;return;}
        while (true) {
            self::skipWs($json,$i);
            if (($json[$i]??null)!=='"') throw new DomainException('json_invalid');
            $key=self::scanString($json,$i);
            if (array_key_exists($key,$seen)) throw new DomainException('duplicate_json_key');
            $seen[$key]=true;self::skipWs($json,$i);
            if (($json[$i]??null)!==':') throw new DomainException('json_invalid');
            ++$i;self::scanValue($json,$i,$depth);self::skipWs($json,$i);
            $c=$json[$i]??null;
            if ($c==='}') {++$i;return;}
            if ($c!==',') throw new DomainException('json_invalid');
            ++$i;
        }
    }

    private static function scanArray(string $json,int &$i,int $depth): void
    {
        ++$i;self::skipWs($json,$i);
        if (($json[$i]??null)===']') {++$i;return;}
        while (true) {
            self::scanValue($json,$i,$depth);self::skipWs($json,$i);
            $c=$json[$i]??null;
            if ($c===']') {++$i;return;}
            if ($c!==',') throw new DomainException('json_invalid');
            ++$i;
        }
    }

    private static function scanString(string $json,int &$i): string
    {
        $start=$i;++$i;$len=strlen($json);$escaped=false;
        while ($i<$len) {
            if (!$escaped && $json[$i]==='"') {
                ++$i;$value=json_decode(substr($json,$start,$i-$start),true,2,JSON_THROW_ON_ERROR);
                if (!is_string($value)) throw new DomainException('json_invalid');
                return $value;
            }
            if (!$escaped && ord($json[$i])<0x20) throw new DomainException('json_invalid');
            if (!$escaped && $json[$i]==='\\') $escaped=true; else $escaped=false;
            ++$i;
        }
        throw new DomainException('json_invalid');
    }
}
