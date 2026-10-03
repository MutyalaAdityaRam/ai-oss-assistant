'use client';

import { useEffect, useState } from 'react';

export default function Navbar() {
  const [theme, setTheme] = useState<'dark' | 'light'>('dark');

  useEffect(() => {
    // Detect OS preference or stored theme
    const stored = localStorage.getItem('theme_pref') as 'dark' | 'light' | null;
    if (stored) {
      setTheme(stored);
      document.documentElement.setAttribute('data-theme', stored);
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
      setTheme('light');
      document.documentElement.setAttribute('data-theme', 'light');
    }
  }, []);

  function toggleTheme() {
    const nextTheme = theme === 'dark' ? 'light' : 'dark';
    setTheme(nextTheme);
    localStorage.setItem('theme_pref', nextTheme);
    document.documentElement.setAttribute('data-theme', nextTheme);
  }

  return (
    <nav style={{
      background: 'var(--bg-surface)',
      borderBottom: '1px solid var(--border-color)',
      padding: '14px 24px',
      position: 'sticky',
      top: 0,
      zIndex: 100,
    }}>
      <div style={{
        maxWidth: '1280px',
        margin: '0 auto',
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
      }}>
        {/* Brand Logo & Title */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '14px' }}>
          <a href="/dashboard" style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <div style={{
              width: '32px',
              height: '32px',
              borderRadius: '8px',
              background: 'linear-gradient(135deg, var(--accent-cyan), var(--accent-primary))',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontWeight: 700,
              color: '#fff',
              fontSize: '1rem',
            }}>
              ⚡
            </div>
            <span style={{ fontSize: '1.15rem', fontWeight: 700, letterSpacing: '-0.02em' }}>
              AI OSS Assistant
            </span>
          </a>

          {/* Live Status Pill */}
          <span style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: '6px',
            background: 'rgba(16, 185, 129, 0.1)',
            color: 'var(--color-success)',
            border: '1px solid rgba(16, 185, 129, 0.3)',
            padding: '2px 10px',
            borderRadius: '12px',
            fontSize: '0.75rem',
            fontWeight: 600,
          }}>
            <span style={{
              width: '6px',
              height: '6px',
              borderRadius: '50%',
              background: 'var(--color-success)',
              boxShadow: '0 0 8px var(--color-success)',
            }} />
            Live — 15 Candidate Repos Tracked
          </span>
        </div>

        {/* Navigation Links & Theme Toggle */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '24px' }}>
          <a href="/dashboard" style={{ fontSize: '0.9rem', fontWeight: 500, color: 'var(--text-secondary)' }}>
            Dashboard
          </a>
          <a href="/settings" style={{ fontSize: '0.9rem', fontWeight: 500, color: 'var(--text-secondary)' }}>
            Settings
          </a>

          {/* Animated Light/Dark Theme Switch */}
          <button
            onClick={toggleTheme}
            aria-label="Toggle Dark/Light Mode"
            style={{
              background: 'var(--bg-surface-hover)',
              border: '1px solid var(--border-color)',
              color: 'var(--text-primary)',
              padding: '6px 14px',
              borderRadius: '20px',
              fontSize: '0.82rem',
              fontWeight: 600,
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              transition: 'all 0.2s ease',
            }}
          >
            {theme === 'dark' ? '🌙 Dark' : '☀️ Light'}
          </button>
        </div>
      </div>
    </nav>
  );
}
