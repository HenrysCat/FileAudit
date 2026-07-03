# Example only. Review paths and account choice before running as Administrator.

$TaskName = "FileAudit Collector"
$ScriptPath = "C:\Path\To\FileAuditCollector.ps1"

$Action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$ScriptPath`""

$Trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 5) `
    -RepetitionDuration (New-TimeSpan -Days 3650)

# SYSTEM account example.
$Principal = New-ScheduledTaskPrincipal `
    -UserId "SYSTEM" `
    -LogonType ServiceAccount `
    -RunLevel Highest

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $Action `
    -Trigger $Trigger `
    -Principal $Principal `
    -Description "Posts Windows file audit events to FileAudit."

# Service account alternative:
# $Principal = New-ScheduledTaskPrincipal -UserId "DOMAIN\FileAuditSvc" -LogonType Password -RunLevel Highest
# Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Principal $Principal
