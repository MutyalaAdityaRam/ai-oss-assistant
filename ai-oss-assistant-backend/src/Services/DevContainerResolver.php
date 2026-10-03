<?php

namespace AiOssAssistant\Services;

class DevContainerResolver
{
    public const UNIVERSAL_IMAGE = 'mcr.microsoft.com/devcontainers/universal';
    public const NODE_TEMPLATE    = 'mcr.microsoft.com/devcontainers/typescript-node';
    public const PYTHON_TEMPLATE  = 'mcr.microsoft.com/devcontainers/python';
    public const GO_TEMPLATE      = 'mcr.microsoft.com/devcontainers/go';
    public const JAVA_TEMPLATE    = 'mcr.microsoft.com/devcontainers/java';
    public const RUST_TEMPLATE    = 'mcr.microsoft.com/devcontainers/rust';

    /**
     * Resolves the appropriate devcontainer image/template based on detected repository files.
     *
     * @param array $fileList List of relative file paths present in the repository
     * @return array Config array containing resolved template and image
     */
    public static function resolve(array $fileList): array
    {
        // 1. If repo maintainers already defined a devcontainer, respect it
        foreach ($fileList as $file) {
            if ($file === '.devcontainer/devcontainer.json' || str_ends_with($file, '/devcontainer.json')) {
                return [
                    'custom'  => true,
                    'file'    => $file,
                    'image'   => 'custom',
                    'reason'  => 'Existing .devcontainer/devcontainer.json found',
                ];
            }
        }

        // 2. Detect project manifests
        $hasNode   = in_array('package.json', $fileList, true);
        $hasPython = in_array('requirements.txt', $fileList, true) || in_array('pyproject.toml', $fileList, true);
        $hasGo     = in_array('go.mod', $fileList, true);
        $hasJava   = in_array('pom.xml', $fileList, true) || in_array('build.gradle', $fileList, true);
        $hasRust   = in_array('Cargo.toml', $fileList, true);

        $matches = [];
        if ($hasNode)   $matches[] = self::NODE_TEMPLATE;
        if ($hasPython) $matches[] = self::PYTHON_TEMPLATE;
        if ($hasGo)     $matches[] = self::GO_TEMPLATE;
        if ($hasJava)   $matches[] = self::JAVA_TEMPLATE;
        if ($hasRust)   $matches[] = self::RUST_TEMPLATE;

        // Multiple matches or no matches fallback to universal
        if (count($matches) === 1) {
            return [
                'custom' => false,
                'image'  => $matches[0],
                'reason' => 'Single language manifest detected',
            ];
        }

        return [
            'custom' => false,
            'image'  => self::UNIVERSAL_IMAGE,
            'reason' => count($matches) > 1 ? 'Multiple language manifests detected' : 'No standard manifest detected',
        ];
    }
}
