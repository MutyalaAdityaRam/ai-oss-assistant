<?php

namespace AiOssAssistant\Tests\Unit;

use AiOssAssistant\Services\DevContainerResolver;
use PHPUnit\Framework\TestCase;

class DevContainerResolverTest extends TestCase
{
    public function testNodeManifestDetection(): void
    {
        $result = DevContainerResolver::resolve(['package.json', 'src/index.js']);
        $this->assertEquals(DevContainerResolver::NODE_TEMPLATE, $result['image']);
        $this->assertFalse($result['custom']);
    }

    public function testPythonManifestDetection(): void
    {
        $result = DevContainerResolver::resolve(['requirements.txt', 'main.py']);
        $this->assertEquals(DevContainerResolver::PYTHON_TEMPLATE, $result['image']);
    }

    public function testGoManifestDetection(): void
    {
        $result = DevContainerResolver::resolve(['go.mod', 'main.go']);
        $this->assertEquals(DevContainerResolver::GO_TEMPLATE, $result['image']);
    }

    public function testJavaManifestDetection(): void
    {
        $result = DevContainerResolver::resolve(['pom.xml']);
        $this->assertEquals(DevContainerResolver::JAVA_TEMPLATE, $result['image']);
    }

    public function testRustManifestDetection(): void
    {
        $result = DevContainerResolver::resolve(['Cargo.toml']);
        $this->assertEquals(DevContainerResolver::RUST_TEMPLATE, $result['image']);
    }

    public function testMultipleManifestsFallbackToUniversal(): void
    {
        $result = DevContainerResolver::resolve(['package.json', 'requirements.txt']);
        $this->assertEquals(DevContainerResolver::UNIVERSAL_IMAGE, $result['image']);
    }

    public function testExistingDevContainerJsonRespected(): void
    {
        $result = DevContainerResolver::resolve(['.devcontainer/devcontainer.json', 'package.json']);
        $this->assertTrue($result['custom']);
        $this->assertEquals('.devcontainer/devcontainer.json', $result['file']);
    }
}
