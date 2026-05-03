$p = 'd:\n88-rfq-plugin\n88-rfq-plugin\includes\class-n88-boards.php'
$bytes = [IO.File]::ReadAllBytes($p)
$hasCr = ($bytes -contains 13)
Write-Host "HasCR: $hasCr"
$lines = [IO.File]::ReadAllLines($p)
for ($i = 272; $i -le 285; $i++) {
    Write-Host ("{0}|{1}" -f ($i+1), $lines[$i])
}
