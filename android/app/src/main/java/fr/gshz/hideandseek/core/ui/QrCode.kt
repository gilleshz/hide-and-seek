package fr.gshz.hideandseek.core.ui

import android.graphics.Bitmap
import android.graphics.Color
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.size
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter
import fr.gshz.hideandseek.R

@Composable
fun QrCode(content: String, size: Dp = 200.dp, modifier: Modifier = Modifier) {
    val bitmap = remember(content) {
        generateQrBitmap(content, size.value.toInt())
    }
    bitmap?.let {
        Image(
            bitmap = it.asImageBitmap(),
            contentDescription = stringResource(R.string.qr_code_description),
            modifier = modifier.size(size),
        )
    }
}

private fun generateQrBitmap(content: String, sizePx: Int): Bitmap? {
    if (content.isBlank()) return null
    return try {
        val matrix = QRCodeWriter().encode(content, BarcodeFormat.QR_CODE, sizePx, sizePx)
        val bitmap = Bitmap.createBitmap(sizePx, sizePx, Bitmap.Config.RGB_565)
        for (x in 0 until sizePx) {
            for (y in 0 until sizePx) {
                bitmap.setPixel(x, y, if (matrix[x, y]) Color.BLACK else Color.WHITE)
            }
        }
        bitmap
    } catch (_: Exception) {
        null
    }
}
