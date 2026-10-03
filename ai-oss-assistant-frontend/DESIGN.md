# Premium Dashboard Design System — DESIGN.md

This document defines the design tokens, color palettes, typography, layout shell, signature elements, and accessibility constraints governing `ai-oss-assistant-frontend`.

---

## 🎨 Tone & Product Identity

The AI Open Source Contribution Assistant is a precise, evidence-based, quietly technical tool that watches open-source repositories, fixes real security/bug findings, and presents verified visual proof to maintainers.

---

## 💎 Compact Design Token System

### 1. Dual Color Palettes (CSS Theme Variables)

| Token Name | Light Theme Palette | Dark Theme Palette | Purpose |
|---|---|---|---|
| `--bg-app` | `#f8fafc` | `#0b0f19` | Main application background |
| `--bg-surface` | `#ffffff` | `#111827` | Glass/Surface card containers |
| `--bg-surface-hover` | `#f1f5f9` | `#1f2937` | Hover state for interactive cards |
| `--border-color` | `#e2e8f0` | `#1f2937` | Subtle structural borders |
| `--text-primary` | `#0f172a` | `#f9fafb` | Primary headings & body text |
| `--text-secondary` | `#475569` | `#9ca3af` | Secondary labels & metadata |
| `--text-muted` | `#94a3b8` | `#6b7280` | Muted captions & disclaimers |
| `--accent-primary` | `#2563eb` | `#3b82f6` | Primary action buttons & links |
| `--accent-secondary` | `#7c3aed` | `#8b5cf6` | Complexity metrics & badges |
| `--color-success` | `#059669` | `#10b981` | Passing tests & clean rescans |
| `--color-danger` | `#dc2626` | `#ef4444` | High severity bugs & errors |

### 2. Typography

- **Display & Headings**: `Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`
- **Utility / Monospace / Diffs**: `'JetBrains Mono', 'Fira Code', Consolas, Monaco, monospace`

### 3. Layout Shell ASCII Wireframe

```
+-------------------------------------------------------------------------------+
| [Logo] AI OSS Assistant   |  Dashboard   Suggestions   Settings   | [Theme 🌙] |
+-------------------------------------------------------------------------------+
|  Live Status: 🟢 15 Candidate Repositories Tracked                            |
+-------------------------------------------------------------------------------+
|                                                                               |
|  +-----------------------------------+   +---------------------------------+  |
|  | Paired Recharts Complexity Chart  |   | Wall-Clock Runtime Sparkline    |  |
|  +-----------------------------------+   +---------------------------------+  |
|                                                                               |
|  +-------------------------------------------------------------------------+  |
|  | Live GitHub Compare Diff Panel                                          |  |
|  +-------------------------------------------------------------------------+  |
+-------------------------------------------------------------------------------+
```

---

## 🌟 Signature Element

**The Paired Recharts Visual Comparison & Live Diff Inspector**:
Every repository and fix detail view renders paired before/after visual bar charts comparing cyclomatic complexity paths (Lizard) and wall-clock test suite runtime deltas, accompanied by the non-negotiable honesty disclaimer caption:
> *"Cyclomatic complexity — a measure of how many independent paths exist through the code, not algorithmic (Big-O) complexity."*

---

## ♿ Accessibility & Motion Constraints

- **Theme Variable Purity**: Zero hardcoded color hexes inside React components. All colors reference CSS variables.
- **Prefers-Reduced-Motion**: All CSS transitions and keyframe animations honor `@media (prefers-reduced-motion: reduce)`.
- **Keyboard Focus States**: Visible `:focus-visible` outline rings on all interactive elements.
