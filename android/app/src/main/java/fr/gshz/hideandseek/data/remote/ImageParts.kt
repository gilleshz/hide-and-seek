package fr.gshz.hideandseek.data.remote

import android.content.ContentResolver
import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns
import dagger.hilt.android.qualifiers.ApplicationContext
import java.io.IOException
import javax.inject.Inject
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * Every multipart endpoint, chat images, photo answers and the card a powerup is played with, streams
 * the picked image the same way instead of loading it into memory.
 */
class ImageParts @Inject constructor(@ApplicationContext private val context: Context) {

    fun image(imageUri: String): MultipartBody.Part {
        val uri = Uri.parse(imageUri)
        val resolver = context.applicationContext.contentResolver

        return MultipartBody.Part.createFormData(
            "image",
            fileNameOf(resolver, uri),
            streamingBody(resolver, uri, mimeTypeOf(resolver, uri)),
        )
    }

    fun text(value: String): RequestBody = value.toRequestBody(PLAIN_TEXT.toMediaTypeOrNull())

    private fun streamingBody(resolver: ContentResolver, uri: Uri, mimeType: String): RequestBody {
        val mediaType = mimeType.toMediaTypeOrNull()
        return object : RequestBody() {
            override fun contentType() = mediaType

            override fun contentLength() = -1L

            override fun writeTo(sink: okio.BufferedSink) {
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

    private companion object {
        const val STREAM_BUFFER_SIZE = 8192
        const val FALLBACK_FILE_NAME = "image.jpg"
        const val FALLBACK_MIME_TYPE = "image/jpeg"
        const val PLAIN_TEXT = "text/plain"
    }
}
