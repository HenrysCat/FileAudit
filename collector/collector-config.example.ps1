$ApiUrl = "https://fileaudit.local/api/ingest.php"
$ApiToken = "CHANGE_ME"
$ServerName = $env:COMPUTERNAME
$EventIds = @(4656,4659,4660,4663,4670,5145)
$BatchSize = 100
$MaxEventsPerRun = 2000
$FirstRunLookbackMinutes = 30
$StatePath = "C:\ProgramData\FileAudit\state.json"
$LogPath = "C:\ProgramData\FileAudit\collector.log"

# DNS debug-log collector settings. This collector uses the same API token and config file.
$DnsApiUrl = "https://fileaudit.local/api/dns-ingest.php"
$DnsLogPath = "C:\dnslog\dnslog.txt"
$DnsStatePath = "C:\ProgramData\FileAudit\dns-state.json"
$DnsLogPathForCollector = "C:\ProgramData\FileAudit\dns-collector.log"
$DnsBatchSize = 100
