# publish-event-request.json

- Source: https://api.agentcat.com/v1/schema/public/?format=json (OpenAPI 3.0.3)
- Fetched: 2026-10-20
- JSON pointer: `/paths/~1v1~1public~1events~1/post/requestBody/content/application~1json/schema`
- Command:

```bash
php -r '$d = json_decode(file_get_contents("https://api.agentcat.com/v1/schema/public/?format=json"), false, 512, JSON_THROW_ON_ERROR); $s = $d->paths->{"/v1/public/events/"}->post->requestBody->content->{"application/json"}->schema; file_put_contents("tests/Fixtures/schema/publish-event-request.json", json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");'
sha256sum tests/Fixtures/schema/publish-event-request.json
```

- Digest (6388 bytes):

```
ef2e2f996f1fbe55d81225869a129c754faa00c9d0cd3a98794305ceb4ec054d  tests/Fixtures/schema/publish-event-request.json
```
