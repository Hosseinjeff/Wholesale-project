# Site changes and preservation workflow

Updated: 2026-10-30, Asia/Tehran

## Source of truth

- WordPress plugin source lives in `shop-wp/molavi-shop/` in this repository.
- The live release branch is `molavisale-release-2026-10-28`.
- Do not edit live plugin files in WordPress or cPanel. Change the repository copy,
  commit it, push the commit, and deploy from that pushed revision.
- The `.githooks/post-commit` hook pushes every commit to the branch's configured
  upstream. Install it for each clone with `tools/install_git_hooks.sh`. If a push
  fails, the commit remains local and the hook reports that it needs a retry.

## Deploying plugin code

The cPanel uploader is `tools/deploy_wordpress_plugin.py`. It accepts a Git ref
before the plugin changes, and only deploys tracked PHP, JavaScript and CSS files
under `shop-wp/molavi-shop/` that changed since that ref.

```sh
python tools/deploy_wordpress_plugin.py --since <last-deployed-commit>
```

The script requires a clean working tree and an upstream that points at the same
commit as `HEAD`. It reads cPanel credentials from the existing
`MOLAVISALE_CPANEL_URL`, `MOLAVISALE_CPANEL_USERNAME` and
`MOLAVISALE_CPANEL_PASSWORD` environment variables; it never prints them. It uses
the HTTPS cPanel UAPI through the configured proxy, backs up changed remote files
under `/tmp`, writes only the changed tracked source files, and reads each file
back to compare it with Git. The current cPanel file editor inserts one
`<meta charset="utf-8">` after the first opening `<header>` tag in PHP templates.
The uploader accepts only that exact insertion when removing it yields a
byte-for-byte match with the committed file, and reports any affected paths. It
deliberately excludes
`shop-wp/molavi-shop/includes/runtime-config.php` and does not package or replace
the plugin directory.

After a successful deploy, record the deployed commit here or in the relevant
release note and use it as `--since` for the next deployment.

Most recent plugin deployment: commit `62173404ece9e09a2348c19429e451559d73c688`
(2026-10-31). The five changed plugin files were uploaded and read back successfully
with `python tools/deploy_wordpress_plugin.py --since 879e4fd7b3c357f50c7c5fadddcdb5792f989711`.
Use that commit as the next deployment's `--since` value.

## WordPress data

Git contains code and safe project documentation. Products, pages edited in
WordPress, customer records, orders, uploaded media and runtime credentials live
in the WordPress database or private storage. They are not automatically captured
by Git and must not be copied into this repository. Use host/database backups for
those records. When changing customer-facing page content in WordPress, export the
safe content or recreate the change in a tracked template before calling that
change version-controlled.

Never commit `wp-config.php`, `runtime-config.php`, service-account keys, supplier
source contents, customer data, order data, database files or `/tmp` backups.
