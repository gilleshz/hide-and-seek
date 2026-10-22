package fr.gshz.hideandseek.feature.settings.avatar

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class AvatarCropMathTest {

    @Test
    fun `the short side is covered at zoom one`() {
        val rect = avatarDrawRect(200, 100, VIEWPORT, AvatarCropTransform())

        assertEquals(-50f, rect.left, DELTA)
        assertEquals(0f, rect.top, DELTA)
        assertEquals(200f, rect.width, DELTA)
        assertEquals(100f, rect.height, DELTA)
    }

    @Test
    fun `zooming keeps the image point under the centroid fixed`() {
        val imageWidth = 300
        val imageHeight = 200
        val before = avatarDrawRect(imageWidth, imageHeight, VIEWPORT, AvatarCropTransform())
        val centroidX = 0.1f
        val centroidPx = VIEWPORT / 2f + centroidX * VIEWPORT
        val imageX = (centroidPx - before.left) / before.width * imageWidth

        val zoomed = AvatarCropTransform().gestured(
            zoomFactor = 2f,
            pan = CropPoint(0f, 0f),
            centroid = CropPoint(centroidX, 0f),
            imageWidth = imageWidth,
            imageHeight = imageHeight,
        )
        val after = avatarDrawRect(imageWidth, imageHeight, VIEWPORT, zoomed)

        assertEquals(centroidPx, after.left + imageX / imageWidth * after.width, DELTA)
    }

    @Test
    fun `the image cannot be dragged past its edge`() {
        val clamped = AvatarCropTransform(offsetX = 10f).clamped(200, 100)
        val rect = avatarDrawRect(200, 100, VIEWPORT, clamped)

        assertEquals(0.5f, clamped.offsetX, DELTA)
        assertEquals(0f, rect.left, DELTA)
    }

    @Test
    fun `dragging moves the image within its bounds`() {
        val rect = avatarDrawRect(200, 100, VIEWPORT, AvatarCropTransform(offsetX = 0.1f))

        assertEquals(-40f, rect.left, DELTA)
    }

    @Test
    fun `zoom is clamped to the supported range`() {
        assertEquals(MAX_AVATAR_ZOOM, AvatarCropTransform(zoom = 10f).clamped(100, 100).zoom, DELTA)
        assertEquals(MIN_AVATAR_ZOOM, AvatarCropTransform(zoom = 0.2f).clamped(100, 100).zoom, DELTA)
    }

    @Test
    fun `a square image cannot be panned at cover zoom`() {
        val clamped = AvatarCropTransform(offsetX = 0.3f).clamped(100, 100)
        val rect = avatarDrawRect(100, 100, VIEWPORT, clamped)

        assertEquals(0f, clamped.offsetX, DELTA)
        assertEquals(0f, rect.left, DELTA)
        assertEquals(100f, rect.width, DELTA)
    }

    @Test
    fun `the exported rect scales with the viewport`() {
        val rect = avatarDrawRect(200, 100, EXPORT_SIZE, AvatarCropTransform())

        assertEquals(-256f, rect.left, DELTA)
        assertEquals(0f, rect.top, DELTA)
        assertEquals(1024f, rect.width, DELTA)
        assertEquals(512f, rect.height, DELTA)
    }

    @Test
    fun `the clamped transform always covers the viewport`() {
        val transforms = listOf(
            AvatarCropTransform(zoom = 3f, offsetX = 99f, offsetY = -99f),
            AvatarCropTransform(zoom = 1f, offsetX = -99f, offsetY = 99f),
        )

        transforms.forEach { transform ->
            val rect = avatarDrawRect(300, 150, VIEWPORT, transform)
            assertTrue(rect.left <= 0f)
            assertTrue(rect.top <= 0f)
            assertTrue(rect.left + rect.width >= VIEWPORT)
            assertTrue(rect.top + rect.height >= VIEWPORT)
        }
    }

    private companion object {
        const val VIEWPORT = 100f
        const val EXPORT_SIZE = 512f
        const val DELTA = 0.01f
    }
}
