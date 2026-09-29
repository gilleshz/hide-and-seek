package fr.gshz.hideandseek.data.remote

import android.content.ContentResolver
import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.ImageDecoder
import android.net.Uri
import android.provider.OpenableColumns
import dagger.hilt.android.qualifiers.ApplicationContext
import java.io.BufferedOutputStream
import java.io.IOException
import javax.inject.Inject
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okio.BufferedSink

/** Long edge above which an upload is resampled: more pixels than this only cost mobile data. */
internal const val UPLOAD_MAX_EDGE_PX = 2048

internal fun needsResample(width: Int, height: Int): Boolean = maxOf(width, height) > UPLOAD_MAX_EDGE_PX

internal fun resampleFactor(width: Int, height: Int): Int =
    (maxOf(width, height) + UPLOAD_MAX_EDGE_PX - 1) / UPLOAD_MAX_EDGE_PX

/**
 * Every multipart endpoint, chat images, photo answers and the card a powerup is played with, streams
 * the picked image the same way instead of loading it into memory. Oversized photos, which the server
 * refuses above 40 MP before it can decode them, are resampled down to a shareable size instead.
 */
class ImageParts @Inject constructor(@ApplicationContext private val context: Context) {

    fun image(imageUri: String): MultipartBody.Part {
        val uri = Uri.parse(imageUri)
        val resolver = context.applicationContext.contentResolver
        val bounds = boundsOf(resolver, uri)

        if (bounds == null || !needsResample(bounds.first, bounds.second)) {
            return MultipartBody.Part.createFormData(
                "image",
                fileNameOf(resolver, uri),
                streamingBody(resolver, uri, mimeTypeOf(resolver, uri)),
            )
        }

        return MultipartBody.Part.createFormData(
            "image",
            jpegNameOf(resolver, uri),
            resampledBody(resolver, uri, resampleFactor(bounds.first, bounds.second)),
        )
    }

    fun text(value: String): RequestBody = value.toRequestBody(PLAIN_TEXT.toMediaTypeOrNull())

    private fun streamingBody(resolver: ContentResolver, uri: Uri, mimeType: String): RequestBody {
        val mediaType = mimeType.toMediaTypeOrNull()
        return object : RequestBody() {
            override fun contentType() = mediaType

            override fun contentLength() = -1L

            override fun writeTo(sink: BufferedSink) {
                try {
                    resolver.openInputStream(uri)?.use { input ->
                        input.copyTo(sink.outputStream(), STREAM_BUFFER_SIZE)
                    } ?: throw IOException("Image file is empty")
                } catch (e: SecurityException) {
                    throw IOException("Cannot read image content: permission denied", e)
                } catch (e: IllegalArgumentException) {
                    throw IOException("Cannot read image content: unsupported URI", e)
                }
            }
        }
    }

    /**
     * Decoding runs inside [RequestBody.writeTo], on the HTTP thread. [ImageDecoder] both samples
     * during the decode, so the full-size bitmap is never allocated, and applies the EXIF rotation,
     * which the re-encoded JPEG would otherwise lose. The software allocator is what makes the
     * resulting bitmap readable for compression.
     */
    private fun resampledBody(resolver: ContentResolver, uri: Uri, factor: Int): RequestBody =
        object : RequestBody() {
            override fun contentType() = JPEG_MIME_TYPE.toMediaTypeOrNull()

            override fun contentLength() = -1L

            override fun writeTo(sink: BufferedSink) {
                val bitmap = decodeResampled(resolver, uri, factor)
                try {
                    val output = BufferedOutputStream(sink.outputStream(), STREAM_BUFFER_SIZE)
                    if (!bitmap.compress(Bitmap.CompressFormat.JPEG, JPEG_QUALITY, output)) {
                        throw IOException("Cannot encode image")
                    }
                    output.flush()
                } finally {
                    bitmap.recycle()
                }
            }
        }

    private fun decodeResampled(resolver: ContentResolver, uri: Uri, factor: Int): Bitmap {
        val source = ImageDecoder.createSource(resolver, uri)
        return ImageDecoder.decodeBitmap(source) { decoder, _, _ ->
            decoder.setTargetSampleSize(factor)
            decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
        }
    }

    /** An unreadable header is not an error: the photo is simply streamed untouched. */
    private fun boundsOf(resolver: ContentResolver, uri: Uri): Pair<Int, Int>? {
        val options = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        runCatching {
            resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, options) }
        }

        return options.takeIf { it.outWidth > 0 && it.outHeight > 0 }
            ?.let { it.outWidth to it.outHeight }
    }

    private fun mimeTypeOf(resolver: ContentResolver, uri: Uri): String = try {
        resolver.getType(uri) ?: FALLBACK_MIME_TYPE
    } catch (e: SecurityException) {
        throw IOException("Cannot read image: permission denied", e)
    } catch (e: IllegalArgumentException) {
        throw IOException("Cannot read image type: unsupported URI", e)
    }

    private fun fileNameOf(resolver: ContentResolver, uri: Uri): String = try {
        resolver.query(uri, null, null, null, null)?.use { cursor ->
            val nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
            cursor.moveToFirst()
            if (nameIndex >= 0) cursor.getString(nameIndex) else FALLBACK_FILE_NAME
        } ?: FALLBACK_FILE_NAME
    } catch (e: SecurityException) {
        throw IOException("Cannot read image metadata: permission denied", e)
    } catch (e: IllegalArgumentException) {
        throw IOException("Cannot read image metadata: unsupported URI", e)
    }

    private fun jpegNameOf(resolver: ContentResolver, uri: Uri): String =
        fileNameOf(resolver, uri).substringBeforeLast('.', FALLBACK_FILE_NAME) + ".jpg"

    private companion object {
        const val STREAM_BUFFER_SIZE = 8192
        const val JPEG_QUALITY = 85
        const val FALLBACK_FILE_NAME = "image.jpg"
        const val FALLBACK_MIME_TYPE = "image/jpeg"
        const val JPEG_MIME_TYPE = "image/jpeg"
        const val PLAIN_TEXT = "text/plain"
    }
}
