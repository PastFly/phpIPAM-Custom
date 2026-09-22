<?php
declare(strict_types=1);

/**
 * Dedicated machine credential endpoint controller.
 * Request selects only an already-running job+lease; it cannot select a connection/profile.
 */
final class IntegrationDnsCredentialHttpController
{
    private Closure $factory;
    private const MAX_BODY=4096;

    public function __construct(callable $factory)
    {
        $this->factory=Closure::fromCallable($factory);
    }

    private static function response(int $status,array $body): array
    {
        try {
            $json=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        } catch(Throwable $e) {
            $status=503;
            $json='{"ok":false,"error":"credential_unavailable"}';
        }
        return [
            'status'=>$status,
            'headers'=>[
                'Cache-Control'=>'no-store, max-age=0',
                'Pragma'=>'no-cache',
                'Content-Type'=>'application/json; charset=UTF-8',
                'X-Content-Type-Options'=>'nosniff'
            ],
            'body'=>$json
        ];
    }

    public function handle(#[SensitiveParameter] array $request): array
    {
        if(($request['method']??null)!=='POST') return self::response(405,['ok'=>false,'error'=>'method_not_allowed']);
        if(($request['https']??null)!==true) return self::response(403,['ok'=>false,'error'=>'https_required']);

        $type=$request['content_type']??null;
        if(!is_string($type)||strtolower(trim(explode(';',$type,2)[0]))!=='application/json') {
            return self::response(415,['ok'=>false,'error'=>'content_type_required']);
        }

        $authorization=$request['authorization']??null;
        if(!is_string($authorization)||
           !preg_match('/^Bearer (dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43})$/D',$authorization,$m)) {
            return self::response(401,['ok'=>false,'error'=>'collector_auth_failed']);
        }
        $token=$m[1];

        $raw=$request['raw_body']??null;
        if(!is_string($raw)) return self::response(400,['ok'=>false,'error'=>'request_invalid']);
        if(strlen($raw)>self::MAX_BODY) return self::response(413,['ok'=>false,'error'=>'request_too_large']);
        if($raw==='') return self::response(400,['ok'=>false,'error'=>'request_invalid']);

        try { $input=self::decodeStrict($raw); }
        catch(Throwable $e) { return self::response(400,['ok'=>false,'error'=>'request_invalid']); }

        if(!is_array($input)||array_is_list($input)||
           array_keys($input)!==['job_id','lease_id']||
           !is_string($input['job_id'])||!preg_match('/^[0-9a-f]{32}$/D',$input['job_id'])||
           !is_string($input['lease_id'])||!preg_match('/^[0-9a-f]{32}$/D',$input['lease_id'])) {
            return self::response(400,['ok'=>false,'error'=>'request_invalid']);
        }

        $credential=null;
        try {
            $service=($this->factory)();
            if(!is_object($service)||!is_callable([$service,'deliver'])) throw new RuntimeException('service_unavailable');
            $credential=$service->deliver($token,$input['job_id'],$input['lease_id']);
            if(!is_array($credential)) throw new DomainException('credential_unavailable');
            return self::response(200,['ok'=>true,'data'=>$credential]);
        } catch(DomainException $e) {
            $code=$e->getMessage();
            if($code==='collector_auth_failed') return self::response(401,['ok'=>false,'error'=>$code]);
            if(in_array($code,['lease_invalid','job_not_found','job_not_running','job_obsolete','assignment_changed','job_expired','credential_already_delivered'],true)) {
                return self::response(409,['ok'=>false,'error'=>$code]);
            }
            if($code==='id_invalid') return self::response(400,['ok'=>false,'error'=>$code]);
            return self::response(503,['ok'=>false,'error'=>'credential_unavailable']);
        } catch(Throwable $e) {
            return self::response(503,['ok'=>false,'error'=>'credential_unavailable']);
        } finally {
            if(is_array($credential)&&isset($credential['password'])&&is_string($credential['password'])&&function_exists('sodium_memzero')) {
                @sodium_memzero($credential['password']);
            }
            if(isset($token)&&is_string($token)&&function_exists('sodium_memzero')) @sodium_memzero($token);
        }
    }

    private static function decodeStrict(string $json): array
    {
        $i=0; self::scanValue($json,$i,0); self::skipWs($json,$i);
        if($i!==strlen($json)) throw new DomainException('json_invalid');
        $decoded=json_decode($json,true,12,JSON_THROW_ON_ERROR);
        if(!is_array($decoded)) throw new DomainException('json_invalid');
        return $decoded;
    }

    private static function skipWs(string $json,int &$i): void
    {
        $len=strlen($json);
        while($i<$len&&($json[$i]===' '||$json[$i]==="
"||$json[$i]===""||$json[$i]==="	")) ++$i;
    }

    private static function scanValue(string $json,int &$i,int $depth): void
    {
        if($depth>12) throw new DomainException('json_invalid');
        self::skipWs($json,$i);
        if($i>=strlen($json)) throw new DomainException('json_invalid');
        $c=$json[$i];
        if($c==='{'){self::scanObject($json,$i,$depth+1);return;}
        if($c==='['){self::scanArray($json,$i,$depth+1);return;}
        if($c==='"'){self::scanString($json,$i);return;}
        $start=$i; $len=strlen($json);
        while($i<$len&&!str_contains(" \\t\\r\\n,]}",$json[$i])) ++$i;
        if($i===$start) throw new DomainException('json_invalid');
    }

    private static function scanObject(string $json,int &$i,int $depth): void
    {
        ++$i; self::skipWs($json,$i); $seen=[];
        if(($json[$i]??null)==='}'){++$i;return;}
        while(true){
            self::skipWs($json,$i);
            if(($json[$i]??null)!=='"') throw new DomainException('json_invalid');
            $key=self::scanString($json,$i);
            if(array_key_exists($key,$seen)) throw new DomainException('duplicate_json_key');
            $seen[$key]=true;
            self::skipWs($json,$i);
            if(($json[$i]??null)!==':') throw new DomainException('json_invalid');
            ++$i; self::scanValue($json,$i,$depth); self::skipWs($json,$i);
            $c=$json[$i]??null;
            if($c==='}'){++$i;return;}
            if($c!==',') throw new DomainException('json_invalid');
            ++$i;
        }
    }

    private static function scanArray(string $json,int &$i,int $depth): void
    {
        ++$i; self::skipWs($json,$i);
        if(($json[$i]??null)===']'){++$i;return;}
        while(true){
            self::scanValue($json,$i,$depth); self::skipWs($json,$i);
            $c=$json[$i]??null;
            if($c===']'){++$i;return;}
            if($c!==',') throw new DomainException('json_invalid');
            ++$i;
        }
    }

    private static function scanString(string $json,int &$i): string
    {
        $start=$i; ++$i; $len=strlen($json); $escaped=false;
        while($i<$len){
            $ord=ord($json[$i]);
            if(!$escaped&&$json[$i]==='"'){
                ++$i;
                $decoded=json_decode(substr($json,$start,$i-$start),true,2,JSON_THROW_ON_ERROR);
                if(!is_string($decoded)) throw new DomainException('json_invalid');
                return $decoded;
            }
            if(!$escaped&&$ord<0x20) throw new DomainException('json_invalid');
            if(!$escaped&&$json[$i]==='\') $escaped=true; else $escaped=false;
            ++$i;
        }
        throw new DomainException('json_invalid');
    }
}
