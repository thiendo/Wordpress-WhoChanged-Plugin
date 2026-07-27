# WhoChanged — Playground demo package

Upload **this entire folder** to a public GitHub repo:

`https://github.com/thiendo/wordpress-whochanged-plugin`

## Contents

| File | Purpose |
|---|---|
| `whochanged-demo.zip` | Demo plugin (Settings locked, Buy Now → Freemius) |
| `blueprint.json` | WordPress Playground blueprint |
| `seed-demo-logs.php` | Seeded activity logs for Statistics |
| `build-demo-zip.sh` | Rebuild the zip from production + demo overlays |
| `embed-snippet.html` | Landing-page iframe sample |

## Test link

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/thiendo/wordpress-whochanged-plugin/main/demo/blueprint.json
```

## Rebuild zip after code changes

From the WhoChanged project root:

```bash
./demo/build-demo-zip.sh
```

Then push the updated `demo/` folder to GitHub.
