#!/usr/bin/env python3
"""
resolve-issue-context.py
Builds a minimal, targeted context package for a specific finding or suggestion.
Parses 1-hop direct local imports only, avoiding whole-repo context dumps.
Outputs context-package.json.
"""

import os
import sys
import json
import re
from pathlib import Path

def parse_direct_imports(filepath, repo_dir):
    abs_path = os.path.join(repo_dir, filepath)
    if not os.path.isfile(abs_path):
        return []

    direct_imports = []
    file_dir = os.path.dirname(abs_path)
    ext = Path(filepath).suffix.lower()

    try:
        with open(abs_path, "r", encoding="utf-8") as f:
            content = f.read()

        lines = content.split("\n")
        imported_paths = []

        for line in lines:
            # JS/TS require or import
            if ext in [".js", ".jsx", ".ts", ".tsx"]:
                # require('./utils/logger') or import ... from './utils/logger'
                m = re.search(r'''(?:require\(['"]|import\s+.*?from\s+['"])(?:\./|\.\./)([^'"]+)['"]''', line)
                if m:
                    rel = m.group(1)
                    imported_paths.append(rel)
            # Python import
            elif ext == ".py":
                # from .logger import logInfo
                m = re.search(r'''from\s+\.([a-zA-Z0-9_]+)\s+import''', line)
                if m:
                    rel = m.group(1) + ".py"
                    imported_paths.append(rel)

        for imp_rel in imported_paths:
            # Resolve relative to current file's dir
            candidate = os.path.normpath(os.path.join(file_dir, imp_rel))
            if not candidate.endswith(ext) and not os.path.splitext(candidate)[1]:
                candidate += ext

            if os.path.isfile(candidate):
                rel_to_repo = os.path.relpath(candidate, repo_dir).replace("\\", "/")
                
                # Extract exported signatures
                with open(candidate, "r", encoding="utf-8") as f_imp:
                    imp_content = f_imp.read()

                sigs = []
                for imp_line in imp_content.split("\n"):
                    clean = imp_line.strip()
                    if clean.startswith("export ") or clean.startswith("def ") or clean.startswith("function ") or clean.startswith("class "):
                        sigs.append(clean[:100])

                direct_imports.append({
                    "path": rel_to_repo,
                    "signatures": sigs[:10] # limit signatures per import
                })

    except Exception as e:
        print(f"[!] Warning parsing imports for {filepath}: {e}")

    return direct_imports

def resolve_context(repo_dir, finding_file=None, finding_line=None, description=""):
    manifest_path = os.path.join(repo_dir, "repo-file-manifest.json")
    manifest_files = {}

    if os.path.isfile(manifest_path):
        with open(manifest_path, "r", encoding="utf-8") as f:
            m_data = json.load(f)
            manifest_files = m_data.get("files", {})

    target_file = finding_file

    # If no file metadata given (e.g. suggestion/custom request), search SOURCE CODE files only
    if not target_file:
        source_files = [f for f, info in manifest_files.items() if info.get("category") == "SOURCE CODE"]
        
        # Simple keyword search across source files
        keywords = re.findall(r"\w+", description.lower())
        best_file = None
        max_matches = 0

        for s_file in source_files:
            abs_s = os.path.join(repo_dir, s_file)
            if os.path.isfile(abs_s):
                try:
                    with open(abs_s, "r", encoding="utf-8") as f:
                        text = f.read().lower()
                    matches = sum(1 for kw in keywords if len(kw) > 3 and kw in text)
                    if matches > max_matches:
                        max_matches = matches
                        best_file = s_file
                except Exception:
                    pass

        target_file = best_file or (source_files[0] if source_files else None)

    if not target_file or not os.path.isfile(os.path.join(repo_dir, target_file)):
        return {
            "error": f"Target file '{target_file}' not found in repository",
            "finding_description": description
        }

    abs_target = os.path.join(repo_dir, target_file)
    with open(abs_target, "r", encoding="utf-8") as f:
        target_content = f.read()

    direct_imports = parse_direct_imports(target_file, repo_dir)

    return {
        "target_file": target_file,
        "target_line": finding_line,
        "target_content": target_content,
        "direct_imports": direct_imports,
        "finding_description": description,
        "is_bounded_context": True
    }

def main():
    repo_dir = sys.argv[1] if len(sys.argv) > 1 else "."
    finding_file = sys.argv[2] if len(sys.argv) > 2 and sys.argv[2] != "none" else None
    description = sys.argv[3] if len(sys.argv) > 3 else "Fix requested issue"

    output_file = os.path.join(repo_dir, "context-package.json")
    ctx = resolve_context(repo_dir, finding_file, description=description)

    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(ctx, f, indent=2)

    print(f"[✓] Context package resolved for target: '{ctx.get('target_file')}'")
    print(f"    Direct imports included: {len(ctx.get('direct_imports', []))}")
    print(f"    Context package saved to: {output_file}")

if __name__ == "__main__":
    main()
