import fs from 'fs';
import path from 'path';

describe('Frontend Premium Design System & Theme Verification', () => {
  const cssContent = fs.readFileSync(
    path.join(__dirname, '../app/globals.css'),
    'utf8'
  );

  test('DESIGN.md specification document exists', () => {
    const designMdPath = path.join(__dirname, '../DESIGN.md');
    expect(fs.existsSync(designMdPath)).toBe(true);
  });

  test('globals.css defines Light and Dark CSS variable theme tokens', () => {
    expect(cssContent).toContain('[data-theme="dark"]');
    expect(cssContent).toContain('[data-theme="light"]');
    expect(cssContent).toContain('--bg-app');
    expect(cssContent).toContain('--bg-surface');
    expect(cssContent).toContain('--text-primary');
    expect(cssContent).toContain('--accent-primary');
  });

  test('globals.css includes prefers-reduced-motion media query fallback', () => {
    expect(cssContent).toContain('@media (prefers-reduced-motion: reduce)');
  });

  test('globals.css includes keyboard focus visible outline styles', () => {
    expect(cssContent).toContain(':focus-visible');
  });
});
