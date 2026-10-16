/*
 * A picture chosen in a file input is cropped, turned and made the right size in the browser,
 * before it is sent — on Cropper.js (`libraries:sync cropperjs`), with no script of the page's own.
 *
 *   <input type="file" name="photo" accept="image/*" data-pf-image
 *          data-aspect="1"              optional: the crop is held to this ratio (1, 16/9, 4:3); free without it
 *          data-max-width="1024"        optional: the most each side may be; a smaller picture is not enlarged
 *          data-max-height="1024"
 *          data-width="1200"            optional: exactly this wide (and/or data-height), enlarged if need be
 *          data-type="image/jpeg"       optional: image/jpeg (the default), image/png or image/webp
 *          data-quality="0.9"           optional: for JPEG and WebP
 *          data-round="1"               optional: a round guide over the crop, for an avatar
 *          data-preview="#avatar"       optional: an <img> that shows the result
 *          data-submit="1"              optional: send the form once the picture is ready
 *          data-crop="0"                optional: no dialog, only made the right size
 *          data-words='{"Cancel": "…"}'> optional: the dialog's words, translated
 *
 * Choosing a file opens a dialog with the picture, a crop box (held to `data-aspect`), and buttons
 * to rotate, flip, zoom and reset. "Use this picture" puts the result in the input in place of the
 * file chosen, so the form sends it as it would have sent the original. "Cancel", or Escape,
 * empties the input: with `data-aspect`, nothing leaves the page uncropped.
 *
 * Told to the page as events on the input:
 *   pf:image-ready   {file, canvas}   the result is in the input
 *   pf:image-cancel  {}               the dialog was closed without a picture
 *
 * Without Cropper.js, or with `data-crop="0"`, the picture is only made the right size. An input
 * added later needs nothing: the script listens on the document.
 */
(function () {
    'use strict';

    /** A ratio from `1`, `1.5`, `16/9` or `4:3`; NaN (free) for anything else. */
    function ratio(value) {
        if (!value) {
            return NaN;
        }
        var parts = String(value).match(/^\s*([\d.]+)\s*[/:]\s*([\d.]+)\s*$/);
        var result = parts ? parseFloat(parts[1]) / parseFloat(parts[2]) : parseFloat(value);

        return result > 0 && isFinite(result) ? result : NaN;
    }

    function whole(input, name) {
        var value = parseInt(input.getAttribute(name) || '', 10);

        return value > 0 ? value : 0;
    }

    function settings(input) {
        var type = input.getAttribute('data-type') || 'image/jpeg';
        var quality = parseFloat(input.getAttribute('data-quality') || '');

        return {
            aspect: ratio(input.getAttribute('data-aspect')),
            width: whole(input, 'data-width'),
            height: whole(input, 'data-height'),
            maxWidth: whole(input, 'data-max-width'),
            maxHeight: whole(input, 'data-max-height'),
            type: /^image\/(jpeg|png|webp)$/.test(type) ? type : 'image/jpeg',
            quality: quality > 0 && quality <= 1 ? quality : 0.9,
            round: input.getAttribute('data-round') === '1',
            crop: input.getAttribute('data-crop') !== '0'
        };
    }

    /**
     * The size of the result: the exact width and/or height asked for (the other side kept in
     * proportion), or the picture's own; then brought within the maximums, never enlarged by them.
     */
    function size(width, height, wanted) {
        var w = wanted.width;
        var h = wanted.height;
        if (w && !h) {
            h = w * height / width;
        } else if (h && !w) {
            w = h * width / height;
        } else if (!w) {
            w = width;
            h = height;
        }
        var scale = Math.min(1, (wanted.maxWidth || Infinity) / w, (wanted.maxHeight || Infinity) / h);

        return { width: Math.max(1, Math.round(w * scale)), height: Math.max(1, Math.round(h * scale)) };
    }

    /** The picture drawn at its final size — on white for JPEG, which has no transparency. */
    function draw(source, wanted) {
        var final = size(source.naturalWidth || source.width, source.naturalHeight || source.height, wanted);
        var canvas = document.createElement('canvas');
        canvas.width = final.width;
        canvas.height = final.height;
        var context = canvas.getContext('2d');
        if (wanted.type === 'image/jpeg') {
            context.fillStyle = '#fff';
            context.fillRect(0, 0, final.width, final.height);
        }
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
        context.drawImage(source, 0, 0, final.width, final.height);

        return canvas;
    }

    function tell(input, name, detail) {
        input.dispatchEvent(new CustomEvent(name, { detail: detail || {}, bubbles: true }));
    }

    /** The result in the input in place of the chosen file, its preview, and the form sent if asked. */
    function finish(input, chosen, canvas, wanted, done) {
        canvas.toBlob(function (blob) {
            if (!blob) {
                done();
                return;
            }
            var extension = wanted.type === 'image/png' ? 'png' : wanted.type === 'image/webp' ? 'webp' : 'jpg';
            var name = String(chosen.name || 'picture').replace(/\.[^.]*$/, '') + '.' + extension;
            var file = new File([blob], name, { type: wanted.type, lastModified: Date.now() });
            try {
                var transfer = new DataTransfer();
                transfer.items.add(file);
                input.files = transfer.files;
            } catch (e) {
                // A browser that cannot set a file input's files sends the original: the server
                // still checks and sizes it.
            }
            var preview = input.getAttribute('data-preview');
            var image = preview ? document.querySelector(preview) : null;
            if (image) {
                image.src = URL.createObjectURL(file);
                image.hidden = false;
            }
            done();
            tell(input, 'pf:image-ready', { file: file, canvas: canvas });
            if (input.getAttribute('data-submit') === '1' && input.form) {
                if (input.form.requestSubmit) {
                    input.form.requestSubmit();
                } else {
                    input.form.submit();
                }
            }
        }, wanted.type, wanted.quality);
    }

    function button(label, action) {
        var element = document.createElement('button');
        element.setAttribute('type', 'button');
        element.setAttribute('data-pf-image-action', action);
        element.textContent = label;

        return element;
    }

    /** The crop dialog for a chosen picture. */
    function crop(input, chosen, wanted) {
        var own = {};
        try {
            own = JSON.parse(input.getAttribute('data-words') || '{}') || {};
        } catch (e) {
            own = {};
        }
        var t = function (words) { return typeof own[words] === 'string' ? own[words] : words; };
        var address = URL.createObjectURL(chosen);

        var dialog = document.createElement('dialog');
        dialog.className = 'pf-image-dialog' + (wanted.round ? ' pf-image-round' : '');
        var title = document.createElement('h2');
        title.setAttribute('id', 'pf-image-title');
        title.textContent = t('Crop the picture');
        dialog.setAttribute('aria-labelledby', 'pf-image-title');
        var stage = document.createElement('div');
        stage.className = 'pf-image-stage';
        var image = document.createElement('img');
        image.setAttribute('alt', '');
        image.src = address;
        stage.appendChild(image);
        var tools = document.createElement('div');
        tools.className = 'pf-image-tools';
        [['Rotate left', 'rotate-left'], ['Rotate right', 'rotate-right'], ['Flip', 'flip'], ['Zoom in', 'zoom-in'],
            ['Zoom out', 'zoom-out'], ['Reset', 'reset']].forEach(function (one) {
            tools.appendChild(button(t(one[0]), one[1]));
        });
        var actions = document.createElement('div');
        actions.className = 'pf-image-actions';
        var cancel = button(t('Cancel'), 'cancel');
        var use = button(t('Use this picture'), 'use');
        use.className = 'pf-image-use';
        actions.append(cancel, use);
        dialog.append(title, stage, tools, actions);
        document.body.appendChild(dialog);

        var cropper = new window.Cropper(image, {
            aspectRatio: wanted.aspect,
            viewMode: 1,
            dragMode: 'move',
            autoCropArea: 1,
            background: false,
            responsive: true
        });
        var closed = false;

        function close() {
            if (closed) {
                return;
            }
            closed = true;
            cropper.destroy();
            URL.revokeObjectURL(address);
            if (dialog.open && dialog.close) {
                dialog.close();
            }
            dialog.remove();
        }

        function abandon() {
            if (closed) {
                return;
            }
            close();
            input.value = '';
            tell(input, 'pf:image-cancel');
        }

        dialog.addEventListener('click', function (event) {
            var action = event.target && event.target.getAttribute ? event.target.getAttribute('data-pf-image-action') : null;
            if (action === 'rotate-left' || action === 'rotate-right') {
                cropper.rotate(action === 'rotate-left' ? -90 : 90);
            } else if (action === 'flip') {
                cropper.scaleX(-(cropper.getData().scaleX || 1));
            } else if (action === 'zoom-in' || action === 'zoom-out') {
                cropper.zoom(action === 'zoom-in' ? 0.1 : -0.1);
            } else if (action === 'reset') {
                cropper.reset();
            } else if (action === 'cancel') {
                abandon();
            } else if (action === 'use') {
                use.disabled = true;
                var canvas = cropper.getCroppedCanvas({ imageSmoothingEnabled: true, imageSmoothingQuality: 'high' });
                if (!canvas) {
                    use.disabled = false;
                    return;
                }
                finish(input, chosen, draw(canvas, wanted), wanted, close);
            }
        });
        // Escape.
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            abandon();
        });

        if (dialog.showModal) {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    }

    /** Only made the right size: no dialog. */
    function resize(input, chosen, wanted) {
        var address = URL.createObjectURL(chosen);
        var image = document.createElement('img');
        image.onload = function () {
            finish(input, chosen, draw(image, wanted), wanted, function () { URL.revokeObjectURL(address); });
        };
        image.onerror = function () {
            URL.revokeObjectURL(address);
        };
        image.src = address;
    }

    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input || !input.matches || !input.matches('input[type="file"][data-pf-image]')) {
            return;
        }
        var chosen = input.files && input.files[0];
        // Not a picture this can draw: left as it is, for the server to judge.
        if (!chosen || !/^image\/(jpeg|png|gif|webp|bmp|avif)$/.test(chosen.type || '')) {
            return;
        }
        var wanted = settings(input);
        if (wanted.crop && window.Cropper) {
            crop(input, chosen, wanted);
        } else {
            resize(input, chosen, wanted);
        }
    });

    window.pfImage = { size: size, ratio: ratio };
}());
