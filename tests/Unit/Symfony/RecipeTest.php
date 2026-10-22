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
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\Yaml\Yaml;

final class RecipeTest extends TestCase
{
    private const string RECIPE = __DIR__ . '/../../../recipe/agentcat/sdk/1.0';

    public function testTheManifestRegistersTheBundleCopiesTheConfigAndAddsTheProjectId(): void
    {
        $manifest = Json::decode((string) file_get_contents(self::RECIPE . '/manifest.json'));

        $this->assertEquals((object) [AgentCatBundle::class => ['all']], JsonAssert::at($manifest, 'bundles'));
        $parents = class_parents(AgentCatBundle::class); // Bundle: Symfony 8.1 deprecates HttpKernel's BundleInterface
        $this->assertIsArray($parents);
        $this->assertContains(Bundle::class, $parents);
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

        $config = $this->process($yaml['agentcat']);

        $this->assertArrayHasKey('project_id', $config);
        $this->assertArrayHasKey('exclude_servers', $config);
        $this->assertSame('%env(default::AGENTCAT_PROJECT_ID)%', $config['project_id']);
        $this->assertSame([], $config['exclude_servers']);
    }

    /**
     * A bare `%env()%` throws when its variable is not set, which makes the whole agentcat configuration unreadable:
     * every server then answers untracked. So every variable of the recipe has a default, in the examples too.
     */
    public function testEveryVariableOfTheRecipeHasADefault(): void
    {
        $yaml = (string) file_get_contents(self::RECIPE . '/config/packages/agentcat.yaml');

        $this->assertSame(2, preg_match_all('/%env\(([^)]*)\)%/', $yaml, $variables));
        foreach ($variables[1] as $variable) {
            $this->assertStringStartsWith('default::', $variable);
        }
    }

    /** The examples, once uncommented, are valid for the bundle; an unset variable makes the project of the server `project_id`. */
    public function testTheExamplesAreValidForTheBundle(): void
    {
        $lines = file(self::RECIPE . '/config/packages/agentcat.yaml', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $yaml = Yaml::parse(implode("\n", array_map(static fn(string $line): string => (string) preg_replace('/^    # /', '    ', $line), $lines)));
        $this->assertIsArray($yaml);
        $this->assertIsArray($yaml['agentcat'] ?? null);

        $config = $this->process($yaml['agentcat']);

        $this->assertSame(['internal'], $config['exclude_servers'] ?? null);
        $this->assertSame(['billing' => '%env(default::AGENTCAT_BILLING_PROJECT_ID)%'], $config['projects'] ?? null);
    }

    public function testTheRecipeIsNotShipped(): void
    {
        $lines = file(__DIR__ . '/../../../.gitattributes', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertContains('/recipe export-ignore', array_map(trim(...), $lines));
    }

    /**
     * The agentcat configuration as the bundle's tree processes it.
     *
     * @param array<array-key, mixed> $agentcat
     *
     * @return array<array-key, mixed>
     */
    private function process(array $agentcat): array
    {
        $extension = (new AgentCatBundle())->getContainerExtension();
        $this->assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        $this->assertInstanceOf(ConfigurationInterface::class, $configuration);

        return (new Processor())->processConfiguration($configuration, [$agentcat]);
    }
}
