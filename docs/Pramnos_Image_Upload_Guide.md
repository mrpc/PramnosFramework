---
use_cases:
  - Letting a user crop, rotate or flip a picture before uploading it
  - Forcing an uploaded picture to a ratio, like a square avatar or a 16:9 cover
  - Resizing photos in the browser so a phone's 12-megapixel file is not uploaded whole
  - Setting the size profile pictures are uploaded at
---

# Image Upload Guide

A file input can crop, rotate and resize its picture in the browser before the form sends it.
The framework's `pf-image.js` does it, on Cropper.js from the asset catalog, with no script of the
page's own. The profile picture card on **Account → Profile** uses it.

```bash
pramnos project:install cropperjs           # into www/assets/vendor/cropperjs/1.6.2/
pramnos project:resync --js --all          # pf-image.js
```

```php
<?php echo \Pramnos\Html\ImageUpload::assets(); ?>

<form method="post" enctype="multipart/form-data" action="<?php echo adminUrl('Articles/cover'); ?>">
    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
    <img id="cover-preview" hidden alt="">
    <input type="file" name="cover" accept="image/*"
        <?php echo \Pramnos\Html\ImageUpload::attributes([
            'aspect'   => '16/9',
            'maxWidth' => 1600,
            'preview'  => '#cover-preview',
        ]); ?>>
    <button type="submit">Save</button>
</form>
```

Choosing a picture opens a dialog with the picture and a crop box. The box is held to the ratio
when one is given, and free otherwise. Its buttons rotate left and right, flip, zoom in and out,
and reset. **Use this picture** puts the result in the input in place of the file that was
chosen, so the form sends it as it would have sent the original, under the same field name.
**Cancel**, or Escape, empties the input. With a ratio, nothing leaves the page uncropped.

## Options

| `attributes()` option | Attribute | |
|---|---|---|
| `aspect` | `data-aspect` | The crop is held to this ratio: `1`, `1.5`, `'16/9'`, `'4:3'`. Free without it |
| `maxWidth`, `maxHeight` | `data-max-width`, `data-max-height` | The most each side may be. A smaller picture is not enlarged |
| `width`, `height` | `data-width`, `data-height` | Exactly this size. With one side only, the other keeps the proportion. A smaller picture is enlarged |
| `type` | `data-type` | `image/jpeg` (the default), `image/png` or `image/webp`. JPEG is drawn on white, because it has no transparency |
| `quality` | `data-quality` | For JPEG and WebP, `0`–`1`; `0.9` by default |
| `round` | `data-round` | A round guide over the crop, for an avatar. The result is still the square |
| `preview` | `data-preview` | A selector for an `<img>` that shows the result, and is unhidden |
| `submit` | `data-submit` | Send the form as soon as the picture is ready |
| `crop` | `data-crop` | `false`: no dialog, only resized |

An option `attributes()` does not know is an `InvalidArgumentException`, so a misspelt `ratio`
fails at once rather than leaving the crop free. Give `width` and `height` together only with an
`aspect` of the same ratio, or the picture is stretched.

The dialog's words are translated through `t()`: `Crop the picture`, `Rotate left`,
`Rotate right`, `Flip`, `Zoom in`, `Zoom out`, `Reset`, `Cancel`, `Use this picture`.
`attributes()` puts the translated ones in `data-words`.

## What is told to the page

```js
input.addEventListener('pf:image-ready', (e) => upload(e.detail.file));   // also e.detail.canvas
input.addEventListener('pf:image-cancel', () => showHint());
```

`pf:image-ready` comes once the result is in the input, so a page that uploads with `fetch()`
takes `e.detail.file` instead of the form. An input added to the page later needs nothing: the
script listens on the document.

## Without Cropper.js

`ImageUpload::assets()` prints Cropper.js only where `project:install cropperjs` has put it in the
web root. Without it a picture is still resized to the options' sizes, but no dialog opens, so a
ratio is not enforced. The server has to check and size what arrives in any case. A browser
without JavaScript sends the original, and so does one that cannot replace a file input's files.

The result is drawn on a canvas, so it carries no EXIF data: no camera, no location, and no
orientation either, because the browser has already turned the picture upright. An animated GIF
becomes its first frame.

## The page's security policy and styles

The chosen file is shown to the cropper through `URL.createObjectURL()`, a `blob:` address. The
framework's Content-Security-Policy allows `blob:` in `img-src`. A policy of the application's
own, set in its web server or a header of its own, has to allow it too, or the crop area stays
empty grey.

The dialog's styles are in the theme's `components.css`. A project scaffolded before the dialog
existed takes the newer copy with `project:resync --css`. A copy the project changed is kept and
named, and `--overwrite` replaces it.

## The profile picture

The picture input on **Account → Profile** asks for a square with a round guide. It is sent as
soon as the picture is cropped, and shown in the card's preview. The size is the
`profile_photo_upload_size` setting, in pixels: 512 by default, never below the 256-pixel square
that is shown, nor above the 1024 pixels the original is kept at. In code:

```php
\Pramnos\User\ProfilePhoto::uploadSize();          // the setting, kept within 256–1024
\Pramnos\User\ProfilePhoto::uploadAttributes();    // the input's attributes, for a theme of your own
```

The server crops and sizes the picture as before (see the Account Guide's
[Profile picture](Pramnos_Account_Guide.md#profile-picture)), so a picture that arrives uncropped
is still a square.

## The theme's colours

The dialog has the class `pf-image-dialog`, plus `pf-image-round` with the round guide. Each
theme's `components.css` styles it in the theme's background, text and primary colour. The
dialog is a native `<dialog>`, opened modal, so focus stays in it and Escape closes it.
