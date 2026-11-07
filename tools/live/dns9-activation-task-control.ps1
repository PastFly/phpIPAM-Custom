#requires -Version 5.1
# Caller supplies the fixed PermanentName and Action (enable, disable or status).
$ErrorActionPreference='Stop'
$tasks=@(Get-ScheduledTask -TaskName $PermanentName -ErrorAction Stop)
if($tasks.Count -ne 1){throw 'task_identity_invalid'}
$task=$tasks[0]
if($Action -eq 'enable') {
    if($task.State -ne 'Disabled'){throw 'task_not_disabled'}
    Enable-ScheduledTask -TaskName $PermanentName -TaskPath $task.TaskPath -ErrorAction Stop | Out-Null
} elseif($Action -eq 'disable') {
    Disable-ScheduledTask -TaskName $PermanentName -TaskPath $task.TaskPath -ErrorAction Stop | Out-Null
} elseif($Action -ne 'status') {throw 'task_action_invalid'}
$tasks=@(Get-ScheduledTask -TaskName $PermanentName -TaskPath $task.TaskPath -ErrorAction Stop)
if($tasks.Count -ne 1){throw 'task_identity_invalid'}
$task=$tasks[0]
$info=Get-ScheduledTaskInfo -TaskName $PermanentName -TaskPath $task.TaskPath -ErrorAction Stop
$enabled=($task.State -ne 'Disabled')
if(($Action -eq 'enable' -and -not $enabled) -or ($Action -eq 'disable' -and $enabled)){throw 'task_state_invalid'}
$epoch=[DateTimeOffset]::new($info.LastRunTime.ToUniversalTime()).ToUnixTimeSeconds()
$state=@{enabled=[bool]$enabled;last_run_epoch=[long]$epoch}|ConvertTo-Json -Compress
Write-Output ('DNS9_ACTIVATION_TASK_STATE='+$state)
