import './globals.css';
import type { Metadata } from 'next';
import Navbar from '@/components/Navbar';

export const metadata: Metadata = {
  title: 'AI Open Source Contribution Assistant',
  description: 'Daily unattended GitHub repository discovery, vulnerability scanning, auto-fixing, and PR orchestration.',
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en">
      <body>
        <Navbar />
        <main style={{ padding: '24px', maxWidth: '1280px', margin: '0 auto' }}>
          {children}
        </main>
      </body>
    </html>
  );
}
