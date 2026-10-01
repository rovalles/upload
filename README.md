# Upload API — version 1

Plain PHP image service with clean routes, nested folders, batch uploads, and one configurable allowed origin. No framework, database, Composer installation, tokens, or resizing required.

## Requirements and setup

PHP 8.2+ with Fileinfo, and Apache with mod_rewrite (or another web server routing requests to `public/index.php`).

1. Copy `config.example.php` to `config.php`.
2. Set `allowed_origin` to the exact requesting origin, for example `https://afterhourslab.com`. Include the scheme and port if applicable; omit the trailing slash. An empty setting rejects API requests.
3. Set `base_url` to the public service URL, for example `https://uploads.example.com`.
4. Point the domain's document root to this project's `public/` folder. Keep config, source, and storage outside the web root. Give PHP write access to `storage/images`, or configure another private absolute storage directory.
5. PHP defaults limit request size and upload count. For batches up to 20 images of 10 MiB each, set `upload_max_filesize=10M`, `post_max_size=210M`, and `max_file_uploads=20` in the hosting PHP settings. Align reverse proxy limits too. Application limits are configurable separately.

For local development:

```sh
UPLOAD_ALLOWED_ORIGIN=http://localhost:5173 UPLOAD_BASE_URL=http://localhost:8080 php -S localhost:8080 -t public public/index.php
```

The API requires the exact Origin header for listing and writes. CORS/origin checks are the only access restriction in v1; non-browser clients can spoof this header. Image view URLs are public and support requests without Origin headers for normal image embedding. No tokens are implemented.

Subdirectory hosting is supported: include the subdirectory in `base_url` and route that directory to `public/index.php` without exposing the rest of the project.

## Routes

| Method | Route | Behavior |
| --- | --- | --- |
| POST | `/api/images` | Multipart batch upload; creates images, conflicts return 409 |
| GET | `/api/images?root=client-one&path=gallery` | List images directly in the folder (not recursive) |
| GET / HEAD | `/api/images/client-one/gallery/photo.jpg` | Public image bytes / headers |
| PUT | `/api/images/client-one/gallery/photo.jpg` | Replace an existing image using raw image bytes |
| PATCH | `/api/images/client-one/gallery/photo.jpg` | Move/rename with JSON `{ "root": "client-one", "path": "new/folder", "name": "renamed.jpg" }` |
| DELETE | `/api/images/client-one/gallery/photo.jpg` | Delete an existing image; returns 204 |
| OPTIONS | Any image route | CORS preflight |

Root is a nonempty relative folder; it may contain nested folders. Item paths are relative to root, and leading/trailing slashes are trimmed. Empty item paths save directly in root. Filenames must not include folders. Paths allow ASCII letters, numbers, spaces, underscores, hyphens and dots within components; dot-prefixed components, traversal, backslashes, and symlinks are rejected. Folder operations and recursive deletion are not supported.

JPEG, PNG, WebP and GIF are supported. Actual image content must match the filename extension. SVG is not accepted. Images are stored without transformation. Rename cannot change the image's format; the new extension must still match its content.

## Upload from JavaScript

```js
const sets = [
  {
    root: 'client-one',
    items: [
      { path: '', name: 'photo.jpg' },
      { path: '/covers/page/store', name: 'cover.jpg' }
    ]
  },
  {
    root: 'client-two',
    items: [{ path: 'gallery', name: 'photo.jpg' }]
  }
];

const data = new FormData();
data.append('metadata', JSON.stringify(sets));
data.append('images[0][0]', photoFile);
data.append('images[0][1]', coverFile);
data.append('images[1][0]', otherPhotoFile);

const response = await fetch('https://uploads.example.com/api/images', {
  method: 'POST',
  body: data
});
const result = await response.json();
if (!response.ok) throw new Error(result.error.message);
console.log(result.images);
```

The browser supplies Origin and the multipart Content-Type automatically. Do not set either manually. Each file matches its set/item indexes. Every item requires a file and unmatched files are rejected. The entire batch is validated before saving; existing files are never silently overwritten. Normal save failures roll back images created by that request. A process crash or filesystem failure is not a transactional guarantee.

Success: `201` for upload, `200` for listing/replacement/move, `204` for deletion.

```json
{
  "images": [{
    "path": "client-one/photo.jpg",
    "url": "https://uploads.example.com/api/images/client-one/photo.jpg",
    "mime": "image/jpeg",
    "size": 123456,
    "width": 1200,
    "height": 800
  }]
}
```

Replace and move return a singular `image` object. Listing returns `images`. Errors use a consistent shape:

```json
{ "error": { "code": "IMAGE_NOT_FOUND", "message": "The requested image does not exist." } }
```

## Checks and future resizing

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
python3 tests/integration.py
```

CI runs PHP syntax checks and HTTP integration checks covering batch upload, nested paths, view/list/replace/move/delete, conflicts, origin restrictions, invalid content, traversal, symlinks and validation before writes.

`ImageValidator` handles image inspection separately from `ImageStorage`. A future processor can generate named variants while preserving originals. Version 1 explicitly rejects `variants` so callers cannot mistake an upload for a resize operation. Tokens and root-level permissions can be added to the request guard later.
