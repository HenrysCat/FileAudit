Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ConfigPath = Join-Path $ScriptDir "collector-config.ps1"
$DefaultLogPath = "C:\ProgramData\FileAudit\dns-collector.log"

function Write-DnsCollectorLog {
    param([string]$Message)

    $line = "{0} {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    $directory = Split-Path -Parent $DnsLogPathForCollector
    if (-not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }
    Add-Content -LiteralPath $DnsLogPathForCollector -Value $line
}

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    $message = "Missing collector config: $ConfigPath. Copy collector-config.example.ps1 to collector-config.ps1 and edit it."
    $DnsLogPathForCollector = $DefaultLogPath
    Write-DnsCollectorLog $message
    Write-Error $message
    exit 1
}

. $ConfigPath

if (-not (Get-Variable -Name DnsLogPathForCollector -Scope Script -ErrorAction SilentlyContinue)) { $DnsLogPathForCollector = $DefaultLogPath }
if (-not (Get-Variable -Name DnsBatchSize -Scope Script -ErrorAction SilentlyContinue)) { $DnsBatchSize = 100 }
if (-not (Get-Variable -Name DnsApiUrl -Scope Script -ErrorAction SilentlyContinue)) { $DnsApiUrl = $ApiUrl -replace '/api/ingest\.php$', '/api/dns-ingest.php' }
if (-not (Get-Variable -Name DnsStatePath -Scope Script -ErrorAction SilentlyContinue)) { $DnsStatePath = "C:\ProgramData\FileAudit\dns-state.json" }
if (-not (Get-Variable -Name DnsLogPath -Scope Script -ErrorAction SilentlyContinue)) { throw "DnsLogPath is required in $ConfigPath" }
if (-not (Get-Variable -Name ServerName -Scope Script -ErrorAction SilentlyContinue)) { $ServerName = $env:COMPUTERNAME }

function Convert-DnsName {
    param([string]$Name)

    # Windows DNS debug logs encode labels as (3)www(7)example(3)com(0).
    $decoded = $Name -replace '\(\d+\)', '.'
    return $decoded.Trim().Trim('.')
}

function ConvertFrom-DnsDebugLine {
    param([string]$Line)

    if ($Line -notmatch '^(?<time>\d{1,2}/\d{1,2}/\d{4}\s+\d{1,2}:\d{2}:\d{2}(?:\s+(?:AM|PM))?)') { return $null }
    $timeText = $Matches.time
    if ($Line -notmatch '\b(?:UDP|TCP)\s+Rcv\s+(?<client_ip>(?:\d{1,3}\.){3}\d{1,3}|[0-9A-Fa-f:]+)') { return $null }
    $clientIp = $Matches.client_ip
    if ($Line -notmatch '\]\s+(?<query_type>[A-Za-z0-9]+)\s+(?<query_name>.+?)\s*$') { return $null }
    $queryType = $Matches.query_type
    $queryName = Convert-DnsName $Matches.query_name
    if ([string]::IsNullOrWhiteSpace($queryName)) { return $null }

    $responseCode = $null
    if ($Line -match '\b(?<response_code>NOERROR|NXDOMAIN|SERVFAIL|REFUSED|FORMERR|NOTIMP)\b') { $responseCode = $Matches.response_code }

    try {
        if ($timeText -match '(?i)\s(?:AM|PM)$') {
            $timeCreated = [datetime]::Parse($timeText, [Globalization.CultureInfo]::InvariantCulture).ToUniversalTime().ToString('o')
        } else {
            $timeCreated = [datetime]::ParseExact($timeText, 'dd/MM/yyyy HH:mm:ss', [Globalization.CultureInfo]::InvariantCulture).ToUniversalTime().ToString('o')
        }
    } catch { return $null }
    $hashSource = "$ServerName`n$Line"
    $hashBytes = [Text.Encoding]::UTF8.GetBytes($hashSource)
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $entryHash = -join ($sha.ComputeHash($hashBytes) | ForEach-Object { $_.ToString('x2') }) } finally { $sha.Dispose() }

    return [ordered]@{
        dns_server = $ServerName
        time_created = $timeCreated
        client_ip = $clientIp
        query_name = $queryName
        query_type = $queryType
        response_code = $responseCode
        entry_hash = $entryHash
        raw_line = $Line
    }
}

function Send-DnsBatch {
    param([array]$Batch)
    return Invoke-RestMethod -Uri $DnsApiUrl -Method Post -ContentType 'application/json' -Headers @{ Authorization = "Bearer $ApiToken" } -Body ($Batch | ConvertTo-Json -Depth 4) -TimeoutSec 60
}

try {
    if (-not (Test-Path -LiteralPath $DnsLogPath)) { throw "DNS log was not found: $DnsLogPath" }
    $stateDirectory = Split-Path -Parent $DnsStatePath
    if (-not (Test-Path -LiteralPath $stateDirectory)) { New-Item -ItemType Directory -Path $stateDirectory -Force | Out-Null }
    $lastPosition = [int64]0
    if (Test-Path -LiteralPath $DnsStatePath) {
        $state = Get-Content -LiteralPath $DnsStatePath -Raw | ConvertFrom-Json
        if ($null -ne $state.position) { $lastPosition = [int64]$state.position }
    }

    $stream = [IO.File]::Open($DnsLogPath, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite)
    try {
        if ($lastPosition -gt $stream.Length) { Write-DnsCollectorLog 'DNS log was rotated or truncated; resetting position.'; $lastPosition = 0 }
        [void]$stream.Seek($lastPosition, [IO.SeekOrigin]::Begin)
        $reader = New-Object IO.StreamReader($stream, [Text.Encoding]::UTF8, $true, 4096, $true)
        try {
            $queries = @()
            $ignored = 0
            while (($line = $reader.ReadLine()) -ne $null) {
                $query = ConvertFrom-DnsDebugLine $line
                if ($null -eq $query) { $ignored++; continue }
                $queries += $query
            }
            $newPosition = $stream.Position
        } finally { $reader.Dispose() }
    } finally { $stream.Dispose() }

    Write-DnsCollectorLog "Read from position=$lastPosition. Parsed=$($queries.Count) Ignored=$ignored"
    for ($i = 0; $i -lt $queries.Count; $i += $DnsBatchSize) {
        $end = [Math]::Min($i + $DnsBatchSize - 1, $queries.Count - 1)
        $response = Send-DnsBatch @($queries[$i..$end])
        Write-DnsCollectorLog ("API response: " + ($response | ConvertTo-Json -Compress))
        if (-not $response.ok) { throw "API did not accept DNS batch starting at index $i" }
    }

    @{ position = $newPosition; updated_at = (Get-Date).ToUniversalTime().ToString('o') } | ConvertTo-Json | Set-Content -LiteralPath $DnsStatePath -Encoding UTF8
    Write-DnsCollectorLog "Saved position=$newPosition"
} catch {
    Write-DnsCollectorLog ("ERROR: " + $_.Exception.Message)
    throw
}
