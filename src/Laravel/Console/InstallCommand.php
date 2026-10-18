<?php

declare(strict_types=1);

namespace AgentCat\Laravel\Console;

use AgentCat\Laravel\Internal\EnvFile;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;

/**
 * `php artisan agentcat:install`: writes `AGENTCAT_PROJECT_ID` into `.env` and an empty key into `.env.example`, and
 * publishes `config/agentcat.php` with `--config`. It sends nothing over the network.
 *
 * @internal
 */
final class InstallCommand extends Command
{
    // Set in the constructor, not as property overrides: #[\Override] on a property needs PHP 8.5, and PHPStan on
    // 8.5 demands it.
    public function __construct()
    {
        $this->signature = <<<'SIGNATURE'
            agentcat:install
                {project? : Your AgentCat project ID (proj_…)}
                {--config : Also publish config/agentcat.php}
            SIGNATURE;
        $this->description = 'Set up AgentCat for this application';
        parent::__construct();
    }

    public function handle(Application $app): int
    {
        $project = $this->argument('project');
        $project = is_string($project) && $project !== '' ? $project : $this->ask('Your AgentCat project ID (proj_…)');
        if (!is_string($project) || preg_match('/\Aproj_[0-9A-Za-z]{27}\z/', $project) !== 1) {
            $this->error('The project ID must be proj_ followed by 27 letters or digits.');

            return self::FAILURE;
        }
        $env = $app->environmentFilePath();
        try {
            EnvFile::set($env, 'AGENTCAT_PROJECT_ID', $project);
            EnvFile::ensure($env . '.example', 'AGENTCAT_PROJECT_ID');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('config') === true) {
            $this->call('vendor:publish', ['--tag' => 'agentcat-config']);
        }
        $this->info(sprintf('AgentCat is set up: AGENTCAT_PROJECT_ID is in %s. Every laravel/mcp server is tracked, except the excluded ones in config/agentcat.php.', $env));

        return self::SUCCESS;
    }
}
