# `public/api/` is intentionally (almost) empty

Every `/api/*` endpoint is now served by attribute-routed controllers in
`src/Controllers/` through the framework Router (`public/_dispatch.php`).

This directory must nevertheless **exist**, because Apache's strangler config
(`apache/site.conf`) routes the API with:

```apache
<Directory /var/www/html/public/api>
    FallbackResource /_dispatch.php
</Directory>
```

A `<Directory>` FallbackResource only applies when the directory exists. If this
folder is deleted, every `/api/*` request 404s. `.gitkeep` keeps it in git.
