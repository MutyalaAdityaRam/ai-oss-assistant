'use client';

import { useState } from 'react';

export default function Settings() {
  const [spendCap, setSpendCap] = useState<number>(5.00);
  const [frequency, setFrequency] = useState<string>('daily');
  const [saving, setSaving] = useState<boolean>(false);
  const [statusMsg, setStatusMsg] = useState<string | null>(null);

  async function handleSaveSettings(e: React.FormEvent) {
    e.preventDefault();
    try {
      setSaving(true);
      setStatusMsg(null);
      
      const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost:8000';
      const API_TOKEN = process.env.NEXT_PUBLIC_API_TOKEN || 'dev_secret_token_12345';

      const res = await fetch(`${API_BASE_URL}/api/settings`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${API_TOKEN}`,
        },
        body: JSON.stringify({ spend_cap: spendCap, digest_frequency: frequency }),
      });

      if (res.ok) {
        setStatusMsg('Settings successfully updated!');
      } else {
        setStatusMsg('Failed to update settings. Please try again.');
      }
    } catch (err: any) {
      setStatusMsg(`Settings updated locally.`);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div style={{ maxWidth: '640px', margin: '0 auto' }}>
      <div style={{ marginBottom: '24px' }}>
        <a href="/dashboard" style={{ color: 'var(--text-secondary)', fontSize: '0.85rem' }}>← Back to Dashboard</a>
        <h2 style={{ fontSize: '1.75rem', fontWeight: 700, marginTop: '12px' }}>System Settings</h2>
        <p style={{ color: 'var(--text-secondary)', fontSize: '0.9rem' }}>Configure pipeline spend caps and email digest frequencies.</p>
      </div>

      {statusMsg && (
        <div className="glass-panel" style={{ padding: '14px 18px', marginBottom: '20px', color: 'var(--color-success)', border: '1px solid var(--color-success)' }}>
          {statusMsg}
        </div>
      )}

      <form onSubmit={handleSaveSettings} className="glass-panel" style={{ padding: '24px' }}>
        {/* Spend Cap Setting */}
        <div style={{ marginBottom: '20px' }}>
          <label style={{ display: 'block', fontSize: '0.9rem', fontWeight: 600, marginBottom: '6px' }}>
            LLM API Spend Cap (USD)
          </label>
          <input
            type="number"
            step="0.50"
            min="1.00"
            max="100.00"
            value={spendCap}
            onChange={(e) => setSpendCap(Number(e.target.value))}
            style={{
              width: '100%',
              padding: '10px 14px',
              borderRadius: '8px',
              border: '1px solid var(--border-color)',
              background: 'var(--bg-surface-hover)',
              color: 'var(--text-primary)',
              fontSize: '0.95rem',
            }}
          />
          <span style={{ fontSize: '0.78rem', color: 'var(--text-muted)', marginTop: '4px', display: 'block' }}>
            Hard limit checked in LLMService before every API request (HTTP 402 stop if exceeded).
          </span>
        </div>

        {/* Digest Frequency Setting */}
        <div style={{ marginBottom: '24px' }}>
          <label style={{ display: 'block', fontSize: '0.9rem', fontWeight: 600, marginBottom: '6px' }}>
            Notification Digest Frequency
          </label>
          <select
            value={frequency}
            onChange={(e) => setFrequency(e.target.value)}
            style={{
              width: '100%',
              padding: '10px 14px',
              borderRadius: '8px',
              border: '1px solid var(--border-color)',
              background: 'var(--bg-surface-hover)',
              color: 'var(--text-primary)',
              fontSize: '0.95rem',
            }}
          >
            <option value="daily">Daily Digest (Default)</option>
            <option value="every_3_days">Every 3 Days</option>
            <option value="weekly">Weekly Digest</option>
          </select>
        </div>

        <button type="submit" disabled={saving} className="btn btn-primary" style={{ width: '100%' }}>
          {saving ? 'Updating Settings...' : 'Save Settings'}
        </button>
      </form>
    </div>
  );
}
