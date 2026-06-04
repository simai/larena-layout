# Tests

Commands executed:

```bash
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer validate --strict
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer dump-autoload
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run validate:larena
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run lint
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run analyse
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run test
```

Result: passed.

Covered assertions:

- layout descriptors require stable key, profile policy and non-duplicated valid regions;
- admin-like profiles require access policy;
- page descriptors require route key, layout binding and profile;
- binding descriptors require source trace;
- resolved plans carry explain/cache data and are not canonical truth by default;
- drafts cannot publish without validation;
- data source bindings must be access-scoped;
- SitePack manifests reject executable PHP payloads.
