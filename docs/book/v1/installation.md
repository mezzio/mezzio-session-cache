---
show_file_content: true
---

<!-- markdownlint-disable MD001 MD041 -->
## Cache Implementation Required
<!-- markdownlint-enable -->

To use this component, a PSR-6 `CacheItemPoolInterface` implementation is required.
[laminas-cache](https://docs.laminas.dev/laminas-cache/) provides the PSR-6 implementations, install it and choose one of the cache adapters.

### Install laminas-cache and a Cache Adapter

Install laminas-cache via [Composer](https://getcomposer.org/):

```bash
$ composer require laminas/laminas-cache
```

laminas-cache is shipped without a specific cache adapter to allow free choice of storage backends and their dependencies.
For example, install the [laminas-cache `Filesystem` adapter](https://docs.laminas.dev/laminas-cache/v3/storage/adapter/#filesystem-adapter):

```bash
$ composer require laminas/laminas-cache-storage-adapter-filesystem
```

### Read More in the laminas-cache Documentation

- [PSR-6](https://docs.laminas.dev/laminas-cache/v3/psr6/)
- [Cache Adapters](https://docs.laminas.dev/laminas-cache/v3/storage/adapter/)
