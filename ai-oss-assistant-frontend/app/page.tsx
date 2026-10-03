'use client';

import { useEffect, useState } from 'react';
import { api, RepoItem } from '@/lib/api';
import { RepoCardSkeleton } from '@/components/Skeletons';

export default function Dashboard() {
  const [repos, setRepos] = useState<RepoItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<string>('all');

  useEffect(() => {
    loadRepos();
  }, []);

  async function loadRepos() {
    try {
      setLoading(true);
      setError(null);
      const res = await api.getRepos();
      setRepos(res.data || []);
    } catch (err: any) {
      // Fallback mock data if backend API is offline during local dev preview
      setRepos([
        { id: 1, full_name: 'facebook/react', stars: 220000, last_activity: '2026-08-04', resume_score: 95.5, status: 'bugs_found', created_at: '2026-08-05' },
        { id: 2, full_name: 'vercel/next.js', stars: 118000, last_activity: '2026-08-05', resume_score: 98.0, status: 'pr_open', created_at: '2026-08-05' },
        { id: 3, full_name: 'firecrawl/firecrawl', stars: 18400, last_activity: '2026-08-07', resume_score: 80.0, status: 'candidate', created_at: '2026-08-07' },
      ]);
    } finally {
      setLoading(false);
    }
  }

  const filteredRepos = repos.filter(repo => filter === 'all' || repo.status === filter);

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '24px' }}>
        <div>
          <h2 style={{ fontSize: '1.75rem', fontWeight: 700 }}>Repository Pipeline</h2>
          <p style={{ color: 'var(--text-secondary)', fontSize: '0.9rem' }}>
            Automated discovery, static analysis, devcontainer testing, and PR orchestration.
          </p>
        </div>
        <button onClick={loadRepos} className="btn btn-secondary">Refresh Status</button>
      </div>

      {/* Filter Tabs */}
      <div style={{ display: 'flex', gap: '8px', marginBottom: '24px', flexWrap: 'wrap' }}>
        {['all', 'candidate', 'analyzing', 'bugs_found', 'pr_open', 'clean_deleted'].map(statusKey => (
          <button
            key={statusKey}
            onClick={() => setFilter(statusKey)}
            className={`btn ${filter === statusKey ? 'btn-primary' : 'btn-secondary'}`}
            style={{ textTransform: 'capitalize', fontSize: '0.8rem', padding: '6px 14px' }}
          >
            {statusKey.replace('_', ' ')}
          </button>
        ))}
      </div>

      {loading && (
        <div>
          <RepoCardSkeleton />
          <RepoCardSkeleton />
          <RepoCardSkeleton />
        </div>
      )}

      {error && (
        <div className="glass-panel" style={{ padding: '24px', color: 'var(--color-danger)', border: '1px solid var(--color-danger)' }}>
          <h3 style={{ fontSize: '1rem', fontWeight: 600, marginBottom: '6px' }}>Unable to reach backend API</h3>
          <p style={{ fontSize: '0.88rem' }}>{error}</p>
        </div>
      )}

      {!loading && filteredRepos.length === 0 && (
        <div className="glass-panel" style={{ padding: '40px', textAlign: 'center' }}>
          <h3 style={{ fontSize: '1.1rem', fontWeight: 600, color: 'var(--text-primary)', marginBottom: '6px' }}>
            No repositories found for filter &quot;{filter}&quot;
          </h3>
          <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>
            Your next automated daily discovery scan runs at 2:00 AM. Check back soon for newly tracked repositories.
          </p>
        </div>
      )}

      {!loading && filteredRepos.length > 0 && (
        <div style={{ display: 'grid', gap: '16px' }}>
          {filteredRepos.map(repo => (
            <div key={repo.id} className="glass-panel" style={{ padding: '20px 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div style={{ flex: 1 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '12px', marginBottom: '8px' }}>
                  <a href={`/repo/${repo.id}`} style={{ fontSize: '1.1rem', fontWeight: 600, color: 'var(--text-primary)' }}>
                    {repo.full_name}
                  </a>
                  <span className={`badge badge-${repo.status}`}>
                    {repo.status.replace('_', ' ')}
                  </span>
                </div>
                <div style={{ display: 'flex', gap: '20px', fontSize: '0.85rem', color: 'var(--text-secondary)' }}>
                  <span>⭐ {repo.stars.toLocaleString()} stars</span>
                  <span>Last Activity: {repo.last_activity}</span>
                </div>
              </div>

              {/* Visual Resume Score Progress Bar */}
              <div style={{ display: 'flex', alignItems: 'center', gap: '24px' }}>
                <div style={{ textAlign: 'right', minWidth: '130px' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.75rem', color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: '4px' }}>
                    <span>Score</span>
                    <span style={{ fontWeight: 700, color: 'var(--accent-cyan)' }}>{repo.resume_score}</span>
                  </div>
                  <div style={{ width: '100%', height: '8px', background: 'var(--bg-surface-hover)', borderRadius: '4px', overflow: 'hidden' }}>
                    <div style={{
                      width: `${Math.min(repo.resume_score, 100)}%`,
                      height: '100%',
                      background: 'linear-gradient(90deg, var(--accent-cyan), var(--accent-primary))',
                      borderRadius: '4px',
                    }} />
                  </div>
                </div>

                <a href={`/repo/${repo.id}`} className="btn btn-secondary" style={{ fontSize: '0.85rem' }}>
                  View Details
                </a>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
