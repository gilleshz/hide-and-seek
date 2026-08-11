package fr.gshz.hideandseek.data

import android.content.ContentResolver
import android.content.ContentValues
import android.content.Context
import android.net.Uri
import android.os.Build
import android.provider.MediaStore
import android.util.Log
import dagger.hilt.android.qualifiers.ApplicationContext
import fr.gshz.hideandseek.core.util.StreamLimitExceededException
import fr.gshz.hideandseek.core.util.copyCapped
import java.io.IOException
import java.io.InputStream
import java.io.OutputStream
import javax.inject.Inject
import javax.inject.Named
import javax.inject.Singleton
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.ResponseBody

@Singleton
class ImageSaver @Inject constructor(
    @ApplicationContext private val context: Context,
    @Named("image") private val client: OkHttpClient,
) {

    suspend fun save(url: String): Result<Unit> = withContext(Dispatchers.IO) {
        try {
            fetchAndInsert(url)
            Result.success(Unit)
        } catch (e: IOException) {
            Log.w(TAG, "Failed to save chat image", e)
            Result.failure(e)
        } catch (e: SecurityException) {
            Log.w(TAG, "Failed to save chat image (permission)", e)
            Result.failure(e)
        }
    }

    private fun fetchAndInsert(url: String) {
        val request = Request.Builder().url(url).build()
        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) throw IOException("Image download failed: HTTP ${response.code}")
            val body = response.body ?: throw IOException("Image download returned an empty body")
            checkImageSize(body)
            val mimeType = response.header("Content-Type")?.substringBefore(';')?.trim() ?: DEFAULT_MIME_TYPE
            insertIntoGallery(url.substringAfterLast('/'), mimeType, body.byteStream())
        }
    }

    // A -1 Content-Length means "unknown"; the bounded copy is then the enforcing guard.
    private fun checkImageSize(body: ResponseBody) {
        if (body.contentLength() > MAX_IMAGE_BYTES) throw IOException("Image too large")
    }

    private fun insertIntoGallery(displayName: String, mimeType: String, input: InputStream) {
        val resolver = context.contentResolver
        val values = ContentValues().apply {
            put(MediaStore.Images.Media.DISPLAY_NAME, displayName)
            put(MediaStore.Images.Media.MIME_TYPE, mimeType)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                put(MediaStore.Images.Media.RELATIVE_PATH, RELATIVE_PATH)
                put(MediaStore.Images.Media.IS_PENDING, 1)
            }
        }
        val itemUri = resolver.insert(collectionUri(), values)
            ?: throw IOException("MediaStore insert failed")
        val output = resolver.openOutputStream(itemUri)
            ?: throw IOException("Cannot open MediaStore output stream")
        copyBounded(input, output, itemUri, resolver)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val pending = ContentValues().apply { put(MediaStore.Images.Media.IS_PENDING, 0) }
            resolver.update(itemUri, pending, null, null)
        }
    }

    private fun copyBounded(input: InputStream, output: OutputStream, itemUri: Uri, resolver: ContentResolver) {
        try {
            output.use { input.copyCapped(it, MAX_IMAGE_BYTES) }
        } catch (e: StreamLimitExceededException) {
            resolver.delete(itemUri, null, null)
            throw e
        }
    }

    private fun collectionUri(): Uri =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            MediaStore.Images.Media.getContentUri(MediaStore.VOLUME_EXTERNAL_PRIMARY)
        } else {
            MediaStore.Images.Media.EXTERNAL_CONTENT_URI
        }

    private companion object {
        const val TAG = "ImageSaver"
        const val DEFAULT_MIME_TYPE = "image/jpeg"
        const val RELATIVE_PATH = "Pictures/JetLag"
        // Camera photos from the chat; generous floor, keeps the copy inside the low-memory budget.
        const val MAX_IMAGE_BYTES = 25L * 1024 * 1024
    }
}
