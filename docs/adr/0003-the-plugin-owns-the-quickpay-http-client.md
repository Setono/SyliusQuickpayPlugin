# The plugin owns the Quickpay HTTP client, with one policy for every request

The Payum gateway built its own SDK client through PSR-18 discovery: no total time limit, and a 60 s idle timeout. Meanwhile, the plugin's own calls (fraud check, operations panel, fraud report, doctor, save validation) built clients from the gateway configuration's api key with a 5 s cap. So the same Quickpay payment was reached over two roads with different timeouts, and an app could change neither. Now the plugin builds every SDK client on one Symfony scoped HTTP client, and hands the gateway its client through payum-quickpay's `quickpay.client` option. Every request to Quickpay gets the same policy: 10 s idle, 10 s total, no retries. An app can override it in its own `framework.http_client` configuration.

## Considered Options

- **The gateway is the only road**, with the plugin's own calls becoming Payum requests handled by plugin actions. Rejected for two reasons: no library request returns a Quickpay payment, so every call would need a request and an action of its own; and the doctor and save validation check gateway configurations that need no gateway, such as one being edited.
- **Two policies**: short for reads and long for money operations, or short for admin pages and long for background work. Rejected: the SDK sends through PSR-18, which can't vary options per request without a client of the plugin's own. And a timed-out capture recovers by itself: the library refuses a second operation while one is in flight, and the callback or the reconcile command brings the Sylius payment in line.
- **Two roads with a shared policy**. Rejected: it fixes the timeouts but leaves every caller turning an api key into a client.

## Consequences

- **The 10 s ceiling is set against the reconcile lock.** A callback makes up to four sequential reads, which must finish inside the per-payment lock's 60 s TTL. Raising the timeout means raising the lock's TTL.
- **No retries by default.** Symfony's `retry_failed` also retries POSTs on some statuses (423, 425, 429, 502, 503), and a retried capture or refund can move money twice. An app that enables retries should limit them to GET; the README shows how.
- **Request counts stay as they are.** Sylius' capture action and the library's money actions each read the payment for their own guards, and the plugin doesn't bypass them, so a capture still reads the payment more than once.
