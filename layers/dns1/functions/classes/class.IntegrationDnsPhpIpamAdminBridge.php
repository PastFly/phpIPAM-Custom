<?php
declare(strict_types=1);

/**
 * Trusted phpIPAM HTTP boundary for DNS1 administration.
 * Session establishment remains the host route's responsibility.
 */
final class IntegrationDnsPhpIpamAdminBridge
{
    private Closure $controllerFactory;
    private const MAX_BODY=16384;
    private const CSRF_SCOPE='integration_microsoft_dns_config';

    public function __construct(callable $controllerFactory)
    {
        $this->controllerFactory=Closure::fromCallable($controllerFactory);
    }

    private static function response(int $status,string $error): array
    {
        return [
            'status'=>$status,
            'headers'=>[
                'Cache-Control'=>'no-store',
                'Content-Type'=>'application/json; charset=UTF-8',
                'X-Content-Type-Options'=>'nosniff'
            ],
            'body'=>['ok'=>false,'error'=>$error]
        ];
    }

    public function handle(object $user,array $server,array $headers,#[SensitiveParameter] string $rawBody): array
    {
        $method=$server['REQUEST_METHOD']??'';
        if ($method!=='POST') return self::response(405,'method_not_allowed');

        $contentType=$server['CONTENT_TYPE']??'';
        if (!is_string($contentType) || strtolower(trim(explode(';',$contentType,2)[0]))!=='application/json') {
            return self::response(415,'content_type_required');
        }

        if (!method_exists($user,'is_admin') || !method_exists($user,'isHttps') ||
            !isset($user->Crypto) || !is_object($user->Crypto) || !method_exists($user->Crypto,'csrf_cookie')) {
            return self::response(503,'configuration_unavailable');
        }
        if ($user->is_admin(false)!==true) return self::response(403,'admin_required');
        if ($user->isHttps()!==true) return self::response(403,'request_denied');

        $actor=isset($user->user) && is_object($user->user) ? ($user->user->id??null) : null;
        if (!is_int($actor) && !(is_string($actor)&&preg_match('/^[1-9][0-9]*$/D',$actor))) {
            return self::response(403,'admin_required');
        }
        $actor=(int)$actor;
        if ($actor<1) return self::response(403,'admin_required');

        $csrf=self::header($headers,'x-phpipam-dns-csrf');
        if ($csrf===null || $csrf==='' || strlen($csrf)>512 || preg_match('/[\x00-\x1f\x7f]/',$csrf)) {
            return self::response(403,'request_denied');
        }
        try {
            if ($user->Crypto->csrf_cookie('validate',self::CSRF_SCOPE,$csrf)===false) {
                return self::response(403,'request_denied');
            }
        } catch (Throwable $e) {
            return self::response(403,'request_denied');
        }

        if (strlen($rawBody)>self::MAX_BODY) return self::response(413,'request_too_large');
        if ($rawBody==='') return self::response(400,'request_invalid');

        try {
            $input=self::decodeStrict($rawBody);
        } catch (Throwable $e) {
            return self::response(400,'request_invalid');
        }

        if (!is_array($input) || array_is_list($input) ||
            array_diff(array_keys($input),['action','data']) ||
            !is_string($input['action']??null)) {
            return self::response(400,'request_invalid');
        }

        try {
            $controller=($this->controllerFactory)();
            if (!is_object($controller) || !is_callable([$controller,'handle'])) {
                throw new RuntimeException('controller_unavailable');
            }
            return $controller->handle([
                'method'=>'POST',
                'authenticated'=>true,
                'admin'=>true,
                'https'=>true,
                'csrf_valid'=>true,
                'actor_id'=>$actor,
                'input'=>$input
            ]);
        } catch (Throwable $e) {
            return self::response(503,'configuration_unavailable');
        }
    }

    private static function header(array $headers,string $wanted): ?string
    {
        foreach ($headers as $name=>$value) {
            if (!is_string($name) || strtolower($name)!==$wanted || !is_string($value)) continue;
            return trim($value);
        }
        return null;
    }

    private static function decodeStrict(string $json): array
    {
        $i=0;
        self::scanValue($json,$i,0);
        self::skipWs($json,$i);
        if ($i!==strlen($json)) throw new DomainException('json_invalid');
        $decoded=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new DomainException('json_invalid');
        return $decoded;
    }

    private static function skipWs(string $json,int &$i): void
    {
        $len=strlen($json);
        while ($i<$len && ($json[$i]===' ' || $json[$i]==="\n" || $json[$i]==="\r" || $json[$i]==="\t")) ++$i;
    }

    private static function scanValue(string $json,int &$i,int $depth): void
    {
        if ($depth>16) throw new DomainException('json_invalid');
        self::skipWs($json,$i);
        if ($i>=strlen($json)) throw new DomainException('json_invalid');
        $c=$json[$i];
        if ($c==='{') { self::scanObject($json,$i,$depth+1); return; }
        if ($c==='[') { self::scanArray($json,$i,$depth+1); return; }
        if ($c==='"') { self::scanString($json,$i); return; }

        $start=$i; $len=strlen($json);
        while ($i<$len && !str_contains(" \t\r\n,]}",$json[$i])) ++$i;
        if ($i===$start) throw new DomainException('json_invalid');
    }

    private static function scanObject(string $json,int &$i,int $depth): void
    {
        ++$i;
        self::skipWs($json,$i);
        $seen=[];
        if (($json[$i]??null)==='}') { ++$i; return; }

        while (true) {
            self::skipWs($json,$i);
            if (($json[$i]??null)!=='"') throw new DomainException('json_invalid');
            $key=self::scanString($json,$i);
            if (array_key_exists($key,$seen)) throw new DomainException('duplicate_json_key');
            $seen[$key]=true;

            self::skipWs($json,$i);
            if (($json[$i]??null)!==':') throw new DomainException('json_invalid');
            ++$i;
            self::scanValue($json,$i,$depth);

            self::skipWs($json,$i);
            $c=$json[$i]??null;
            if ($c==='}') { ++$i; return; }
            if ($c!==',') throw new DomainException('json_invalid');
            ++$i;
        }
    }

    private static function scanArray(string $json,int &$i,int $depth): void
    {
        ++$i;
        self::skipWs($json,$i);
        if (($json[$i]??null)===']') { ++$i; return; }
        while (true) {
            self::scanValue($json,$i,$depth);
            self::skipWs($json,$i);
            $c=$json[$i]??null;
            if ($c===']') { ++$i; return; }
            if ($c!==',') throw new DomainException('json_invalid');
            ++$i;
        }
    }

    private static function scanString(string $json,int &$i): string
    {
        $start=$i; ++$i; $len=strlen($json); $escaped=false;
        while ($i<$len) {
            $ord=ord($json[$i]);
            if (!$escaped && $json[$i]==='"') {
                ++$i;
                $token=substr($json,$start,$i-$start);
                $decoded=json_decode($token,true,2,JSON_THROW_ON_ERROR);
                if (!is_string($decoded)) throw new DomainException('json_invalid');
                return $decoded;
            }
            if (!$escaped && $ord<0x20) throw new DomainException('json_invalid');
            if (!$escaped && $json[$i]==='\\') $escaped=true;
            else $escaped=false;
            ++$i;
        }
        throw new DomainException('json_invalid');
    }
}
