# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 1.2.0 - TBD

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

## 1.1.1 - 2018-10-26

### Added

- Nothing.

### Changed

- [#4](https://github.com/zendframework/zend-expressive-session-cache/pull/4) modifies the behavior when setting a persistent cookie. Previously,
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

- [#3](https://github.com/zendframework/zend-expressive-session-cache/pull/3) adds a new constructor argument, `bool $persistent = false`. When
  this is toggled to `true`, a `Max-Age` directive will be added with a value
  equivalent to the `$cacheExpire` value. You can configure this value using the
  `zend-expressive-session-cache.persistent` configuration key.

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
