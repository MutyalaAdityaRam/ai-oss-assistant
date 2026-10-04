'use client';

import { useState, useEffect } from 'react';
import { useParams } from 'next/navigation';
import { api, RepoItem, FindingItem, FixItem } from '@/lib/api';

export default function ChatInterface() {
  const params = useParams();
  const repoId = Number(params.repoId);

  const [repo, setRepo] = useState<RepoItem | null>(null);
  const [findings, setFindings] = useState<FindingItem[]>([]);
  const [fixes, setFixes] = useState<FixItem[]>([]);
  const [suggestedPrompts, setSuggestedPrompts] = useState<string[]>([]);
  const [inputMessage, setInputMessage] = useState('');
  const [messages, setMessages] = useState<Array<{ sender: 'user' | 'agent'; text: string; route?: string }>>([]);
  const [loading, setLoading] = useState(false);
  const [contextLoading, setContextLoading] = useState(true);

  useEffect(() => {
    if (repoId) {
      loadContext();
    }
  }, [repoId]);

  async function loadContext() {
    try {
      setContextLoading(true);
      const res = await api.getChatContext(repoId);
      if (res.data) {
        const d = res.data;
        setRepo(d.repo);
        setFindings(d.findings || []);
        setFixes(d.fixes || []);
        setSuggestedPrompts(d.suggested_prompts || []);
        setMessages([
          {
            sender: 'agent',
            text: d.greeting || `Hello! I am your dedicated engineering assistant for ${d.repo.full_name}. How can I help you plan or implement fixes?`,
          }
        ]);
      }
    } catch (err: any) {
      // Fallback
      setMessages([
        {
          sender: 'agent',
          text: `Hello! I am your AI repository assistant for repository #${repoId}. How can I assist you with implementation planning and code modifications?`,
        }
      ]);
    } finally {
      setContextLoading(false);
    }
  }

  async function handleSendMessage(textToSend?: string) {
    const text = (textToSend || inputMessage).trim();
    if (!text) return;

    if (!textToSend) {
      setInputMessage('');
    }

    setMessages(prev => [...prev, { sender: 'user', text }]);
    setLoading(true);

    try {
      const res = await api.sendMessage(repoId, text);
      const agentData = res.data;
      setMessages(prev => [
        ...prev,
        {
          sender: 'agent',
          text: agentData.message || agentData.reply || 'Action completed successfully.',
          route: agentData.route,
        }
      ]);
    } catch (err: any) {
      setMessages(prev => [
        ...prev,
        { sender: 'agent', text: `Error: ${err.message}` }
      ]);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div style={{ maxWidth: '960px', margin: '0 auto' }}>
      {/* Navigation Breadcrumb */}
      <div style={{ marginBottom: '18px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <a href={repo ? `/repo/${repo.id}` : '/'} style={{ color: '#94a3b8', fontSize: '0.85rem' }}>
          ← Back to {repo ? repo.full_name : 'Dashboard'}
        </a>
        <a href="/" style={{ color: '#94a3b8', fontSize: '0.85rem' }}>
          Pipeline Dashboard
        </a>
      </div>

      {/* Repo Awareness Header Banner */}
      <div
        className="glass-panel"
        style={{
          padding: '18px 24px',
          marginBottom: '20px',
          borderLeft: '4px solid #3b82f6',
          background: 'rgba(59, 130, 246, 0.05)',
        }}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '12px' }}>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <h2 style={{ fontSize: '1.4rem', fontWeight: 700, margin: 0 }}>
                {repo ? repo.full_name : `Repository #${repoId}`}
              </h2>
              {repo && (
                <span className={`badge badge-${repo.status}`}>
                  {repo.status.replace('_', ' ')}
                </span>
              )}
            </div>
            <p style={{ color: '#94a3b8', fontSize: '0.85rem', margin: '4px 0 0 0' }}>
              🤖 Context Active: AI assistant is fully aware of this repository&apos;s code, {findings.length} detected findings, and {fixes.length} prepared fixes.
            </p>
          </div>

          {repo && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '12px', fontSize: '0.82rem', color: '#cbd5e1' }}>
              <span>⭐ {repo.stars?.toLocaleString() || 0}</span>
              <span>•</span>
              <span>Score: {repo.resume_score}</span>
              <a
                href={`https://github.com/${repo.full_name}`}
                target="_blank"
                rel="noreferrer"
                style={{ color: '#60a5fa', textDecoration: 'none' }}
              >
                GitHub ↗
              </a>
            </div>
          )}
        </div>

        {/* Suggested Implementation Plan Chips */}
        {suggestedPrompts.length > 0 && (
          <div style={{ marginTop: '14px', display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' }}>
            <span style={{ fontSize: '0.75rem', fontWeight: 600, color: '#93c5fd', textTransform: 'uppercase' }}>
              Quick Planning:
            </span>
            {suggestedPrompts.map((prompt, idx) => (
              <button
                key={idx}
                onClick={() => handleSendMessage(prompt)}
                disabled={loading}
                style={{
                  background: 'rgba(59, 130, 246, 0.12)',
                  border: '1px solid rgba(59, 130, 246, 0.3)',
                  color: '#bfdbfe',
                  borderRadius: '20px',
                  padding: '4px 12px',
                  fontSize: '0.78rem',
                  cursor: 'pointer',
                  textAlign: 'left',
                }}
              >
                💡 {prompt}
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Chat Messages Panel */}
      <div className="glass-panel" style={{ height: '560px', display: 'flex', flexDirection: 'column' }}>
        <div style={{ flex: 1, padding: '24px', overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '18px' }}>
          {contextLoading && (
            <div style={{ color: '#94a3b8', fontSize: '0.88rem', textAlign: 'center', margin: 'auto' }}>
              Loading repository context and architecture awareness...
            </div>
          )}

          {!contextLoading && messages.map((msg, idx) => (
            <div key={idx} style={{ alignSelf: msg.sender === 'user' ? 'flex-end' : 'flex-start', maxWidth: '85%' }}>
              <div
                style={{
                  padding: '14px 20px',
                  borderRadius: '14px',
                  background: msg.sender === 'user'
                    ? 'linear-gradient(135deg, #0284c7, #2563eb)'
                    : 'rgba(255, 255, 255, 0.04)',
                  border: msg.sender === 'agent' ? '1px solid rgba(255, 255, 255, 0.08)' : 'none',
                  fontSize: '0.92rem',
                  lineHeight: '1.6',
                  whiteSpace: 'pre-wrap',
                }}
              >
                {msg.route && (
                  <span
                    className={`badge ${msg.route === 'PATH_A' ? 'badge-bugs_found' : 'badge-clean_deleted'}`}
                    style={{ marginBottom: '10px', display: 'inline-block' }}
                  >
                    {msg.route === 'PATH_A' ? 'Engineering Plan & Pipeline' : 'Direct Fork Tool'}
                  </span>
                )}
                <div>{msg.text}</div>
              </div>
            </div>
          ))}

          {loading && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px', color: '#94a3b8', fontSize: '0.85rem' }}>
              <span className="spinner" style={{ width: '14px', height: '14px', border: '2px solid #3b82f6', borderTopColor: 'transparent', borderRadius: '50%', display: 'inline-block', animation: 'spin 1s linear infinite' }} />
              Agent is analyzing {repo?.full_name || 'repository'} and formulating implementation plan...
            </div>
          )}
        </div>

        {/* Chat Input Form */}
        <form
          onSubmit={(e) => {
            e.preventDefault();
            handleSendMessage();
          }}
          style={{ padding: '16px 24px', borderTop: '1px solid var(--border-card)', display: 'flex', gap: '12px' }}
        >
          <input
            type="text"
            value={inputMessage}
            onChange={e => setInputMessage(e.target.value)}
            placeholder={`Ask to plan implementation or modify ${repo?.full_name || 'this repository'}...`}
            style={{
              flex: 1,
              background: 'rgba(0,0,0,0.3)',
              border: '1px solid var(--border-card)',
              borderRadius: '10px',
              padding: '12px 16px',
              color: '#fff',
              outline: 'none',
              fontSize: '0.92rem',
            }}
          />
          <button type="submit" disabled={loading || !inputMessage.trim()} className="btn btn-primary" style={{ padding: '0 24px' }}>
            Send
          </button>
        </form>
      </div>
    </div>
  );
}
