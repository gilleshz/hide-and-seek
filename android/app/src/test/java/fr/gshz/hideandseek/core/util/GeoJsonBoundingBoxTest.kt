package fr.gshz.hideandseek.core.util

import fr.gshz.hideandseek.domain.model.BoundingBox
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class GeoJsonBoundingBoxTest {

    @Test
    fun `a feature collection with multipolygons yields the outer bounds`() {
        assertEquals(
            BoundingBox(swLat = 46.50, swLng = 6.60, neLat = 46.60, neLng = 6.70),
            geoJsonBoundingBox(MULTI_POLYGON),
        )
    }

    @Test
    fun `a geometry collection walks every nested geometry`() {
        assertEquals(
            BoundingBox(swLat = 46.50, swLng = 6.60, neLat = 46.55, neLng = 6.65),
            geoJsonBoundingBox(GEOMETRY_COLLECTION),
        )
    }

    @Test
    fun `a point yields a zero-size box`() {
        assertEquals(
            BoundingBox(swLat = 46.50, swLng = 6.60, neLat = 46.50, neLng = 6.60),
            geoJsonBoundingBox("""{"type":"Point","coordinates":[6.60,46.50]}"""),
        )
    }

    @Test
    fun `invalid and non-finite positions are ignored`() {
        assertEquals(
            BoundingBox(swLat = 46.50, swLng = 6.60, neLat = 46.50, neLng = 6.60),
            geoJsonBoundingBox(INVALID_POSITIONS),
        )
    }

    @Test
    fun `malformed json yields null`() {
        assertNull(geoJsonBoundingBox("not json"))
    }

    @Test
    fun `a document without positions yields null`() {
        assertNull(geoJsonBoundingBox("""{"type":"FeatureCollection","features":[]}"""))
    }
}

private val MULTI_POLYGON = """
    {"type":"FeatureCollection","features":[
      {"type":"Feature","properties":{},"geometry":{"type":"MultiPolygon","coordinates":[
        [[[6.60,46.50],[6.70,46.50],[6.70,46.60],[6.60,46.60],[6.60,46.50]]],
        [[[6.62,46.52],[6.68,46.52],[6.68,46.58],[6.62,46.52]]]
      ]}}
    ]}
""".trimIndent()

private val GEOMETRY_COLLECTION = """
    {"type":"GeometryCollection","geometries":[
      {"type":"Point","coordinates":[6.63,46.52]},
      {"type":"LineString","coordinates":[[6.60,46.50],[6.65,46.55]]}
    ]}
""".trimIndent()

private val INVALID_POSITIONS = """
    {"type":"LineString","coordinates":[["x","y"],[6.60,46.50],[1e400,46.60]]}
""".trimIndent()
