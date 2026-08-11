package fr.gshz.hideandseek.core.scanner

import android.graphics.Rect
import android.media.Image
import android.graphics.ImageFormat
import androidx.camera.core.ImageInfo
import androidx.camera.core.ImageProxy
import androidx.camera.core.impl.TagBundle
import androidx.camera.core.impl.utils.ExifData
import com.google.zxing.BarcodeFormat
import com.google.zxing.LuminanceSource
import com.google.zxing.PlanarYUVLuminanceSource
import com.google.zxing.RGBLuminanceSource
import com.google.zxing.qrcode.QRCodeWriter
import java.nio.ByteBuffer
import java.util.Random
import org.junit.jupiter.api.Assertions.assertArrayEquals
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

private val QR_BLACK = 0xFF000000.toInt()
private val QR_WHITE = 0xFFFFFFFF.toInt()

private const val DEFAULT_QR_SIZE = 512
private const val SMALL_QR_SIZE = 96
private const val ODD_QR_SIZE = 97
private const val ROW_PADDING = 13

class QrAnalyzerTest {

    private fun luminanceOf(content: String, size: Int = DEFAULT_QR_SIZE): LuminanceSource {
        val matrix = QRCodeWriter().encode(content, BarcodeFormat.QR_CODE, size, size)
        val pixels = IntArray(size * size) { index ->
            val x = index % size
            val y = index / size
            if (matrix[x, y]) QR_BLACK else QR_WHITE
        }
        return RGBLuminanceSource(size, size, pixels)
    }

    private fun solidWhiteLuminance(size: Int = DEFAULT_QR_SIZE): LuminanceSource =
        RGBLuminanceSource(size, size, IntArray(size * size) { QR_WHITE })

    private fun noiseLuminance(seed: Long, size: Int = DEFAULT_QR_SIZE): LuminanceSource {
        val random = Random(seed)
        val pixels = IntArray(size * size) { if (random.nextBoolean()) QR_BLACK else QR_WHITE }
        return RGBLuminanceSource(size, size, pixels)
    }

    private fun yuvLuminanceOf(content: String, size: Int): ByteArray {
        val matrix = QRCodeWriter().encode(content, BarcodeFormat.QR_CODE, size, size)
        return ByteArray(size * size) { index ->
            val x = index % size
            val y = index / size
            if (matrix[x, y]) 0 else 0xFF.toByte()
        }
    }

    private fun decodeYuv(yuv: ByteArray, width: Int, height: Int): String? =
        decodeQr(PlanarYUVLuminanceSource(yuv, width, height, 0, 0, width, height, false))

    private fun paddedPlaneOf(
        content: String,
        size: Int,
        rowStride: Int,
        pixelStride: Int,
    ): ByteArray {
        val yuv = yuvLuminanceOf(content, size)
        val plane = ByteArray(rowStride * size) { 0x7F }
        for (row in 0 until size) {
            val rowStart = row * rowStride
            for (col in 0 until size) {
                plane[rowStart + col * pixelStride] = yuv[row * size + col]
            }
        }
        return plane
    }

    private fun imageProxyOf(
        plane: ByteArray,
        width: Int,
        height: Int,
        rowStride: Int,
        pixelStride: Int,
    ): ImageProxy = FakeImageProxy(
        width = width,
        height = height,
        plane = FakePlaneProxy(ByteBuffer.wrap(plane), rowStride, pixelStride),
    )

    @Test
    fun `decodes a plain join code`() {
        assertEquals("8S4X2N", decodeQr(luminanceOf("8S4X2N")))
    }

    @Test
    fun `decodes accented utf8 content`() {
        assertEquals("café", decodeQr(luminanceOf("café")))
    }

    @Test
    fun `decodes a json join payload`() {
        val payload = """{"apiUrl":"https://api.example.com/","apiKey":"secret","joinCode":"8S4X2N"}"""
        assertEquals(payload, decodeQr(luminanceOf(payload)))
    }

    @Test
    fun `decodes a small qr`() {
        assertEquals("8S4X2N", decodeQr(luminanceOf("8S4X2N", SMALL_QR_SIZE)))
    }

    @Test
    fun `returns null for a solid white frame`() {
        assertNull(decodeQr(solidWhiteLuminance()))
    }

    @Test
    fun `returns null for random noise`() {
        assertNull(decodeQr(noiseLuminance(seed = 42)))
    }

    @Test
    fun `strips row padding from a yuv plane`() {
        val size = SMALL_QR_SIZE
        val image = imageProxyOf(
            paddedPlaneOf("8S4X2N", size, rowStride = size + ROW_PADDING, pixelStride = 1),
            width = size,
            height = size,
            rowStride = size + ROW_PADDING,
            pixelStride = 1,
        )
        val extracted = image.extractYPlane()
        assertEquals("8S4X2N", extracted?.let { decodeYuv(it, size, size) })
    }

    @Test
    fun `strips pixel padding from a yuv plane`() {
        val size = SMALL_QR_SIZE
        val image = imageProxyOf(
            paddedPlaneOf("8S4X2N", size, rowStride = size * 2, pixelStride = 2),
            width = size,
            height = size,
            rowStride = size * 2,
            pixelStride = 2,
        )
        val extracted = image.extractYPlane()
        assertEquals("8S4X2N", extracted?.let { decodeYuv(it, size, size) })
    }

    @Test
    fun `extracts an odd-sized plane with row and pixel padding`() {
        val size = ODD_QR_SIZE
        val rowStride = size * 2 + ROW_PADDING
        val image = imageProxyOf(
            paddedPlaneOf("8S4X2N", size, rowStride = rowStride, pixelStride = 2),
            width = size,
            height = size,
            rowStride = rowStride,
            pixelStride = 2,
        )
        val extracted = image.extractYPlane()
        assertEquals("8S4X2N", extracted?.let { decodeYuv(it, size, size) })
    }

    @Test
    fun `rotates a 90-degree buffer by swapping dimensions`() {
        assertArrayEquals(
            byteArrayOf(4, 2, 0, 5, 3, 1),
            rotateYuv(byteArrayOf(0, 1, 2, 3, 4, 5), width = 2, height = 3, degrees = 90),
        )
    }

    @Test
    fun `rotates a 180-degree buffer by reversing rows`() {
        assertArrayEquals(
            byteArrayOf(5, 4, 3, 2, 1, 0),
            rotateYuv(byteArrayOf(0, 1, 2, 3, 4, 5), width = 2, height = 3, degrees = 180),
        )
    }

    @Test
    fun `rotates a 270-degree buffer by swapping dimensions`() {
        assertArrayEquals(
            byteArrayOf(1, 3, 5, 0, 2, 4),
            rotateYuv(byteArrayOf(0, 1, 2, 3, 4, 5), width = 2, height = 3, degrees = 270),
        )
    }

    @Test
    fun `decodes a 90-degree rotated frame`() {
        val size = SMALL_QR_SIZE
        val rotated = rotateYuv(yuvLuminanceOf("8S4X2N", size), size, size, 90)
        assertEquals("8S4X2N", decodeYuv(rotated, size, size))
    }

    @Test
    fun `decodes a 180-degree rotated frame`() {
        val size = SMALL_QR_SIZE
        val rotated = rotateYuv(yuvLuminanceOf("8S4X2N", size), size, size, 180)
        assertEquals("8S4X2N", decodeYuv(rotated, size, size))
    }

    @Test
    fun `decodes a 270-degree rotated frame`() {
        val size = SMALL_QR_SIZE
        val rotated = rotateYuv(yuvLuminanceOf("8S4X2N", size), size, size, 270)
        assertEquals("8S4X2N", decodeYuv(rotated, size, size))
    }
}

private class FakePlaneProxy(
    private val buffer: ByteBuffer,
    private val rowStride: Int,
    private val pixelStride: Int,
) : ImageProxy.PlaneProxy {
    override fun getBuffer(): ByteBuffer = buffer
    override fun getRowStride(): Int = rowStride
    override fun getPixelStride(): Int = pixelStride
}

private class FakeImageProxy(
    private val width: Int,
    private val height: Int,
    private val plane: ImageProxy.PlaneProxy,
) : ImageProxy {
    override fun getFormat(): Int = ImageFormat.YUV_420_888
    override fun getWidth(): Int = width
    override fun getHeight(): Int = height
    override fun getPlanes(): Array<ImageProxy.PlaneProxy> = arrayOf(plane)
    override fun getCropRect(): Rect = Rect(0, 0, width, height)
    override fun setCropRect(rect: Rect?) = Unit
    override fun getImageInfo(): ImageInfo = FakeImageInfo
    override fun getImage(): Image? = null
    override fun close() = Unit
}

private object FakeImageInfo : ImageInfo {
    override fun getTagBundle(): TagBundle = TagBundle.emptyBundle()
    override fun getTimestamp(): Long = 0L
    override fun getRotationDegrees(): Int = 0
    override fun populateExifData(builder: ExifData.Builder) = Unit
}
