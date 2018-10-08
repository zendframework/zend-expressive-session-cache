# TODO

- [ ] Setup Travis-CI integration
  - [ ] Update the `.travis.yml` to include php-nightly.
- [ ] Setup Packagist integration
- [ ] Create a factory for the adapter.
  - [ ] Should allow specifying each of the arguments for the constructor via configuration
  - [ ] Should allow specifying the _name_ of the PSR-6 cache adapter service to use, defaulting to the `CacheItemPoolInterface` if not present.
- [ ] Document the adapter.
  - [ ] Version the documentation immediately
  - [ ] Write about configuration.
  - [ ] Write a section in the zend-expressive-swoole documentation about sessions.
  - [ ] Give examples using cache/predis-adapter or similar.
  - [ ] Build docs and push to gh-pages branch
  - [ ] Setup zfbot webhooks
- [ ] Update the README.md.
