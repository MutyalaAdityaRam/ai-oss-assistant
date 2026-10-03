#!/usr/bin/env python3
"""
parse-code-semantics.py
Distinguishes comments from executable code per-language across source files,
and extracts fenced code snippets from DOCUMENTATION/META files as tagged reference material.
Outputs code-semantics.json.
"""

import os
import sys
import json
import re
from pathlib import Path

def extract_fenced_code_snippets(repo_dir, manifest_files):
    doc_files = [f for f, info in manifest_files.items() if info.get("category") == "DOCUMENTATION/META"]
    tagged_snippets = []

    for doc_file in doc_files:
        abs_path = os.path.join(repo_dir, doc_file)
        if os.path.isfile(abs_path) and abs_path.endswith((".md", ".rst")):
            try:
                with open(abs_path, "r", encoding="utf-8") as f:
                    content = f.read()

                # Find fenced code blocks ```lang ... ```
                matches = re.finditer(r"```([a-zA-Z0-9_-]*)\n(.*?)```", content, re.DOTALL)
                for idx, m in enumerate(matches):
                    lang = m.group(1).strip() or "text"
                    snippet_code = m.group(2).strip()
                    if snippet_code:
                        tagged_snippets.append({
                            "source_doc": doc_file,
                            "snippet_id": f"{doc_file}#block-{idx+1}",
                            "language": lang,
                            "code": snippet_code[:1000] # limit snippet size
                        })
            except Exception as e:
                print(f"[!] Warning parsing doc snippets from {doc_file}: {e}")

    return tagged_snippets

def parse_file_semantics(filepath, repo_dir):
    abs_path = os.path.join(repo_dir, filepath)
    if not os.path.isfile(abs_path):
        return {"total_lines": 0, "code_lines": 0, "comment_lines": 0}

    ext = Path(filepath).suffix.lower()
    
    try:
        with open(abs_path, "r", encoding="utf-8") as f:
            lines = f.readlines()

        total_lines = len(lines)
        comment_lines = 0
        code_lines = 0
        in_multiline_comment = False

        for line in lines:
            stripped = line.strip()
            if not stripped:
                continue

            # Multiline comment tracking for JS/TS/C/C++/PHP
            if ext in [".js", ".jsx", ".ts", ".tsx", ".c", ".cpp", ".h", ".hpp", ".php", ".java", ".go", ".rs"]:
                if "/*" in stripped and "*/" in stripped:
                    comment_lines += 1
                    continue
                if "/*" in stripped:
                    in_multiline_comment = True
                    comment_lines += 1
                    continue
                if in_multiline_comment:
                    comment_lines += 1
                    if "*/" in stripped:
                        in_multiline_comment = False
                    continue
                if stripped.startswith("//"):
                    comment_lines += 1
                    continue

            # Python comment tracking
            elif ext == ".py":
                if stripped.startswith("#"):
                    comment_lines += 1
                    continue
                if stripped.startswith('"""') or stripped.startswith("'''"):
                    comment_lines += 1
                    continue

            code_lines += 1

        return {
            "total_lines": total_lines,
            "code_lines": code_lines,
            "comment_lines": comment_lines,
            "comment_ratio": round(comment_lines / max(total_lines, 1), 2)
        }

    except Exception:
        return {"total_lines": 0, "code_lines": 0, "comment_lines": 0}

def main():
    repo_dir = sys.argv[1] if len(sys.argv) > 1 else "."
    manifest_path = os.path.join(repo_dir, "repo-file-manifest.json")
    output_file = os.path.join(repo_dir, "code-semantics.json")

    manifest_files = {}
    if os.path.isfile(manifest_path):
        with open(manifest_path, "r", encoding="utf-8") as f:
            m_data = json.load(f)
            manifest_files = m_data.get("files", {})

    print(f"[*] Parsing code semantics and doc code snippets for: {os.path.abspath(repo_dir)}...")

    tagged_snippets = extract_fenced_code_snippets(repo_dir, manifest_files)

    source_files = {f: info for f, info in manifest_files.items() if info.get("category") in ["SOURCE CODE", "TEST FILES"]}
    file_semantics = {}

    for rel_path in source_files:
        file_semantics[rel_path] = parse_file_semantics(rel_path, repo_dir)

    result = {
        "tagged_reference_snippets": tagged_snippets,
        "file_semantics": file_semantics,
        "snippet_count": len(tagged_snippets)
    }

    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(result, f, indent=2)

    print(f"[✓] Code semantics parsed cleanly. Found {len(tagged_snippets)} reference snippets.")
    print(f"    Output saved to: {output_file}")

if __name__ == "__main__":
    main()
