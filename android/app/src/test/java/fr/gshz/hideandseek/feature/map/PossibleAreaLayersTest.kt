package fr.gshz.hideandseek.feature.map

import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class PossibleAreaLayersTest {

    @Test
    fun `empty GeometryCollection is detected as empty`() {
        assertTrue(isEmptyGeoJsonGeometry("""{"type":"GeometryCollection","geometries":[]}"""))
    }

    @Test
    fun `polygon with empty coordinates is detected as empty`() {
        assertTrue(isEmptyGeoJsonGeometry("""{"type":"Polygon","coordinates":[]}"""))
    }

    @Test
    fun `multipolygon with empty coordinates is detected as empty`() {
        assertTrue(isEmptyGeoJsonGeometry("""{"type":"MultiPolygon","coordinates":[]}"""))
    }

    @Test
    fun `polygon with a ring is not empty`() {
        val polygon = """{"type":"Polygon","coordinates":""" +
            """[[[0.0,0.0],[0.0,1.0],[1.0,1.0],[1.0,0.0],[0.0,0.0]]]}"""
        assertFalse(isEmptyGeoJsonGeometry(polygon))
    }

    @Test
    fun `invalid json is not treated as empty`() {
        assertFalse(isEmptyGeoJsonGeometry("not-geojson"))
    }
}
