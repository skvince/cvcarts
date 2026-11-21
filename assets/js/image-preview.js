(function () {
    var maxUploadSize = 3584 * 1024;
    var maxOriginalSize = 20 * 1024 * 1024;

    function decodeImage(file) {
        if (typeof createImageBitmap === 'function') {
            return createImageBitmap(file).then(function (bitmap) {
                return {
                    image: bitmap,
                    width: bitmap.width,
                    height: bitmap.height,
                    dispose: function () { bitmap.close(); }
                };
            }).catch(function () {
                return decodeImageElement(file);
            });
        }

        return decodeImageElement(file);
    }

    function decodeImageElement(file) {
        var source = URL.createObjectURL(file);
        return new Promise(function (resolve, reject) {
            var image = new Image();
            image.onload = function () {
                resolve({
                    image: image,
                    width: image.naturalWidth,
                    height: image.naturalHeight,
                    dispose: function () { URL.revokeObjectURL(source); }
                });
            };
            image.onerror = function () {
                URL.revokeObjectURL(source);
                reject(new Error('The image could not be opened.'));
            };
            image.src = source;
        });
    }

    async function resizeProductImage(file) {
        var decoded = await decodeImage(file);
        try {
            var scale = Math.min(1, 1920 / Math.max(decoded.width, decoded.height));
            for (var attempt = 0; attempt < 8; attempt += 1) {
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(decoded.width * scale));
                canvas.height = Math.max(1, Math.round(decoded.height * scale));
                var context = canvas.getContext('2d');
                if (!context) {
                    throw new Error('Image processing is unavailable.');
                }
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(decoded.image, 0, 0, canvas.width, canvas.height);

                for (var quality = 0.86; quality >= 0.62; quality -= 0.12) {
                    var blob = await new Promise(function (resolve) {
                        canvas.toBlob(resolve, 'image/jpeg', quality);
                    });
                    if (blob && blob.size <= maxUploadSize) {
                        var filename = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                        return new File([blob], filename, {type: 'image/jpeg', lastModified: Date.now()});
                    }
                }
                scale *= 0.8;
            }
        } finally {
            decoded.dispose();
        }
        throw new Error('The photo could not be resized small enough.');
    }

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

        input.addEventListener('change', async function () {
            var file = input.files && input.files[0];
            var resizeProductUpload = input.dataset.imageResize === 'true';
            input.setCustomValidity('');
            if (!file) {
                resetPreview();
                return;
            }

            if (/\.pdf$/i.test(file.name) || file.type === 'application/pdf') {
                resetPreview();
                input.setCustomValidity('Choose a JPG, JPEG, PNG, or WEBP image.');
                input.reportValidity();
                return;
            }

            if (file.size > (resizeProductUpload ? maxOriginalSize : maxUploadSize)) {
                resetPreview();
                input.value = '';
                input.setCustomValidity(resizeProductUpload
                    ? 'Choose a photo smaller than 20 MB.'
                    : 'Image must be 3.5 MB (3584 KB) or smaller.');
                input.reportValidity();
                return;
            }

            var isStandardImage = /^image\/(?:jpeg|png|webp)$/i.test(file.type)
                || /\.(?:jpe?g|png|webp)$/i.test(file.name);
            var isPhoneImage = resizeProductUpload
                && (/^image\/(?:heic|heif)/i.test(file.type) || /\.(?:heic|heif)$/i.test(file.name));
            if (!isStandardImage && !isPhoneImage) {
                resetPreview();
                input.value = '';
                input.setCustomValidity('Choose a JPG, JPEG, PNG, or WEBP image.');
                input.reportValidity();
                return;
            }

            if (resizeProductUpload && (file.size > maxUploadSize || isPhoneImage)) {
                input.setCustomValidity('Preparing photo for upload.');
                try {
                    file = await resizeProductImage(file);
                    var transfer = new DataTransfer();
                    transfer.items.add(file);
                    input.files = transfer.files;
                } catch (error) {
                    resetPreview();
                    input.value = '';
                    input.setCustomValidity(isPhoneImage
                        ? 'This phone photo could not be converted. Choose JPEG/PNG, or change your camera format to Most Compatible.'
                        : 'This photo could not be resized. Choose a smaller JPEG, PNG, or WEBP image.');
                    input.reportValidity();
                    return;
                }
            }

            input.setCustomValidity('');

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
