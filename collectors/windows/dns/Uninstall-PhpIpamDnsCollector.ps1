#requires -Version 5.1
[CmdletBinding(SupportsShouldProcess=$true,ConfirmImpact='High')]
param()

Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'

$TaskName='phpIPAM Microsoft DNS Collector'
$Root=Join-Path $env:ProgramData 'phpIPAM\DnsCollector'

$identity=[Security.Principal.WindowsIdentity]::GetCurrent()
$principal=New-Object Security.Principal.WindowsPrincipal($identity)
if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'dns9_admin_required'}

$task=Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if($null -ne $task -and $PSCmdlet.ShouldProcess($TaskName,'Unregister Scheduled Task')){
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

if(Test-Path -LiteralPath $Root){
    $full=[IO.Path]::GetFullPath($Root)
    $expected=[IO.Path]::GetFullPath((Join-Path $env:ProgramData 'phpIPAM\DnsCollector'))
    if($full -ne $expected){throw 'dns9_install_root_invalid'}
    if($PSCmdlet.ShouldProcess($Root,'Remove DNS collector files including DPAPI token blob')){
        Remove-Item -LiteralPath $Root -Recurse -Force
    }
}

Write-Output 'DNS9_AUTONOMOUS_UNINSTALL_OK=1'
