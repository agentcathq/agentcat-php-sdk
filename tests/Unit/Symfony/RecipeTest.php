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

        $this->assertEquals((object) [AgentCatBundle::class => ['all']], JsonAssert::at($manifest, 'bundles'));
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
        $this->assertArrayHasKey('agentcat', $yaml);
        $this->assertIsArray($yaml['agentcat']);
        $this->assertArrayHasKey('project_id', $yaml['agentcat']);
        $this->assertSame('%env(default::AGENTCAT_PROJECT_ID)%', $yaml['agentcat']['project_id']);
        $extension = (new AgentCatBundle())->getContainerExtension();
        $this->assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        $this->assertInstanceOf(ConfigurationInterface::class, $configuration);

        $config = (new Processor())->processConfiguration($configuration, [$yaml['agentcat']]);

        $this->assertArrayHasKey('project_id', $config);
        $this->assertArrayHasKey('exclude_servers', $config);
        $this->assertSame('%env(default::AGENTCAT_PROJECT_ID)%', $config['project_id']);
        $this->assertSame([], $config['exclude_servers']);
    }

    public function testTheRecipeIsNotShipped(): void
    {
        $lines = file(__DIR__ . '/../../../.gitattributes', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertContains('/recipe export-ignore', array_map(trim(...), $lines));
    }
}
