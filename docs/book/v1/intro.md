# mezzio-session-cache

This component provides a [PSR-6](https://www.php-fig.org/psr/psr-6/) session
persistence adapter for use with [mezzio-session](https://docs.mezzio.dev/mezzio-session/).

PSR-6 defines cache items and cache item pools. This package uses a cache item
pool in which to store and retrieve sessions. PSR-6 was chosen over the simpler
[PSR-16](https://www.php-fig.org/psr/psr-16/) as it specifically provides
functionality around _expiry_, which allows us to expire sessions.

## Installation

Install mezzio-session-cache via [Composer](https://getcomposer.org/):

```bash
$ composer require mezzio/mezzio-session-cache
```

You will also need to install a package that provides a PSR-6
`CacheItemPoolInterface` implementation. You can [search for PSR-6 providers on
Packagist](https://packagist.org/providers/psr/cache-implementation). We have
had excellent luck with the various implementations provided by the [PHP-Cache
project](http://www.php-cache.com/en/latest/).

## Usage

Generally, you will only provide configuration for this service, including
configuring a PSR-6 `CacheItemPoolInterface` service; mezzio-session
will then consume it via its [SessionMiddleware](https://docs.mezzio.dev/mezzio-session/middleware/).
