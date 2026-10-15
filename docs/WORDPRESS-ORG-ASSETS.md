# WordPress.org listing assets

WordPress.org listing artwork lives in `.wordpress-org/`, separate from the
plugin's runtime `assets/` directory.

The repository contains the final directory artwork exported from the selected
concepts under `artwork/wordpress-org/`:

| File | Required size | Purpose |
| --- | ---: | --- |
| `banner-772x250.png` | 772 × 250 | Standard directory banner |
| `banner-1544x500.png` | 1544 × 500 | High-resolution directory banner |
| `icon-128x128.png` | 128 × 128 | Standard plugin icon |
| `icon-256x256.png` | 256 × 256 | High-resolution plugin icon |

Preserve each file's exact lowercase filename, dimensions, and PNG format when
updating the artwork. Keep development notes and source design files outside
`.wordpress-org/`; the release action copies that directory to the public
WordPress.org SVN assets directory.

The listing uses these screenshots captured from the local Studio site:

| File | Subject |
| --- | --- |
| `screenshot-1.jpg` | Careers directory with search and job-type filters |
| `screenshot-2.jpg` | Job details and on-site application form |
| `screenshot-3.jpg` | Applications inbox with fictional demo candidates |

Keep a matching numbered caption under `== Screenshots ==` in `readme.txt`
for every screenshot. PNG and JPEG are supported; preserve the sequential
`screenshot-N` filenames. Use verified demo records for inbox captures and
exclude real candidate information.
