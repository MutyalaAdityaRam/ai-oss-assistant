#!/usr/bin/env python3
"""
parse-documented-instructions.py
Parses a repository's documented build/run/test instructions in strict priority order:
1. package.json scripts
2. Makefile targets
3. README.md code blocks under install/setup/testing headings
4. Language-standard fallback ONLY if 1-3 yield nothing (with explicit log)
"""

import os
import sys
import json
import re

def parse_package_json(repo_dir):
    pkg_path = os.path.join(repo_dir, "package.json")
    if not os.path.isfile(pkg_path):
        return None

    try:
        with open(pkg_path, "r", encoding="utf-8") as f:
            data = json.load(f)
            scripts = data.get("scripts", {})
            if scripts:
                cmds = {}
                if "test" in scripts:
                    cmds["test"] = "npm test"
                if "build" in scripts:
                    cmds["build"] = "npm run build"
                if "start" in scripts:
                    cmds["start"] = "npm start"

                if cmds:
                    return {
                        "source": "package.json",
                        "commands": cmds,
                        "is_fallback": False
                    }
    except Exception as e:
        print(f"[!] Warning reading package.json: {e}")
    return None

def parse_makefile(repo_dir):
    makefile_path = os.path.join(repo_dir, "Makefile")
    if not os.path.isfile(makefile_path):
        return None

    try:
        with open(makefile_path, "r", encoding="utf-8") as f:
            content = f.read()
            targets = re.findall(r"^([a-zA-Z0-9_-]+):", content, re.MULTILINE)
            cmds = {}
            if "test" in targets:
                cmds["test"] = "make test"
            if "build" in targets:
                cmds["build"] = "make build"
            if "run" in targets or "start" in targets:
                cmds["start"] = "make run" if "run" in targets else "make start"

            if cmds:
                return {
                    "source": "Makefile",
                    "commands": cmds,
                    "is_fallback": False
                }
    except Exception as e:
        print(f"[!] Warning reading Makefile: {e}")
    return None

def parse_readme(repo_dir):
    readme_path = None
    for f in os.listdir(repo_dir):
        if f.lower().startswith("readme"):
            readme_path = os.path.join(repo_dir, f)
            break

    if not readme_path or not os.path.isfile(readme_path):
        return None

    try:
        with open(readme_path, "r", encoding="utf-8") as f:
            content = f.read()

        # Search for headings matching install|setup|usage|running|testing
        sections = re.split(r"\n#{1,4}\s+", content)
        cmds = {}

        for sec in sections:
            lines = sec.split("\n")
            heading = lines[0].lower()
            if re.search(r"install|setup|usage|running|testing", heading):
                sec_text = "\n".join(lines[1:])
                # Find fenced code blocks ```bash or ```sh or ```
                blocks = re.findall(r"```(?:bash|sh|shell)?\n(.*?)```", sec_text, re.DOTALL)
                for b in blocks:
                    for line in b.split("\n"):
                        clean_line = line.strip().lstrip("$ ")
                        if clean_line and not clean_line.startswith("#"):
                            if "test" in clean_line and "test" not in cmds:
                                cmds["test"] = clean_line
                            elif ("build" in clean_line or "compile" in clean_line) and "build" not in cmds:
                                cmds["build"] = clean_line
                            elif ("run" in clean_line or "start" in clean_line) and "start" not in cmds:
                                cmds["start"] = clean_line

        if cmds:
            return {
                "source": "README.md",
                "commands": cmds,
                "is_fallback": False
            }
    except Exception as e:
        print(f"[!] Warning reading README.md: {e}")
    return None

def get_language_fallback(repo_dir):
    print("[!] LOG: No documented build/test instructions found in package.json, Makefile, or README.md. Applying language-standard fallback guess...")
    
    # Simple directory presence checks
    if os.path.isfile(os.path.join(repo_dir, "requirements.txt")) or os.path.isfile(os.path.join(repo_dir, "pyproject.toml")):
        return {
            "source": "language-standard-fallback (Python)",
            "commands": {"test": "pytest", "build": "python -m build"},
            "is_fallback": True
        }
    if os.path.isfile(os.path.join(repo_dir, "go.mod")):
        return {
            "source": "language-standard-fallback (Go)",
            "commands": {"test": "go test ./...", "build": "go build ./..."},
            "is_fallback": True
        }
    if os.path.isfile(os.path.join(repo_dir, "Cargo.toml")):
        return {
            "source": "language-standard-fallback (Rust)",
            "commands": {"test": "cargo test", "build": "cargo build"},
            "is_fallback": True
        }

    return {
        "source": "universal-fallback",
        "commands": {"test": "npm test"},
        "is_fallback": True
    }

def main():
    repo_dir = sys.argv[1] if len(sys.argv) > 1 else "."
    output_file = os.path.join(repo_dir, "build-test-instructions.json")

    res = (parse_package_json(repo_dir) or
           parse_makefile(repo_dir) or
           parse_readme(repo_dir) or
           get_language_fallback(repo_dir))

    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(res, f, indent=2)

    print(f"[✓] Documented instructions parsed. Source: '{res['source']}', Is Fallback: {res['is_fallback']}")
    print(f"    Commands: {json.dumps(res['commands'])}")

if __name__ == "__main__":
    main()
