package fr.gshz.hideandseek.feature.settings.avatar

import android.content.Context
import android.graphics.Bitmap
import android.graphics.ImageDecoder
import android.net.Uri
import java.io.IOException
import kotlin.math.max

internal const val AVATAR_SOURCE_MAX_EDGE_PX = 2048

/** Decodes a picked or captured photo, honoring EXIF orientation and sampling large originals. */
internal fun decodeAvatarSource(context: Context, uri: Uri): Bitmap? = try {
    val source = ImageDecoder.createSource(context.contentResolver, uri)
    ImageDecoder.decodeBitmap(source) { decoder, info, _ ->
        val width = info.size.width
        val height = info.size.height
        val longest = max(width, height)
        if (longest > AVATAR_SOURCE_MAX_EDGE_PX) {
            val scale = AVATAR_SOURCE_MAX_EDGE_PX.toFloat() / longest
            decoder.setTargetSize(
                (width * scale).toInt().coerceAtLeast(1),
                (height * scale).toInt().coerceAtLeast(1),
            )
        }
        decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
    }
} catch (_: IOException) {
    null
} catch (_: IllegalArgumentException) {
    null
} catch (_: SecurityException) {
    null
}
