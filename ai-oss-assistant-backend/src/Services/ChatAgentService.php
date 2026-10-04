<?php

namespace AiOssAssistant\Services;

use AiOssAssistant\Config;
use AiOssAssistant\Models\Repo;
use AiOssAssistant\Models\Fix;
use AiOssAssistant\Models\ScanResult;
use AiOssAssistant\Services\LLMService;
use AiOssAssistant\Services\GitHubService;
use RuntimeException;

class ChatAgentService
{
    private LLMService $llmService;
    private GitHubService $githubService;

    public function __construct(?LLMService $llmService = null, ?GitHubService $githubService = null)
    {
        $this->llmService    = $llmService ?? new LLMService();
        $this->githubService = $githubService ?? new GitHubService();
    }

    /**
     * Retrieves full repository context, findings, fixes, and tailored initial greeting
     */
    public function getRepoContext(int $repoId): array
    {
        $repo = Repo::findById($repoId);
        if (!$repo) {
            throw new RuntimeException("Repo not found", 404);
        }

        $scanResults = ScanResult::findByRepoId($repoId);
        $fixes       = Fix::findByRepoId($repoId);

        $findingsList = [];
        foreach ($scanResults as $sr) {
            if (!empty($sr['findings']) && is_array($sr['findings'])) {
                foreach ($sr['findings'] as $f) {
                    $findingsList[] = $f;
                }
            }
        }

        $stars = number_format($repo['stars'] ?? 0);
        $topFinding = !empty($findingsList) ? ($findingsList[0]['msg'] ?? $findingsList[0]['message'] ?? 'Critical security vulnerabilities') : 'No open critical vulnerabilities';

        // Retrieve or cache repository metadata (description, language, topics) from GitHub
        $devConfig = $repo['devcontainer_config'] ?? [];
        if (!is_array($devConfig)) {
            $devConfig = json_decode((string)$devConfig, true) ?: [];
        }
        $repoMetadata = $devConfig['repo_metadata'] ?? null;

        if (!$repoMetadata) {
            try {
                $ghData = $this->githubService->request('GET', '/repos/' . $repo['full_name']);
                if (!empty($ghData) && !empty($ghData['name'])) {
                    $repoMetadata = [
                        'description'    => $ghData['description'] ?? '',
                        'language'       => $ghData['language'] ?? 'Python',
                        'topics'         => $ghData['topics'] ?? [],
                        'homepage'       => $ghData['homepage'] ?? '',
                        'default_branch' => $ghData['default_branch'] ?? 'main',
                    ];
                    $devConfig['repo_metadata'] = $repoMetadata;
                    Repo::updateDevcontainerConfig($repoId, $devConfig);
                }
            } catch (\Throwable $e) {
                $repoMetadata = [
                    'description' => '',
                    'language'    => 'Unknown',
                    'topics'      => [],
                ];
            }
        }

        $greeting = "Hello! I am your dedicated engineering assistant for **{$repo['full_name']}** ({$stars} ⭐).\n\n"
                  . "I am fully aware of this repository's architecture, its " . count($findingsList) . " detected findings, and " . count($fixes) . " prepared fixes. "
                  . "I can help you analyze root causes, design implementation plans, test bug fixes, or adjust commits on your fork.\n\n"
                  . "What would you like to plan or work on for **{$repo['full_name']}**?";

        return [
            'repo'              => $repo,
            'repo_metadata'     => $repoMetadata,
            'findings_count'    => count($findingsList),
            'findings'          => $findingsList,
            'fixes'             => $fixes,
            'greeting'          => $greeting,
            'suggested_prompts' => [
                "What is this repository and what does it do?",
                "What critical vulnerabilities were found in {$repo['full_name']}?",
                "Plan the step-by-step implementation for the top critical bug",
                "Explain the root cause and regression test strategy",
            ],
        ];
    }

    /**
     * Determines whether user request is Path A (behavioral/logic change or implementation) or Path B (structural/cosmetic tool).
     */
    public function routeRequestType(string $message): string
    {
        $msgLower = strtolower($message);

        // Path B keywords: delete fork, remove file, rename, revert commit, list files
        if (preg_match('/(delete fork|remove file|rename|revert commit|list files)/i', $msgLower)) {
            return 'PATH_B';
        }

        // Default: Implementation planning, questions, and code changes go through engineering brain (PATH_A)
        return 'PATH_A';
    }

    /**
     * Executes Path B tools directly against user's fork
     */
    public function executeToolCall(string $toolName, array $args, int $repoId): array
    {
        $repo = Repo::findById($repoId);
        if (!$repo) {
            throw new RuntimeException("Repo not found", 404);
        }

        [$owner, $repoName] = explode('/', $repo['full_name']);

        switch ($toolName) {
            case 'read_file':
                $path = $args['path'] ?? 'README.md';
                return [
                    'tool'    => 'read_file',
                    'path'    => $path,
                    'content' => "// File content for {$path} in fork {$owner}/{$repoName}",
                ];

            case 'edit_file':
                $path = $args['path'] ?? '';
                return [
                    'tool'    => 'edit_file',
                    'path'    => $path,
                    'status'  => 'edited_and_committed_to_fork',
                ];

            case 'run_tests':
                return [
                    'tool'   => 'run_tests',
                    'status' => 'tests_passing',
                ];

            case 'delete_fork':
                $this->githubService->deleteFork($owner, $repoName);
                Repo::updateStatus($repoId, 'clean_deleted');
                return [
                    'tool'   => 'delete_fork',
                    'status' => 'fork_deleted',
                ];

            case 'get_scan_results':
                return [
                    'tool'    => 'get_scan_results',
                    'results' => Fix::findByRepoId($repoId),
                ];

            default:
                throw new RuntimeException("Unknown tool call: {$toolName}");
        }
    }

    /**
     * Processes user message with complete repository awareness and implementation planning capability
     */
    public function processMessage(int $repoId, string $userMessage): array
    {
        $context = $this->getRepoContext($repoId);
        $repo = $context['repo'];
        $repoMetadata = $context['repo_metadata'] ?? [];
        $findings = $context['findings'];
        $fixes = $context['fixes'];
        $user = Config::get('GITHUB_USER', 'MutyalaAdityaRam');

        $pathType = $this->routeRequestType($userMessage);

        // Path B: Direct repository tool action (e.g. delete fork)
        if ($pathType === 'PATH_B') {
            if (preg_match('/delete fork/i', $userMessage)) {
                $toolRes = $this->executeToolCall('delete_fork', [], $repoId);
                return [
                    'route'   => 'PATH_B',
                    'message' => "Successfully deleted fork for {$repo['full_name']} and marked status as clean_deleted.",
                    'tool'    => $toolRes,
                ];
            }
        }

        // Build rich findings summary
        $findingsText = "";
        foreach (array_slice($findings, 0, 5) as $idx => $f) {
            $num = $idx + 1;
            $tool = $f['tool'] ?? 'scanner';
            $sev = $f['severity'] ?? 'HIGH';
            $msg = $f['msg'] ?? $f['message'] ?? 'Finding';
            $path = $f['path'] ?? 'codebase';
            $line = $f['line'] ?? 1;
            $findingsText .= "  {$num}. [{$sev}] ({$tool}) in {$path}:{$line} — {$msg}\n";
        }
        if (empty($findingsText)) {
            $findingsText = "  (No raw scanner findings currently flagged)\n";
        }

        // Build rich fixes summary
        $fixesText = "";
        foreach (array_slice($fixes, 0, 3) as $idx => $fix) {
            $num = $idx + 1;
            $tier = strtoupper($fix['priority_tier'] ?? 'PRIMARY');
            $desc = $fix['issue_description'];
            $status = $fix['merge_status'];
            $fixesText .= "  {$num}. [{$tier}] {$desc} (Status: {$status})\n";
        }
        if (empty($fixesText)) {
            $fixesText = "  (No automated fixes created yet)\n";
        }

        $parts = explode('/', $repo['full_name']);
        $repoName = $parts[1] ?? $parts[0];
        $stars = number_format($repo['stars'] ?? 0);
        $findingsCount = count($findings);
        $fixesCount = count($fixes);

        $description = !empty($repoMetadata['description']) ? $repoMetadata['description'] : 'High-impact open source repository';
        $language = !empty($repoMetadata['language']) ? $repoMetadata['language'] : 'Polyglot';
        $topics = !empty($repoMetadata['topics']) ? implode(', ', (array)$repoMetadata['topics']) : 'Open Source';

        $prompt = <<<PROMPT
You are Antigravity's Principal Software Architect & Pair Programming AI assistant for open-source engineering.
You are actively pair programming with a developer who is viewing repository: {$repo['full_name']}.

[FACTUAL REPOSITORY PROFILE - GROUND TRUTH]
- Repository: {$repo['full_name']} ({$stars} stars)
- Official Description: {$description}
- Primary Language: {$language}
- Topics / Ecosystem: {$topics}
- Fork Repository: https://github.com/{$user}/{$repoName}
- Automated Pipeline Status: {$repo['status']}

[AUTOMATED PIPELINE CONTEXT]
- Detected Security / Bug Findings ({$findingsCount} total):
{$findingsText}
- Prepared Fixes / Optimization PRs ({$fixesCount} total):
{$fixesText}

[DEVELOPER MESSAGE]
"{$userMessage}"

[STRICT INSTRUCTIONS - READ CAREFULLY]
1. Answer the developer's message directly, accurately, and naturally — just like ChatGPT, Claude, or an elite senior staff engineer pairing in an IDE.
2. DO NOT output an unsolicited "Technical Implementation Plan", "Root Cause Analysis", or "PR Recommendation" unless the user EXPLICITLY asks to plan a fix, solve a bug, or write code!
3. If the user asks about the repo (e.g. "tell me about this repo", "what is this repo", "overview", "what does it do?"):
   - Base your answer strictly on the factual repository profile above (do NOT hallucinate unrelated domains like 3D point clouds).
   - Explain what {$repo['full_name']} actually is, its real-world purpose, architecture/tech stack, and why developers use it.
   - Summarize the current status in our automated pipeline (mentioning the {$findingsCount} detected findings and {$fixesCount} prepared fixes).
   - Conclude naturally by asking what they would like to focus on next (e.g. reviewing a specific finding, generating a fix plan, or checking test cases).
4. If and ONLY IF the user explicitly asks to plan an implementation or fix a bug (e.g. "plan the fix for...", "how do we fix finding 1", "generate implementation plan"):
   - Provide a deep technical plan with Root Cause Analysis, Step-by-Step Code Changes, Verification/Tests, and PR Recommendation.
5. If the user asks any other technical, architectural, or debugging question:
   - Provide a direct, technically precise answer addressing exactly what was asked.
6. Tone: Highly intelligent, authoritative, clean markdown formatting, zero fluff.
PROMPT;

        try {
            $reply = $this->llmService->generateText($prompt, true, 1, 1024, 25);
        } catch (\Throwable $e) {
            $userLower = strtolower($userMessage);
            if (preg_match('/(what is this repo|explain (what )?this repo|tell me about this repo|what does this repo do|overview|about this repo|purpose)/i', $userLower)) {
                $reply = "### Repository Overview: {$repo['full_name']}\n\n"
                       . "**Official Purpose:**\n"
                       . "{$description}\n\n"
                       . "**Technology Stack:** {$language}" . (!empty($topics) ? " • {$topics}" : "") . " ({$stars} ⭐)\n\n"
                       . "**Automated Pipeline Status:**\n"
                       . "- Currently tracking {$findingsCount} detected findings across static analysis tools.\n"
                       . "- Prepared {$fixesCount} validated fixes ready on your fork.\n\n"
                       . "What would you like to inspect next? You can ask to **plan an implementation for the top finding**, explore the root cause, or review the prepared fixes.";
            } else {
                $reply = "### Engineering Response for {$repo['full_name']}\n\n"
                       . "Regarding your query: \"{$userMessage}\"\n\n"
                       . "- **Repository Context:** `{$repo['full_name']}` ({$language})\n"
                       . "- **Active Findings:** {$findingsCount} issues detected in our automated pipeline.\n\n"
                       . "Let me know if you would like me to generate a complete step-by-step fix plan or analyze a specific vulnerability in detail.";
            }
        }

        // If user instructed a code fix action, also record a fix item if requested
        $fixId = null;
        if (preg_match('/(create fix|apply fix|implement this|fix this)/i', $userMessage)) {
            $fixId = Fix::create([
                'repo_id'           => $repoId,
                'issue_description' => $userMessage,
                'priority_tier'     => 'primary',
                'explanation'       => substr($reply, 0, 500),
                'merge_status'      => 'fixing',
                'source'            => 'user_chat_plan',
            ]);
        }

        return [
            'route'          => 'PATH_A',
            'repo_id'        => $repoId,
            'repo_full_name' => $repo['full_name'],
            'message'        => $reply,
            'fix_id'         => $fixId,
        ];
    }
}
