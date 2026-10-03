#!/usr/bin/env python3
import json
import os
import sys

def load_json(path):
    if not os.path.exists(path):
        return None
    try:
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        print(f"Warning: Could not parse {path}: {e}")
        return None

def main():
    target_dir = sys.argv[1] if len(sys.argv) > 1 else '.'
    repo_id = os.environ.get('REPO_ID', '1')

    semgrep_data = load_json(os.path.join(target_dir, 'semgrep.json'))
    trivy_data   = load_json(os.path.join(target_dir, 'trivy.json'))
    gitleaks_data= load_json(os.path.join(target_dir, 'gitleaks.json'))

    findings = []
    severity_summary = {'high': 0, 'medium': 0, 'low': 0}

    # Process Semgrep
    if semgrep_data and 'results' in semgrep_data:
        for res in semgrep_data['results']:
            sev = res.get('extra', {}).get('severity', 'WARNING').lower()
            if 'error' in sev or 'high' in sev:
                severity_summary['high'] += 1
            elif 'warning' in sev or 'medium' in sev:
                severity_summary['medium'] += 1
            else:
                severity_summary['low'] += 1

            findings.append({
                'tool': 'semgrep',
                'rule_id': res.get('check_id'),
                'path': res.get('path'),
                'line': res.get('line', res.get('start', {}).get('line')),
                'message': res.get('extra', {}).get('message', 'Semgrep finding')
            })

    # Process Gitleaks
    if gitleaks_data and isinstance(gitleaks_data, list):
        for leak in gitleaks_data:
            severity_summary['high'] += 1
            findings.append({
                'tool': 'gitleaks',
                'rule_id': leak.get('RuleID'),
                'path': leak.get('File'),
                'line': leak.get('StartLine'),
                'message': f"Leaked Secret: {leak.get('Description')}"
            })

    compact_payload = {
        'type': 'analysis',
        'repo_id': int(repo_id),
        'tool': 'merged',
        'finding_count': len(findings),
        'severity_summary': severity_summary,
        'findings': findings[:20],
        'artifact_url': f"https://github.com/{os.environ.get('GITHUB_REPOSITORY', 'local')}/actions/runs/{os.environ.get('GITHUB_RUN_ID', '1')}"
    }

    out_path = os.path.join(target_dir, 'merged-findings.json')
    with open(out_path, 'w', encoding='utf-8') as f:
        json.dump(compact_payload, f, indent=2)

    print(f"Merged scan findings: {len(findings)} total findings recorded at {out_path}.")

if __name__ == '__main__':
    main()
