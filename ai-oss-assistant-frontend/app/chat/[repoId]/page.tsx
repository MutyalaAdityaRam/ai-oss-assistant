'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { api } from '@/lib/api';

export default function ChatInterface() {
  const params = useParams();
  const repoId = Number(params.repoId);

  const [inputMessage, setInputMessage] = useState('');
  const [messages, setMessages] = useState<Array<{ sender: 'user' | 'agent'; text: string; route?: string }>>([
    { sender: 'agent', text: 'Hello! I am your AI repository assistant. How can I modify or adjust your fork?' }
  ]);
  const [loading, setLoading] = useState(false);

  async function handleSendMessage(e: React.FormEvent) {
    e.preventDefault();
    if (!inputMessage.trim()) return;

    const userText = inputMessage.trim();
    setInputMessage('');
    setMessages(prev => [...prev, { sender: 'user', text: userText }]);
    setLoading(true);

    try {
      const res = await api.sendMessage(repoId, userText);
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
    <div style={{ maxWidth: '900px', margin: '0 auto' }}>
      <div style={{ marginBottom: '24px' }}>
        <a href="/" style={{ color: '#94a3b8', fontSize: '0.85rem' }}>← Back to Dashboard</a>
        <h2 style={{ fontSize: '1.75rem', fontWeight: 700, marginTop: '12px' }}>Interactive Fork Chat Agent</h2>
        <p style={{ color: '#94a3b8', fontSize: '0.9rem' }}>
          Path A (code logic changes) routes through full pipeline verification. Path B (structural/cosmetic) executes directly on your fork.
        </p>
      </div>

      <div className="glass-panel" style={{ height: '500px', display: 'flex', flexDirection: 'column' }}>
        {/* Messages Scroll Panel */}
        <div style={{ flex: 1, padding: '24px', overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '16px' }}>
          {messages.map((msg, idx) => (
            <div key={idx} style={{ alignSelf: msg.sender === 'user' ? 'flex-end' : 'flex-start', maxWidth: '80%' }}>
              <div
                style={{
                  padding: '12px 18px',
                  borderRadius: '14px',
                  background: msg.sender === 'user' ? 'linear-gradient(135deg, #06b6d4, #3b82f6)' : 'rgba(255,255,255,0.05)',
                  border: msg.sender === 'agent' ? '1px solid var(--border-card)' : 'none',
                  fontSize: '0.92rem',
                }}
              >
                {msg.route && (
                  <span className={`badge ${msg.route === 'PATH_A' ? 'badge-bugs_found' : 'badge-clean_deleted'}`} style={{ marginBottom: '8px', display: 'inline-block' }}>
                    {msg.route}
                  </span>
                )}
                <div>{msg.text}</div>
              </div>
            </div>
          ))}
          {loading && <div style={{ color: '#94a3b8', fontSize: '0.85rem' }}>Agent is evaluating instruction...</div>}
        </div>

        {/* Input Form */}
        <form onSubmit={handleSendMessage} style={{ padding: '16px 24px', borderTop: '1px solid var(--border-card)', display: 'flex', gap: '12px' }}>
          <input
            type="text"
            value={inputMessage}
            onChange={e => setInputMessage(e.target.value)}
            placeholder="Type instructions e.g. 'Add unit test for logger' or 'Delete temporary docs'..."
            style={{
              flex: 1,
              background: 'rgba(0,0,0,0.3)',
              border: '1px solid var(--border-card)',
              borderRadius: '10px',
              padding: '12px 16px',
              color: '#fff',
              outline: 'none',
            }}
          />
          <button type="submit" disabled={loading} className="btn btn-primary">Send</button>
        </form>
      </div>
    </div>
  );
}
