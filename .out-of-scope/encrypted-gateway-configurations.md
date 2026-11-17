# Encrypted gateway configurations

The plugin doesn't support gateway configurations encrypted with PayumBundle's encryption
(`payum.dynamic_gateways.encryption.defuse_secret_key`). It reads one nothing has decrypted as unusable
(`UnusableGatewayConfiguration`, `isUnreadable()`), and each reader applies its error policy (ADR 0002).

## Why this is out of scope

- **Sylius core doesn't support it either.** Sylius' `PayumController` reads `use_authorize` from the stored
  configuration before anything decrypts it. With encryption on, the capture mode "on completion" silently becomes
  "immediately" at checkout. Supporting encryption means working around Sylius core.
- **It costs more than it brings.** It needs the gateway configuration module to decrypt through Payum's cypher, a
  test environment of its own with encryption on, and checks of saving in the admin, where empty options are written
  as null and defuse's encryption expects strings. No store has asked for it.

## Prior requests

- #163: "Support encrypted gateway configurations"
