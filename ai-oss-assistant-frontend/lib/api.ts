const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost/AI/ai-oss-assistant-backend/public';
const API_TOKEN = process.env.NEXT_PUBLIC_API_TOKEN || 'dev_secret_token_12345';

async function fetchApi<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const url = `${API_BASE_URL}${endpoint}`;
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 2500); // 2.5s fast timeout to prevent browser hanging

  const headers = {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${API_TOKEN}`,
    ...(options.headers || {}),
  };

  try {
    const response = await fetch(url, { ...options, headers, signal: controller.signal });
    clearTimeout(timeoutId);
    
    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}));
      throw new Error(errorData.error || `API Request failed with status ${response.status}`);
    }

    return response.json();
  } catch (err: any) {
    clearTimeout(timeoutId);
    throw err;
  }
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

export interface ScanResultItem {
  id: number;
  repo_id: number;
  tool: string;
  finding_count: number;
  severity_summary?: { high: number; medium: number; low: number };
  artifact_url?: string;
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

export const api = {
  getRepos: () => fetchApi<{ data: RepoItem[] }>('/api/repos'),
  getRepoDetail: (id: number) => fetchApi<{ data: { repo: RepoItem; scan_results: ScanResultItem[]; fixes: FixItem[] } }>(`/api/repos/${id}`),
  getReport: (id: number) => fetchApi<{ data: any }>(`/api/repos/${id}/report`),
  approvePr: (id: number) => fetchApi<{ status: string; pr_url?: string }>(`/api/repos/${id}/approve-pr`, { method: 'POST' }),
  declinePr: (id: number) => fetchApi<{ status: string }>(`/api/repos/${id}/decline-pr`, { method: 'POST' }),
  getFixDiff: (fixId: number) => fetchApi<{ status: string; fix_id: number; explanation: string; diff?: string; error?: string }>(`/api/fixes/${fixId}/diff`),
  getOptimization: (fixId: number) => fetchApi<{ status: string; data: OptimizationResultItem }>(`/api/fixes/${fixId}/optimization`),
  getSuggestions: (repoId: number) => fetchApi<{ status: string; implemented_count: number; can_implement: boolean; data: SuggestionItem[] }>(`/api/repos/${repoId}/suggestions`),
  selectSuggestion: (repoId: number, suggestionId: number) => fetchApi<{ status: string; suggestion_id: number; fix_id: number }>(`/api/repos/${repoId}/suggestions/${suggestionId}/select`, { method: 'POST' }),
  submitCustomSuggestion: (repoId: number, description: string) => fetchApi<{ status: string; suggestion_id: number; fix_id: number }>(`/api/repos/${repoId}/suggestions/custom`, { method: 'POST', body: JSON.stringify({ description }) }),
  skipSuggestions: (repoId: number) => fetchApi<{ status: string; skipped_count: number }>(`/api/repos/${repoId}/suggestions/skip`, { method: 'POST' }),
  sendMessage: (repoId: number, message: string) => fetchApi<{ data: any }>(`/api/chat/${repoId}`, { method: 'POST', body: JSON.stringify({ message }) }),
};
