<?php
declare(strict_types=1);

/** Source/profile administration only. No provider calls, collector secret delivery, or native IP writes. */
final class IntegrationDnsConfigService
{
    public function __construct(private IntegrationDnsConfigRepository $repo,private IntegrationDnsCipher $cipher) {}
    private static function actor(int $id): void { if ($id<1) throw new DomainException('actor_invalid'); }
    private static function fields(array $in,array $allowed): void
    {
        if (array_diff(array_keys($in),$allowed)) throw new DomainException('fields_invalid');
    }
    private static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[0-9a-f]{32}$/D',$id)) throw new DomainException('id_invalid');
        return $id;
    }
    private static function text(mixed $text,int $max,string $code): string
    {
        if (!is_string($text) || $text!==trim($text) || $text==='' || strlen($text)>$max || !preg_match('//u',$text) || preg_match('/[\x00-\x1f\x7f]/',$text)) throw new DomainException($code);
        return $text;
    }
    private static function username(mixed $text): string
    {
        $text=self::text($text,255,'username_invalid');
        if (!preg_match('/^(?:[^\s@\\\\]+@[^\s@\\\\]+|[^\s@\\\\]+\\\\[^\s@\\\\]+)$/uD',$text)) throw new DomainException('username_invalid');
        return $text;
    }
    private static function revision(?array $row,mixed $expected): void
    {
        if (!is_int($expected) || $expected!==($row['revision']??0)) throw new DomainException('revision_conflict');
    }
    private static function increment(int $value): int
    {
        if ($value>=2147483647) throw new DomainException('version_exhausted');
        return $value+1;
    }
    private function consumers(string $id): array
    {
        return array_values(array_filter($this->repo->connections(),static fn($c)=>$c['profile_id']===$id));
    }
    private function confirmImpact(array $consumers,mixed $ack): void
    {
        if (!is_array($ack) || !array_is_list($ack)) throw new DomainException('profile_impact_conflict');
        foreach ($ack as $id) self::id($id);
        $expected=array_column($consumers,'id'); sort($expected); sort($ack);
        if ($expected!==$ack) throw new DomainException('profile_impact_conflict');
    }
    private function invalidate(array $rows,string $status,int $actor): void
    {
        foreach ($rows as $r) {
            $r['revision']=self::increment($r['revision']); $r['binding_epoch']=self::increment($r['binding_epoch']);
            $r['enabled']=false; $r['status']=$status; $r['updated_by']=$actor; $r['updated_at']=time();
            $this->repo->putConnection($r);
        }
    }
    private function event(string $action,string $id,int $actor): void
    {
        $this->repo->audit(['action'=>$action,'object_id'=>$id,'actor_id'=>$actor,'created_at'=>time()]);
    }
    private function publicProfile(array $row): array
    {
        $out=array_intersect_key($row,array_flip(['id','name','username','revision','secret_version','updated_at','updated_by']));
        $out['password_set']=$row['secret_envelope']!==null;
        $out['connections']=array_map(static fn($c)=>array_intersect_key($c,array_flip(['id','name','server'])), $this->consumers($row['id']));
        return $out;
    }
    public function snapshot(): array
    {
        return $this->repo->transaction(fn()=>['profiles'=>array_map(fn($p)=>$this->publicProfile($p),$this->repo->profiles()),'connections'=>$this->repo->connections(),'collector_control_available'=>false]);
    }
    public function saveProfile(#[SensitiveParameter] array $in,int $actor): array
    {
        self::actor($actor);
        self::fields($in,['id','expected_revision','name','username','password_action','password','acknowledge_connections']);
        return $this->repo->transaction(function() use ($in,$actor) {
            $id=($in['id']??null)===null ? bin2hex(random_bytes(16)) : self::id($in['id']);
            $row=($in['id']??null)===null ? null : $this->repo->profile($id);
            if (($in['id']??null)!==null && $row===null) throw new DomainException('not_found');
            self::revision($row,$in['expected_revision']??null);
            if ($row===null && count($this->repo->profiles())>=128) throw new DomainException('config_limit');
            $name=self::text($in['name']??null,128,'name_invalid'); $username=self::username($in['username']??null);
            $action=$in['password_action']??null; $password=$in['password']??null;
            if (!in_array($action,['replace','unchanged'],true) || !is_string($password)) throw new DomainException('password_action_invalid');
            $consumers=$this->consumers($id);
            if ($action==='unchanged') {
                if ($password!=='') throw new DomainException('password_action_invalid');
                if ($row===null || $row['secret_envelope']===null) throw new DomainException('password_invalid');
                if ($username!==$row['username']) throw new DomainException('password_required_for_username_change');
                $envelope=$row['secret_envelope']; $version=$row['secret_version'];
            } else {
                if ($password==='' || strlen($password)>4096 || str_contains($password,"\0") || preg_match('/^(?:\*{3,}|•{3,})$/uD',$password)) throw new DomainException('password_invalid');
                $this->confirmImpact($consumers,$in['acknowledge_connections']??null);
                $version=self::increment($row['secret_version']??0);
                try { $envelope=$this->cipher->seal($password,$id,$username,$version); }
                finally { sodium_memzero($password); }
            }
            $saved=['id'=>$id,'name'=>$name,'username'=>$username,'secret_envelope'=>$envelope,'secret_version'=>$version,
                'revision'=>self::increment($row['revision']??0),'updated_at'=>time(),'updated_by'=>$actor];
            $this->repo->putProfile($saved);
            if ($action==='replace') $this->invalidate($consumers,'needs_validation',$actor);
            $this->event($row===null?'profile_created':($action==='replace'?'profile_rotated':'profile_updated'),$id,$actor);
            return $this->publicProfile($saved);
        });
    }
    public function revokeProfile(string $id,int $expected,array $ack,int $actor): void
    {
        self::actor($actor); self::id($id);
        $this->repo->transaction(function() use ($id,$expected,$ack,$actor) {
            $row=$this->repo->profile($id); if ($row===null) throw new DomainException('not_found'); self::revision($row,$expected);
            $consumers=$this->consumers($id); $this->confirmImpact($consumers,$ack);
            $row['secret_envelope']=null; $row['revision']=self::increment($row['revision']); $row['secret_version']=self::increment($row['secret_version']);
            $row['updated_at']=time(); $row['updated_by']=$actor; $this->repo->putProfile($row);
            $this->invalidate($consumers,'credential_revoked',$actor); $this->event('profile_revoked',$id,$actor);
        });
    }
    public function deleteProfile(string $id,int $expected,int $actor): void
    {
        self::actor($actor); self::id($id);
        $this->repo->transaction(function() use ($id,$expected,$actor) {
            $row=$this->repo->profile($id); if ($row===null) throw new DomainException('not_found'); self::revision($row,$expected);
            if ($this->consumers($id)) throw new DomainException('profile_in_use');
            $this->repo->removeProfile($id); $this->event('profile_deleted',$id,$actor);
        });
    }
    public function saveConnection(array $in,int $actor): array
    {
        self::actor($actor); self::fields($in,['id','expected_revision','name','server','interval_seconds','credential_mode','profile_id','share_profile']);
        return $this->repo->transaction(function() use ($in,$actor) {
            $id=($in['id']??null)===null?bin2hex(random_bytes(16)):self::id($in['id']);
            $old=($in['id']??null)===null?null:$this->repo->connection($id);
            if (($in['id']??null)!==null && $old===null) throw new DomainException('not_found'); self::revision($old,$in['expected_revision']??null);
            $name=self::text($in['name']??null,128,'name_invalid'); $server=self::text($in['server']??null,254,'server_invalid');
            $server=strtolower(preg_replace('/\.$/','',$server));
            if (strlen($server)>253 || !str_contains($server,'.') || filter_var($server,FILTER_VALIDATE_IP) ||
                !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$server)) throw new DomainException('server_invalid');
            $interval=$in['interval_seconds']??null;
            if (!is_int($interval) || $interval<60 || $interval>3600) throw new DomainException('interval_invalid');
            $mode=$in['credential_mode']??null; $profile=$in['profile_id']??null;
            if (!is_bool($in['share_profile']??null) || !in_array($mode,['profile','collector_identity'],true)) throw new DomainException('credential_mode_invalid');
            if ($mode==='profile') {
                self::id($profile); $p=$this->repo->profile($profile);
                if ($p===null || $p['secret_envelope']===null) throw new DomainException('profile_unavailable');
                $others=array_filter($this->consumers($profile),static fn($c)=>$c['id']!==$id);
                if ($others && ($old['profile_id']??null)!==$profile && !$in['share_profile']) throw new DomainException('profile_share_confirmation_required');
            } elseif ($profile!==null) throw new DomainException('credential_mode_invalid');
            if ($old===null && count($this->repo->connections())>=256) throw new DomainException('config_limit');
            $saved=['id'=>$id,'provider'=>'microsoft_dns','name'=>$name,'server'=>$server,'interval_seconds'=>$interval,
                'credential_mode'=>$mode,'profile_id'=>$profile,'collector_id'=>null,'enabled'=>false,
                'status'=>$old===null?'not_configured':'needs_validation','revision'=>self::increment($old['revision']??0),
                'binding_epoch'=>self::increment($old['binding_epoch']??0),'updated_at'=>time(),'updated_by'=>$actor];
            $this->repo->putConnection($saved); $this->event($old===null?'connection_created':'connection_updated',$id,$actor);
            return $saved;
        });
    }
    public function deleteConnection(string $id,int $expected,int $actor): void
    {
        self::actor($actor); self::id($id);
        $this->repo->transaction(function() use ($id,$expected,$actor) {
            $row=$this->repo->connection($id); if ($row===null) throw new DomainException('not_found'); self::revision($row,$expected);
            if ($row['enabled']) throw new DomainException('connection_must_be_disabled');
            $this->repo->removeConnection($id); $this->event('connection_deleted',$id,$actor);
        });
    }
}
