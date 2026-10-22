# publish-event-request.json

- Source: https://api.agentcat.com/v1/schema/public/?format=json (OpenAPI 3.0.3)
- Fetched: 2026-10-17
- JSON pointer: `/paths/~1v1~1public~1events~1/post/requestBody/content/application~1json/schema`
- Command:

```bash
php -r '$d = json_decode(file_get_contents("https://api.agentcat.com/v1/schema/public/?format=json"), false, 512, JSON_THROW_ON_ERROR); $s = $d->paths->{"/v1/public/events/"}->post->requestBody->content->{"application/json"}->schema; file_put_contents("tests/Fixtures/schema/publish-event-request.json", json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");'
sha256sum tests/Fixtures/schema/publish-event-request.json
```

- Digest (6353 bytes):

```
9dac28d9ce0fed0f108ec28d2bd1c2b2e8b894a606bd6ff79a235d1876e5ab56  tests/Fixtures/schema/publish-event-request.json
```
