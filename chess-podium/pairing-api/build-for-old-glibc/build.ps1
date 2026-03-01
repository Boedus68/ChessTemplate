# Compila bbpPairings per sistemi con glibc vecchia (CentOS 7, hosting condivisi)
# Richiede Docker Desktop installato su Windows
# Esegui da PowerShell: .\build.ps1

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$OutputDir = (Get-Item $ScriptDir).Parent.FullName

Write-Host "Compilazione bbpPairings per glibc 2.17 (CentOS 7)..."
Write-Host "Output: $OutputDir\bbpPairings.exe"
Write-Host ""

docker run --rm -v "${OutputDir}:/output" centos:7 bash -c 'yum install -y centos-release-scl devtoolset-7-gcc-c++ make wget tar > /dev/null 2>&1 && source scl_source enable devtoolset-7 && cd /tmp && wget -q https://github.com/BieremaBoyzProgramming/bbpPairings/archive/refs/tags/v4.1.0.tar.gz -O bbp.tar.gz && tar -xzf bbp.tar.gz && cd bbpPairings-4.1.0 && make && cp bbpPairings.exe /output/bbpPairings.exe && echo OK'

Write-Host ""
Write-Host "Fatto! Carica $OutputDir\bbpPairings.exe su bedo.it/pairing-api/ via FTP"
