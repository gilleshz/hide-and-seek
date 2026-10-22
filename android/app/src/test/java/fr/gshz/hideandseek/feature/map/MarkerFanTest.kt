package fr.gshz.hideandseek.feature.map

import kotlin.math.hypot
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotEquals
import org.junit.jupiter.api.Test

class MarkerFanTest {

    @Test
    fun `two markers at the same point get distinct offsets on the ring`() {
        val offsets = fanOffsets(listOf(marker("a"), marker("b")))

        val a = offsets.getValue("a")
        val b = offsets.getValue("b")
        assertNotEquals(a, b)
        assertEquals(FAN_RADIUS, hypot(a.x.toDouble(), a.y.toDouble()), 0.01)
        assertEquals(FAN_RADIUS, hypot(b.x.toDouble(), b.y.toDouble()), 0.01)
    }

    @Test
    fun `three markers at the same point are spread evenly`() {
        val offsets = fanOffsets(listOf(marker("a"), marker("b"), marker("c")))

        val points = listOf(offsets.getValue("a"), offsets.getValue("b"), offsets.getValue("c"))
        assertEquals(3, points.distinct().size)
        points.forEach { point ->
            assertEquals(FAN_RADIUS, hypot(point.x.toDouble(), point.y.toDouble()), 0.01)
        }
    }

    @Test
    fun `markers far apart keep their exact position`() {
        val offsets = fanOffsets(
            listOf(
                marker("a", latitude = 48.0, longitude = 2.0),
                marker("b", latitude = 48.1, longitude = 2.1),
            ),
        )

        assertEquals(MarkerFanOffset.NONE, offsets.getValue("a"))
        assertEquals(MarkerFanOffset.NONE, offsets.getValue("b"))
    }

    @Test
    fun `a single marker gets no offset`() {
        assertEquals(MarkerFanOffset.NONE, fanOffsets(listOf(marker("a"))).getValue("a"))
    }

    @Test
    fun `the fan is deterministic regardless of input order`() {
        val first = fanOffsets(listOf(marker("a"), marker("b"), marker("c")))
        val second = fanOffsets(listOf(marker("c"), marker("a"), marker("b")))

        assertEquals(first, second)
    }

    private fun marker(
        uuid: String,
        latitude: Double = 48.0,
        longitude: Double = 2.0,
    ) = PlayerMarker(
        playerUuid = uuid,
        displayName = uuid,
        initials = uuid.uppercase(),
        latitude = latitude,
        longitude = longitude,
        isSelf = false,
    )

    private companion object {
        const val FAN_RADIUS = 20.0
    }
}
