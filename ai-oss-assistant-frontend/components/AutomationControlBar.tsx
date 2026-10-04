'use client';

import { useState, useEffect } from 'react';
import { api, AutomationStatusData } from '@/lib/api';

export default function AutomationControlBar() {
  const [statusData, setStatusData] = useState<AutomationStatusData>({
    automation_status: 'active',
    is_paused: false,
    is_run_once: false,
    can_search_and_scan: true,
    last_run_at: null,
    paused_at: null,
    run_once_at: null,
    description: 'Automation is ACTIVE. Autonomous daily repository discovery, vulnerability scanning, and bug fixing run on schedule.',
  });
  const [loading, setLoading] = useState<boolean>(false);
  const [notification, setNotification] = useState<string | null>(null);

  useEffect(() => {
    fetchStatus();
    const interval = setInterval(fetchStatus, 5000);
    return () => clearInterval(interval);
  }, []);

  async function fetchStatus() {
    try {
      const res = await api.getAutomationStatus();
      if (res.data) {
        setStatusData(res.data);
      }
    } catch (err) {
      // Fallback silently if offline in local preview
    }
  }

  async function handlePause() {
    try {
      setLoading(true);
      const res = await api.pauseAutomation();
      if (res.data) setStatusData(res.data);
      setNotification('⏸️ Automation paused. Discovery, scanning, and automatic bug fixing are stopped. PRs and chat remain active.');
      setTimeout(() => setNotification(null), 5000);
    } catch (err: any) {
      setNotification('Error pausing automation.');
    } finally {
      setLoading(false);
    }
  }

  async function handleResume() {
    try {
      setLoading(true);
      const res = await api.resumeAutomation();
      if (res.data) setStatusData(res.data);
      setNotification('▶️ Automation resumed. Daily discovery and fixing pipeline is now active.');
      setTimeout(() => setNotification(null), 5000);
    } catch (err: any) {
      setNotification('Error resuming automation.');
    } finally {
      setLoading(false);
    }
  }

  async function handleRunOnce() {
    try {
      setLoading(true);
      const res = await api.runOnceAutomation(true);
      if (res.data) setStatusData(res.data);
      setNotification('⚡ Single-cycle run started for today. The pipeline will run one pass and then automatically pause.');
      setTimeout(() => setNotification(null), 6000);
    } catch (err: any) {
      setNotification('Error triggering run-once.');
    } finally {
      setLoading(false);
    }
  }

  const isPaused = statusData.automation_status === 'paused';
  const isRunOnce = statusData.automation_status === 'run_once';
  const isActive = statusData.automation_status === 'active';

  return (
    <div
      className="glass-panel"
      style={{
        padding: '16px 20px',
        marginBottom: '24px',
        borderRadius: '12px',
        border: isPaused
          ? '1px solid rgba(239, 68, 68, 0.4)'
          : isRunOnce
          ? '1px solid rgba(245, 158, 11, 0.4)'
          : '1px solid rgba(16, 185, 129, 0.4)',
        background: isPaused
          ? 'rgba(239, 68, 68, 0.04)'
          : isRunOnce
          ? 'rgba(245, 158, 11, 0.04)'
          : 'rgba(16, 185, 129, 0.04)',
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '16px' }}>
        {/* Status Indicator & Label */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <div
            style={{
              width: '12px',
              height: '12px',
              borderRadius: '50%',
              backgroundColor: isPaused ? '#ef4444' : isRunOnce ? '#f59e0b' : '#10b981',
              boxShadow: isPaused
                ? '0 0 10px #ef4444'
                : isRunOnce
                ? '0 0 10px #f59e0b'
                : '0 0 10px #10b981',
              flexShrink: 0,
            }}
          />
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <span style={{ fontWeight: 700, fontSize: '0.95rem', color: 'var(--text-primary)' }}>
                Automation Pipeline:
              </span>
              <span
                style={{
                  fontSize: '0.8rem',
                  fontWeight: 600,
                  padding: '3px 8px',
                  borderRadius: '6px',
                  backgroundColor: isPaused
                    ? 'rgba(239, 68, 68, 0.2)'
                    : isRunOnce
                    ? 'rgba(245, 158, 11, 0.2)'
                    : 'rgba(16, 185, 129, 0.2)',
                  color: isPaused ? '#fca5a5' : isRunOnce ? '#fcd34d' : '#6ee7b7',
                  textTransform: 'uppercase',
                  letterSpacing: '0.04em',
                }}
              >
                {statusData.automation_status.replace('_', ' ')}
              </span>
            </div>
            <p style={{ margin: '4px 0 0 0', fontSize: '0.82rem', color: 'var(--text-secondary)', maxWidth: '620px' }}>
              {isPaused && (
                <>
                  <strong style={{ color: '#fca5a5' }}>Paused:</strong> Repo searching, scanning, and bug fixing are halted. Interactive services (PR accepts, declines, chat bot, repo deletion) remain active.
                </>
              )}
              {isRunOnce && (
                <>
                  <strong style={{ color: '#fcd34d' }}>Run-Once (Today Only):</strong> Processing today&apos;s cycle now. It will automatically pause upon completion.
                </>
              )}
              {isActive && (
                <>
                  <strong style={{ color: '#6ee7b7' }}>Active:</strong> Running autonomous daily discovery, vulnerability scanning, and multi-finding PR fixes on schedule (2:00 AM daily).
                </>
              )}
            </p>
          </div>
        </div>

        {/* Action Buttons */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
          {/* Pause Button */}
          {!isPaused && (
            <button
              onClick={handlePause}
              disabled={loading}
              className="btn btn-secondary"
              style={{
                fontSize: '0.85rem',
                padding: '7px 14px',
                display: 'flex',
                alignItems: 'center',
                gap: '6px',
                borderColor: 'rgba(239, 68, 68, 0.5)',
                color: '#fca5a5',
              }}
              title="Pause searching, scanning, and automated fixes"
            >
              <span>⏸️</span> Pause Automation
            </button>
          )}

          {/* Resume Button */}
          {isPaused && (
            <button
              onClick={handleResume}
              disabled={loading}
              className="btn btn-primary"
              style={{
                fontSize: '0.85rem',
                padding: '7px 14px',
                display: 'flex',
                alignItems: 'center',
                gap: '6px',
                backgroundColor: '#10b981',
                borderColor: '#10b981',
              }}
              title="Resume scheduled daily discovery and fix pipeline"
            >
              <span>▶️</span> Resume Automation
            </button>
          )}

          {/* Run One Time Button */}
          <button
            onClick={handleRunOnce}
            disabled={loading}
            className="btn btn-secondary"
            style={{
              fontSize: '0.85rem',
              padding: '7px 14px',
              display: 'flex',
              alignItems: 'center',
              gap: '6px',
              borderColor: 'rgba(245, 158, 11, 0.5)',
              color: '#fcd34d',
            }}
            title="Execute today's cycle once and then automatically pause"
          >
            <span>⚡</span> Run One Time (Today Only)
          </button>
        </div>
      </div>

      {/* Real-time Notification Banner */}
      {notification && (
        <div
          style={{
            marginTop: '12px',
            padding: '8px 12px',
            borderRadius: '6px',
            fontSize: '0.82rem',
            backgroundColor: 'rgba(59, 130, 246, 0.15)',
            border: '1px solid rgba(59, 130, 246, 0.3)',
            color: '#93c5fd',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
          }}
        >
          <span>{notification}</span>
          <button
            onClick={() => setNotification(null)}
            style={{ background: 'none', border: 'none', color: '#93c5fd', cursor: 'pointer', fontSize: '0.85rem' }}
          >
            ✕
          </button>
        </div>
      )}
    </div>
  );
}
