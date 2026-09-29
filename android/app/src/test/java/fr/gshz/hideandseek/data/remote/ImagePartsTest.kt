package fr.gshz.hideandseek.data.remote

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class ImagePartsTest {

    @Test
    fun `photos at or under the cap are uploaded untouched`() {
        assertFalse(needsResample(UPLOAD_MAX_EDGE_PX, 1536))
        assertFalse(needsResample(2048, 2048))
        assertFalse(needsResample(1200, 900))
    }

    @Test
    fun `portrait photos are measured on their long edge`() {
        assertTrue(needsResample(1536, UPLOAD_MAX_EDGE_PX + 1))
        assertFalse(needsResample(1536, UPLOAD_MAX_EDGE_PX))
    }

    @Test
    fun `a 50 megapixel shot decodes to a shareable size`() {
        val width = 8160
        val height = 6144
        val factor = resampleFactor(width, height)

        assertEquals(4, factor)
        assertEquals(2040, width / factor)
        assertEquals(1536, height / factor)
    }

    @Test
    fun `the sample factor never leaves the long edge above the cap`() {
        for (longEdge in listOf(2049, 3000, 4000, 8160, 12000, 16384, 20000, 100_000)) {
            val factor = resampleFactor(longEdge, longEdge / 2)
            val sampled = longEdge / factor

            assertTrue(sampled <= UPLOAD_MAX_EDGE_PX, "long edge $longEdge sampled to $sampled")
            assertTrue(sampled > UPLOAD_MAX_EDGE_PX / 4, "long edge $longEdge collapsed to $sampled")
        }
    }

    @Test
    fun `resampled photos still fit the server pixel cap`() {
        val factor = resampleFactor(4000, 3000)
        val sampledWidth = 4000 / factor
        val sampledHeight = 3000 / factor

        assertEquals(2, factor)
        assertTrue(sampledWidth * sampledHeight <= 40_000_000)
        assertTrue(maxOf(sampledWidth, sampledHeight) <= 10_000)
    }
}
