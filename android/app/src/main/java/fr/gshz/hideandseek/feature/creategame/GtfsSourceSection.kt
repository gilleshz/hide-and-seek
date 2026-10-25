package fr.gshz.hideandseek.feature.creategame

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckBox
import androidx.compose.material.icons.filled.CheckBoxOutlineBlank
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TriStateCheckbox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.state.ToggleableState
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.GtfsRoute
import fr.gshz.hideandseek.domain.model.GtfsSourceState

@Suppress("LongParameterList")
@Composable
internal fun GtfsSourceSection(
    sources: List<GtfsSourceState>,
    routes: List<Pair<GtfsSourceState, GtfsRoute>>,
    selectedKeys: Set<String>,
    onToggleRoute: (String, String) -> Unit,
    onToggleAllRoutes: (String) -> Unit,
    onRemoveSource: (String) -> Unit,
) {
    sources.forEach { source ->
        Spacer(Modifier.height(Spacing.sm))
        val sourceRoutes = routes.filter { it.first.uuid == source.uuid }
        GtfsSourceHeader(
            source = source,
            selectedCount = sourceRoutes.count { (_, route) ->
                "${source.uuid}|${route.routeId}" in selectedKeys
            },
            totalCount = sourceRoutes.size,
            onRemove = { onRemoveSource(source.uuid) },
        )
        GtfsSourceMeta(source)
        if (sourceRoutes.isNotEmpty()) {
            GtfsBulkSelectRow(
                source = source,
                routes = sourceRoutes,
                selectedKeys = selectedKeys,
                onToggle = { onToggleAllRoutes(source.uuid) },
            )
        }
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
private fun GtfsSourceMeta(source: GtfsSourceState) {
    if (!source.requiresSubset) return
    Text(
        text = stringResource(R.string.gtfs_large_feed_note),
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
    val compressed = source.sizeBytes ?: return
    val uncompressed = source.uncompressedBytes
    val label = if (uncompressed != null) {
        stringResource(R.string.gtfs_large_feed_sizes, compressed / 1_000_000, uncompressed / 1_000_000)
    } else {
        stringResource(R.string.gtfs_catalog_size, compressed / 1_000_000)
    }
    Text(
        text = label,
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
}

@Composable
private fun GtfsBulkSelectRow(
    source: GtfsSourceState,
    routes: List<Pair<GtfsSourceState, GtfsRoute>>,
    selectedKeys: Set<String>,
    onToggle: () -> Unit,
) {
    val selectedCount = routes.count { (_, route) ->
        "${source.uuid}|${route.routeId}" in selectedKeys
    }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onToggle),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        TriStateCheckbox(
            state = when (selectedCount) {
                0 -> ToggleableState.Off
                routes.size -> ToggleableState.On
                else -> ToggleableState.Indeterminate
            },
            onClick = onToggle,
        )
        Spacer(Modifier.width(Spacing.xs))
        Text(
            text = stringResource(R.string.transit_select_all_shown),
            style = MaterialTheme.typography.titleSmall,
            color = MaterialTheme.colorScheme.primary,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
private fun GtfsSourceHeader(
    source: GtfsSourceState,
    selectedCount: Int,
    totalCount: Int,
    onRemove: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(top = Spacing.sm),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
    ) {
        Text(
            text = source.name,
            style = MaterialTheme.typography.titleSmall,
            color = MaterialTheme.colorScheme.secondary,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f),
        )
        SourceChip(stringResource(R.string.transit_source_chip_gtfs))
        if (source.hasRealtime) LiveChip()
        Text(
            text = stringResource(R.string.transit_selected_count, selectedCount, totalCount),
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
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
