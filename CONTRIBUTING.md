# Contributing to AgentCat 🎉

Thank you for your interest in contributing to AgentCat! We're excited to have you join our community of developers building analytics tools for MCP servers.

## Getting Started

1. **Fork the repository** on GitHub
2. **Clone your fork** locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/agentcat-php-sdk.git
   cd agentcat-php-sdk
   ```
3. **Install dependencies** using Composer (PHP 8.3 or later, with ext-curl, ext-json and ext-mbstring):
   ```bash
   composer install
   ```
4. **Create a branch** for your feature or fix:
   ```bash
   git checkout -b feature/your-feature-name
   # or
   git checkout -b fix/your-bug-fix
   ```

## Development Process

### Making Changes

1. **Write your code** following our PHP standards: `declare(strict_types=1)`, `final` classes, native types, and `@internal` on every class that is not public API
2. **Add tests** for new features (required for feature additions)
3. **Run every check** to ensure everything passes:
   ```bash
   composer check
   ```
4. **Fix the code style** and apply Rector when `composer check` reports changes:
   ```bash
   composer cs:fix            # Run php-cs-fixer
   vendor/bin/rector process  # Apply the Rector rules
   ```

### Commit Conventions

We follow [Conventional Commits](https://www.conventionalcommits.org/). Your commit messages should be structured as:

```
<type>: <description>

[optional body]
```

**Types:**

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `test`: Adding or updating tests
- `refactor`: Code change that neither fixes a bug nor adds a feature
- `ci`: Changes to the CI workflows
- `build`: Changes to the dependencies or the package
- `chore`: Changes to build process or auxiliary tools

**Examples:**

```bash
git commit -m "feat: add telemetry exporters for observability"
git commit -m "fix: handle edge case in session tracking"
git commit -m "docs: update API documentation"
```

## Pull Request Process

1. **Push your changes** to your fork:

   ```bash
   git push origin feature/your-feature-name
   ```

2. **Create a Pull Request** from your fork to our `main` branch

3. **Fill out the PR description** with:

   - What changes you've made
   - Why these changes are needed
   - Any relevant context or screenshots

4. **Wait for review** - We will review your PR as soon as we can

5. **Address feedback** if any changes are requested

6. **Celebrate** 🎉 once your PR is merged!

### The Required Check

The one required check is `All green`. It passes when every CI job passes: static analysis on the lowest and the highest dependencies, the tests on PHP 8.3, 8.4 and 8.5, coverage, the released-version legs, the runtime suite, the package probe and the backward-compatibility check. The released-version legs take their versions from Packagist on each run: the newest patch of each minor release of `mcp/sdk`, `laravel/mcp` and `symfony/mcp-bundle`, from the lowest version that `require-dev` allows. The package probe runs on each of those `mcp/sdk` releases. The nightly runs the legs and the probe again, so a release published between pull requests that breaks them opens an issue. When the install step of a leg fails, `require-dev` does not allow that release yet: widen its range, or the range of the framework that it needs.

### No Issue Required

You don't need to open an issue before submitting a PR. Feel free to submit pull requests directly with your improvements!

## Good First Issues

Looking for a place to start? Check out issues labeled [`good first issue`](https://github.com/agentcathq/agentcat-php-sdk/labels/good%20first%20issue) - these are great for newcomers to the codebase.

## Testing

- New features **should include tests** to ensure reliability
- Run tests locally with `vendor/bin/phpunit`
- We use [PHPUnit](https://phpunit.de/) for our test suite
- Test files live under `tests/`, in the suite that fits (`Unit`, `Integration`, `Contract`, `E2E`, …), at the path of the code they test with a `Test.php` suffix: `tests/Unit/Internal/Engine/ToolListingTest.php` tests `src/Internal/Engine/ToolListing.php`

Some tests need more than PHPUnit:

```bash
# PHPStan and the adapter suites against mcp/sdk 0.9.0, in a throwaway copy under .cache/
tests/bin/mcp-sdk-leg.sh

# The releases that the released-version legs test, read from Packagist
php tests/bin/released-versions.php

# The runtime suite: PHP-FPM, FrankenPHP, Apache, NGINX Unit, the CLI and Swoole, in Docker
AGENTCAT_RUNTIME_TESTS=1 vendor/bin/phpunit --testsuite Runtime --group runtime

# The package probe: installs the dist (git archive HEAD, so commit first) in a fresh project and tracks a server
tests/bin/package-probe.sh 0.9.0
```

The runtime containers write files as root or www-data into the test home under `.cache/test-home`, and a test that ends removes them. When you kill a runtime run before it ends (for example with Ctrl+C), those files can stay there: remove them with `docker run --rm -v "$PWD/.cache:/cache" php:8.3-cli rm -rf /cache/test-home`, or with `sudo rm -rf .cache/test-home`.

## Code Quality

Before submitting your PR, ensure your code passes all checks:

```bash
# Run all checks at once
composer check

# Or run them individually
composer validate --strict    # Validate composer.json
composer normalize --dry-run  # Check that composer.json is normalized
composer cs:check             # Check code style
composer rector:check         # Check the Rector rules
composer stan                 # Check types with PHPStan (level max, in both configurations)
composer deps                 # Check the declared dependencies
composer test                 # Run tests
```

Our CI will run these same checks on your PR.

## Dependencies

While we don't restrict adding new dependencies, they are generally **discouraged** unless absolutely necessary. If you need to add a dependency:

1. Consider if the functionality can be achieved with existing dependencies
2. Check if the dependency is well-maintained and lightweight
3. Ensure it's compatible with our MIT license
4. Add it using Composer: `composer require <package-name>`

## Project Structure

```
agentcat-php-sdk/
├── src/                  # Source code
│   ├── AgentCat.php      # Main entry point: track(), publishCustomEvent(), fake()
│   ├── McpSdk/           # The mcp/sdk adapter
│   ├── LaravelMcp/       # The laravel/mcp adapter
│   ├── Laravel/          # The Laravel service provider and agentcat:install
│   ├── Symfony/          # The Symfony bundle
│   ├── Exporters/        # The OTLP, Datadog, Sentry and PostHog exporters
│   ├── Testing/          # AgentCat::fake() and its assertions
│   └── Internal/         # The engine, delivery, telemetry and diagnostics
├── config/               # config/agentcat.php for Laravel
├── resources/boost/      # The Laravel Boost guidelines for AI agents
├── recipe/               # The Symfony Flex recipe
├── tests/                # Test files
└── docs/                 # The README images
```

## Community

- **Discord**: Join our [Discord server](https://discord.gg/n9qpyhzp2u) for discussions
- **Documentation**: Visit [docs.agentcat.com](https://docs.agentcat.com) for detailed guides
- **Issues**: Browse [open issues](https://github.com/agentcathq/agentcat-php-sdk/issues) for areas needing help

## Versioning

The AgentCat team handles versioning and releases. Your contributions will be included in the next appropriate release based on semantic versioning principles: everything that is not marked `@internal` is public API, and the backward-compatibility check compares each PR with the latest 1.x release.

Each pull request with a user-facing change adds its entry under `## [Unreleased]` in `CHANGELOG.md`, in the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format. A release takes its notes from these entries.

## Release a version

The release workflow (`.github/workflows/release.yml`) does the release in two steps, with a pull request between them. The Java and Rust SDKs release the same way.

The SDK has no version file: `composer.json` has no `version`, and Packagist reads each version from its `vX.Y.Z` tag. So the tag is the publication, and the workflow needs no Packagist credential.

1. In GitHub, open **Actions > Release > Run workflow** on `main`. Keep `patch`, or type `minor`, `major` or an exact version such as `1.2.0`. The words count from the newest `vX.Y.Z` tag.
2. The workflow opens the pull request "Release vX.Y.Z". It turns `[Unreleased]` into the dated section of the version in `CHANGELOG.md`, and starts CI on the pull request. An `[Unreleased]` section with no entry, a tag `vX.Y.Z` that exists, or a version that Packagist already lists stops the workflow.
3. Read the pull request, and merge it when CI passes.
4. The merge starts the workflow again. It waits for CI on the merge commit, and then creates the tag `vX.Y.Z` on that commit. The GitHub hook of Packagist reads the tag, and the workflow waits until Packagist lists the version. Then it creates the GitHub release of the tag, with the CHANGELOG section as the notes.

If a step fails, fix the cause and re-run the failed jobs of that run. The tag comes before the Packagist check and the GitHub release, so each step skips the work that is done: a tag on the merge commit, a version that Packagist lists, a GitHub release that exists. A tag of the version on another commit stops the workflow: the workflow never moves or deletes a tag.

When Packagist does not list the version in 30 minutes, the workflow fails. Check the Packagist webhook of the repository (**Settings > Webhooks**, `https://packagist.org/api/github`) and its recent deliveries, or press **Update** on the [package page](https://packagist.org/packages/agentcat/sdk) as a maintainer. Then re-run the failed jobs.

Do not push a `vX.Y.Z` tag by hand. Packagist publishes every tag that it reads, and a tag that moves later changes a version that people installed. Only the release workflow tags a release. Pre-releases (`v1.2.0-rc.1`) stay a manual task: the workflow releases only `X.Y.Z` versions, and its version words do not count pre-release tags.

To run a step of the workflow by hand, use `php tests/bin/release.php prepare <patch | minor | major | X.Y.Z> <YYYY-MM-DD>`, `status` or `notes <X.Y.Z>`. The script needs PHP alone, not `composer install`. `tests/Unit/ReleaseTest.php` tests these steps in the normal suite.

### One-time setup

- In the repository settings, turn on **Actions > General > Allow GitHub Actions to create and approve pull requests**, so that the workflow can open the release pull request.
- Create a `release` environment, limited to the `main` branch.
- Keep the Packagist webhook of the repository: **Settings > Webhooks**, with the payload URL `https://packagist.org/api/github` and the `push` event. The package page on Packagist says "This package is auto-updated." while the hook works.

## Recognition

All contributors are recognized in our repository. Your contributions help make AgentCat better for everyone building MCP servers!

## Questions?

If you have questions about contributing, feel free to:

- Ask in our [Discord server](https://discord.gg/n9qpyhzp2u)
- Open an [issue](https://github.com/agentcathq/agentcat-php-sdk/issues) on GitHub

We answer issues and pull requests as soon as we can.

Thank you for contributing to AgentCat! 🐱
