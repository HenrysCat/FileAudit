Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ConfigPath = Join-Path $ScriptDir "collector-config.ps1"
$DefaultLogPath = "C:\ProgramData\FileAudit\collector.log"

function Write-BootstrapLog {
    param([string]$Message)

    $line = "{0} {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    $logDir = Split-Path -Parent $DefaultLogPath
    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }
    Add-Content -LiteralPath $DefaultLogPath -Value $line
}

Write-BootstrapLog "Collector bootstrap started. Script path=$($MyInvocation.MyCommand.Path)"

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    $message = "Missing collector config: $ConfigPath. Copy collector-config.example.ps1 to collector-config.ps1 and edit it."
    Write-BootstrapLog $message
    Write-Error $message
    exit 1
}

try {
    . $ConfigPath
} catch {
    Write-BootstrapLog ("Failed to load config $ConfigPath. " + $_.Exception.Message)
    throw
}

if (-not (Get-Variable -Name LogPath -Scope Script -ErrorAction SilentlyContinue)) {
    $LogPath = $DefaultLogPath
}

if (-not (Get-Variable -Name FirstRunLookbackMinutes -Scope Script -ErrorAction SilentlyContinue)) {
    $FirstRunLookbackMinutes = 30
}

function Write-CollectorLog {
    param([string]$Message)

    $line = "{0} {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    $logDir = Split-Path -Parent $LogPath
    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }
    Add-Content -LiteralPath $LogPath -Value $line
}

function Get-EventDataMap {
    param([xml]$Xml)

    $map = @{}
    if ($Xml.Event.EventData -and $Xml.Event.EventData.Data) {
        foreach ($data in $Xml.Event.EventData.Data) {
            $name = [string]$data.Name
            if ([string]::IsNullOrWhiteSpace($name)) {
                continue
            }

            $value = $null
            if ($data.PSObject.Properties.Match("#text").Count -gt 0) {
                $value = [string]$data.'#text'
            } else {
                $value = [string]$data.InnerText
            }

            if ($map.ContainsKey($name) -and -not [string]::IsNullOrWhiteSpace($value)) {
                $map[$name] = "{0}; {1}" -f $map[$name], $value
            } else {
                $map[$name] = $value
            }
        }
    }

    return $map
}

function Get-MapValue {
    param(
        [hashtable]$Map,
        [string[]]$Names
    )

    foreach ($name in $Names) {
        if ($Map.ContainsKey($name) -and -not [string]::IsNullOrWhiteSpace([string]$Map[$name])) {
            return [string]$Map[$name]
        }
    }

    return $null
}

function Test-AccessMaskBit {
    param(
        [string]$AccessMask,
        [int64]$Bit
    )

    if ([string]::IsNullOrWhiteSpace($AccessMask)) {
        return $false
    }

    try {
        $clean = $AccessMask.Trim()
        if ($clean.StartsWith("0x", [System.StringComparison]::OrdinalIgnoreCase)) {
            $value = [Convert]::ToInt64($clean.Substring(2), 16)
        } else {
            $value = [Convert]::ToInt64($clean, 10)
        }

        return (($value -band $Bit) -ne 0)
    } catch {
        return $false
    }
}

function Test-AccessText {
    param(
        [string]$Text,
        [string[]]$Needles
    )

    if ([string]::IsNullOrWhiteSpace($Text)) {
        return $false
    }

    foreach ($needle in $Needles) {
        if ($Text.IndexOf($needle, [System.StringComparison]::OrdinalIgnoreCase) -ge 0) {
            return $true
        }
    }

    return $false
}

function Test-DeleteAccess {
    param([string]$AccessMask, [string]$AccessList)

    return (Test-AccessMaskBit $AccessMask 0x10000) -or (Test-AccessText $AccessList @("DELETE"))
}

function Test-WriteAccess {
    param([string]$AccessMask, [string]$AccessList)

    $maskHasWrite = (Test-AccessMaskBit $AccessMask 0x2) `
        -or (Test-AccessMaskBit $AccessMask 0x4) `
        -or (Test-AccessMaskBit $AccessMask 0x10) `
        -or (Test-AccessMaskBit $AccessMask 0x100)

    $textHasWrite = Test-AccessText $AccessList @(
        "WriteData",
        "AppendData",
        "WriteAttributes",
        "WriteExtendedAttributes",
        "AddFile",
        "AddSubdirectory",
        "CreateFiles",
        "CreateFolders"
    )

    return $maskHasWrite -or $textHasWrite
}

function Test-PermissionAccess {
    param([string]$AccessMask, [string]$AccessList)

    $maskHasPermission = (Test-AccessMaskBit $AccessMask 0x40000) -or (Test-AccessMaskBit $AccessMask 0x80000)
    $textHasPermission = Test-AccessText $AccessList @("WRITE_DAC", "WRITE_OWNER", "ChangePermissions", "TakeOwnership")

    return $maskHasPermission -or $textHasPermission
}

function Test-FailedStatus {
    param([string]$Status)

    if ([string]::IsNullOrWhiteSpace($Status)) {
        return $false
    }

    if ($Status -eq "0x0") {
        return $false
    }

    return Test-AccessText $Status @("fail", "denied", "0xc000", "0x800")
}

function Get-FileAuditAction {
    param(
        [int]$EventId,
        [string]$AccessMask,
        [string]$AccessList,
        [string]$Status
    )

    switch ($EventId) {
        4660 { return "Deleted" }
        4670 { return "PermissionChanged" }
        4659 { return "DeleteRequested" }
        4656 { return "HandleRequested" }
        4663 {
            if (Test-DeleteAccess $AccessMask $AccessList) {
                return "DeleteRequested"
            }
            if (Test-PermissionAccess $AccessMask $AccessList) {
                return "PermissionChanged"
            }
            if (Test-WriteAccess $AccessMask $AccessList) {
                return "Modified"
            }
            return "Unknown"
        }
        5145 {
            if (Test-FailedStatus $Status) {
                return "FailedAccess"
            }
            if (Test-WriteAccess $AccessMask $AccessList) {
                return "Written"
            }
            return "Unknown"
        }
        default { return "Unknown" }
    }
}

function ConvertTo-FileAuditEvent {
    param([System.Diagnostics.Eventing.Reader.EventRecord]$Event)

    [xml]$xml = $Event.ToXml()
    $data = Get-EventDataMap $xml

    $eventId = [int]$Event.Id
    $accessMask = Get-MapValue $data @("AccessMask")
    $accessList = Get-MapValue $data @("AccessList", "Accesses")
    $status = Get-MapValue $data @("Status")
    $keywordsText = $null

    if ($Event.KeywordsDisplayNames) {
        $keywordsText = [string]::Join(", ", @($Event.KeywordsDisplayNames))
    } elseif ($xml.Event.System.Keywords) {
        $keywordsText = [string]$xml.Event.System.Keywords
    }

    $systemUserId = $null
    if ($xml.Event.System.Security) {
        $systemUserId = [string]$xml.Event.System.Security.UserID
    }

    $auditEvent = [ordered]@{
        server_name = $ServerName
        computer_name = [string]$Event.MachineName
        event_id = $eventId
        record_id = [int64]$Event.RecordId
        time_created = $Event.TimeCreated.ToUniversalTime().ToString("o")
        username = Get-MapValue $data @("SubjectUserName")
        domain_name = Get-MapValue $data @("SubjectDomainName")
        user_sid = Get-MapValue $data @("SubjectUserSid")
        source_ip = Get-MapValue $data @("IpAddress")
        source_port = Get-MapValue $data @("IpPort")
        object_name = Get-MapValue $data @("ObjectName")
        object_type = Get-MapValue $data @("ObjectType")
        share_name = Get-MapValue $data @("ShareName")
        relative_target_name = Get-MapValue $data @("RelativeTargetName")
        action = Get-FileAuditAction -EventId $eventId -AccessMask $accessMask -AccessList $accessList -Status $status
        access_mask = $accessMask
        access_list = $accessList
        handle_id = Get-MapValue $data @("HandleId")
        logon_id = Get-MapValue $data @("SubjectLogonId")
        transaction_id = Get-MapValue $data @("TransactionId")
        task_category = [string]$Event.TaskDisplayName
        keywords_text = $keywordsText
        process_name = Get-MapValue $data @("ProcessName")
        status = $status
        provider_name = [string]$Event.ProviderName
        system_task = [string]$Event.Task
        system_keywords = [string]$Event.Keywords
        system_user_id = $systemUserId
        process_id = Get-MapValue $data @("ProcessId")
    }

    return $auditEvent
}

function Send-FileAuditBatch {
    param([array]$Batch)

    $headers = @{
        Authorization = "Bearer $ApiToken"
    }

    $json = $Batch | ConvertTo-Json -Depth 8

    return Invoke-RestMethod `
        -Uri $ApiUrl `
        -Method Post `
        -ContentType "application/json" `
        -Headers $headers `
        -Body $json `
        -TimeoutSec 60
}

function Test-IgnoredTmpPath {
    param([System.Collections.IDictionary]$AuditEvent)

    foreach ($field in @("object_name", "relative_target_name")) {
        if ($AuditEvent.Contains($field) -and -not [string]::IsNullOrWhiteSpace([string]$AuditEvent[$field])) {
            if ([string]$AuditEvent[$field] -match '(?i)\.tmp$') {
                return $true
            }
        }
    }

    return $false
}

try {
    $stateDir = Split-Path -Parent $StatePath
    if (-not (Test-Path -LiteralPath $stateDir)) {
        New-Item -ItemType Directory -Path $stateDir -Force | Out-Null
    }

    $lastRecordId = 0
    if (Test-Path -LiteralPath $StatePath) {
        $state = Get-Content -LiteralPath $StatePath -Raw | ConvertFrom-Json
        if ($state.last_record_id) {
            $lastRecordId = [int64]$state.last_record_id
        }
    }

    Write-CollectorLog "Start. last_record_id=$lastRecordId"
    Write-CollectorLog "Config path=$ConfigPath"
    Write-CollectorLog "State path=$StatePath"
    Write-CollectorLog "Event IDs=$($EventIds -join ',')"
    Write-CollectorLog "MaxEventsPerRun=$MaxEventsPerRun BatchSize=$BatchSize"

    $filter = @{
        LogName = "Security"
        Id = $EventIds
    }

    if ($lastRecordId -le 0) {
        $filter.StartTime = (Get-Date).AddMinutes(-1 * [int]$FirstRunLookbackMinutes)
        Write-CollectorLog "No state found. First run is limited to events from the last $FirstRunLookbackMinutes minutes. StartTime=$($filter.StartTime.ToString('o'))"
    }

    try {
        $rawEvents = @(Get-WinEvent -FilterHashtable $filter -MaxEvents $MaxEventsPerRun -ErrorAction Stop)
    } catch {
        if ($_.FullyQualifiedErrorId -like "NoMatchingEventsFound*") {
            $rawEvents = @()
        } else {
            throw
        }
    }

    Write-CollectorLog "Raw events returned by Get-WinEvent=$($rawEvents.Count)"

    $events = @($rawEvents |
        Where-Object { $_.RecordId -gt $lastRecordId } |
        Sort-Object RecordId)

    Write-CollectorLog "Events found=$($events.Count)"
    if ($events.Count -gt 0) {
        Write-CollectorLog "RecordId range=$($events[0].RecordId)-$($events[-1].RecordId)"
    }

    if ($events.Count -eq 0) {
        Write-CollectorLog "No events to post."
        exit 0
    }

    $converted = @()
    $ignoredTmp = 0
    foreach ($event in $events) {
        $auditEvent = ConvertTo-FileAuditEvent -Event $event
        if (Test-IgnoredTmpPath -AuditEvent $auditEvent) {
            $ignoredTmp++
            continue
        }

        $converted += $auditEvent
    }

    Write-CollectorLog "Ignored .tmp events=$ignoredTmp"

    if ($converted.Count -eq 0) {
        $newLastRecordId = [int64]($events[-1].RecordId)
        $newState = @{
            last_record_id = $newLastRecordId
            updated_at = (Get-Date).ToUniversalTime().ToString("o")
        }

        $newState | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath $StatePath -Encoding UTF8
        Write-CollectorLog "No postable events after filtering."
        Write-CollectorLog "Saved last_record_id=$newLastRecordId"
        exit 0
    }

    $posted = 0
    for ($i = 0; $i -lt $converted.Count; $i += $BatchSize) {
        $end = [Math]::Min($i + $BatchSize - 1, $converted.Count - 1)
        $batch = @($converted[$i..$end])
        $response = Send-FileAuditBatch -Batch $batch

        Write-CollectorLog ("API response: " + ($response | ConvertTo-Json -Compress -Depth 5))

        if (-not $response.ok) {
            throw "API did not accept batch starting at index $i"
        }

        $posted += $batch.Count
    }

    $newLastRecordId = [int64]($events[-1].RecordId)
    $newState = @{
        last_record_id = $newLastRecordId
        updated_at = (Get-Date).ToUniversalTime().ToString("o")
    }

    $newState | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath $StatePath -Encoding UTF8

    Write-CollectorLog "Posted=$posted"
    Write-CollectorLog "Saved last_record_id=$newLastRecordId"
} catch {
    Write-CollectorLog ("ERROR: " + $_.Exception.Message)
    throw
}

# For internal testing with self-signed HTTPS certificates, prefer installing a trusted
# certificate on the server. As a temporary last resort, some environments add a custom
# certificate validation callback before Invoke-RestMethod. That is intentionally not
# enabled here because it weakens transport security.
