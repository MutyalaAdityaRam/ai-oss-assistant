'use client';

export function RepoCardSkeleton() {
  return (
    <div className="glass-panel" style={{ padding: '20px', marginBottom: '16px' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '12px' }}>
        <div className="skeleton" style={{ width: '40%', height: '24px' }} />
        <div className="skeleton" style={{ width: '20%', height: '24px' }} />
      </div>
      <div className="skeleton" style={{ width: '60%', height: '16px', marginBottom: '16px' }} />
      <div style={{ display: 'flex', gap: '12px' }}>
        <div className="skeleton" style={{ width: '30%', height: '32px' }} />
        <div className="skeleton" style={{ width: '30%', height: '32px' }} />
      </div>
    </div>
  );
}

export function FixDiffSkeleton() {
  return (
    <div className="glass-panel" style={{ padding: '24px' }}>
      <div className="skeleton" style={{ width: '50%', height: '28px', marginBottom: '16px' }} />
      <div className="skeleton" style={{ width: '100%', height: '180px' }} />
    </div>
  );
}
