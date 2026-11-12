#requires -Version 5.1
[CmdletBinding()]
param()

Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'

$TaskName='phpIPAM Microsoft DNS Collector'
$Root=Join-Path $env:ProgramData 'phpIPAM\DnsCollector'
$StatusPath=Join-Path $Root 'status.json'

$task=Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if($null -eq $task){
    [PSCustomObject][ordered]@{
        installed=$false
        task_state=$null
        last_run_time=$null
        last_task_result=$null
        collector_state=$null
        collector_updated_at=$null
        job_id=$null
        job_type=$null
        result_ok=$null
        result_code=$null
    }
    return
}

$info=Get-ScheduledTaskInfo -TaskName $TaskName
$collector=$null
if(Test-Path -LiteralPath $StatusPath -PathType Leaf){
    try{$collector=Get-Content -LiteralPath $StatusPath -Raw | ConvertFrom-Json -ErrorAction Stop}catch{}
}

[PSCustomObject][ordered]@{
    installed=$true
    task_state=[string]$task.State
    last_run_time=$info.LastRunTime
    last_task_result=[int64]$info.LastTaskResult
    collector_state=$(if($null -eq $collector){$null}else{[string]$collector.state})
    collector_updated_at=$(if($null -eq $collector){$null}else{[int64]$collector.updated_at})
    job_id=$(if($null -eq $collector){$null}else{$collector.job_id})
    job_type=$(if($null -eq $collector){$null}else{$collector.job_type})
    result_ok=$(if($null -eq $collector){$null}else{$collector.result_ok})
    result_code=$(if($null -eq $collector){$null}else{$collector.result_code})
}
