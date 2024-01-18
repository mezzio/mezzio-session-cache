# Introduction

This component provides a [PSR-6](https://www.php-fig.org/psr/psr-6/) session
persistence adapter for use with [mezzio-session](https://docs.mezzio.dev/mezzio-session/).

PSR-6 defines cache items and cache item pools. This package uses a cache item
pool in which to store and retrieve sessions. PSR-6 was chosen over the simpler
[PSR-16](https://www.php-fig.org/psr/psr-16/) as it specifically provides
functionality around _expiry_, which allows us to expire sessions.

## Usage

Generally, you will only provide configuration for this service, including
configuring a PSR-6 `CacheItemPoolInterface` service; mezzio-session
will then consume it via its [SessionMiddleware](https://docs.mezzio.dev/mezzio-session/middleware/).
