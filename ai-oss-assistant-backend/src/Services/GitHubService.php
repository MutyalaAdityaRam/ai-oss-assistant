<?php

namespace AiOssAssistant\Services;

use AiOssAssistant\Config;
use Firebase\JWT\JWT;
use RuntimeException;
use Throwable;

class GitHubService
{
    private string $appId;
    private string $privateKey;

    public function __construct(?string $appId = null, ?string $privateKey = null)
    {
        $this->appId      = $appId ?? Config::get('GITHUB_APP_ID', '123456');
        $this->privateKey = $privateKey ?? Config::get('GITHUB_APP_PRIVATE_KEY', '');
    }

    /**
     * Generates a GitHub App JWT valid for 10 minutes.
     */
    public function generateJwt(): string
    {
        if (empty($this->privateKey) || str_contains($this->privateKey, 'BEGIN RSA PRIVATE KEY') || str_contains($this->privateKey, '...')) {
            return 'mock_jwt_token';
        }

        $now = time();
        $payload = [
            'iat' => $now - 60,
            'exp' => $now + (10 * 60),
            'iss' => $this->appId,
        ];

        try {
            return JWT::encode($payload, $this->privateKey, 'RS256');
        } catch (Throwable $e) {
            return 'mock_jwt_token';
        }
    }

    /**
     * Executes HTTP requests against the GitHub API using cURL.
     */
    public function request(string $method, string $endpoint, ?array $body = null, ?string $token = null): array
    {
        $url = str_starts_with($endpoint, 'https://') ? $endpoint : 'https://api.github.com' . $endpoint;
        $ch  = curl_init($url);

        $headers = [
            'User-Agent: AI-OSS-Contribution-Assistant/1.0',
            'Accept: application/vnd.github.v3+json',
            'Content-Type: application/json',
        ];

        $token = $token ?? Config::get('GITHUB_TOKEN', $this->generateJwt());
        if (!empty($token) && $token !== 'mock_jwt_token') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_SSL_VERIFYPEER => true, // Enforce strict TLS certificate verification
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("GitHub API cURL Error: " . $error);
        }

        $decoded = json_decode($response, true) ?? [];

        if ($status >= 400) {
            $msg = $decoded['message'] ?? "GitHub API returned HTTP {$status}";
            throw new RuntimeException($msg, $status);
        }

        return $decoded;
    }

    /**
     * Search public repositories
     */
    public function searchRepos(string $query = 'stars:>100 sort:updated-desc', int $perPage = 10): array
    {
        $endpoint = '/search/repositories?q=' . urlencode($query) . '&per_page=' . $perPage;
        return $this->request('GET', $endpoint);
    }

    /**
     * Fork a repository to the authenticated user's account
     */
    public function forkRepo(string $owner, string $repo): array
    {
        return $this->request('POST', "/repos/{$owner}/{$repo}/forks");
    }

    /**
     * Create a branch on a fork
     */
    public function createBranch(string $owner, string $repo, string $branchName, string $fromSha): array
    {
        return $this->request('POST', "/repos/{$owner}/{$repo}/git/refs", [
            'ref' => 'refs/heads/' . $branchName,
            'sha' => $fromSha,
        ]);
    }

    /**
     * Create a pull request from fork default branch to upstream default branch
     */
    public function createPullRequest(string $upstreamOwner, string $upstreamRepo, string $title, string $body, string $head, string $base = 'main'): array
    {
        return $this->request('POST', "/repos/{$upstreamOwner}/{$upstreamRepo}/pulls", [
            'title' => $title,
            'body'  => $body,
            'head'  => $head,
            'base'  => $base,
        ]);
    }

    /**
     * Trigger GitHub Actions workflow via workflow_dispatch
     */
    public function triggerWorkflow(string $owner, string $repo, string $workflowId, string $ref, array $inputs = []): array
    {
        return $this->request('POST', "/repos/{$owner}/{$repo}/actions/workflows/{$workflowId}/dispatches", [
            'ref'    => $ref,
            'inputs' => $inputs,
        ]);
    }

    /**
     * Delete a fork repository
     */
    public function deleteFork(string $owner, string $repo): array
    {
        return $this->request('DELETE', "/repos/{$owner}/{$repo}");
    }

    /**
     * Live Compare API call (Addendum §1 — Diff viewing)
     * GET /repos/{owner}/{repo}/compare/{base_sha}...{head_sha}
     */
    public function compareShas(string $owner, string $repo, string $baseSha, string $headSha): array
    {
        return $this->request('GET', "/repos/{$owner}/{$repo}/compare/{$baseSha}...{$headSha}");
    }

    /**
     * Check GitHub Actions workflow run status (Addendum §2 — Cron poller backup)
     * GET /repos/{owner}/{repo}/actions/runs/{run_id}
     */
    public function getWorkflowRunStatus(string $owner, string $repo, string $runId): array
    {
        return $this->request('GET', "/repos/{$owner}/{$repo}/actions/runs/{$runId}");
    }
}
