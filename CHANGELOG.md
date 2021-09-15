# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 1.4.1 - TBD

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.4.0 - 2021-09-15

### Added

- [#3](https://github.com/mezzio/mezzio-session-cache/pull/3) adds support for SameSite cookies. By default, the SameSite attribute will be set to "Lax", but the value can be configured via the mezzio-session-cache.cookie_same_site configuration setting.


-----

### Release Notes for [1.4.0](https://github.com/mezzio/mezzio-session-cache/milestone/1)



### 1.4.0

- Total issues resolved: **0**
- Total pull requests resolved: **1**
- Total contributors: **1**

#### Enhancement

 - [3: Implement SameSite Support (Fixes #1)](https://github.com/mezzio/mezzio-session-cache/pull/3) thanks to @SlvrEagle23

## 1.3.1 - 2019-06-24

### Added

- [zendframework/zend-expressive-session-cache#8](https://github.com/zendframework/zend-expressive-session-cache/pull/8) adds support for PHP 7.3.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.3.0 - 2019-01-22

### Added

- [zendframework/zend-expressive-session-cache#7](https://github.com/zendframework/zend-expressive-session-cache/pull/7) adds the ability to set the session cookie domain, secure, and
  httponly options. Each may be passed to the `CacheSessionPersistence`
  constructor, or as options consumed by its factory. See the documentation for
  full details.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.2.0 - 2018-10-31

### Added

- [zendframework/zend-expressive-session-cache#5](https://github.com/zendframework/zend-expressive-session-cache/pull/5) adds support for the new `SessionCookiePersistenceInterface` added
  in mezzio-session 1.2.0.  Specifically, `CacheSessionPersistence` now
  queries the session instance `getSessionLifetime()` method to determine
  whether or not to send an `Expires` directive with the session cookie.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.1.1 - 2018-10-26

### Added

- Nothing.

### Changed

- [zendframework/zend-expressive-session-cache#4](https://github.com/zendframework/zend-expressive-session-cache/pull/4) modifies the behavior when setting a persistent cookie. Previously,
  it would set a Max-Age directive on the cookie; however, this is not supported
  in all browsers or SAPIs. As such, it now creates an Expires directive, which
  will have essentially the same effect for users.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.1.0 - 2018-10-25

### Added

- [zendframework/zend-expressive-session-cache#3](https://github.com/zendframework/zend-expressive-session-cache/pull/3) adds a new constructor argument, `bool $persistent = false`. When
  this is toggled to `true`, a `Max-Age` directive will be added with a value
  equivalent to the `$cacheExpire` value. You can configure this value using the
  `mezzio-session-cache.persistent` configuration key.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.0.0 - 2018-10-09

### Added

- Everything.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.
