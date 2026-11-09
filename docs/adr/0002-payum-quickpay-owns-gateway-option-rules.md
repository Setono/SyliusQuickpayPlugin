# payum-quickpay owns what a gateway option means; the plugin never interprets a gateway configuration itself

The plugin reads a gateway configuration only through its gateway configuration module, and the module delegates what every gateway option means (deprecated 1.x names, defaults, normalization, validation) to payum-quickpay's `GatewayOptions`, the same code the gateway itself is built from. The plugin used to read the stored configuration in eight places with rules of its own, and they had drifted from the library's: the doctor passed configurations the gateway then rejected on every operation, the admin form accepted values the gateway rejects, and re-saving an unchanged form could change the agreement or the capture mode. With one rule, saving refuses what the gateway would reject, the doctor checks what the gateway checks, and the admin form shows what checkout uses.

## Considered Options

- **The plugin keeps its own copy of the rules**, pinned to the library's by tests. Rejected: it is the copy that drifted, and the library's normalizers were private, so a pinning test can only sample cases.
- **The plugin builds the gateway (the factory's `createConfig()` and `payum.api` closure) to read a configuration.** Rejected: it builds an SDK client on every read, reports only the first invalid option, and exposes no api key.

## Consequences

- **An unusable gateway configuration is an error wherever it is read.** An invalid configuration (written by fixtures, the CLI, or the database directly, which skip validation) or an encrypted one makes the module throw. Each reader applies its existing error policy, so the shared callback endpoint answers 500 and Quickpay retries, the fraud check fails open, and checkout shows no logos. Only the doctor and the admin form handle the violations in detail. Encrypting gateway configurations is not supported.
- **The plugin owns only the capture mode.** It is Sylius' `use_authorize` option, which the library never reads, with the deprecated `auto_capture` folded in by the library's rule.
- **A new gateway option starts in the library**, and the plugin adds only its form field and its translated save message. Saving shows the admin, in their language, what each rejected option accepts, so every option the library can reject has a message of its own in the plugin; until it has one, a generic message names the option.
