# WSS Core

Lightweight base plugin for Webshopschool client websites. No frontend/admin features yet — it exists as a stable foundation that can auto-update from GitHub so future functionality can be rolled out to all client sites by publishing a new release.

---

## How it works

- The plugin polls `https://api.github.com/repos/{owner}/{repo}/releases/latest` (cached for 6 hours).
- It compares the GitHub release tag against the installed version.
- If the release tag is newer, WordPress shows an update notice exactly like a wordpress.org plugin.
- Update package preference:
  1. A release asset named **`wss-core.zip`** (recommended — produced by the bundled GitHub Action).
  2. Otherwise the auto-generated GitHub `zipball_url` (the folder rename is corrected automatically).
- The `Update URI` header points to GitHub so the wordpress.org updater never collides with this plugin.

---

## Configuration

Edit `includes/class-wss-core.php`:

```php
const GITHUB_OWNER = 'webshopschool';
const GITHUB_REPO  = 'wss-core';
```

That is the only configuration. Everything else is derived from the plugin headers.

---

## Setup guide

### 1. Create the GitHub repository

1. Create a new **public** GitHub repo (e.g. `webshopschool/wss-core`).
2. Update `GITHUB_OWNER` and `GITHUB_REPO` in `includes/class-wss-core.php` to match.

### 2. Upload the plugin

```bash
git init
git remote add origin git@github.com:webshopschool/wss-core.git
git add .
git commit -m "Initial commit: WSS Core 1.0.0"
git branch -M main
git push -u origin main
```

### 3. Create the first release

1. Bump the version in **both**:
   - `wss-core.php` — `Version:` header and `WSS_CORE_VERSION` constant.
   - `readme.txt` — `Stable tag:`.
2. Commit and push.
3. On GitHub: **Releases → Draft a new release**.
4. **Tag**: `1.0.1` (plain SemVer — `1.0.1`, `1.1.0`, `2.0.0`, …). `v1.0.1` also works but plain is preferred.
5. **Title**: `1.0.1`.
6. Write release notes — they appear in the WP "View details" modal.
7. **Publish release**.
8. The bundled GitHub Action automatically builds and attaches `wss-core.zip` to the release. Wait ~1 minute for it to finish.

### 4. Install on a client website

Three options:

**A. Manual install (first time):**
1. Download the `wss-core.zip` asset from the GitHub release.
2. WP admin → Plugins → Add New → Upload Plugin → choose the zip → Install → Activate.

**B. SFTP/SSH:**
- Upload the `wss-core` folder to `wp-content/plugins/`.
- Activate via WP admin.

**C. WP-CLI:**
```bash
wp plugin install https://github.com/webshopschool/wss-core/releases/latest/download/wss-core.zip --activate
```

### 5. Push a future update

1. Make your changes.
2. Bump `Version:` in `wss-core.php`, `WSS_CORE_VERSION`, and `Stable tag:` in `readme.txt`.
3. Commit & push.
4. Draft a new release with the matching tag (e.g. `1.0.2`).
5. Publish — within 6 hours every client site sees an update notice in **Plugins → Installed Plugins**. Click "update now" as usual.
6. To force an immediate check, visit **Dashboard → Updates → Check again** or run `wp plugin update wss-core`.

---

## Folder-name caveat

WordPress requires the unzipped plugin folder to be named exactly `wss-core/`.

- GitHub auto-generated zipballs unpack into a folder like `webshopschool-wss-core-abc1234/` — this plugin's updater **automatically renames** that to `wss-core/` during installation, so it still works.
- But the **safest** approach is to ship a release asset named exactly `wss-core.zip` containing a top-level `wss-core/` folder. The included GitHub Action (`.github/workflows/release.yml`) does this for you on every release.

---

## Cache & debugging

- Release data is cached in a transient (`wss_core_github_release`) for 6 hours.
- Failed API calls cache an error marker for 15 minutes to prevent hammering GitHub.
- The transient is cleared automatically when WSS Core itself updates and on activation/deactivation.
- Force-clear manually:
  ```bash
  wp transient delete wss_core_github_release
  wp transient delete update_plugins --network
  ```

---

## Private repos

Currently public-only. To support a private repo you would need to send an `Authorization: token …` header in `wp_remote_get()` and use the asset API URL (not `browser_download_url`) with `Accept: application/octet-stream`. Out of scope for v1.0.0.
