package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.PhotoTarget
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class TraceGeometryTest {

    @Test
    fun `one degree of latitude measures about 111 kilometres`() {
        val length = traceLengthMeters(listOf(ZonePin(0.0, 0.0), ZonePin(1.0, 0.0)))

        assertEquals(111_195.0, length, 10.0)
    }

    @Test
    fun `a multi segment trace sums its segments`() {
        val vertices = listOf(ZonePin(0.0, 0.0), ZonePin(1.0, 0.0), ZonePin(2.0, 0.0))

        assertEquals(2.0 * 111_195.0, traceLengthMeters(vertices), 20.0)
    }

    @Test
    fun `an empty trace has no length`() {
        assertEquals(0.0, traceLengthMeters(emptyList()), 0.0)
    }

    @Test
    fun `a single vertex has no length`() {
        assertEquals(0.0, traceLengthMeters(listOf(ZonePin(48.85, 2.35))), 0.0)
    }

    @Test
    fun `an empty trace projects to no points`() {
        assertEquals(emptyList<CanvasPoint>(), projectTrace(emptyList(), 100, 100, 0.1f))
    }

    @Test
    fun `every projected point stays inside the padded canvas`() {
        val vertices = listOf(
            ZonePin(48.8566, 2.3522),
            ZonePin(48.8600, 2.3600),
            ZonePin(48.8590, 2.3700),
            ZonePin(48.8520, 2.3650),
        )

        val points = projectTrace(vertices, WIDTH, HEIGHT, PADDING_FRACTION)

        points.forEach { point ->
            assertTrue(point.x >= PADDING - EPSILON && point.x <= WIDTH - PADDING + EPSILON, "x=${point.x}")
            assertTrue(point.y >= PADDING - EPSILON && point.y <= HEIGHT - PADDING + EPSILON, "y=${point.y}")
        }
    }

    @Test
    fun `several polylines share one bounding box`() {
        val far = listOf(ZonePin(0.010, 0.010), ZonePin(0.011, 0.010))
        val near = listOf(ZonePin(0.0, 0.0), ZonePin(0.001, 0.0))

        val projected = projectTracePaths(listOf(near, far), WIDTH, HEIGHT, 0.0f)

        val all = projected.flatten()
        all.forEach { assertTrue(it.x.isFinite() && it.y.isFinite()) }
        // The far polyline sits well past the near one, so their boxes cannot both start at the origin.
        assertTrue(projected[1].minOf { it.y } < projected[0].minOf { it.y })
    }

    @Test
    fun `a square bounding box projects to a square`() {
        val vertices = listOf(
            ZonePin(0.0, 0.0),
            ZonePin(0.001, 0.0),
            ZonePin(0.001, 0.001),
            ZonePin(0.0, 0.001),
        )

        val points = projectTrace(vertices, 300, 200, 0.0f)

        assertEquals(spanOf(points) { it.x }, spanOf(points) { it.y }, 0.5f)
    }

    @Test
    fun `longitude shrinks with the cosine of the centre latitude`() {
        val vertices = listOf(
            ZonePin(60.0, 0.0),
            ZonePin(60.001, 0.0),
            ZonePin(60.001, 0.001),
            ZonePin(60.0, 0.001),
        )

        val points = projectTrace(vertices, 400, 400, 0.0f)

        assertEquals(spanOf(points) { it.y } / 2f, spanOf(points) { it.x }, 1f)
    }

    @Test
    fun `the northernmost vertex lands at the smallest y`() {
        val vertices = listOf(
            ZonePin(48.8520, 2.3522),
            ZonePin(48.8600, 2.3600),
            ZonePin(48.8560, 2.3700),
        )

        val points = projectTrace(vertices, WIDTH, HEIGHT, PADDING_FRACTION)

        val northernmost = vertices.indices.maxBy { vertices[it].latitude }
        assertEquals(points.minOf { it.y }, points[northernmost].y, 0.0f)
    }

    @Test
    fun `a purely north south trace is centred horizontally without NaN`() {
        val vertices = listOf(ZonePin(0.0, 5.0), ZonePin(0.001, 5.0), ZonePin(0.002, 5.0))

        val points = projectTrace(vertices, WIDTH, HEIGHT, PADDING_FRACTION)

        points.forEach { point ->
            assertTrue(point.x.isFinite(), "x=${point.x}")
            assertTrue(point.y.isFinite(), "y=${point.y}")
            assertEquals(WIDTH / 2f, point.x, EPSILON)
        }
        assertEquals(PADDING, points.minOf { it.y }, EPSILON)
    }

    @Test
    fun `a purely east west trace is centred vertically without NaN`() {
        val vertices = listOf(ZonePin(5.0, 0.0), ZonePin(5.0, 0.001), ZonePin(5.0, 0.002))

        val points = projectTrace(vertices, WIDTH, HEIGHT, PADDING_FRACTION)

        points.forEach { point ->
            assertTrue(point.x.isFinite(), "x=${point.x}")
            assertEquals(HEIGHT / 2f, point.y, EPSILON)
        }
        assertEquals(PADDING, points.minOf { it.x }, EPSILON)
    }

    @Test
    fun `a repeated single point collapses to the canvas centre`() {
        val vertices = listOf(ZonePin(48.85, 2.35), ZonePin(48.85, 2.35))

        val points = projectTrace(vertices, WIDTH, HEIGHT, PADDING_FRACTION)

        points.forEach { point ->
            assertEquals(WIDTH / 2f, point.x, EPSILON)
            assertEquals(HEIGHT / 2f, point.y, EPSILON)
        }
    }

    @Test
    fun `the traced streets question asks for a kilometre in metric`() {
        assertEquals(1000.0, minimumTraceMeters(PhotoTarget.StreetsTraced, Edition.Metric))
    }

    @Test
    fun `the traced streets question asks for half a mile in imperial`() {
        assertEquals(804.672, minimumTraceMeters(PhotoTarget.StreetsTraced, Edition.Imperial))
    }

    @Test
    fun `other photo targets have no minimum`() {
        assertNull(minimumTraceMeters(PhotoTarget.TraceNearestStreet, Edition.Metric))
        assertNull(minimumTraceMeters(PhotoTarget.Tree, Edition.Imperial))
    }

    private fun spanOf(points: List<CanvasPoint>, selector: (CanvasPoint) -> Float): Float =
        points.maxOf(selector) - points.minOf(selector)

    private companion object {
        const val WIDTH = 200
        const val HEIGHT = 100
        const val PADDING_FRACTION = 0.1f
        val PADDING = PADDING_FRACTION * minOf(WIDTH, HEIGHT)
        const val EPSILON = 0.01f
    }
}
