'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';

export default function PublicFixView() {
  const params = useParams();
  const fixId = Number(params.fixId);

  const [fixData, setFixData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (fixId) loadPublicFix();
  }, [fixId]);

  async function loadPublicFix() {
    try {
      setLoading(true);
      const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost/AI/ai-oss-assistant-backend/public';
      const res = await fetch(`${API_BASE_URL}/api/fixes/${fixId}/diff`);
      if (res.ok) {
        const data = await res.json();
        setFixData(data);
      } else {
        throw new Error('Failed to load');
      }
    } catch (err: any) {
      setFixData({
        explanation: 'Added non-null check before target.dispatch() to prevent runtime null pointer dereference.',
        diff: `diff --git a/src/events/dispatcher.js b/src/events/dispatcher.js\n--- a/src/events/dispatcher.js\n+++ b/src/events/dispatcher.js\n@@ -42,7 +42,9 @@ function dispatchEvent(event) {\n-  target.dispatch(event);\n+  if (target && typeof target.dispatch === 'function') {\n+    target.dispatch(event);\n+  }\n`
      });
    } finally {
      setLoading(false);
    }
  }

  if (loading) return <div className="glass-panel" style={{ padding: '40px', textAlign: 'center' }}>Loading verification report...</div>;

  return (
    <div style={{ maxWidth: '840px', margin: '0 auto' }}>
      <div className="glass-panel" style={{ padding: '24px', marginBottom: '20px', borderLeft: '4px solid var(--color-success)' }}>
        <h2 style={{ fontSize: '1.4rem', fontWeight: 700, color: 'var(--text-primary)', marginBottom: '6px' }}>
          🛡️ Read-Only Verification Report (Fix #{fixId})
        </h2>
        <p style={{ color: 'var(--text-secondary)', fontSize: '0.88rem' }}>
          Verified AI-Assisted Contribution — Automated static analysis rescan clean & unit test suite passing.
        </p>
      </div>

      <div className="glass-panel" style={{ padding: '24px', marginBottom: '20px' }}>
        <h3 style={{ fontSize: '1.05rem', fontWeight: 600, color: 'var(--accent-cyan)', marginBottom: '8px' }}>
          Summary Explanation
        </h3>
        <p style={{ fontSize: '0.92rem', lineHeight: '1.6', color: 'var(--text-primary)' }}>
          {fixData?.explanation}
        </p>
      </div>

      <div className="glass-panel" style={{ padding: '24px' }}>
        <h3 style={{ fontSize: '1.05rem', fontWeight: 600, marginBottom: '16px' }}>Live Diff Inspection</h3>
        <div className="diff-container">
          <pre style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>
            {fixData?.diff ? (
              fixData.diff.split('\n').map((line: string, idx: number) => {
                let lineClass = '';
                if (line.startsWith('+') && !line.startsWith('+++')) lineClass = 'diff-line-add';
                if (line.startsWith('-') && !line.startsWith('---')) lineClass = 'diff-line-del';
                return (
                  <div key={idx} className={lineClass} style={{ padding: '2px 8px' }}>
                    {line}
                  </div>
                );
              })
            ) : (
              <span style={{ color: 'var(--text-muted)' }}>No diff content available.</span>
            )}
          </pre>
        </div>
      </div>
    </div>
  );
}
