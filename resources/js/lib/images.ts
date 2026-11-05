// A little above what the server keeps, so the final squeeze works from a clean picture.
const MAX_EDGE = 1024;
const QUALITY = 0.62;

/**
 * Shrinks a photo on the phone before it is sent, which saves mobile data and time.
 * The server shrinks it again to its final size, so a file this cannot read is sent as it is.
 */
export async function shrinkPhoto(file: File): Promise<File> {
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const context = canvas.getContext('2d');
        if (!context) return file;
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        // WebP is normally much smaller than a camera JPEG. The server re-encodes this again, so
        // this pass is principally about using less mobile data during the upload.
        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/webp', QUALITY));
        if (!blob || blob.size >= file.size) return file;

        return new File([blob], `${file.name.replace(/\.[^.]+$/, '')}.webp`, { type: 'image/webp' });
    } catch {
        return file;
    }
}

export function fileSize(bytes: number): string {
    return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
