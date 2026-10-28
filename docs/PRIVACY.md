# Privacy

The plugin stores no personal data and therefore ships `null_provider`.

* No database tables (db/install.xml absent).
* Replay markers in the MUC cache `consumed` hold a SHA-256 hash of a one-time token and its expiry, application scope, ttl 7200 s, no user id, no content. They are not personal data and vanish with the cache.
* Settings hold origins, numbers and on/off switches only (0.2.0 adds the switch `storageaccess`).
* The Storage Access API button asks the browser for permission; the answer stays in the browser. Only the fixed word `retry` with the reason `storage_access_granted` crosses the bridge.
* The browser probes run in the user's browser; only a coarse state (one of five names) crosses the postMessage bridge to the platform page and is not stored or sent to the server.
* The event `fallback_launched` is written by the core log store, which has its own provider. It carries user, course module and context, but no token.
* 0.3.0: problems detected in the browser and every use of the new window are recorded as events in the site log
  (`embed_state_reported`, `fallback_launched`): the user (as every log entry), the context, a status word from a fixed
  list and a correlation id. The correlation id is a SHA-256-derived value of a random per-launch nonce: random, not
  linkable to a person beyond the log row it is in. No URL, token, browser property or facts are logged. The log stores'
  own privacy providers export and delete these rows, so the plugin keeps its null provider.
* 0.3.0: the session cache `reports` (rate limit) holds SHA-1 hashes of report signatures and times, for the session only.
* The end-user notice states what is checked, that only a status word and a random attempt number are recorded when the tool cannot be used, and that no browser protection needs to be disabled.
