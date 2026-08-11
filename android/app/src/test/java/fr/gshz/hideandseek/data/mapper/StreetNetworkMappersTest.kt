package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.StreetNetworkDto
import fr.gshz.hideandseek.data.remote.dto.StreetWayDto
import fr.gshz.hideandseek.domain.model.StreetClass
import fr.gshz.hideandseek.domain.model.StreetNetworkStatus
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class StreetNetworkMappersTest {

    @Test
    fun `a coordinate pair is read as longitude then latitude`() {
        val network = StreetNetworkDto(
            status = "ready",
            ways = listOf(
                StreetWayDto(
                    streetClass = "residential",
                    coordinates = listOf(listOf(LONGITUDE, LATITUDE), listOf(LONGITUDE_2, LATITUDE_2)),
                ),
            ),
        ).toDomain()

        val points = network.ways.single().points
        assertEquals(LATITUDE, points[0].latitude, 0.0)
        assertEquals(LONGITUDE, points[0].longitude, 0.0)
        assertEquals(LATITUDE_2, points[1].latitude, 0.0)
        assertEquals(LONGITUDE_2, points[1].longitude, 0.0)
    }

    @Test
    fun `a ready payload maps the status and every way`() {
        val network = StreetNetworkDto(
            status = "ready",
            ways = listOf(way("residential"), way("sidewalk")),
        ).toDomain()

        assertEquals(StreetNetworkStatus.Ready, network.status)
        assertEquals(listOf(StreetClass.Residential, StreetClass.Sidewalk), network.ways.map { it.streetClass })
    }

    @Test
    fun `a way with a malformed coordinate pair is discarded rather than re-indexed`() {
        val network = StreetNetworkDto(
            status = "ready",
            ways = listOf(
                StreetWayDto(
                    streetClass = "residential",
                    coordinates = listOf(
                        listOf(LONGITUDE),
                        listOf(LONGITUDE, LATITUDE),
                        listOf(LONGITUDE_2, LATITUDE_2),
                    ),
                    junctionIndices = listOf(2),
                ),
                way("secondary", junctionIndices = listOf(1)),
            ),
        ).toDomain()

        assertEquals(listOf(StreetClass.Secondary), network.ways.map { it.streetClass })
        assertEquals(listOf(1), network.ways.single().junctionIndices)
    }

    @Test
    fun `a way with a coordinate outside the earth is discarded`() {
        val network = StreetNetworkDto(
            status = "ready",
            ways = listOf(
                StreetWayDto(
                    streetClass = "residential",
                    coordinates = listOf(listOf(LONGITUDE, LATITUDE), listOf(200.0, 95.0)),
                ),
                way("secondary"),
            ),
        ).toDomain()

        assertEquals(listOf(StreetClass.Secondary), network.ways.map { it.streetClass })
    }

    @Test
    fun `a way left with fewer than two points is discarded`() {
        val network = StreetNetworkDto(
            status = "ready",
            ways = listOf(StreetWayDto(streetClass = "residential", coordinates = listOf(listOf(LONGITUDE, LATITUDE)))),
        ).toDomain()

        assertTrue(network.ways.isEmpty())
    }

    @Test
    fun `an unknown class falls back to other rather than dropping the way`() {
        val network = StreetNetworkDto(status = "ready", ways = listOf(way("hyperloop"))).toDomain()

        assertEquals(StreetClass.Other, network.ways.single().streetClass)
    }

    @Test
    fun `an unknown status reads as unavailable while a known one keeps its wire meaning`() {
        assertEquals(StreetNetworkStatus.Unavailable, StreetNetworkDto(status = "warming").toDomain().status)
        assertEquals(StreetNetworkStatus.Pending, StreetNetworkDto(status = "pending").toDomain().status)
    }

    @Test
    fun `an all-default payload maps to an empty pending-shaped network`() {
        val network = StreetNetworkDto().toDomain()

        assertEquals(StreetNetworkStatus.Unavailable, network.status)
        assertTrue(network.ways.isEmpty())
    }

    @Test
    fun `a junction index outside the kept points is dropped`() {
        val network = StreetNetworkDto(
            status = "ready",
            ways = listOf(way("residential", junctionIndices = listOf(0, 1, 7))),
        ).toDomain()

        assertEquals(listOf(0, 1), network.ways.single().junctionIndices)
    }

    private companion object {
        const val LONGITUDE = 13.40512
        const val LATITUDE = 52.52001
        const val LONGITUDE_2 = 13.40598
        const val LATITUDE_2 = 52.52044

        fun way(streetClass: String, junctionIndices: List<Int> = emptyList()) = StreetWayDto(
            streetClass = streetClass,
            coordinates = listOf(listOf(LONGITUDE, LATITUDE), listOf(LONGITUDE_2, LATITUDE_2)),
            junctionIndices = junctionIndices,
        )
    }
}
