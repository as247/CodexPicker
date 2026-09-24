<#
CodexPicker Setup -- dynamic Codex configuration manager (Windows)

Usage:
  .\codex-setup.ps1 <config-id>
  .\codex-setup.ps1 <config-id> -ApiEndpoint "http://codexpicker.test/api/v1"

API:
  GET {ApiEndpoint}/config/{config-id}
  GET {ApiEndpoint}/config/{config-id}/models

The config response is expected to contain:
  {
    "provider": {
      "name": "OpenRouter",
      "api": "https://openrouter.ai/api/v1"
    },
    "reasoning_efforts": []
  }
#>
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string]$ConfigId,

    [Parameter(Mandatory = $false)]
    [string]$ApiEndpoint = 'http://codexpicker.test/api/v1'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$SCRIPT_VERSION = '2.0.0'
$BACKUP_DIRNAME = 'backup-codexpicker'
$CATALOG_FILENAME = 'codex_picker_models.json'
$ABORT_SENTINEL = '__CODEXPICKER_SETUP_ABORT__'

function Write-Ok {
    param([string]$Message)
    Write-Host '[OK] ' -ForegroundColor Green -NoNewline
    Write-Host $Message
}
function Write-Warn2 {
    param([string]$Message)
    Write-Host '[!]  ' -ForegroundColor Yellow -NoNewline
    Write-Host $Message
}
function Write-Head {
    param([string]$Message)
    Write-Host ''
    Write-Host $Message -ForegroundColor White
}
function Write-Dim {
    param([string]$Message)
    Write-Host $Message -ForegroundColor DarkGray
}
function Die {
    param([string]$Message)
    Write-Host ''
    Write-Host "[X] $Message" -ForegroundColor Red
    throw $ABORT_SENTINEL
}

# ---------------------------------------------------------------- paths

$CodexHomeDir = if ($env:CODEX_HOME) { $env:CODEX_HOME } else { Join-Path $HOME '.codex' }
$ConfigPath   = Join-Path $CodexHomeDir 'config.toml'
$ModelsPath   = Join-Path $CodexHomeDir $CATALOG_FILENAME
$BackupDir    = Join-Path $CodexHomeDir $BACKUP_DIRNAME
$BackupConfig = Join-Path $BackupDir 'config.toml'
$Manifest     = Join-Path $BackupDir 'manifest.txt'
$CatalogValue = $ModelsPath -replace '\\', '/'

# ---------------------------------------------------------------- API helpers

function Normalize-ApiEndpoint {
    param([string]$Value)
    $result = $Value.Trim()
    if ([string]::IsNullOrWhiteSpace($result)) {
        throw 'API endpoint cannot be empty.'
    }
    try { $uri = [Uri]$result } catch { throw "Invalid API endpoint: $result" }
    if ($uri.Scheme -notin @('http', 'https')) {
        throw "API endpoint must use http or https: $result"
    }
    return $result.TrimEnd('/')
}

function Get-RemoteJson {
    param(
        [Parameter(Mandatory = $true)][string]$Url,
        [Parameter(Mandatory = $true)][string]$Description
    )
    try {
        Write-Dim "GET $Url"
        $response = Invoke-RestMethod -Uri $Url -Method Get -UseBasicParsing -ErrorAction Stop
        if ($null -eq $response) { throw 'The server returned an empty response.' }
        return $response
    } catch {
        throw "Failed to read $Description.`nURL: $Url`n$($_.Exception.Message)"
    }
}

function Get-RemoteJsonText {
    param(
        [Parameter(Mandatory = $true)][string]$Url,
        [Parameter(Mandatory = $true)][string]$Description
    )
    try {
        Write-Dim "GET $Url"
        $response = Invoke-WebRequest -Uri $Url -Method Get -UseBasicParsing -ErrorAction Stop
        $content = [string]$response.Content
        if ([string]::IsNullOrWhiteSpace($content)) { throw 'The server returned an empty response.' }
        $null = $content | ConvertFrom-Json
        return $content
    } catch {
        throw "Failed to read $Description.`nURL: $Url`n$($_.Exception.Message)"
    }
}

function Convert-ToProviderId {
    param([Parameter(Mandatory = $true)][string]$Name)

    # Convert display name to the provider key used by [model_providers.<key>].
    $id = $Name.Trim().ToLowerInvariant()
    $id = [regex]::Replace($id, '[^a-z0-9_-]+', '-')
    $id = [regex]::Replace($id, '-{2,}', '-')
    $id = $id.Trim('-')

    if ([string]::IsNullOrWhiteSpace($id)) {
        throw "Provider name '$Name' cannot be converted to a provider key."
    }
    return $id
}

function Convert-ToTomlBasicString {
    param([AllowEmptyString()][string]$Value)
    if ($null -eq $Value) { $Value = '' }
    return '"' + ($Value -replace '\\', '\\' -replace '"', '\"') + '"'
}

function Convert-ToTomlArray {
    param([AllowEmptyString()][string[]]$Values)
    if ($null -eq $Values -or $Values.Count -eq 0) { return '[]' }
    $items = foreach ($value in $Values) { Convert-ToTomlBasicString $value }
    return '[ ' + ($items -join ', ') + ' ]'
}

# ---------------------------------------------------------------- backup / restore

function Ensure-Backup {
    # A backup is a one-time snapshot of the pre-CodexPicker config. Never
    # replace it or create another snapshot on later runs.
    if (Test-Path -LiteralPath $BackupDir) {
        Write-Dim "Existing backup preserved: $BackupConfig"
        return
    }

    New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null

    $originalConfigExisted = Test-Path -LiteralPath $ConfigPath

    if ($originalConfigExisted) {
        Copy-Item -LiteralPath $ConfigPath -Destination $BackupConfig
        Write-Ok "Backed up config.toml -> $BackupConfig"
    } else {
        Write-Warn2 'config.toml not found; a new file will be created'
    }

    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    $manifestLines = @(
        "script_version=$SCRIPT_VERSION"
        "config_id=$ConfigId"
        "api_endpoint=$ApiEndpoint"
        "installed_at=$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
        "original_config_existed=$(if ($originalConfigExisted) { 1 } else { 0 })"
    )
    [System.IO.File]::WriteAllText($Manifest, (($manifestLines -join "`n") + "`n"), $utf8NoBom)
}

function Invoke-CodexPickerRestore {
    $ErrorActionPreference = 'Stop'
    Set-StrictMode -Version Latest

    Write-Head 'Restore the default Codex configuration'

    if (-not (Test-Path -LiteralPath $BackupDir)) {
        Die "Backup directory not found:`n  $BackupDir`nNothing to restore."
    }

    $hadConfig = $true
    $manifestRaw = ''

    if (Test-Path -LiteralPath $Manifest) {
        $manifestRaw = Get-Content -LiteralPath $Manifest -Raw -Encoding UTF8
        if ($manifestRaw -match '(?m)^original_config_existed=0\s*$') { $hadConfig = $false }
    }

    if ($hadConfig -and -not (Test-Path -LiteralPath $BackupConfig)) {
        Die "Backup is corrupted: missing $BackupConfig"
    }
    Write-Host ''
    Write-Host 'The following actions will be performed:'
    if ($hadConfig) {
        Write-Host "  1. Restore config.toml from $BackupConfig"
    } else {
        Write-Host "  1. Delete $ConfigPath"
        Write-Dim '     (config.toml did not exist before installation)'
    }
    Write-Host "  2. Delete $ModelsPath"
    Write-Host "  3. Delete the backup directory $BackupDir"
    Write-Host ''

    $answer = Read-Host 'Restore now? Type y to continue, anything else to cancel'
    if ($answer -notin @('y', 'Y', 'yes', 'YES')) {
        Write-Host 'Cancelled; nothing was modified.'
        return
    }

    if ($hadConfig) {
        Copy-Item -LiteralPath $BackupConfig -Destination $ConfigPath -Force
        Write-Ok 'config.toml restored'
    } elseif (Test-Path -LiteralPath $ConfigPath) {
        Remove-Item -LiteralPath $ConfigPath -Force
        Write-Ok 'config.toml deleted'
    }

    if (Test-Path -LiteralPath $ModelsPath) {
        Remove-Item -LiteralPath $ModelsPath -Force
        Write-Ok "$CATALOG_FILENAME deleted"
    }

    Remove-Item -LiteralPath $BackupDir -Recurse -Force
    Write-Ok 'Backup directory cleaned up'

    Write-Host ''
    Write-Ok 'Restore complete; the Codex configuration is back to its pre-install state.'
    Write-Host ''
    Write-Warn2 'Fully quit the ChatGPT desktop app / Codex client and reopen it for the restore to take effect'
}

function Offer-CodexPickerRestore {
    if (-not (Test-Path -LiteralPath $BackupDir)) { return $false }

    Write-Host ''
    Write-Host 'A CodexPicker backup exists. You can restore it before continuing.'
    Write-Host "  Backup: $BackupDir"
    $answer = Read-Host 'Restore the pre-CodexPicker configuration now? Type y to restore, anything else to continue'
    if ($answer -in @('y', 'Y', 'yes', 'YES')) {
        Invoke-CodexPickerRestore
        return $true
    }
    return $false
}

# ---------------------------------------------------------------- TOML scanner

# Tracks bracket depth and multi-line strings so we can tell which lines are real
# section headers
$script:Depth = 0
$script:MlState = ''

function Update-ScanState {
    param([string]$Line)
    $n = $Line.Length
    $i = 0
    $instr = ''
    while ($i -lt $n) {
        $c = $Line[$i]
        $c3 = if ($i + 3 -le $n) { $Line.Substring($i, 3) } else { '' }

        if ($script:MlState) {
            if ($script:MlState -eq 'basic' -and $c3 -eq '"""') { $script:MlState = ''; $i += 3; continue }
            if ($script:MlState -eq 'literal' -and $c3 -eq "'''") { $script:MlState = ''; $i += 3; continue }
            if ($script:MlState -eq 'basic' -and $c -eq '\') { $i += 2; continue }
            $i++; continue
        }
        if ($instr) {
            if ($instr -eq 'basic') {
                if ($c -eq '\') { $i += 2; continue }
                if ($c -eq '"') { $instr = '' }
            } else {
                if ($c -eq "'") { $instr = '' }
            }
            $i++; continue
        }
        if ($c3 -eq '"""') { $script:MlState = 'basic';   $i += 3; continue }
        if ($c3 -eq "'''") { $script:MlState = 'literal'; $i += 3; continue }

        switch ($c) {
            '#' { return }
            '"' { $instr = 'basic' }
            "'" { $instr = 'literal' }
            '[' { $script:Depth++ }
            ']' { if ($script:Depth -gt 0) { $script:Depth-- } }
        }
        $i++
    }
}

function Get-TomlKey {
    param([string]$Line)
    $l = $Line.Trim()
    if ($l -eq '' -or $l.StartsWith('#')) { return '' }
    $eq = $l.IndexOf('=')
    if ($eq -lt 1) { return '' }
    $k = $l.Substring(0, $eq).Trim()
    return $k.Trim('"').Trim("'")
}

function Get-TomlValue {
    param([string]$Line)
    $l = $Line.Trim()
    $eq = $l.IndexOf('=')
    if ($eq -lt 0) { return '' }
    return $l.Substring($eq + 1).Trim()
}

# ---------------------------------------------------------------- config.toml surgery

function Format-Val {
    param([string]$Value)
    if ($null -eq $Value) { return '' }
    if ($Value.Length -gt 58) { return $Value.Substring(0, 58) + '...' }
    return $Value
}

$TARGET_KEYS = @(
    'model',
    'model_provider',
    'model_reasoning_effort',
    'model_catalog_json'
)

$PROVIDER_KEYS = @('name', 'base_url', 'wire_api', 'experimental_bearer_token')

function Get-TargetValue {
    param([string]$Key)
    switch ($Key) {
        'model_provider'         { return (Convert-ToTomlBasicString $script:ProviderId) }
        'model'                  { return (Convert-ToTomlBasicString $script:ModelSlug) }
        'model_reasoning_effort' { return (Convert-ToTomlBasicString $script:ModelReasoningEffort) }
        'model_catalog_json'     { return (Convert-ToTomlBasicString $CatalogValue) }
    }
    return '""'
}

function Get-ProviderTargetValue {
    param([string]$Key)
    switch ($Key) {
        'name'                       { return (Convert-ToTomlBasicString $script:ProviderName) }
        'base_url'                   { return (Convert-ToTomlBasicString $script:ProviderApi) }
        'wire_api'                  { return '"responses"' }
        'experimental_bearer_token' { return (Convert-ToTomlBasicString $script:ApiKey) }
    }
    return '""'
}

function Consume-TomlAssignment {
    param(
        [System.Collections.Generic.List[string]]$Lines,
        [ref]$Index
    )
    Update-ScanState $Lines[$Index.Value]
    $Index.Value++
    while (($script:MlState -or $script:Depth -ne 0) -and $Index.Value -lt $Lines.Count) {
        Update-ScanState $Lines[$Index.Value]
        $Index.Value++
    }
}

function Update-DesktopReasoningEfforts {
    param([System.Collections.Generic.List[string]]$Lines)

    $replacement = 'enabled-reasoning-efforts = ' + (Convert-ToTomlArray $script:ReasoningEfforts)
    $out = New-Object System.Collections.Generic.List[string]
    $script:Depth = 0
    $script:MlState = ''

    $i = 0
    $inDesktop = $false
    $desktopHeaderIndex = -1
    $seen = $false

    while ($i -lt $Lines.Count) {
        $line = $Lines[$i]
        $trimmed = $line.Trim()
        $isHeader = (-not $script:MlState) -and ($script:Depth -eq 0) -and $trimmed.StartsWith('[')

        if ($isHeader) {
            $hdr = $trimmed
            $close = $hdr.IndexOf(']')
            if ($close -gt 0) { $hdr = $hdr.Substring(0, $close + 1) }
            $hdr = $hdr.TrimStart('[').TrimEnd(']').Trim().Replace('"', '').Replace("'", '')

            if ($hdr -eq 'desktop') {
                $inDesktop = $true
                $desktopHeaderIndex = $out.Count
                $out.Add($line)
                Update-ScanState $line
                $i++
                continue
            }

            $inDesktop = $false
            $out.Add($line)
            Update-ScanState $line
            $i++
            continue
        }

        if ($inDesktop -and (Get-TomlKey $line) -eq 'enabled-reasoning-efforts') {
            Consume-TomlAssignment -Lines $Lines -Index ([ref]$i)
            $out.Add($replacement)
            $seen = $true
            continue
        }

        $out.Add($line)
        Update-ScanState $line
        $i++
    }

    if (-not $seen) {
        if ($desktopHeaderIndex -ge 0) {
            $insertAt = $out.Count
            for ($j = $desktopHeaderIndex + 1; $j -lt $out.Count; $j++) {
                if ($out[$j].Trim().StartsWith('[')) {
                    $insertAt = $j
                    break
                }
            }
            $out.Insert($insertAt, $replacement)
        } else {
            if ($out.Count -gt 0 -and $out[$out.Count - 1] -ne '') { $out.Add('') }
            $out.Add('[desktop]')
            $out.Add($replacement)
        }
    }

    return $out
}

function Update-ConfigToml {
    param([string]$Path)

    $raw = ''
    if (Test-Path -LiteralPath $Path) {
        $raw = Get-Content -LiteralPath $Path -Raw -Encoding UTF8
        if ($null -eq $raw) { $raw = '' }
    }
    $raw = $raw -replace "`r`n", "`n"
    $raw = $raw.TrimEnd("`n")

    $Lines = New-Object System.Collections.Generic.List[string]
    if ($raw -ne '') {
        foreach ($line in ($raw -split "`n")) { $Lines.Add($line) }
    }

    $Out = New-Object System.Collections.Generic.List[string]
    $Report = New-Object System.Collections.Generic.List[string]
    $Seen = New-Object System.Collections.Generic.HashSet[string]

    $script:Depth = 0
    $script:MlState = ''
    $idx = 0
    $curSection = ''
    $skipSection = $false
    $insAt = 0

    while ($idx -lt $Lines.Count) {
        $line = $Lines[$idx]
        $trimmed = $line.Trim()
        $isHeader = (-not $script:MlState) -and ($script:Depth -eq 0) -and $trimmed.StartsWith('[')

        if ($isHeader) {
            $hdr = $trimmed
            $close = $hdr.IndexOf(']')
            if ($close -gt 0) { $hdr = $hdr.Substring(0, $close + 1) }
            $hdr = $hdr.TrimStart('[').TrimEnd(']').Trim().Replace('"', '').Replace("'", '')
            $curSection = $hdr
            $skipSection = $false

            if ($hdr -eq "model_providers.$($script:ProviderId)" -or
                $hdr -like "model_providers.$($script:ProviderId).*") {
                $skipSection = $true
                $Report.Add("Removed the old [$hdr] (it will be rewritten with the remote provider settings)")
            } elseif ($hdr -eq 'profiles' -or $hdr -like 'profiles.*') {
                $skipSection = $true
                $Report.Add("Removed [$hdr] because profiles can mask model / model_provider settings")
            }

            Update-ScanState $line
            $idx++
            if (-not $skipSection) { $Out.Add($line) }
            continue
        }

        if ($curSection -and $skipSection) {
            Update-ScanState $line
            $idx++
            continue
        }

        $key = Get-TomlKey $line

        if ($key -and $TARGET_KEYS -contains $key) {
            $oldValue = Get-TomlValue $trimmed
            $newValue = Get-TargetValue $key
            Consume-TomlAssignment -Lines $Lines -Index ([ref]$idx)
            $Out.Add("$key = $newValue")
            $insAt = $Out.Count
            [void]$Seen.Add($key)
            if ($oldValue -ne $newValue) {
                $Report.Add("Rewrote $key`: $(Format-Val $oldValue) -> $newValue")
            }
            continue
        }

        $Out.Add($line)
        if ($key) { $insAt = $Out.Count }
        Update-ScanState $line
        $idx++
    }

    $missing = @($TARGET_KEYS | Where-Object { -not $Seen.Contains($_) })
    $final = New-Object System.Collections.Generic.List[string]

    for ($i = 0; $i -lt $Out.Count; $i++) {
        if ($i -eq $insAt -and $missing.Count -gt 0) {
            foreach ($key in $missing) { $final.Add("$key = $(Get-TargetValue $key)") }
            $missing = @()
            if ($Out[$i].Trim().StartsWith('[')) { $final.Add('') }
        }
        $final.Add($Out[$i])
    }
    foreach ($key in $missing) { $final.Add("$key = $(Get-TargetValue $key)") }

    $desktopUpdated = Update-DesktopReasoningEfforts -Lines $final
    $final = New-Object System.Collections.Generic.List[string]
    foreach ($line in $desktopUpdated) { $final.Add($line) }

    if ($final.Count -gt 0 -and $final[$final.Count - 1] -ne '') { $final.Add('') }
    $final.Add("[model_providers.$($script:ProviderId)]")
    $final.Add("name = $(Convert-ToTomlBasicString $script:ProviderName)")
    $final.Add("base_url = $(Convert-ToTomlBasicString $script:ProviderApi)")
    $final.Add('wire_api = "responses"')
    $final.Add("experimental_bearer_token = $(Convert-ToTomlBasicString $script:ApiKey)")

    return @{ Lines = $final; Report = $Report }
}

function Update-ConfigTomlOnlyTargetedFields {
    param([string]$Path)

    $raw = ''
    if (Test-Path -LiteralPath $Path) {
        $raw = Get-Content -LiteralPath $Path -Raw -Encoding UTF8
        if ($null -eq $raw) { $raw = '' }
    }
    $raw = ($raw -replace "`r`n", "`n").TrimEnd("`n")

    $Lines = New-Object System.Collections.Generic.List[string]
    if ($raw -ne '') {
        foreach ($line in ($raw -split "`n")) { $Lines.Add($line) }
    }

    $Out = New-Object System.Collections.Generic.List[string]
    $Report = New-Object System.Collections.Generic.List[string]
    $SeenTop = New-Object System.Collections.Generic.HashSet[string]
    $SeenProvider = New-Object System.Collections.Generic.HashSet[string]
    $providerSection = "model_providers.$($script:ProviderId)"
    $currentSection = ''
    $providerFound = $false
    $topInserted = $false
    $providerInserted = $false
    $script:Depth = 0
    $script:MlState = ''

    $insertTop = {
        foreach ($key in $TARGET_KEYS) {
            if (-not $SeenTop.Contains($key)) {
                $Out.Add("$key = $(Get-TargetValue $key)")
            }
        }
    }
    $insertProvider = {
        foreach ($key in $PROVIDER_KEYS) {
            if (-not $SeenProvider.Contains($key)) {
                $Out.Add("$key = $(Get-ProviderTargetValue $key)")
            }
        }
    }

    for ($idx = 0; $idx -lt $Lines.Count;) {
        $line = $Lines[$idx]
        $trimmed = $line.Trim()
        $isHeader = (-not $script:MlState) -and ($script:Depth -eq 0) -and $trimmed.StartsWith('[')

        if ($isHeader) {
            if ($providerFound -and -not $providerInserted) { & $insertProvider; $providerInserted = $true }
            if (-not $topInserted) { & $insertTop; $topInserted = $true }

            $hdr = $trimmed
            $close = $hdr.IndexOf(']')
            if ($close -gt 0) { $hdr = $hdr.Substring(0, $close + 1) }
            $currentSection = $hdr.TrimStart('[').TrimEnd(']').Trim().Replace('"', '').Replace("'", '')
            if ($currentSection -eq $providerSection) { $providerFound = $true }

            $Out.Add($line)
            Update-ScanState $line
            $idx++
            continue
        }

        $key = Get-TomlKey $line
        if ($currentSection -eq '' -and $key -and $TARGET_KEYS -contains $key) {
            $oldValue = Get-TomlValue $trimmed
            $newValue = Get-TargetValue $key
            Consume-TomlAssignment -Lines $Lines -Index ([ref]$idx)
            $Out.Add("$key = $newValue")
            [void]$SeenTop.Add($key)
            if ($oldValue -ne $newValue) { $Report.Add("Rewrote $key`: $(Format-Val $oldValue) -> $newValue") }
            continue
        }

        if ($currentSection -eq $providerSection -and $key -and $PROVIDER_KEYS -contains $key) {
            $oldValue = Get-TomlValue $trimmed
            $newValue = Get-ProviderTargetValue $key
            Consume-TomlAssignment -Lines $Lines -Index ([ref]$idx)
            $Out.Add("$key = $newValue")
            [void]$SeenProvider.Add($key)
            if ($oldValue -ne $newValue) { $Report.Add("Rewrote [$providerSection].$key`: $(Format-Val $oldValue) -> $newValue") }
            continue
        }

        $Out.Add($line)
        Update-ScanState $line
        $idx++
    }

    if ($providerFound -and -not $providerInserted) { & $insertProvider; $providerInserted = $true }
    if (-not $topInserted) { & $insertTop; $topInserted = $true }
    if (-not $providerFound) {
        if ($Out.Count -gt 0 -and $Out[$Out.Count - 1] -ne '') { $Out.Add('') }
        $Out.Add("[$providerSection]")
        foreach ($key in $PROVIDER_KEYS) { $Out.Add("$key = $(Get-ProviderTargetValue $key)") }
    }

    # The installer uses this targeted updater, so apply the desktop setting
    # here as well. Previously only the unused full-rewrite path did this.
    $desktopUpdated = Update-DesktopReasoningEfforts -Lines $Out
    $Out = New-Object System.Collections.Generic.List[string]
    foreach ($line in $desktopUpdated) { $Out.Add($line) }

    if ($Out.Count -gt 0 -and $Out[$Out.Count - 1] -ne '') { $Out.Add('') }
    return @{ Lines = $Out; Report = $Report }
}

function Assert-NoDuplicateTopLevelKeys {
    param([System.Collections.Generic.List[string]]$Lines)

    $seen = @{}
    $depth = 0
    $ml = ''
    $inLeading = $true
    $script:Depth = 0
    $script:MlState = ''

    foreach ($line in $Lines) {
        $trimmed = $line.Trim()
        if (-not $ml -and $depth -eq 0 -and $trimmed.StartsWith('[')) { $inLeading = $false }

        if ($inLeading) {
            $key = Get-TomlKey $line
            if ($key) {
                if ($seen.ContainsKey($key)) { throw "Generated config.toml has a duplicate top-level key: $key" }
                $seen[$key] = $true
            }
        }

        $script:MlState = $ml
        $script:Depth = $depth
        Update-ScanState $line
        $ml = $script:MlState
        $depth = $script:Depth
    }
}

# ---------------------------------------------------------------- install

function Invoke-CodexPickerInstall {
    $ErrorActionPreference = 'Stop'
    Set-StrictMode -Version Latest

    Write-Head "Configuring CodexPicker config id: $ConfigId"

    try {
        $script:ApiKey = (Read-Host 'Enter your provider API key').Trim()
    } catch {
        Die 'Cannot read API key input (non-interactive environment); exiting (no files were modified).'
    }

    if ([string]::IsNullOrWhiteSpace($script:ApiKey)) {
        Die 'API key cannot be empty.'
    }
    if ($script:ApiKey -match '"') {
        Die 'The API key must not contain double quotes.'
    }

    # Backup happens only after both remote GETs have succeeded and the key was entered.
    Ensure-Backup

    $tmpCatalog = "$ModelsPath.codexpicker-tmp"
    try {
        [System.IO.File]::WriteAllText($tmpCatalog, $script:CatalogJson, (New-Object System.Text.UTF8Encoding($false)))
        Move-Item -LiteralPath $tmpCatalog -Destination $ModelsPath -Force
    } catch {
        Remove-Item -LiteralPath $tmpCatalog -Force -ErrorAction SilentlyContinue
        Die "Failed to save the model catalog to $ModelsPath.`n$($_.Exception.Message)`nconfig.toml has not been modified."
    }
    Write-Ok "Model catalog saved unchanged: $ModelsPath"

    $result = Update-ConfigTomlOnlyTargetedFields -Path $ConfigPath
    $final = $result.Lines
    $report = $result.Report
    $TmpConfig = "$ConfigPath.codexpicker-tmp"
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)

    [System.IO.File]::WriteAllText($TmpConfig, (($final -join "`n") + "`n"), $utf8NoBom)

    try {
        Assert-NoDuplicateTopLevelKeys -Lines $final
    } catch {
        Remove-Item -LiteralPath $TmpConfig -Force -ErrorAction SilentlyContinue
        Die "Generated configuration validation failed; the original config.toml was not modified.`n$($_.Exception.Message)"
    }

    Move-Item -LiteralPath $TmpConfig -Destination $ConfigPath -Force

    $manifestLines = @(
        "script_version=$SCRIPT_VERSION"
        "config_id=$ConfigId"
        "api_endpoint=$ApiEndpoint"
        "installed_at=$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
        "provider_name=$($script:ProviderName)"
        "provider_id=$($script:ProviderId)"
        "provider_api=$($script:ProviderApi)"
        "catalog_path=$ModelsPath"
        "reasoning_efforts=$(($script:ReasoningEfforts -join ','))"
        '--- changes made to config.toml ---'
    ) + @($report)

    [System.IO.File]::WriteAllText($Manifest, (($manifestLines -join "`n") + "`n"), $utf8NoBom)

    Write-Ok "Updated: $ConfigPath"
    Write-Ok "Catalog saved: $ModelsPath"
    Write-Dim 'Model and reasoning effort were selected from the first catalog model.'
    Write-Ok "Provider: $($script:ProviderName) -> $($script:ProviderId)"
    Write-Ok "Provider API: $($script:ProviderApi)"
    Write-Ok "Reasoning efforts: $(if ($script:ReasoningEfforts.Count -gt 0) { $script:ReasoningEfforts -join ', ' } else { '(empty)' })"

    if ($report.Count -gt 0) {
        Write-Head "Changes made to your existing configuration ($($report.Count) total)"
        foreach ($item in $report) { Write-Host "  - $item" }
    }

    Write-Head 'Configuration written'
    Write-Host @"
  model                   = "$($script:ModelSlug)"
  model_provider         = "$($script:ProviderId)"
  model_reasoning_effort = "$($script:ModelReasoningEffort)"
  model_catalog_json     = "$CatalogValue"

  [model_providers.$($script:ProviderId)]
  name                   = "$($script:ProviderName)"
  base_url               = "$($script:ProviderApi)"
  wire_api               = "responses"
  experimental_bearer_token = "<provided API key>"

  [desktop]
  enabled-reasoning-efforts = $(Convert-ToTomlArray $script:ReasoningEfforts)
"@

    Write-Host ''
    Write-Ok 'Installation complete.'
    Write-Host ''
    Write-Warn2 'Fully quit the ChatGPT desktop app / Codex client and reopen it for the change to take effect.'
}

# ---------------------------------------------------------------- main

function Invoke-CodexPickerMain {
    $ErrorActionPreference = 'Stop'
    Set-StrictMode -Version Latest

    $script:ApiEndpoint = Normalize-ApiEndpoint $ApiEndpoint

    Write-Head "CodexPicker Setup v$SCRIPT_VERSION"
    Write-Dim "Config ID: $ConfigId"
    Write-Dim "Config API: $script:ApiEndpoint"
    Write-Dim "Codex directory: $CodexHomeDir"

    if (-not (Test-Path -LiteralPath $CodexHomeDir)) {
        Die @"
Codex configuration directory not found:
  $CodexHomeDir

Please run Codex / the ChatGPT desktop app / the Codex extension once so that
the directory is created, or set CODEX_HOME and try again.
"@
    }

    if (Offer-CodexPickerRestore) { return }

    # 1. Read remote config before modifying any local config.
    $encodedId = [Uri]::EscapeDataString($ConfigId)
    $configUrl = "$script:ApiEndpoint/config/$encodedId"
    $modelsUrl = "$script:ApiEndpoint/config/$encodedId/models"

    $remoteConfig = $null
    try {
        $remoteConfig = Get-RemoteJson -Url $configUrl -Description "remote config for id '$ConfigId'"
    } catch {
        Write-Host ''
        Write-Warn2 $_.Exception.Message

        if (Test-Path -LiteralPath $BackupDir) {
            [void](Offer-CodexPickerRestore)
            return
        }

        Die 'Remote config could not be read and no backup exists. Nothing was modified.'
    }

    # Validate provider config.
    try {
        if ($null -eq $remoteConfig.provider) { throw "Response is missing 'provider'." }
        if ([string]::IsNullOrWhiteSpace([string]$remoteConfig.provider.name)) {
            throw "Response is missing 'provider.name'."
        }
        if ([string]::IsNullOrWhiteSpace([string]$remoteConfig.provider.api)) {
            throw "Response is missing 'provider.api'."
        }

        $providerUri = [Uri][string]$remoteConfig.provider.api
        if ($providerUri.Scheme -notin @('http', 'https')) {
            throw "provider.api must use http or https."
        }

        $script:ProviderName = ([string]$remoteConfig.provider.name).Trim()
        $script:ProviderApi  = ([string]$remoteConfig.provider.api).TrimEnd('/')
        $script:ProviderId   = Convert-ToProviderId $script:ProviderName

        if ($null -eq $remoteConfig.reasoning_efforts) {
            $script:ReasoningEfforts = @()
        } else {
            $script:ReasoningEfforts = @(
                $remoteConfig.reasoning_efforts |
                    ForEach-Object { ([string]$_).Trim() } |
                    Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
            )
        }
    } catch {
        Die "The remote config was read, but its data is invalid.`n$($_.Exception.Message)"
    }

    Write-Ok "Remote config loaded: $($script:ProviderName) -> $($script:ProviderId)"

    # 2. Download the catalog and save it without changing its JSON content.
    try {
        $catalogJson = Get-RemoteJsonText -Url $modelsUrl -Description "model catalog for config id '$ConfigId'"
        $catalogObject = $catalogJson | ConvertFrom-Json
        if ($null -eq $catalogObject.models -or @($catalogObject.models).Count -eq 0) {
            throw "The model catalog does not contain any models."
        }
        $firstModel = @($catalogObject.models)[0]
        $script:ModelSlug = ([string]$firstModel.slug).Trim()
        if ([string]::IsNullOrWhiteSpace($script:ModelSlug)) {
            throw "The first catalog model is missing 'slug'."
        }
        $firstEffort = @($firstModel.supported_reasoning_levels) |
            ForEach-Object { ([string]$_.effort).Trim() } |
            Where-Object { -not [string]::IsNullOrWhiteSpace($_) } |
            Select-Object -First 1
        $script:ModelReasoningEffort = if ($firstEffort) { $firstEffort } else { 'none' }
        $script:CatalogJson = $catalogJson
    } catch {
        Die "Failed to download/validate the model catalog.`n$($_.Exception.Message)`nconfig.toml has not been modified."
    }

    # 3. API key -> backup -> config.
    Invoke-CodexPickerInstall
}

try {
    Invoke-CodexPickerMain
} catch {
    if ("$_" -ne $ABORT_SENTINEL) { throw }
    if ($PSCommandPath) { exit 1 }
}
