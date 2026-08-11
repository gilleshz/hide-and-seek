package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.StreetClass
import fr.gshz.hideandseek.domain.model.StreetWay
import java.util.Locale
import kotlin.math.cos

internal const val SELECT_RADIUS_METERS = 30.0
internal const val RANK_TIE_METERS = 12.0

enum class TraceShape { Empty, Path, Branches, Disconnected }

private data class StreetEdge(
    val id: Int,
    val streetClass: StreetClass,
    val points: List<ZonePin>,
    val nodeA: String,
    val nodeB: String,
    val lengthMeters: Double,
)

/**
 * The hider taps whole street sections, not points. Ways arrive already cut at their intersections
 * ([StreetWay.junctionIndices]), so an edge is one run of real geometry between two nodes.
 */
class StreetGraph(ways: List<StreetWay>) {

    private val edges: List<StreetEdge> = buildEdges(ways)

    val isEmpty: Boolean get() = edges.isEmpty()

    fun nearestEdgeId(point: ZonePin): Int? {
        val hits = edges.mapNotNull { edge ->
            val distance = edge.distanceMeters(point)
            if (distance > SELECT_RADIUS_METERS) null else EdgeHit(edge.id, distance, edge.streetClass.snapRank)
        }
        val closest = hits.minByOrNull { it.distanceMeters } ?: return null
        val window = closest.distanceMeters + RANK_TIE_METERS
        return (hits.filter { it.distanceMeters <= window }.minWithOrNull(RANK_THEN_DISTANCE) ?: closest).edgeId
    }

    fun lengthMeters(selected: Set<Int>): Double =
        edges.filter { it.id in selected }.sumOf { it.lengthMeters }

    fun selectedPolylines(selected: Set<Int>): List<List<ZonePin>> =
        edges.filter { it.id in selected }.map { it.points }

    fun allPolylines(): List<List<ZonePin>> = edges.map { it.points }

    fun shape(selected: Set<Int>): TraceShape {
        val chosen = edges.filter { it.id in selected }
        if (chosen.isEmpty()) return TraceShape.Empty
        val degree = chosen.flatMap { listOf(it.nodeA, it.nodeB) }.groupingBy { it }.eachCount()
        return when {
            components(chosen) > 1 -> TraceShape.Disconnected
            degree.values.any { it >= BRANCH_DEGREE } -> TraceShape.Branches
            else -> TraceShape.Path
        }
    }
}

private data class EdgeHit(val edgeId: Int, val distanceMeters: Double, val rank: Int)

private val RANK_THEN_DISTANCE =
    compareBy<EdgeHit>({ it.rank }, { it.distanceMeters })

private fun StreetEdge.distanceMeters(point: ZonePin): Double =
    points.zipWithNext().minOf { (start, end) -> haversineMeters(point, footOnSegment(point, start, end)) }

private fun buildEdges(ways: List<StreetWay>): List<StreetEdge> {
    val edges = mutableListOf<StreetEdge>()
    ways.forEach { way ->
        val pins = way.points.map { ZonePin(it.latitude, it.longitude) }
        for ((from, to) in splitPositions(way).zipWithNext()) {
            val run = pins.subList(from, to + 1)
            if (run.size < MIN_EDGE_POINTS) continue
            edges += StreetEdge(
                id = edges.size,
                streetClass = way.streetClass,
                points = run,
                nodeA = nodeKey(run.first()),
                nodeB = nodeKey(run.last()),
                lengthMeters = traceLengthMeters(run),
            )
        }
    }
    return edges
}

private fun splitPositions(way: StreetWay): List<Int> {
    val last = way.points.lastIndex
    return (way.junctionIndices + listOf(0, last))
        .filter { it in 0..last }
        .distinct()
        .sorted()
}

/** Mirrors the server junction key (5 decimals, negative zero collapsed) so two edges meeting at an
 *  intersection share a node string; boxed doubles would split -0.0 from 0.0 and never join them. */
private fun nodeKey(pin: ZonePin): String =
    "%.5f,%.5f".format(Locale.US, pin.longitude + 0.0, pin.latitude + 0.0)

private fun components(edges: List<StreetEdge>): Int {
    val parent = mutableMapOf<String, String>()
    fun root(node: String): String {
        var current = node
        while (parent[current] != current) {
            val next = parent.getValue(current)
            parent[current] = parent.getValue(next)
            current = next
        }
        return current
    }
    edges.forEach { edge ->
        parent.getOrPut(edge.nodeA) { edge.nodeA }
        parent.getOrPut(edge.nodeB) { edge.nodeB }
        parent[root(edge.nodeA)] = root(edge.nodeB)
    }
    return parent.keys.map { root(it) }.toHashSet().size
}

private fun footOnSegment(point: ZonePin, start: ZonePin, end: ZonePin): ZonePin {
    val stretch = cos(point.latitude * RADIANS_PER_DEGREE)
    val startX = (start.longitude - point.longitude) * stretch
    val startY = start.latitude - point.latitude
    val spanX = (end.longitude - start.longitude) * stretch
    val spanY = end.latitude - start.latitude
    val spanSquared = spanX * spanX + spanY * spanY
    if (spanSquared == 0.0) return start
    val along = (-(startX * spanX + startY * spanY) / spanSquared).coerceIn(0.0, 1.0)
    return ZonePin(
        latitude = start.latitude + along * (end.latitude - start.latitude),
        longitude = start.longitude + along * (end.longitude - start.longitude),
    )
}

private const val MIN_EDGE_POINTS = 2
private const val BRANCH_DEGREE = 3
private const val RADIANS_PER_DEGREE = Math.PI / 180.0
