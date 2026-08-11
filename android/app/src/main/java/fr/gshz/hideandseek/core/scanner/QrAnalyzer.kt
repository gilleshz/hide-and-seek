package fr.gshz.hideandseek.core.scanner

import android.graphics.ImageFormat
import android.os.Handler
import android.os.Looper
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import com.google.zxing.BarcodeFormat
import com.google.zxing.BinaryBitmap
import com.google.zxing.DecodeHintType
import com.google.zxing.LuminanceSource
import com.google.zxing.PlanarYUVLuminanceSource
import com.google.zxing.qrcode.QRCodeReader
import com.google.zxing.ReaderException
import com.google.zxing.common.HybridBinarizer

private const val FRAME_INTERVAL = 3
private const val ROTATION_90 = 90
private const val ROTATION_180 = 180
private const val ROTATION_270 = 270

private val DECODE_HINTS = mapOf(
    DecodeHintType.POSSIBLE_FORMATS to listOf(BarcodeFormat.QR_CODE),
    DecodeHintType.TRY_HARDER to true,
)

internal fun decodeQr(luminance: LuminanceSource): String? =
    try {
        QRCodeReader().decode(BinaryBitmap(HybridBinarizer(luminance)), DECODE_HINTS).text
    } catch (_: ReaderException) {
        null
    }

internal class QrAnalyzer(
    private val onResult: (String) -> Unit,
) : ImageAnalysis.Analyzer {

    private val mainHandler = Handler(Looper.getMainLooper())
    private var frameCounter = 0
    private var decoded = false

    override fun analyze(imageProxy: ImageProxy) {
        try {
            if (decoded) return
            if (frameCounter++ % FRAME_INTERVAL != 0) return
            val text = imageProxy.buildLuminanceSource()?.let(::decodeQr)
            if (text != null) {
                decoded = true
                mainHandler.post { onResult(text) }
            }
        } finally {
            imageProxy.close()
        }
    }

    private fun ImageProxy.buildLuminanceSource(): PlanarYUVLuminanceSource? {
        val yBytes = extractYPlane() ?: return null
        return when (val rotation = imageInfo.rotationDegrees) {
            ROTATION_90, ROTATION_270 -> PlanarYUVLuminanceSource(
                rotateYuv(yBytes, width, height, rotation),
                height,
                width,
                0,
                0,
                height,
                width,
                false,
            )
            ROTATION_180 -> PlanarYUVLuminanceSource(
                rotateYuv(yBytes, width, height, rotation),
                width,
                height,
                0,
                0,
                width,
                height,
                false,
            )
            else -> PlanarYUVLuminanceSource(yBytes, width, height, 0, 0, width, height, false)
        }
    }
}

internal fun ImageProxy.extractYPlane(): ByteArray? {
    if (format != ImageFormat.YUV_420_888 || planes.isEmpty()) return null
    val plane = planes[0]
    val buffer = plane.buffer
    val yBytes = ByteArray(width * height)
    var outputIndex = 0
    if (plane.pixelStride == 1) {
        for (row in 0 until height) {
            buffer.position(row * plane.rowStride)
            buffer.get(yBytes, outputIndex, width)
            outputIndex += width
        }
    } else {
        for (row in 0 until height) {
            val rowStart = row * plane.rowStride
            for (col in 0 until width) {
                yBytes[outputIndex + col] = buffer.get(rowStart + col * plane.pixelStride)
            }
            outputIndex += width
        }
    }
    return yBytes
}

internal fun rotateYuv(yuv: ByteArray, width: Int, height: Int, degrees: Int): ByteArray {
    val rotated = ByteArray(yuv.size)
    when (degrees) {
        ROTATION_90 -> {
            for (x in 0 until width) {
                for (y in 0 until height) {
                    rotated[x * height + (height - 1 - y)] = yuv[y * width + x]
                }
            }
        }
        ROTATION_180 -> {
            for (y in 0 until height) {
                for (x in 0 until width) {
                    rotated[(height - 1 - y) * width + (width - 1 - x)] = yuv[y * width + x]
                }
            }
        }
        ROTATION_270 -> {
            for (x in 0 until width) {
                for (y in 0 until height) {
                    rotated[(width - 1 - x) * height + y] = yuv[y * width + x]
                }
            }
        }
    }
    return rotated
}
