<?php
/**
 * The page layout.
 *
 * `<head>` and `<body>` are written out, and both are load-bearing rather than tidy:
 *
 *  - `Theme::getheader()` extracts `<head>…</head>` and the document appends it inside
 *    its own head. Without the tag it finds nothing, and everything head.php emits ends
 *    up in the body — where a browser hoists a stylesheet link but **ignores**
 *    `<link rel="manifest">`. That is what "No manifest detected" was.
 *  - The `<body>` tag is what keeps the split at `[MODULE]` from treating the head
 *    assets as body content.
 */
?>
<head>
<?php $this->getElement('head'); ?>
</head>
<body>
<a class="pf-skip-link" href="#main-content">Skip to content</a>

<?php $this->get_Header(); ?>
<main id="main-content" class="flex-1 py-10">
    <div class="container mx-auto px-4 max-w-5xl">
        [MODULE]
    </div>
</main>
<?php $this->get_Footer(); ?>
</body>
