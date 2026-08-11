package fr.gshz.hideandseek.feature.creategame

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.AddLocationAlt
import androidx.compose.material.icons.filled.CheckBox
import androidx.compose.material.icons.filled.CheckBoxOutlineBlank
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TriStateCheckbox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.state.ToggleableState
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.SectionHeader
import fr.gshz.hideandseek.core.ui.UiText
import fr.gshz.hideandseek.core.ui.resolve
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.GtfsRoute
import fr.gshz.hideandseek.domain.model.GtfsSourceState

private const val FALLBACK_LINE_COLOR = 0xFF4A90D9.toInt()

@Suppress("LongParameterList")
@Composable
internal fun TransitLinePicker(
    groups: List<TransitLineGroup>,
    availableModes: List<String>,
    selectedModeFilters: Set<String>,
    selectedIds: Set<String>,
    onToggleGroup: (TransitLineGroup) -> Unit,
    onToggleGroups: (List<TransitLineGroup>) -> Unit,
    onToggleModeFilter: (String) -> Unit,
    gtfsSources: List<GtfsSourceState> = emptyList(),
    uniqueGtfsRoutes: List<Pair<GtfsSourceState, GtfsRoute>> = emptyList(),
    selectedGtfsRouteKeys: Set<String> = emptySet(),
    onToggleGtfsRoute: (String, String) -> Unit = { _, _ -> },
    onShowGtfsDialog: () -> Unit = {},
    onRemoveGtfsSource: (String) -> Unit = {},
    hasDiscoveredTransit: Boolean = false,
    isDiscoveringTransit: Boolean = false,
    onDiscoverOsmTransit: () -> Unit = {},
    hasSelectedAreas: Boolean = false,
    discoveryModeSelection: Set<String> = DEFAULT_DISCOVERY_MODES,
    onToggleDiscoveryMode: (String) -> Unit = {},
    onToggleAllDiscoveryModes: () -> Unit = {},
    showDiscoveryModeDialog: Boolean = false,
    onShowDiscoveryModeDialog: () -> Unit = {},
    onDismissDiscoveryModeDialog: () -> Unit = {},
    discoveryError: UiText? = null,
) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionHeader(
            text = stringResource(R.string.transit_lines_header),
            icon = Icons.Filled.AddLocationAlt,
        )

        TransitActionButtons(
            onShowGtfsDialog = onShowGtfsDialog,
            isDiscoveringTransit = isDiscoveringTransit,
            hasDiscoveredTransit = hasDiscoveredTransit,
            hasSelectedAreas = hasSelectedAreas,
            onShowDiscoveryModeDialog = onShowDiscoveryModeDialog,
        )

        if (showDiscoveryModeDialog) {
            DiscoveryModeDialog(
                selection = discoveryModeSelection,
                onToggleMode = onToggleDiscoveryMode,
                onToggleAll = onToggleAllDiscoveryModes,
                onSearch = {
                    onDiscoverOsmTransit()
                    onDismissDiscoveryModeDialog()
                },
                onDismiss = onDismissDiscoveryModeDialog,
            )
        }

        DiscoveryErrorText(discoveryError)

        if (hasDiscoveredTransit && availableModes.isNotEmpty()) {
            ModeFilterChipRow(availableModes, selectedModeFilters, onToggleModeFilter)
        }

        if (groups.isNotEmpty() || gtfsSources.isNotEmpty()) {
            TransitLineList(
                groups = groups,
                selectedIds = selectedIds,
                onToggleGroup = onToggleGroup,
                onToggleGroups = onToggleGroups,
                gtfsSources = gtfsSources,
                uniqueGtfsRoutes = uniqueGtfsRoutes,
                selectedGtfsRouteKeys = selectedGtfsRouteKeys,
                onToggleGtfsRoute = onToggleGtfsRoute,
                onRemoveGtfsSource = onRemoveGtfsSource,
            )
        }
    }
}

@Composable
private fun TransitActionButtons(
    onShowGtfsDialog: () -> Unit,
    isDiscoveringTransit: Boolean,
    hasDiscoveredTransit: Boolean,
    hasSelectedAreas: Boolean,
    onShowDiscoveryModeDialog: () -> Unit,
) {
    OutlinedButton(
        onClick = onShowGtfsDialog,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Icon(
            Icons.Filled.Add,
            contentDescription = null,
            modifier = Modifier.size(18.dp),
        )
        Spacer(Modifier.width(Spacing.xs))
        Text(stringResource(R.string.gtfs_add_source))
    }

    if (isDiscoveringTransit) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.Center,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
            Spacer(Modifier.width(Spacing.sm))
            Text(stringResource(R.string.discovering_transit_label), style = MaterialTheme.typography.bodyMedium)
        }
    } else if (!hasDiscoveredTransit && hasSelectedAreas) {
        Button(
            onClick = onShowDiscoveryModeDialog,
            modifier = Modifier.fillMaxWidth(),
        ) {
            Icon(
                Icons.Filled.AddLocationAlt,
                contentDescription = null,
                modifier = Modifier.size(18.dp),
            )
            Spacer(Modifier.width(Spacing.xs))
            Text(stringResource(R.string.osm_discovery_button))
        }
    }
}

@Suppress("LongParameterList")
@Composable
private fun TransitLineList(
    groups: List<TransitLineGroup>,
    selectedIds: Set<String>,
    onToggleGroup: (TransitLineGroup) -> Unit,
    onToggleGroups: (List<TransitLineGroup>) -> Unit,
    gtfsSources: List<GtfsSourceState>,
    uniqueGtfsRoutes: List<Pair<GtfsSourceState, GtfsRoute>>,
    selectedGtfsRouteKeys: Set<String>,
    onToggleGtfsRoute: (String, String) -> Unit,
    onRemoveGtfsSource: (String) -> Unit,
) {
    Column(
        modifier = Modifier
            .heightIn(max = 400.dp)
            .verticalScroll(rememberScrollState()),
        verticalArrangement = Arrangement.spacedBy(Spacing.xs),
    ) {
        if (groups.isNotEmpty()) {
            BulkSelectRow(
                label = stringResource(R.string.transit_select_all_shown),
                groups = groups,
                selectedIds = selectedIds,
                onToggle = onToggleGroups,
            )
        }
        groups.groupBy { it.network }.forEach { (network, networkGroups) ->
            BulkSelectRow(
                label = network.ifBlank { stringResource(R.string.transit_network_other) },
                groups = networkGroups,
                selectedIds = selectedIds,
                onToggle = onToggleGroups,
            )
            networkGroups.forEach { group ->
                TransitLineGroupRow(
                    group = group,
                    selected = group.osmIds.any { it in selectedIds },
                    onToggle = { onToggleGroup(group) },
                )
            }
        }

        GtfsSourceSection(
            sources = gtfsSources,
            routes = uniqueGtfsRoutes,
            selectedKeys = selectedGtfsRouteKeys,
            onToggleRoute = onToggleGtfsRoute,
            onRemoveSource = onRemoveGtfsSource,
        )
    }
}

@Composable
private fun DiscoveryErrorText(error: UiText?) {
    if (error == null) return
    Text(
        text = error.resolve(),
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.error,
    )
}

@Composable
private fun BulkSelectRow(
    label: String,
    groups: List<TransitLineGroup>,
    selectedIds: Set<String>,
    onToggle: (List<TransitLineGroup>) -> Unit,
) {
    val selectedCount = groups.count { group -> group.osmIds.any { it in selectedIds } }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable { onToggle(groups) }
            .padding(top = Spacing.sm),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        TriStateCheckbox(
            state = when (selectedCount) {
                0 -> ToggleableState.Off
                groups.size -> ToggleableState.On
                else -> ToggleableState.Indeterminate
            },
            onClick = { onToggle(groups) },
        )
        Spacer(Modifier.width(Spacing.xs))
        Text(
            text = label,
            style = MaterialTheme.typography.titleSmall,
            color = MaterialTheme.colorScheme.primary,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f),
        )
        Text(
            text = stringResource(R.string.transit_selected_count, selectedCount, groups.size),
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun GtfsSourceSection(
    sources: List<GtfsSourceState>,
    routes: List<Pair<GtfsSourceState, GtfsRoute>>,
    selectedKeys: Set<String>,
    onToggleRoute: (String, String) -> Unit,
    onRemoveSource: (String) -> Unit,
) {
    sources.forEach { source ->
        Spacer(Modifier.height(Spacing.sm))
        GtfsSourceHeader(
            source = source,
            onRemove = { onRemoveSource(source.uuid) },
        )
        val sourceRoutes = routes.filter { it.first.uuid == source.uuid }
        sourceRoutes.forEach { (_, route) ->
            GtfsRouteRow(
                route = route,
                selected = "${route.sourceUuid}|${route.routeId}" in selectedKeys,
                onToggle = { onToggleRoute(route.sourceUuid, route.routeId) },
            )
        }
    }
}

@Composable
private fun GtfsSourceHeader(
    source: GtfsSourceState,
    onRemove: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(top = Spacing.sm),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(
            text = stringResource(R.string.gtfs_source_label, source.name),
            style = MaterialTheme.typography.titleSmall,
            color = MaterialTheme.colorScheme.secondary,
        )
        IconButton(
            onClick = onRemove,
            modifier = Modifier.size(24.dp),
        ) {
            Icon(
                Icons.Filled.Close,
                contentDescription = stringResource(R.string.gtfs_remove_source),
                modifier = Modifier.size(16.dp),
            )
        }
    }
}

@Composable
private fun GtfsRouteRow(
    route: GtfsRoute,
    selected: Boolean,
    onToggle: () -> Unit,
) {
    Surface(
        onClick = onToggle,
        shape = MaterialTheme.shapes.small,
        color = if (selected) MaterialTheme.colorScheme.primaryContainer
        else MaterialTheme.colorScheme.surfaceContainerHigh,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            val icon = if (selected) Icons.Filled.CheckBox else Icons.Filled.CheckBoxOutlineBlank
            val iconTint = if (selected) MaterialTheme.colorScheme.onPrimaryContainer
            else MaterialTheme.colorScheme.onSurfaceVariant
            Icon(imageVector = icon, contentDescription = null, modifier = Modifier.size(20.dp), tint = iconTint)
            Spacer(Modifier.width(Spacing.sm))
            if (route.color.isNotBlank()) {
                Box(
                    modifier = Modifier.size(14.dp).background(
                        color = remember(route.color) { parseLineColor(route.color) },
                        shape = MaterialTheme.shapes.extraSmall,
                    ),
                )
                Spacer(Modifier.width(Spacing.sm))
            }
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = route.label,
                    style = MaterialTheme.typography.titleSmall,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                if (route.longName.isNotBlank() && route.longName != route.label) {
                    Text(
                        text = route.longName,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

@Composable
private fun ModeFilterChipRow(
    modes: List<String>,
    selected: Set<String>,
    onToggle: (String) -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState()),
        horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
    ) {
        modes.forEach { mode ->
            val label = routeTypeLabel(mode)
            FilterChip(
                selected = mode in selected,
                onClick = { onToggle(mode) },
                label = { Text(label) },
            )
        }
    }
}

@Composable
private fun TransitLineGroupRow(
    group: TransitLineGroup,
    selected: Boolean,
    onToggle: () -> Unit,
) {
    Surface(
        onClick = onToggle,
        shape = MaterialTheme.shapes.small,
        color = if (selected) MaterialTheme.colorScheme.primaryContainer
        else MaterialTheme.colorScheme.surfaceContainerHigh,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            val icon = if (selected) Icons.Filled.CheckBox else Icons.Filled.CheckBoxOutlineBlank
            val iconTint = if (selected) MaterialTheme.colorScheme.onPrimaryContainer
                else MaterialTheme.colorScheme.onSurfaceVariant
            Icon(imageVector = icon, contentDescription = null, modifier = Modifier.size(20.dp), tint = iconTint)
            Spacer(Modifier.width(Spacing.sm))
            if (group.colour.isNotBlank()) {
                Box(
                    modifier = Modifier.size(14.dp).background(
                        color = remember(group.colour) { parseLineColor(group.colour) },
                        shape = MaterialTheme.shapes.extraSmall,
                    ),
                )
                Spacer(Modifier.width(Spacing.sm))
            }
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = group.primary,
                    style = MaterialTheme.typography.titleSmall,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                if (group.secondary.isNotBlank() && group.secondary != group.primary) {
                    Text(
                        text = group.secondary,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

private val NAMED_COLORS = mapOf(
    "red" to "#FF0000",
    "blue" to "#0000FF",
    "green" to "#008000",
    "yellow" to "#FFFF00",
    "orange" to "#FFA500",
    "purple" to "#800080",
    "pink" to "#FFC0CB",
    "brown" to "#A52A2A",
    "black" to "#000000",
    "white" to "#FFFFFF",
    "gray" to "#808080",
    "grey" to "#808080",
    "cyan" to "#00FFFF",
    "magenta" to "#FF00FF",
    "maroon" to "#800000",
    "navy" to "#000080",
    "olive" to "#808000",
    "teal" to "#008080",
    "lime" to "#00FF00",
    "aqua" to "#00FFFF",
    "silver" to "#C0C0C0",
    "gold" to "#FFD700",
    "violet" to "#EE82EE",
    "indigo" to "#4B0082",
    "coral" to "#FF7F50",
    "turquoise" to "#40E0D0",
    "crimson" to "#DC143C",
    "darkblue" to "#00008B",
    "darkgreen" to "#006400",
    "darkred" to "#8B0000",
    "lightblue" to "#ADD8E6",
    "lightgreen" to "#90EE90",
    "lightgray" to "#D3D3D3",
    "lightgrey" to "#D3D3D3",
    "darkgray" to "#A9A9A9",
    "darkgrey" to "#A9A9A9",
    "beige" to "#F5F5DC",
    "ivory" to "#FFFFF0",
    "lavender" to "#E6E6FA",
    "salmon" to "#FA8072",
    "tan" to "#D2B48C",
    "plum" to "#DDA0DD",
    "khaki" to "#F0E68C",
    "orchid" to "#DA70D6",
    "azure" to "#F0FFFF",
    "wheat" to "#F5DEB3",
    "chocolate" to "#D2691E",
    "tomato" to "#FF6347",
)

private val HEX6_RE = Regex("^[0-9a-fA-F]{6}$")

internal fun parseLineColor(hex: String): Color {
    if (hex.isBlank()) {
        return Color(FALLBACK_LINE_COLOR)
    }
    // GTFS colours are 6-char hex without #; android.graphics.Color.parseColor requires it
    val sanitized = if (HEX6_RE.matches(hex.trim())) "#${hex.trim()}" else hex
    return try {
        Color(android.graphics.Color.parseColor(sanitized))
    } catch (_: IllegalArgumentException) {
        val named = NAMED_COLORS[hex.trim().lowercase()]
        if (named != null) {
            Color(android.graphics.Color.parseColor(named))
        } else {
            Color(FALLBACK_LINE_COLOR)
        }
    }
}

@Composable
internal fun routeTypeLabel(routeType: String): String = when (routeType) {
    "subway" -> stringResource(R.string.transit_route_metro)
    "light_rail" -> stringResource(R.string.transit_route_light_rail)
    "tram" -> stringResource(R.string.transit_route_tram)
    "train" -> stringResource(R.string.transit_route_train)
    "trolleybus" -> stringResource(R.string.transit_route_trolleybus)
    "bus" -> stringResource(R.string.transit_route_bus)
    "monorail" -> stringResource(R.string.transit_route_monorail)
    "funicular" -> stringResource(R.string.transit_route_funicular)
    else -> "${stringResource(R.string.transit_route_unknown)} ($routeType)"
}
