package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.StreetClass
import fr.gshz.hideandseek.domain.model.StreetPoint
import fr.gshz.hideandseek.domain.model.StreetWay
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class StreetGraphTest {

    @Test
    fun `a way splits into one edge per run between its junctions`() {
        val graph = StreetGraph(listOf(horizontal(junctionAt = 1)))

        assertEquals(2, graph.allPolylines().size)
    }

    @Test
    fun `a tap picks the nearest edge`() {
        val graph = StreetGraph(cross())

        assertEquals(EDGE_AB, graph.nearestEdgeId(ZonePin(0.00001, 0.00040)))
    }

    @Test
    fun `a tap between a street and its sidewalk takes the street`() {
        val graph = StreetGraph(streetBesideSidewalk())
        val betweenThem = ZonePin(0.00003, 0.00050)

        val hit = graph.nearestEdgeId(betweenThem)

        assertEquals(StreetClass.Residential, edgeClassOf(graph, hit))
    }

    @Test
    fun `a tap out of range selects nothing`() {
        val graph = StreetGraph(cross())

        assertNull(graph.nearestEdgeId(ZonePin(0.9, 0.9)))
    }

    @Test
    fun `length is the sum of the chosen edges`() {
        val graph = StreetGraph(cross())

        val one = graph.lengthMeters(setOf(EDGE_AB))
        val two = graph.lengthMeters(setOf(EDGE_AB, EDGE_BC))

        assertTrue(one > 0.0)
        assertEquals(one * 2.0, two, one * 0.01)
    }

    @Test
    fun `an empty selection is Empty and a single edge is a Path`() {
        val graph = StreetGraph(cross())

        assertEquals(TraceShape.Empty, graph.shape(emptySet()))
        assertEquals(TraceShape.Path, graph.shape(setOf(EDGE_AB)))
    }

    @Test
    fun `two edges sharing a node are a Path`() {
        val graph = StreetGraph(cross())

        assertEquals(TraceShape.Path, graph.shape(setOf(EDGE_AB, EDGE_BE)))
    }

    @Test
    fun `three edges meeting at one node branch`() {
        val graph = StreetGraph(cross())

        assertEquals(TraceShape.Branches, graph.shape(setOf(EDGE_AB, EDGE_BC, EDGE_BE)))
    }

    @Test
    fun `two edges that do not touch are Disconnected`() {
        val graph = StreetGraph(cross())

        assertEquals(TraceShape.Disconnected, graph.shape(setOf(EDGE_AB, EDGE_BE_FAR)))
    }

    private fun edgeClassOf(graph: StreetGraph, edgeId: Int?): StreetClass {
        val selected = graph.selectedPolylines(setOf(edgeId ?: -1))
        assertEquals(1, selected.size)
        return if (selected.first().first().latitude == 0.0) StreetClass.Residential else StreetClass.Sidewalk
    }

    private companion object {
        // cross(): a horizontal street A-B-C split at B, and a vertical street D-B-E split at B.
        const val EDGE_AB = 0
        const val EDGE_BC = 1
        const val EDGE_BE = 3
        const val EDGE_BE_FAR = 4

        fun horizontal(junctionAt: Int) = StreetWay(
            streetClass = StreetClass.Residential,
            points = listOf(pt(0.0, 0.0), pt(0.0, 0.001), pt(0.0, 0.002)),
            junctionIndices = listOf(junctionAt),
        )

        fun cross(): List<StreetWay> = listOf(
            StreetWay(
                streetClass = StreetClass.Residential,
                points = listOf(pt(0.0, 0.0), pt(0.0, 0.001), pt(0.0, 0.002)),
                junctionIndices = listOf(1),
            ),
            StreetWay(
                streetClass = StreetClass.Residential,
                points = listOf(pt(-0.001, 0.001), pt(0.0, 0.001), pt(0.001, 0.001)),
                junctionIndices = listOf(1),
            ),
            StreetWay(
                streetClass = StreetClass.Residential,
                points = listOf(pt(0.5, 0.5), pt(0.5, 0.501)),
                junctionIndices = emptyList(),
            ),
        )

        fun streetBesideSidewalk(): List<StreetWay> = listOf(
            StreetWay(StreetClass.Residential, listOf(pt(0.0, 0.0), pt(0.0, 0.001))),
            StreetWay(StreetClass.Sidewalk, listOf(pt(0.00005, 0.0), pt(0.00005, 0.001))),
        )

        fun pt(latitude: Double, longitude: Double) = StreetPoint(latitude, longitude)
    }
}
