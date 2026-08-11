package fr.gshz.hideandseek.feature.creategame

import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.UiText
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GtfsRoute
import fr.gshz.hideandseek.domain.model.GtfsSourceState
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.repository.AreaInfo

enum class GtfsDialogMode { Url, File }

data class TransitLineGroup(
    val ref: String,
    val name: String,
    val nameEn: String,
    val colour: String,
    val routeType: String,
    val network: String,
    val osmIds: Set<String>,
) {
    private val bestName: String get() = when {
        nameEn.isNotBlank() -> nameEn
        name.isNotBlank() -> name
        else -> ""
    }

    val primary: String get() = ref.ifBlank { bestName }

    val secondary: String get() = bestName

    val label: String get() = when {
        ref.isNotBlank() && bestName.isNotBlank() -> "$ref: $bestName"
        ref.isNotBlank() -> ref
        bestName.isNotBlank() -> bestName
        else -> osmIds.firstOrNull() ?: "?"
    }

    companion object {
        fun fromLines(lines: List<TransitLine>): TransitLineGroup {
            val first = lines.first()
            return TransitLineGroup(
                ref = first.ref,
                name = first.name,
                nameEn = first.nameEn,
                colour = first.colour,
                routeType = first.routeType,
                network = first.network,
                osmIds = lines.map { it.osmId }.toSet(),
            )
        }
    }
}

/**
 * A line can carry several networks in one tag, joined by ';', and the order varies between
 * relations. Splitting means it appears under each real network instead of inventing a combined one.
 */
internal fun splitNetworks(network: String): List<String> =
    network.split(';')
        .map { it.trim() }
        .filter { it.isNotEmpty() }
        .ifEmpty { listOf(network) }

data class CreateGameUiState(
    val name: String = "",
    val size: GameSize = GameSize.Medium,
    val edition: Edition = Edition.Metric,
    val isLoading: Boolean = false,
    val error: ErrorType? = null,
    val errorKey: String? = null,
    val errorArgs: Map<String, String>? = null,
    val createdGameUuid: String? = null,
    /** The connection has no account credential to join as host with: the user must set it up on connect. */
    val needsAccount: Boolean = false,
    val needAccountErrorKey: String? = null,
    val areaSearchQuery: String = "",
    val isSearchingAreas: Boolean = false,
    val areaSearchResults: List<AreaInfo> = emptyList(),
    val selectedAreas: List<AreaInfo> = emptyList(),
    val boundaryGeoJson: String? = null,
    val isDiscoveringTransit: Boolean = false,
    val hasDiscoveredTransit: Boolean = false,
    val transitLines: List<TransitLine> = emptyList(),
    val selectedTransitLines: Set<String> = emptySet(),
    val transitModeFilter: Set<String> = emptySet(),
    val discoveryModeSelection: Set<String> = DEFAULT_DISCOVERY_MODES,
    val showDiscoveryModeDialog: Boolean = false,
    val discoveryError: UiText? = null,
    val transitPreviewGeoJson: String? = null,
    val isLoadingTransitPreview: Boolean = false,
    val transitPreviewError: UiText? = null,
    val gtfsSources: List<GtfsSourceState> = emptyList(),
    val selectedGtfsRouteKeys: Set<String> = emptySet(),
    val showGtfsDialog: Boolean = false,
    val gtfsDialogMode: GtfsDialogMode = GtfsDialogMode.Url,
    val gtfsUrlInput: String = "",
    val gtfsUploading: Boolean = false,
    val gtfsError: UiText? = null,
) {

    /** GTFS routes that are not already covered by OSM transit lines (dedup by ref). */
    val uniqueGtfsRoutes: List<Pair<GtfsSourceState, GtfsRoute>>
        get() {
            val all = gtfsSources.flatMap { source ->
                source.routes.map { source to it }
            }
            if (transitModeFilter.isEmpty()) return all
            return all.filter { (_, route) -> route.routeTypeString in transitModeFilter }
        }
    val availableModes: List<String>
        get() = (
            transitLines.map { it.routeType } +
            gtfsSources.flatMap { it.routes }.map { it.routeTypeString }
        ).distinct().sorted()

    /** Collapsed groups: one row per (network, routeType, ref). */
    val transitLineGroups: List<TransitLineGroup>
        get() {
            val filtered = if (transitModeFilter.isEmpty()) {
                transitLines
            } else {
                transitLines.filter { it.routeType in transitModeFilter }
            }
            val gtfsRefs = gtfsSources.flatMap { it.routes }.map { it.ref }.toSet()
            return filtered
                .filter { it.ref !in gtfsRefs }
                .flatMap { line -> splitNetworks(line.network).map { net -> net to line } }
                .groupBy { (net, line) -> Triple(net, line.routeType, line.ref) }
                .map { (key, entries) ->
                    TransitLineGroup.fromLines(entries.map { it.second }).copy(network = key.first)
                }
                .sortedWith(compareBy<TransitLineGroup> { it.network }
                    .thenBy { it.routeType }
                    .thenComparing { a, b -> compareNatural(a.ref, b.ref) })
        }

    /** Expand selected grouped IDs into flat osmIds for the create request. */
    val expandedSelectedLineIds: Set<String>
        get() = expandSelectedLineIds(transitLines, selectedTransitLines)
}

internal fun expandSelectedLineIds(transitLines: List<TransitLine>, selected: Set<String>): Set<String> {
    val allGroups = transitLines
        .flatMap { line -> splitNetworks(line.network).map { net -> net to line } }
        .groupBy { (net, line) -> Triple(net, line.routeType, line.ref) }
        .map { (_, entries) -> TransitLineGroup.fromLines(entries.map { it.second }) }
    return allGroups
        .filter { group -> group.osmIds.any { it in selected } }
        .flatMap { it.osmIds }
        .toSet()
}

internal fun compareNatural(a: String, b: String): Int {
    if (a.isEmpty() || b.isEmpty()) return a.compareTo(b)
    val regex = Regex("(?<=\\D)(?=\\d)|(?<=\\d)(?=\\D)")
    val aIter = a.splitToSequence(regex).iterator()
    val bIter = b.splitToSequence(regex).iterator()
    var result = 0
    while (result == 0 && aIter.hasNext() && bIter.hasNext()) {
        result = naturalChunkCmp(aIter.next(), bIter.next())
    }
    return if (result != 0) result else aIter.hasNext().compareTo(bIter.hasNext())
}

private fun naturalChunkCmp(a: String, b: String): Int {
    if (!a.first().isDigit() || !b.first().isDigit()) {
        return a.compareTo(b, ignoreCase = true)
    }
    val lenCmp = a.length.compareTo(b.length)
    return if (lenCmp != 0) lenCmp else a.compareTo(b)
}

internal val ALL_ROUTE_TYPES = setOf(
    "subway", "light_rail", "tram", "train",
    "monorail", "funicular", "trolleybus", "bus",
)

internal val DEFAULT_DISCOVERY_MODES = setOf("subway", "light_rail", "tram", "train")
