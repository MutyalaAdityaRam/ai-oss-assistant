#!/usr/bin/env bash
set -e

echo "=== Detecting DevContainer Environment ==="

if [ -f ".devcontainer/devcontainer.json" ]; then
    echo "Found custom .devcontainer/devcontainer.json in repository."
    echo "IMAGE=custom" >> $GITHUB_ENV
    exit 0
fi

if [ -f "package.json" ]; then
    echo "Detected Node.js project (package.json)"
    echo "IMAGE=mcr.microsoft.com/devcontainers/typescript-node" >> $GITHUB_ENV
elif [ -f "requirements.txt" ] || [ -f "pyproject.toml" ]; then
    echo "Detected Python project (requirements.txt / pyproject.toml)"
    echo "IMAGE=mcr.microsoft.com/devcontainers/python" >> $GITHUB_ENV
elif [ -f "go.mod" ]; then
    echo "Detected Go project (go.mod)"
    echo "IMAGE=mcr.microsoft.com/devcontainers/go" >> $GITHUB_ENV
elif [ -f "pom.xml" ] || [ -f "build.gradle" ]; then
    echo "Detected Java project (pom.xml / build.gradle)"
    echo "IMAGE=mcr.microsoft.com/devcontainers/java" >> $GITHUB_ENV
elif [ -f "Cargo.toml" ]; then
    echo "Detected Rust project (Cargo.toml)"
    echo "IMAGE=mcr.microsoft.com/devcontainers/rust" >> $GITHUB_ENV
else
    echo "No single manifest detected. Falling back to universal image."
    echo "IMAGE=mcr.microsoft.com/devcontainers/universal" >> $GITHUB_ENV
fi
