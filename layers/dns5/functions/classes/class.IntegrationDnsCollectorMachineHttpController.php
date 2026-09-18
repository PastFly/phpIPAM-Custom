<?php
declare(strict_types=1);

/**
 * Machine-only HTTP controller for poll/result.
 * The host bridge supplies trusted HTTPS/method/content-type/auth header/raw body.
 * No phpIPAM user session, credential decryption or provider execution occurs here.
 */
final class IntegrationDnsCollectorMachineHttpController
{
    private Closure $factory;
    private const MAX_BODY=73728;

    public function __construct(callable $factory)
    {
        $this->factory=Closure::fromCallable($factory);
    }

    private static function response(int $status,mixed $body): array
    {
        return [
            'status'=>$status,
            'headers'=>[
                'Cache-Control'=>'no-store',
                'Content-Type'=>'application/json; charset=UTF-8',
                'X-Content-Type-Options'=>'nosniff'
            ],
            'body'=>$body
        ];
    }

    public function handle(#[SensitiveParameter] array $request): array
    {
        if(($request['method']??null)!=='POST') return self::response(405,['ok'=>false,'error'=>'method_not_allowed']);
        if(($request['https']??null)!==true) return self::response(403,['ok'=>false,'error'=>'https_required']);

        $contentType=$request['content_type']??null;
        if(!is_string($contentType)||strtolower(trim(explode(';',$contentType,2)[0]))!=='application/json') {
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

        if(!is_array($input)||array_is_list($input)||array_diff(array_keys($input),['action','data'])||
           !is_string($input['action']??null)) {
            return self::response(400,['ok'=>false,'error'=>'request_invalid']);
        }

        $action=$input['action'];
        if($action==='poll') {
            if(array_key_exists('data',$input) && $input['data']!==[]) {
                return self::response(400,['ok'=>false,'error'=>'request_invalid']);
            }
        } elseif($action==='result') {
            $data=$input['data']??null;
            if(!is_array($data)||array_is_list($data)||
               array_diff(array_keys($data),['job_id','lease_id','result'])||
               !is_string($data['job_id']??null)||!preg_match('/^[0-9a-f]{32}$/D',$data['job_id'])||
               !is_string($data['lease_id']??null)||!preg_match('/^[0-9a-f]{32}$/D',$data['lease_id'])||
               !is_array($data['result']??null)) {
                return self::response(400,['ok'=>false,'error'=>'request_invalid']);
            }
        } else {
            return self::response(400,['ok'=>false,'error'=>'action_not_supported']);
        }

        try {
            $service=($this->factory)();
            if(!is_object($service)||!is_callable([$service,'poll'])) throw new RuntimeException('service_unavailable');

            if($action==='poll') {
                $work=$service->poll($token);
                if($work===null) return self::response(204,null);
                return self::response(200,['ok'=>true,'data'=>$work]);
            }

            if(!is_callable([$service,'submitResult'])) throw new RuntimeException('service_unavailable');
            $data=$input['data'];
            $result=$service->submitResult($token,$data['job_id'],$data['lease_id'],$data['result']);
            return self::response(200,['ok'=>true,'data'=>$result]);
        } catch(DomainException $e) {
            $code=$e->getMessage();
            if($code==='collector_auth_failed') return self::response(401,['ok'=>false,'error'=>$code]);
            if(in_array($code,['lease_invalid','job_not_running','job_obsolete','assignment_changed','job_expired'],true)) {
                return self::response(409,['ok'=>false,'error'=>$code]);
            }
            if(in_array($code,['result_invalid','id_invalid'],true)) return self::response(400,['ok'=>false,'error'=>$code]);
            return self::response(503,['ok'=>false,'error'=>'control_unavailable']);
        } catch(Throwable $e) {
            return self::response(503,['ok'=>false,'error'=>'control_unavailable']);
        } finally {
            if(isset($token) && is_string($token) && function_exists('sodium_memzero')) @sodium_memzero($token);
        }
    }

    private static function decodeStrict(string $json): array
    {
        $i=0;
        self::scanValue($json,$i,0);
        self::skipWs($json,$i);
        if($i!==strlen($json)) throw new DomainException('json_invalid');
        $decoded=json_decode($json,true,20,JSON_THROW_ON_ERROR);
        if(!is_array($decoded)) throw new DomainException('json_invalid');
        return $decoded;
    }

    private static function skipWs(string $json,int &$i): void
    {
        $len=strlen($json);
        while($i<$len && ($json[$i]===' '||$json[$i]==="\n"||$json[$i]==="\r"||$json[$i]==="\t")) ++$i;
    }

    private static function scanValue(string $json,int &$i,int $depth): void
    {
        if($depth>20) throw new DomainException('json_invalid');
        self::skipWs($json,$i);
        if($i>=strlen($json)) throw new DomainException('json_invalid');
        $c=$json[$i];
        if($c==='{'){self::scanObject($json,$i,$depth+1);return;}
        if($c==='['){self::scanArray($json,$i,$depth+1);return;}
        if($c==='"'){self::scanString($json,$i);return;}
        $start=$i; $len=strlen($json);
        while($i<$len && !str_contains(" \t\r\n,]}",$json[$i])) ++$i;
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
            if(!$escaped && $json[$i]==='"'){
                ++$i;
                $decoded=json_decode(substr($json,$start,$i-$start),true,2,JSON_THROW_ON_ERROR);
                if(!is_string($decoded)) throw new DomainException('json_invalid');
                return $decoded;
            }
            if(!$escaped && $ord<0x20) throw new DomainException('json_invalid');
            if(!$escaped && $json[$i]==='\\') $escaped=true; else $escaped=false;
            ++$i;
        }
        throw new DomainException('json_invalid');
    }
}
