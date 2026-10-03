#!/usr/bin/env python3
import json
import os
import subprocess
import time

def run_cmd(cmd, allow_fail=True):
    try:
        res = subprocess.run(cmd, shell=True, capture_output=True, text=True, check=not allow_fail)
        return res.stdout.strip()
    except Exception as e:
        print(f"Command execution error ({cmd}): {e}")
        return ""

def time_test_suite():
    start = time.time()
    # Try standard test runners
    res = run_cmd("npm test || pytest || go test ./... || mvn test || gradle test")
    end = time.time()

    if not res:
        return None  # Test suite unavailable or not found

    return int((end - start) * 1000)

def measure_lizard_complexity(files):
    if not files:
        return 0

    file_str = " ".join(files)
    out = run_cmd(f"lizard {file_str}")
    
    # Parse Lizard cyclomatic complexity summary line
    # Format: NCCN, CCN, total nloc, avg nloc, avg CCN, function count
    comp = 0
    for line in out.splitlines():
        if "Total nloc" in line or "NLOC" in line:
            parts = line.split()
            for part in parts:
                if part.isdigit():
                    comp = int(part)
    return comp

def main():
    base_sha = os.environ.get('BASE_SHA')
    head_sha = os.environ.get('HEAD_SHA')
    fix_id   = os.environ.get('FIX_ID', '1')

    if not base_sha or not head_sha:
        print("Missing BASE_SHA or HEAD_SHA in environment.")
        return

    # Install Lizard if needed
    run_cmd("pip install lizard --break-system-packages || pip install lizard")

    # Get list of changed files between base_sha and head_sha
    changed_files = run_cmd(f"git diff --name-only {base_sha} {head_sha}").splitlines()
    changed_files = [f for f in changed_files if os.path.exists(f) and f.endswith(('.js', '.ts', '.py', '.go', '.java', '.php', '.cpp', '.c'))]

    # Measure on head_sha
    comp_after = measure_lizard_complexity(changed_files)
    ms_after   = time_test_suite()

    # Measure on base_sha
    run_cmd(f"git checkout {base_sha}")
    comp_before = measure_lizard_complexity(changed_files)
    ms_before   = time_test_suite()

    # Return back to head_sha
    run_cmd(f"git checkout {head_sha}")

    # Compute delta
    runtime_delta_pct = None
    if ms_before is not None and ms_after is not None and ms_before > 0:
        runtime_delta_pct = round(((ms_after - ms_before) / ms_before) * 100.0, 2)

    payload = {
        'type': 'optimization',
        'fix_id': int(fix_id),
        'complexity_tool': 'lizard',
        'complexity_before': comp_before,
        'complexity_after': comp_after,
        'test_suite_duration_ms_before': ms_before,
        'test_suite_duration_ms_after': ms_after,
        'runtime_delta_pct': runtime_delta_pct
    }

    with open('optimization-data.json', 'w') as f:
        json.dump(payload, f, indent=2)

    print("Measured Optimization Data:")
    print(json.dumps(payload, indent=2))

if __name__ == '__main__':
    main()
