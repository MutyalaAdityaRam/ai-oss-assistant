#!/usr/bin/env python3
"""
classify-repo-files.py
Walks a repository directory tree and assigns EXACTLY ONE category to every file.
Outputs repo-file-manifest.json as the source of truth for scanners, context resolving, and command execution.
"""

import os
import sys
import json
import re
from pathlib import Path

# Category Constants
CAT_DOCS = "DOCUMENTATION/META"
CAT_CONFIG = "CONFIG/MANIFEST"
CAT_TEST = "TEST FILES"
CAT_BINARY = "BINARY/ASSET"
CAT_SOURCE = "SOURCE CODE"

# Extension to Language Map
LANG_MAP = {
    ".py": "python",
    ".js": "javascript",
    ".jsx": "javascript",
    ".mjs": "javascript",
    ".ts": "typescript",
    ".tsx": "typescript",
    ".go": "go",
    ".java": "java",
    ".rb": "ruby",
    ".php": "php",
    ".rs": "rust",
    ".c": "c",
    ".h": "c",
    ".cpp": "cpp",
    ".hpp": "cpp",
    ".cc": "cpp",
    ".html": "html",
    ".css": "css",
    ".scss": "css",
}

CONFIG_FILES = {
    "package.json", "package-lock.json", "requirements.txt", "pyproject.toml",
    "pipfile", "go.mod", "go.sum", "pom.xml", "build.gradle", "cargo.toml",
    "cargo.lock", "dockerfile", "docker-compose.yml", ".env.example", "makefile",
    "tox.ini", "setup.cfg"
}

BINARY_EXTS = {
    ".png", ".jpg", ".jpeg", ".gif", ".ico", ".ttf", ".woff", ".woff2",
    ".eot", ".exe", ".so", ".dll", ".bin", ".zip", ".gz", ".tar", ".pdf",
    ".docx", ".doc", ".lock"
}

def is_text_file(filepath):
    try:
        with open(filepath, 'rb') as f:
            chunk = f.read(1024)
            if b'\x00' in chunk:
                return False
            chunk.decode('utf-8')
            return True
    except Exception:
        return False

def classify_file(rel_path, abs_path):
    path_obj = Path(rel_path)
    filename = path_obj.name
    filename_lower = filename.lower()
    ext = path_obj.suffix.lower()
    parts = [p.lower() for p in path_obj.parts]

    # 1. BINARY/ASSET check
    if ext in BINARY_EXTS or not is_text_file(abs_path):
        return CAT_BINARY, None

    # 2. DOCUMENTATION/META
    if (filename_lower.startswith("readme") or
        filename_lower.startswith("contributing") or
        filename_lower.startswith("license") or
        filename_lower.startswith("changelog") or
        filename_lower.startswith("code_of_conduct") or
        filename_lower == "security.md" or
        ".github/issue_template" in rel_path.lower() or
        ".github/pull_request_template" in rel_path.lower() or
        ("docs" in parts and ext in [".md", ".rst"]) or
        (ext == ".txt" and "requirements" not in filename_lower and "dep" not in filename_lower)):
        return CAT_DOCS, None

    # 3. CONFIG/MANIFEST
    if (filename_lower in CONFIG_FILES or
        ".github/workflows" in rel_path.lower() or
        ".devcontainer" in rel_path.lower()):
        return CAT_CONFIG, None

    # 4. TEST FILES
    if (filename_lower.startswith("test_") or
        filename_lower.endswith("_test.py") or
        filename_lower.endswith(".test.js") or
        filename_lower.endswith(".test.ts") or
        filename_lower.endswith(".spec.js") or
        filename_lower.endswith(".spec.ts") or
        filename_lower.endswith("_test.go") or
        any(t in parts for t in ["tests", "test", "__tests__", "spec"])):
        lang = LANG_MAP.get(ext)
        return CAT_TEST, lang

    # 5. SOURCE CODE
    if ext in LANG_MAP:
        return CAT_SOURCE, LANG_MAP[ext]

    # Default fallback for text files not matching language map
    return CAT_DOCS, None

def scan_repository(repo_dir):
    manifest = {
        "summary": {
            CAT_DOCS: 0,
            CAT_CONFIG: 0,
            CAT_SOURCE: 0,
            CAT_TEST: 0,
            CAT_BINARY: 0,
        },
        "files": {}
    }

    for root, dirs, files in os.walk(repo_dir):
        # Exclude .git and node_modules from classification
        dirs[:] = [d for d in dirs if d not in [".git", "node_modules", "vendor", ".next", "__pycache__"]]

        for file in files:
            abs_path = os.path.join(root, file)
            rel_path = os.path.relpath(abs_path, repo_dir).replace("\\", "/")

            cat, lang = classify_file(rel_path, abs_path)
            
            manifest["summary"][cat] += 1
            entry = {"category": cat}
            if lang:
                entry["language"] = lang

            manifest["files"][rel_path] = entry

    return manifest

def main():
    repo_dir = sys.argv[1] if len(sys.argv) > 1 else "."
    output_manifest = os.path.join(repo_dir, "repo-file-manifest.json")

    print(f"[*] Classifying files in repository: {os.path.abspath(repo_dir)}...")
    manifest = scan_repository(repo_dir)

    with open(output_manifest, "w", encoding="utf-8") as f:
        json.dump(manifest, f, indent=2)

    print(f"[✓] Classification complete. Saved to: {output_manifest}")
    print(f"    Summary: {json.dumps(manifest['summary'])}")

if __name__ == "__main__":
    main()
