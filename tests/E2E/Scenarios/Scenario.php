<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Scenarios;

use AgentCat\Identity;
use AgentCat\Internal\Constants;
use AgentCat\Options;

/**
 * The 22 end-to-end scenarios of the TypeScript SDK's real-HTTP matrix, by their TypeScript names, with the options each
 * one tracks its server with. A lane's server picks its scenario by name; the values the scenarios assert are the
 * constants here, as in TypeScript. `RebuildOnDemand` runs on the per-request lanes only, `SessionContinuityStateful`
 * on the long-lived lane only; every other scenario runs on every lane.
 */
enum Scenario: string
{
    public const string SESSION_HOOK_VALUE = 'corr-7';

    public const string AGENT_SESSION_HOOK_VALUE = 'corr-agent-7';

    public const string CUSTOM_CONTEXT_DESCRIPTION = 'why?';

    public const string REDACTION_SECRET = 'SECRET_TOKEN';

    public const string REDACTION_REPLACEMENT = '[REDACTED]';

    public const string IDENTITY_USER_ID = 'e2e-user-1';

    public const string IDENTITY_USER_NAME = 'E2E User';

    public const array IDENTITY_USER_DATA = ['plan' => 'enterprise'];

    public const string CUSTOMER_TAG_KEY = 'e2e_customer_tag';

    public const string CUSTOMER_TAG_VALUE = 'customer-tag-present';

    /** The customer's value for an SDK tag: the SDK's own value must win. */
    public const string COLLIDING_TAG_VALUE = 'customer-collision-loses';

    public const string CUSTOMER_PROP_KEY = 'e2e_customer_prop';

    public const string CUSTOMER_PROP_VALUE = 'prop-value';

    case BaselineDefaults = 'baseline-defaults';
    case TracingOff = 'tracing-off';
    case TracingOffReportMissingOn = 'tracing-off-x-report-missing-on';
    case ReportMissingOff = 'report-missing-off';
    case ReportMissingCall = 'report-missing-call';
    case ContextOff = 'context-off';
    case ContextCustomDescription = 'context-custom-description';
    case AgentTrackingMint = 'agent-tracking-mint';
    case AgentTrackingSupplied = 'agent-tracking-supplied';
    case AgentTrackingHook = 'agent-tracking-x-hook';
    case TaskHookDeterministic = 'task-hook-deterministic';
    case TaskHookNull = 'task-hook-null';
    case TaskSuppliedContinuity = 'task-supplied-continuity';
    case MintBackStructuredMirror = 'mint-back-structured-mirror';
    case StrictSchemaInjection = 'strict-schema-injection';
    case StripProofLoose = 'strip-proof-loose';
    case ErrorPath = 'error-path';
    case HooksIdentityTagsProps = 'hooks-identity-tags-props';
    case Redaction = 'redaction';
    case ClientIdentityLadder = 'client-identity-ladder';
    case RebuildOnDemand = 'rebuild-on-demand';
    case SessionContinuityStateful = 'session-continuity-stateful';

    /** The options of the scenario's server, with `$apiBaseUrl` as the AgentCat API (the test's collector scenario). */
    public function options(?string $apiBaseUrl): Options
    {
        return match ($this) {
            self::TracingOff, self::TracingOffReportMissingOn => new Options(enableTracing: false, apiBaseUrl: $apiBaseUrl),
            self::ReportMissingOff => new Options(enableReportMissing: false, apiBaseUrl: $apiBaseUrl),
            self::ContextOff => new Options(enableToolCallContext: false, apiBaseUrl: $apiBaseUrl),
            self::ContextCustomDescription => new Options(customContextDescription: self::CUSTOM_CONTEXT_DESCRIPTION, apiBaseUrl: $apiBaseUrl),
            self::AgentTrackingMint, self::AgentTrackingSupplied => new Options(enableAgentTracking: true, apiBaseUrl: $apiBaseUrl),
            self::AgentTrackingHook => new Options(
                enableAgentTracking: true,
                resolveSessionId: static fn(): string => self::AGENT_SESSION_HOOK_VALUE,
                apiBaseUrl: $apiBaseUrl,
            ),
            self::TaskHookDeterministic => new Options(resolveSessionId: static fn(): string => self::SESSION_HOOK_VALUE, apiBaseUrl: $apiBaseUrl),
            self::TaskHookNull => new Options(resolveSessionId: static fn(): ?string => null, apiBaseUrl: $apiBaseUrl),
            self::HooksIdentityTagsProps => new Options(
                identify: static fn(): Identity => new Identity(self::IDENTITY_USER_ID, self::IDENTITY_USER_NAME, self::IDENTITY_USER_DATA),
                apiBaseUrl: $apiBaseUrl,
                eventTags: static fn(): array => [
                    Constants::TAG_SESSION_SOURCE => self::COLLIDING_TAG_VALUE, // a collision on an SDK tag: the SDK's value must win
                    self::CUSTOMER_TAG_KEY => self::CUSTOMER_TAG_VALUE,
                ],
                eventProperties: static fn(): array => [self::CUSTOMER_PROP_KEY => self::CUSTOMER_PROP_VALUE],
            ),
            self::Redaction => new Options(
                redactSensitiveInformation: static fn(string $text): string => str_replace(self::REDACTION_SECRET, self::REDACTION_REPLACEMENT, $text),
                apiBaseUrl: $apiBaseUrl,
            ),
            default => new Options(apiBaseUrl: $apiBaseUrl),
        };
    }
}
