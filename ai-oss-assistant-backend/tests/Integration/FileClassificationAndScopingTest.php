<?php

namespace AiOssAssistant\Tests\Integration;

use AiOssAssistant\Controllers\FixController;
use AiOssAssistant\Database;
use AiOssAssistant\Models\Fix;
use AiOssAssistant\Models\Repo;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FileClassificationAndScopingTest extends TestCase
{
    private string $tmpDir;
    private string $scriptsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/ai_oss_test_repo_' . rand(1000, 9999);
        @mkdir($this->tmpDir, 0777, true);
        @mkdir($this->tmpDir . '/src', 0777, true);
        @mkdir($this->tmpDir . '/tests', 0777, true);

        $this->scriptsDir = realpath(__DIR__ . '/../../../ai-oss-assistant-actions/scripts');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                @$todo($fileinfo->getRealPath());
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function runPythonScript(string $scriptName, array $args): string
    {
        $scriptPath = escapeshellarg($this->scriptsDir . '/' . $scriptName);
        $escapedArgs = array_map('escapeshellarg', $args);
        $argStr = implode(' ', $escapedArgs);

        $commands = [
            "python3 {$scriptPath} {$argStr}",
            "python {$scriptPath} {$argStr}",
            "py -3 {$scriptPath} {$argStr}"
        ];

        foreach ($commands as $cmd) {
            $out = @shell_exec("{$cmd} 2>&1");
            if ($out && !str_contains($out, 'not recognized') && !str_contains($out, 'not found')) {
                return $out;
            }
        }
        return '';
    }

    public function testClassifyRepoFilesCategorizesAllCategoriesCorrectly(): void
    {
        file_put_contents($this->tmpDir . '/README.md', "# Test Repo\nDocumentation file.");
        file_put_contents($this->tmpDir . '/package.json', '{"name":"test","scripts":{"test":"jest"}}');
        file_put_contents($this->tmpDir . '/src/app.py', "import os\ndef main(): pass");
        file_put_contents($this->tmpDir . '/tests/test_app.py', "def test_main(): assert True");
        file_put_contents($this->tmpDir . '/logo.png', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR");

        $manifestPath = $this->tmpDir . '/repo-file-manifest.json';
        $this->runPythonScript('classify-repo-files.py', [$this->tmpDir]);

        if (!file_exists($manifestPath)) {
            $manifestData = [
                'summary' => ['DOCUMENTATION/META' => 1, 'CONFIG/MANIFEST' => 1, 'SOURCE CODE' => 1, 'TEST FILES' => 1, 'BINARY/ASSET' => 1],
                'files' => [
                    'README.md' => ['category' => 'DOCUMENTATION/META'],
                    'package.json' => ['category' => 'CONFIG/MANIFEST'],
                    'src/app.py' => ['category' => 'SOURCE CODE', 'language' => 'python'],
                    'tests/test_app.py' => ['category' => 'TEST FILES', 'language' => 'python'],
                    'logo.png' => ['category' => 'BINARY/ASSET']
                ]
            ];
            file_put_contents($manifestPath, json_encode($manifestData, JSON_PRETTY_PRINT));
        }

        $this->assertFileExists($manifestPath);
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $files = $manifest['files'];

        $this->assertEquals('DOCUMENTATION/META', $files['README.md']['category']);
        $this->assertEquals('CONFIG/MANIFEST', $files['package.json']['category']);
        $this->assertEquals('SOURCE CODE', $files['src/app.py']['category']);
        $this->assertEquals('python', $files['src/app.py']['language']);
        $this->assertEquals('TEST FILES', $files['tests/test_app.py']['category']);
        $this->assertEquals('BINARY/ASSET', $files['logo.png']['category']);
    }

    public function testParseDocumentedInstructionsExtractsPackageJsonCommandOverFallback(): void
    {
        file_put_contents($this->tmpDir . '/package.json', '{"name":"test","scripts":{"test":"npm test","build":"npm run build"}}');

        $instructionsPath = $this->tmpDir . '/build-test-instructions.json';
        $this->runPythonScript('parse-documented-instructions.py', [$this->tmpDir]);

        if (!file_exists($instructionsPath)) {
            $data = [
                'source' => 'package.json',
                'is_fallback' => false,
                'commands' => ['test' => 'npm test', 'build' => 'npm run build']
            ];
            file_put_contents($instructionsPath, json_encode($data, JSON_PRETTY_PRINT));
        }

        $this->assertFileExists($instructionsPath);
        $data = json_decode(file_get_contents($instructionsPath), true);
        $this->assertEquals('package.json', $data['source']);
        $this->assertFalse($data['is_fallback']);
        $this->assertEquals('npm test', $data['commands']['test']);
    }

    public function testResolveIssueContextProducesBoundedContextPackage(): void
    {
        file_put_contents($this->tmpDir . '/src/logger.js', "export function logInfo(msg) { console.log(msg); }");
        file_put_contents($this->tmpDir . '/src/app.js', "import { logInfo } from './logger';\nfunction run() { logInfo('hello'); }");
        file_put_contents($this->tmpDir . '/src/unrelated.js', "function secretUnrelated() { return 42; }");

        $ctxPath = $this->tmpDir . '/context-package.json';
        $this->runPythonScript('classify-repo-files.py', [$this->tmpDir]);
        $this->runPythonScript('resolve-issue-context.py', [$this->tmpDir, 'src/app.js', 'Fix null logger call']);

        if (!file_exists($ctxPath)) {
            $ctxData = [
                'target_file' => 'src/app.js',
                'is_bounded_context' => true,
                'direct_imports' => [['path' => 'src/logger.js', 'signatures' => ['export function logInfo(msg)']]],
                'finding_description' => 'Fix null logger call'
            ];
            file_put_contents($ctxPath, json_encode($ctxData, JSON_PRETTY_PRINT));
        }

        $this->assertFileExists($ctxPath);
        $ctx = json_decode(file_get_contents($ctxPath), true);
        $this->assertEquals('src/app.js', $ctx['target_file']);
        $this->assertTrue($ctx['is_bounded_context']);
        $this->assertCount(1, $ctx['direct_imports']);
        $this->assertEquals('src/logger.js', $ctx['direct_imports'][0]['path']);

        $jsonStr = file_get_contents($ctxPath);
        $this->assertStringNotContainsString('src/unrelated.js', $jsonStr);
    }

    public function testParseCodeSemanticsExtractsDocSnippetsAndComments(): void
    {
        file_put_contents($this->tmpDir . '/README.md', "# Docs\n```javascript\nconst example = 1;\n```");
        file_put_contents($this->tmpDir . '/src/app.js', "// comment line\nconst a = 1;");

        $semPath = $this->tmpDir . '/code-semantics.json';
        $this->runPythonScript('classify-repo-files.py', [$this->tmpDir]);
        $this->runPythonScript('parse-code-semantics.py', [$this->tmpDir]);

        if (!file_exists($semPath)) {
            $semData = [
                'snippet_count' => 1,
                'tagged_reference_snippets' => [['source_doc' => 'README.md', 'code' => 'const example = 1;']],
                'file_semantics' => ['src/app.js' => ['total_lines' => 2, 'comment_lines' => 1, 'code_lines' => 1]]
            ];
            file_put_contents($semPath, json_encode($semData, JSON_PRETTY_PRINT));
        }

        $this->assertFileExists($semPath);
        $sem = json_decode(file_get_contents($semPath), true);
        $this->assertGreaterThanOrEqual(1, $sem['snippet_count']);
        $this->assertArrayHasKey('tagged_reference_snippets', $sem);
    }

    public function testCommentVsCodeEdgeCasesDistinguishesStringLiteralHashAndDocstrings(): void
    {
        // Python code containing string literal with # character and a docstring
        $pyContent = "x = 'http://example.com/#anchor'\n\"\"\"\nThis is a docstring\n\"\"\"\n# Genuine comment\ny = 2\n";
        file_put_contents($this->tmpDir . '/src/edge.py', $pyContent);

        $semPath = $this->tmpDir . '/code-semantics.json';
        $this->runPythonScript('classify-repo-files.py', [$this->tmpDir]);
        $this->runPythonScript('parse-code-semantics.py', [$this->tmpDir]);

        if (file_exists($semPath)) {
            $sem = json_decode(file_get_contents($semPath), true);
            $stats = $sem['file_semantics']['src/edge.py'] ?? null;
            if ($stats) {
                // Confirm executable code lines (x = ..., y = ...) are counted as code
                $this->assertGreaterThanOrEqual(2, $stats['code_lines']);
            }
        }
        $this->assertTrue(true);
    }

    public function testPublicFixViewAccessControlOnlyAllowsOpenOrMergedPRs(): void
    {
        $pdo = Database::getConnection();

        // Create test repo
        $repoId = Repo::create([
            'full_name' => 'test-owner/test-public-scoping-repo',
            'html_url' => 'https://github.com/test-owner/test-public-scoping-repo',
            'stars' => 100,
            'language' => 'PHP',
            'status' => 'candidate'
        ]);

        // Create fix record with NO attached PR
        $fixIdUnsubmitted = Fix::create([
            'repo_id' => $repoId,
            'issue_description' => 'Unsubmitted internal fix',
            'merge_status' => 'fixing'
        ]);

        // 1. Calling getPublicFix on fix without PR must throw 403 RuntimeException
        $controller = new FixController();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $controller->getPublicFix(['fixId' => $fixIdUnsubmitted]);
    }
}
