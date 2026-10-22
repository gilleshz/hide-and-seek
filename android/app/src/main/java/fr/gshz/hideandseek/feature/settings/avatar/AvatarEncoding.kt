package fr.gshz.hideandseek.feature.settings.avatar

import android.graphics.Bitmap
import java.io.ByteArrayOutputStream

internal const val AVATAR_JPEG_QUALITY = 90

internal fun Bitmap.toAvatarJpeg(): ByteArray {
    val output = ByteArrayOutputStream()
    check(compress(Bitmap.CompressFormat.JPEG, AVATAR_JPEG_QUALITY, output)) {
        "Cannot encode the cropped avatar"
    }

    return output.toByteArray()
}
