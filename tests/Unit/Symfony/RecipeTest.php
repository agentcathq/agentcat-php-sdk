<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Symfony;

use AgentCat\Internal\Json\Json;
use AgentCat\Symfony\AgentCatBundle;
use AgentCat\Tests\Support\JsonAssert;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Yaml\Yaml;

final class RecipeTest extends TestCase
{
    private const string RECIPE = __DIR__ . '/../../../recipe/agentcat/sdk/1.0';

    public function testTheManifestRegistersTheBundleCopiesTheConfigAndAddsTheProjectId(): void
    {
        $manifest = Json::decode((string) file_get_contents(self::RECIPE . '/manifest.json'));

        $this->assertEquals((object) [\AgentCat\Symfony\AgentCatBundle::class => ['all']], JsonAssert::at($manifest, 'bundles'));
        $implements = class_implements(AgentCatBundle::class);
        $this->assertIsArray($implements);
        $this->assertContains(BundleInterface::class, $implements);
        $this->assertEquals((object) ['config/' => '%CONFIG_DIR%/'], JsonAssert::at($manifest, 'copy-from-recipe'));
        $this->assertEquals((object) ['AGENTCAT_PROJECT_ID' => ''], JsonAssert::at($manifest, 'env'));
    }

    public function testTheCopiedConfigIsValidForTheBundle(): void
    {
        $yaml = Yaml::parseFile(self::RECIPE . '/config/packages/agentcat.yaml');
        $this->assertIsArray($yaml);
        $extension = (new AgentCatBundle())->getContainerExtension();
        $this->assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        $this->assertInstanceOf(ConfigurationInterface::class, $configuration);

        $config = (new Processor())->processConfiguration($configuration, [$yaml['agentcat'] ?? []]);

        $this->assertArrayHasKey('project_id', $config);
        $this->assertArrayHasKey('exclude_servers', $config);
        $this->assertSame('%env(default::AGENTCAT_PROJECT_ID)%', $config['project_id']);
        $this->assertSame([], $config['exclude_servers']);
    }

    public function testTheRecipeIsNotShipped(): void
    {
        $this->assertStringContainsString("/recipe export-ignore\n", (string) file_get_contents(__DIR__ . '/../../../.gitattributes'));
    }
}
