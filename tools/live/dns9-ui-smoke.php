<?php
declare(strict_types=1);

function dns9UiCheck(array $state): int {
    $id='33b6fade9323f15aa7b1f56a8a865a9d';
    $job='3af8e09411c0e94a17ed19d3f0872359';
    $result=$state['connection_runtime'][$id]['zone_result']??null;
    if (!is_array($result) || ($result['id']??null)!==$job || !is_array($result['zones']??null) || count($result['zones'])!==172) {
        throw new DomainException('zone_result_unavailable');
    }
    $html=(new IntegrationDnsAdminView())->render($state,'dns9-ui-smoke');
    if (!str_contains($html,'Полученные зоны: 172')) throw new DomainException('zone_render_failed');
    return count($result['zones']);
}

$direct=isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME'])===__FILE__;
if ($direct) {
    if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0) exit(2);
    try {
        $web='/var/www/html';
        require_once $web.'/functions/functions.php';
        foreach (['IntegrationDnsCipher','IntegrationDnsConfigRepository','IntegrationDnsConfigService',
            'IntegrationDnsPhpIpamConfigRepository','IntegrationDnsAdminView','IntegrationDnsCollectorControlRepository',
            'IntegrationDnsCollectorControlPhpIpamRepository','IntegrationDnsCollectorControlService',
            'IntegrationDnsAutonomousAdminService'] as $class) require_once $web.'/functions/classes/class.'.$class.'.php';
        $db=new Database_PDO();
        $repo=new IntegrationDnsPhpIpamConfigRepository($db);
        $config=new IntegrationDnsConfigService($repo,IntegrationDnsCipher::fromKeyringFile());
        $resolver=static function(string $id) use ($repo): ?array {
            return $repo->transaction(static function() use ($repo,$id): ?array {
                $c=$repo->connection($id);
                if ($c===null) return null;
                $version=null;
                if ($c['credential_mode']==='profile') {
                    $profile=$repo->profile($c['profile_id']);
                    if ($profile===null || $profile['secret_envelope']===null) return null;
                    $version=(int)$profile['secret_version'];
                }
                return ['id'=>$c['id'],'revision'=>(int)$c['revision'],'binding_epoch'=>(int)$c['binding_epoch'],
                    'credential_mode'=>$c['credential_mode'],'profile_id'=>$c['profile_id'],
                    'credential_version'=>$version,'enabled'=>(bool)$c['enabled']];
            });
        };
        $control=new IntegrationDnsCollectorControlService(new IntegrationDnsCollectorControlPhpIpamRepository($db),$resolver);
        $service=new IntegrationDnsAutonomousAdminService($config,$repo,$control);
        $count=dns9UiCheck($service->snapshot());
        echo 'DNS9_UI_SMOKE_ZONE_COUNT='.$count."\n";
    } catch (Throwable $e) { fwrite(STDERR,"DNS9_UI_SMOKE_ERROR=failed\n"); exit(1); }
}
