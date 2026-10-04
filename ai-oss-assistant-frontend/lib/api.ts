const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost/AI/ai-oss-assistant-backend/public';
const FALLBACK_API_URL = 'https://ai-oss-assistant.alwaysdata.net';
const API_TOKEN = process.env.NEXT_PUBLIC_API_TOKEN || 'dev_secret_token_12345';

async function fetchApi<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const headers = {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${API_TOKEN}`,
    ...(options.headers || {}),
  };

  // Try primary URL first
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 3000);
    const primaryUrl = `${API_BASE_URL}${endpoint}`;
    const response = await fetch(primaryUrl, { ...options, headers, signal: controller.signal });
    clearTimeout(timeoutId);

    if (response.ok) {
      return await response.json();
    }
  } catch (err) {
    // If primary failed and it's different from fallback, try live Alwaysdata backend
  }

  // Attempt fallback if primary failed or returned error
  if (API_BASE_URL !== FALLBACK_API_URL) {
    try {
      const fallbackController = new AbortController();
      const fallbackTimeout = setTimeout(() => fallbackController.abort(), 6000);
      const fallbackUrl = `${FALLBACK_API_URL}${endpoint}`;
      const fallbackResponse = await fetch(fallbackUrl, { ...options, headers, signal: fallbackController.signal });
      clearTimeout(fallbackTimeout);

      if (fallbackResponse.ok) {
        return await fallbackResponse.json();
      }
      const errJson = await fallbackResponse.json().catch(() => ({}));
      throw new Error(errJson.error || `API Request failed with status ${fallbackResponse.status}`);
    } catch (fallbackErr: any) {
      throw fallbackErr;
    }
  }

  throw new Error(`Failed to connect to API backend`);
}

export interface RepoItem {
  id: number;
  full_name: string;
  stars: number;
  last_activity: string;
  resume_score: number;
  status: 'candidate' | 'analyzing' | 'clean_deleted' | 'bugs_found' | 'forked' | 'pr_open' | 'expired';
  devcontainer_config?: any;
  created_at: string;
}

export interface FindingItem {
  tool?: string;
  rule_id?: string;
  severity?: string;
  path?: string;
  line?: number;
  msg?: string;
  message?: string;
  explanation?: string;
}

export interface ScanResultItem {
  id: number;
  repo_id: number;
  tool: string;
  finding_count: number;
  severity_summary?: { high: number; medium: number; low: number };
  artifact_url?: string;
  findings?: FindingItem[];
  created_at: string;
}

export interface FixItem {
  id: number;
  repo_id: number;
  issue_description: string;
  base_sha?: string;
  head_sha?: string;
  explanation?: string;
  test_status: string;
  security_status: string;
  retry_count: number;
  merge_status: string;
  source: string;
  priority_tier?: string;
  created_at: string;
}

export interface OptimizationResultItem {
  id?: number;
  fix_id: number;
  complexity_tool?: string;
  complexity_before?: number | null;
  complexity_after?: number | null;
  test_suite_duration_ms_before?: number | null;
  test_suite_duration_ms_after?: number | null;
  runtime_delta_pct?: number | null;
  summary?: string | null;
  created_at?: string;
}

export interface SuggestionItem {
  id: number;
  repo_id: number;
  title: string;
  rationale?: string;
  source_links: string[];
  effort_estimate: 'small' | 'medium' | 'large';
  status: 'proposed' | 'selected' | 'implemented' | 'skipped' | 'rejected';
  fix_id?: number | null;
  source: 'ai_research' | 'user_custom';
  created_at: string;
}

export interface AutomationStatusData {
  automation_status: 'active' | 'paused' | 'run_once';
  is_paused: boolean;
  is_run_once: boolean;
  can_search_and_scan: boolean;
  last_run_at: string | null;
  paused_at: string | null;
  run_once_at: string | null;
  description: string;
}

export const api = {
  getRepos: () => fetchApi<{ data: RepoItem[] }>('/api/repos'),
  getRepoDetail: (id: number) => fetchApi<{ data: { repo: RepoItem; scan_results: ScanResultItem[]; fixes: FixItem[] } }>(`/api/repos/${id}`),
  getReport: (id: number) => fetchApi<{ data: any }>(`/api/repos/${id}/report`),
  approvePr: (id: number) => fetchApi<{ status: string; pr_url?: string }>(`/api/repos/${id}/approve-pr`, { method: 'POST' }),
  declinePr: (id: number) => fetchApi<{ status: string }>(`/api/repos/${id}/decline-pr`, { method: 'POST' }),
  getFixDiff: (fixId: number) => fetchApi<{ status: string; fix_id: number; explanation: string; diff?: string; error?: string; decision_options?: Array<{ option_summary: string; total_score: number; selected: boolean; scores: Record<string, number> }> }>(`/api/fixes/${fixId}/diff`),
  getOptimization: (fixId: number) => fetchApi<{ status: string; data: OptimizationResultItem }>(`/api/fixes/${fixId}/optimization`),
  getSuggestions: (repoId: number) => fetchApi<{ status: string; implemented_count: number; can_implement: boolean; data: SuggestionItem[] }>(`/api/repos/${repoId}/suggestions`),
  selectSuggestion: (repoId: number, suggestionId: number) => fetchApi<{ status: string; suggestion_id: number; fix_id: number }>(`/api/repos/${repoId}/suggestions/${suggestionId}/select`, { method: 'POST' }),
  submitCustomSuggestion: (repoId: number, description: string) => fetchApi<{ status: string; suggestion_id: number; fix_id: number }>(`/api/repos/${repoId}/suggestions/custom`, { method: 'POST', body: JSON.stringify({ description }) }),
  skipSuggestions: (repoId: number) => fetchApi<{ status: string; skipped_count: number }>(`/api/repos/${repoId}/suggestions/skip`, { method: 'POST' }),
  sendMessage: (repoId: number, message: string) => fetchApi<{ data: any }>(`/api/chat/${repoId}`, { method: 'POST', body: JSON.stringify({ message }) }),
  getChatContext: (repoId: number) => fetchApi<{ status: string; data: { repo: RepoItem; findings_count: number; findings: FindingItem[]; fixes: FixItem[]; greeting: string; suggested_prompts: string[] } }>(`/api/chat/${repoId}/context`),
  getAutomationStatus: () => fetchApi<{ status: string; data: AutomationStatusData }>('/api/automation/status'),
  pauseAutomation: () => fetchApi<{ status: string; message: string; data: AutomationStatusData }>('/api/automation/pause', { method: 'POST' }),
  resumeAutomation: () => fetchApi<{ status: string; message: string; data: AutomationStatusData }>('/api/automation/resume', { method: 'POST' }),
  runOnceAutomation: (triggerNow: boolean = true) => fetchApi<{ status: string; message: string; data: AutomationStatusData }>('/api/automation/run-once', { method: 'POST', body: JSON.stringify({ trigger_now: triggerNow }) }),
};
