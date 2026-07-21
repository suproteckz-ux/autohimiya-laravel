# Production Environment

Required non-secret values:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://xn--80aesatk1az7g.kz
```

`APP_URL` must remain Punycode. Do not use the Unicode domain value in `.env`, because Laravel CLI and Plesk Laravel Toolkit can fail with `Invalid URI: Host is malformed`.

Do not commit `.env` or any secret values. This document intentionally excludes `APP_KEY`, database passwords, Paloma credentials, Kaspi secrets, Google credentials, and any tokens.