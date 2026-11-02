package fr.gshz.hideandseek.feature.map

import androidx.annotation.StringRes
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material.icons.outlined.Layers
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.compositeOver
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.Shadow
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.ManualConstraint
import fr.gshz.hideandseek.domain.model.SeekerMarker
import fr.gshz.hideandseek.domain.model.Side

private val DASH_ON = 3.dp
private val DASH_OFF = 2.dp
private const val PLAYERS_SELF_X = 0.36f
private const val PLAYERS_OTHER_X = 0.68f
private const val PLAYERS_DOT_DIVISOR = 4.5f
private val PILL_ICON_BACKING = Color.Black.copy(alpha = 0.35f)
private val PILL_TEXT_COLOR = Color.White
private val PILL_TEXT_SHADOW = Shadow(color = Color.Black, offset = Offset(1f, 1f), blurRadius = 2f)

internal enum class LegendSwatch {
    PossibleArea,
    Excluded,
    HidingZoneBand,
    SearchArea,
    SuspectedStation,
    HidingZone,
    Players,
}

internal data class LegendEntry(
    @StringRes val label: Int,
    val swatch: LegendSwatch,
    @StringRes val detail: Int? = null,
)

internal fun legendEntries(state: MapUiState): List<LegendEntry> = buildList {
    if (state.possibleAreaGeoJson != null) {
        add(LegendEntry(R.string.map_legend_possible_area, LegendSwatch.PossibleArea))
    }
    if (state.stationBandGeoJson != null) {
        add(
            LegendEntry(
                label = R.string.map_legend_edge_zone,
                swatch = LegendSwatch.HidingZoneBand,
                detail = R.string.map_legend_edge_zone_detail,
            ),
        )
    }
    add(LegendEntry(R.string.map_legend_excluded, LegendSwatch.Excluded))
    if (state.manualConstraints.isNotEmpty()) {
        add(LegendEntry(R.string.map_legend_search_area, LegendSwatch.SearchArea))
    }
    if (state.seekerMarkers.isNotEmpty()) {
        add(LegendEntry(R.string.map_legend_suspected_station, LegendSwatch.SuspectedStation))
    }
    if (state.submittedZone != null) {
        add(LegendEntry(R.string.map_legend_hiding_zone, LegendSwatch.HidingZone))
    }
    add(LegendEntry(R.string.map_legend_players, LegendSwatch.Players))
}

@Composable
internal fun MapLegend(
    entries: List<LegendEntry>,
    modifier: Modifier = Modifier,
    darkBase: Boolean = false,
    initiallyExpanded: Boolean = false,
) {
    var expanded by rememberSaveable { mutableStateOf(initiallyExpanded) }
    Column(modifier = modifier, verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        if (expanded) {
            LegendCard(entries, darkBase)
        }
        Row(
            modifier = Modifier
                .heightIn(min = 32.dp)
                .clickable(role = Role.Button, onClick = { expanded = !expanded })
                .padding(start = Spacing.xs, end = Spacing.sm, top = Spacing.xs, bottom = Spacing.xs),
            horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                modifier = Modifier
                    .size(16.dp)
                    .background(PILL_ICON_BACKING, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    imageVector = Icons.Outlined.Layers,
                    contentDescription = null,
                    modifier = Modifier.size(10.dp),
                    tint = Color.White,
                )
            }
            Text(
                text = stringResource(R.string.map_legend_title),
                style = MaterialTheme.typography.labelSmall.copy(
                    color = PILL_TEXT_COLOR,
                    shadow = PILL_TEXT_SHADOW,
                ),
            )
        }
    }
}

@Composable
private fun LegendCard(entries: List<LegendEntry>, darkBase: Boolean) {
    Surface(
        modifier = Modifier.widthIn(max = 240.dp).padding(start = Spacing.sm),
        shape = MaterialTheme.shapes.medium,
        tonalElevation = 3.dp,
        shadowElevation = 3.dp,
    ) {
        Column(
            modifier = Modifier
                .heightIn(max = 280.dp)
                .verticalScroll(rememberScrollState())
                .padding(Spacing.md),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            entries.forEach { entry ->
                LegendRow(entry, darkBase)
            }
        }
    }
}

@Composable
private fun LegendRow(entry: LegendEntry, darkBase: Boolean) {
    var showInfo by rememberSaveable { mutableStateOf(false) }
    Row(
        modifier = Modifier.heightIn(min = 24.dp),
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        LegendSwatch(entry.swatch, darkBase)
        Text(
            text = stringResource(entry.label),
            modifier = Modifier.weight(1f),
            style = MaterialTheme.typography.bodySmall,
        )
        if (entry.detail != null) {
            InfoButton(onClick = { showInfo = true })
        }
    }
    if (showInfo) {
        entry.detail?.let { detail ->
            AlertDialog(
                onDismissRequest = { showInfo = false },
                title = { Text(stringResource(entry.label)) },
                text = { Text(stringResource(detail)) },
                confirmButton = {
                    TextButton(onClick = { showInfo = false }) {
                        Text(stringResource(R.string.map_legend_info_close))
                    }
                },
            )
        }
    }
}

@Composable
private fun InfoButton(onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .size(24.dp)
            .clickable(role = Role.Button, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            imageVector = Icons.Outlined.Info,
            contentDescription = stringResource(R.string.map_legend_edge_zone_info),
            modifier = Modifier.size(16.dp),
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun LegendSwatch(swatch: LegendSwatch, darkBase: Boolean) {
    Canvas(modifier = Modifier.size(18.dp)) {
        val stroke = 1.5.dp.toPx()
        val corner = CornerRadius(3.dp.toPx())
        when (swatch) {
            LegendSwatch.PossibleArea -> drawRoundRect(
                color = Color(MapOverlayStyle.possibleArea),
                cornerRadius = corner,
                style = Stroke(width = MapOverlayStyle.POSSIBLE_AREA_WIDTH.dp.toPx()),
            )
            LegendSwatch.Excluded -> drawRoundRect(
                color = overlaySwatch(MapOverlayStyle.exclusion, MapOverlayStyle.EXCLUSION_OPACITY, darkBase),
                cornerRadius = corner,
            )
            LegendSwatch.HidingZoneBand -> drawRoundRect(
                color = overlaySwatch(MapOverlayStyle.band, MapOverlayStyle.BAND_OPACITY, darkBase),
                cornerRadius = corner,
            )
            LegendSwatch.SearchArea -> drawRoundRect(
                color = Color(MapOverlayStyle.manualConstraint),
                cornerRadius = corner,
                style = Stroke(width = stroke, pathEffect = dashed()),
            )
            LegendSwatch.SuspectedStation -> drawCircle(
                color = Color(MapOverlayStyle.hidingZone),
                radius = size.minDimension / 2f - stroke,
                style = Stroke(width = stroke, pathEffect = dashed()),
            )
            LegendSwatch.HidingZone -> {
                drawCircle(
                    color = Color(MapOverlayStyle.hidingZone).copy(alpha = 0.2f),
                    radius = size.minDimension / 2f,
                )
                drawCircle(
                    color = Color(MapOverlayStyle.hidingZone),
                    radius = size.minDimension / 2f - stroke,
                    style = Stroke(width = stroke),
                )
            }
            LegendSwatch.Players -> drawPlayersSwatch()
        }
    }
}

private fun DrawScope.drawPlayersSwatch() {
    val dot = size.minDimension / PLAYERS_DOT_DIVISOR
    drawCircle(
        color = Color(MapOverlayStyle.playerSelf),
        radius = dot,
        center = Offset(size.width * PLAYERS_SELF_X, size.height / 2f),
    )
    drawCircle(
        color = Color(MapOverlayStyle.playerOther),
        radius = dot,
        center = Offset(size.width * PLAYERS_OTHER_X, size.height / 2f),
    )
}

private fun DrawScope.dashed(): PathEffect =
    PathEffect.dashPathEffect(floatArrayOf(DASH_ON.toPx(), DASH_OFF.toPx()))

private val MAP_PLATE_LIGHT = Color(0xFFEDE9E1)
private val MAP_PLATE_DARK = Color(0xFF31363E)

/** Translucent overlays are composited over a map plate so the swatch matches the base map. */
private fun overlaySwatch(argb: Int, alpha: Float, darkBase: Boolean): Color =
    Color(argb).copy(alpha = alpha).compositeOver(if (darkBase) MAP_PLATE_DARK else MAP_PLATE_LIGHT)

@Preview(showBackground = true)
@Composable
private fun MapLegendExpandedPreview() {
    val state = MapUiState(
        side = Side.Seeker,
        possibleAreaGeoJson = """{"type":"Polygon","coordinates":[]}""",
        stationBandGeoJson = """{"type":"Polygon","coordinates":[]}""",
        manualConstraints = listOf(
            ManualConstraint("constraint-1", ConstraintMode.Include, "{}", "search-area", createdByName = null),
        ),
        seekerMarkers = listOf(SeekerMarker("marker-1", null, 46.52, 6.63)),
    )
    MaterialTheme {
        Box(modifier = Modifier.fillMaxSize().padding(Spacing.md)) {
            MapLegend(
                entries = legendEntries(state),
                modifier = Modifier.align(Alignment.BottomStart),
                initiallyExpanded = true,
            )
        }
    }
}
