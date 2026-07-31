# WordPress.org listing assets

WordPress.org listing artwork lives in `.wordpress-org/`, separate from the
plugin's runtime `assets/` directory.

The repository currently contains clearly labeled temporary placeholders:

| File | Required size | Purpose |
| --- | ---: | --- |
| `banner-772x250.png` | 772 × 250 | Standard directory banner |
| `banner-1544x500.png` | 1544 × 500 | High-resolution directory banner |
| `icon-128x128.png` | 128 × 128 | Standard plugin icon |
| `icon-256x256.png` | 256 × 256 | High-resolution plugin icon |

Replace each file in place with final artwork, preserving its exact lowercase
filename, dimensions, and PNG format. Do not put development notes or source
design files inside `.wordpress-org/`; the release action copies that directory
to the public WordPress.org SVN assets directory.

Screenshots can be added later as `screenshot-1.png`, `screenshot-2.png`, and so
on. Add a matching numbered caption under `== Screenshots ==` in `readme.txt`
for every screenshot.
