<#
  Genera los audios de la demo a partir de guion.json, con las voces de Windows.

  Uso (PowerShell 5.1, desde la raiz del repo):
      powershell -ExecutionPolicy Bypass -File database\demo\generar-audios.ps1

  - Voces "OneCore" (Laura, Pablo, Helena: espanol de Espana) por la API de
    WinRT; voces "Desktop" (Zira: ingles) por System.Speech.
  - Cada linea del guion se sintetiza a WAV; se juntan con una pausa corta y
    ffmpeg las deja en audio/<slug>.mp3 (mono, 64 kbps).
  Solo hace falta volver a correrlo si cambian los textos. Despues hay que
  correr `php artisan escribelo:demo-armar` para regenerar fixture.json.
#>
param(
    [string] $Only = ''
)

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$guion = Get-Content (Join-Path $here 'guion.json') -Raw -Encoding UTF8 | ConvertFrom-Json
$outDir = Join-Path $here 'audio'
New-Item -ItemType Directory -Force $outDir | Out-Null
$tmp = Join-Path ([IO.Path]::GetTempPath()) ('escribelo-demo-' + [guid]::NewGuid())
New-Item -ItemType Directory -Force $tmp | Out-Null

# --- WinRT (voces OneCore) ---------------------------------------------------
Add-Type -AssemblyName System.Runtime.WindowsRuntime
[void][Windows.Media.SpeechSynthesis.SpeechSynthesizer, Windows.Media.SpeechSynthesis, ContentType = WindowsRuntime]
$asTaskGeneric = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1'
} | Select-Object -First 1

function Wait-WinRt($operation, [Type] $resultType) {
    $task = $asTaskGeneric.MakeGenericMethod($resultType).Invoke($null, @($operation))
    $task.Wait(-1) | Out-Null
    return $task.Result
}

$oneCore = New-Object Windows.Media.SpeechSynthesis.SpeechSynthesizer
$oneCoreVoices = [Windows.Media.SpeechSynthesis.SpeechSynthesizer]::AllVoices

# --- System.Speech (voces Desktop) -------------------------------------------
Add-Type -AssemblyName System.Speech
$desktop = New-Object System.Speech.Synthesis.SpeechSynthesizer
$desktopVoices = $desktop.GetInstalledVoices() | ForEach-Object { $_.VoiceInfo.Name }

function Save-Line([string] $voice, [string] $text, [string] $wavPath) {
    $winrtVoice = $oneCoreVoices | Where-Object { $_.DisplayName -like "*$voice*" } | Select-Object -First 1
    if ($winrtVoice) {
        $oneCore.Voice = $winrtVoice
        $stream = Wait-WinRt ($oneCore.SynthesizeTextToStreamAsync($text)) ([Windows.Media.SpeechSynthesis.SpeechSynthesisStream])
        $netStream = [System.IO.WindowsRuntimeStreamExtensions]::AsStreamForRead($stream)
        $file = [IO.File]::Create($wavPath)
        try { $netStream.CopyTo($file) } finally { $file.Dispose(); $netStream.Dispose() }
        return
    }

    $desktopVoice = $desktopVoices | Where-Object { $_ -like "*$voice*" } | Select-Object -First 1
    if (-not $desktopVoice) { throw "No encuentro la voz '$voice'." }
    $desktop.SelectVoice($desktopVoice)
    $desktop.Rate = 0
    $desktop.SetOutputToWaveFile($wavPath)
    $desktop.Speak($text)
    $desktop.SetOutputToNull()
}

foreach ($audio in $guion.audios) {
    if ($Only -and $audio.slug -ne $Only) { continue }
    Write-Host "== $($audio.slug)"

    $parts = @()
    $i = 0
    foreach ($line in $audio.lines) {
        $wav = Join-Path $tmp ('{0}-{1:D2}.wav' -f $audio.slug, $i)
        Save-Line $line.voice $line.text $wav
        $parts += $wav
        $i++
    }

    # Normalizar todas las partes al mismo formato y unirlas con 0.6 s de pausa.
    $inputs = @()
    $filters = @()
    $n = 0
    foreach ($p in $parts) {
        $inputs += @('-i', $p)
        $filters += "[$n`:a]aresample=22050,aformat=channel_layouts=mono,apad=pad_dur=0.6[a$n]"
        $n++
    }
    $concatInputs = (0..($n - 1) | ForEach-Object { "[a$_]" }) -join ''
    $filter = ($filters -join ';') + ";$concatInputs" + "concat=n=$n`:v=0:a=1[out]"
    $mp3 = Join-Path $outDir "$($audio.slug).mp3"

    & ffmpeg -hide_banner -loglevel error -y @inputs -filter_complex $filter -map '[out]' -ac 1 -b:a 64k $mp3
    if ($LASTEXITCODE -ne 0) { throw "ffmpeg fallo con $($audio.slug)" }
    $size = [math]::Round((Get-Item $mp3).Length / 1KB)
    Write-Host "   $mp3 ($size KB)"
}

Remove-Item -Recurse -Force $tmp
