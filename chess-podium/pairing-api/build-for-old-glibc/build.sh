#!/bin/bash
# Compila bbpPairings per sistemi con glibc vecchia (CentOS 7, hosting condivisi)
# Richiede Docker installato
# Esegui: ./build.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
OUTPUT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

echo "Compilazione bbpPairings per glibc 2.17 (CentOS 7)..."
echo "Output: $OUTPUT_DIR/bbpPairings.exe"
echo ""

docker run --rm -v "$OUTPUT_DIR:/output" centos:7 bash -c '
    yum install -y centos-release-scl devtoolset-7-gcc-c++ make wget tar > /dev/null 2>&1
    source scl_source enable devtoolset-7
    cd /tmp
    wget -q https://github.com/BieremaBoyzProgramming/bbpPairings/archive/refs/tags/v4.1.0.tar.gz -O bbp.tar.gz
    tar -xzf bbp.tar.gz
    cd bbpPairings-4.1.0
    make
    cp bbpPairings.exe /output/bbpPairings.exe
    echo "OK: bbpPairings.exe creato"
'

echo ""
echo "Fatto! Carica $OUTPUT_DIR/bbpPairings.exe su bedo.it/pairing-api/"
