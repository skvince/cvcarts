(function () {
    document.querySelectorAll('[data-image-preview]').forEach(function (input) {
        var preview = document.querySelector(input.dataset.imagePreview);
        if (!preview) {
            return;
        }

        var originalSource = preview.src;
        var originalAlt = preview.alt;
        var previewUrl = null;

        function resetPreview() {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = null;
            }
            preview.src = originalSource;
            preview.alt = originalAlt;
            preview.hidden = input.dataset.imagePreviewInitiallyHidden === 'true';
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file || file.type === 'application/pdf' || /\.pdf$/i.test(file.name)) {
                resetPreview();
                return;
            }

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
            previewUrl = URL.createObjectURL(file);
            preview.src = previewUrl;
            preview.alt = 'Preview of selected image';
            preview.hidden = false;
        });
    });
}());
