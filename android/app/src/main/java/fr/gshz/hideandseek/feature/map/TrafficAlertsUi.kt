@file:Suppress("TooManyFunctions")
package fr.gshz.hideandseek.feature.map

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Tram
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.TrafficAlert
import fr.gshz.hideandseek.domain.model.TrafficAlertSeverity
import fr.gshz.hideandseek.domain.model.TransitLine
import java.text.Normalizer
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

private val ACCENT_PATTERN = "\\p{M}+".toRegex()

/** Case- and accent-insensitive line matching, mirroring how the server matches SIRI line refs. */
internal fun normalizeLineRef(value: String): String =
    Normalizer.normalize(value.trim(), Normalizer.Form.NFD)
        .replace(ACCENT_PATTERN, "")
        .lowercase(Locale.ROOT)

internal fun List<TrafficAlert>.alertsForLine(lineRef: String): List<TrafficAlert> {
    val wanted = normalizeLineRef(lineRef)
    return filter { alert -> alert.lineRefs.any { normalizeLineRef(it) == wanted } }
}

/**
 * The overlay refs an alert highlights: the game's selected lines whose ref matches one of the
 * alert's line refs or route ids. An alert naming no line is network-wide and highlights nothing.
 */
internal fun computeAlertHighlightRefs(
    selectedLines: List<TransitLine>,
    alerts: List<TrafficAlert>,
    selectedAlertId: String?,
): Set<String> {
    val alert = alerts.firstOrNull { it.id == selectedAlertId }
    val wanted = alert
        ?.let { (it.lineRefs + it.routeIds).mapTo(HashSet()) { ref -> normalizeLineRef(ref) } }
        .orEmpty()
    return if (wanted.isEmpty()) {
        emptySet()
    } else {
        selectedLines.mapTo(HashSet()) { it.ref }.filterTo(HashSet()) { normalizeLineRef(it) in wanted }
    }
}

private val INFO_COLOR = Color(0xFF1E88E5)
private val WARNING_COLOR = Color(0xFFFB8C00)
private val SEVERE_COLOR = Color(0xFFE53935)
private const val UNKNOWN_LINE_COLOR = "757575"

private const val SHEET_MAX_HEIGHT_FRACTION = 0.6f
private const val SEVERITY_DOT_SIZE_DP = 10f

@Composable
internal fun severityColor(severity: TrafficAlertSeverity): Color = when (severity) {
    TrafficAlertSeverity.Info -> INFO_COLOR
    TrafficAlertSeverity.Warning -> WARNING_COLOR
    TrafficAlertSeverity.Severe -> SEVERE_COLOR
}

@Composable
private fun severityLabel(severity: TrafficAlertSeverity): String = stringResource(
    when (severity) {
        TrafficAlertSeverity.Info -> R.string.traffic_alert_severity_info
        TrafficAlertSeverity.Warning -> R.string.traffic_alert_severity_warning
        TrafficAlertSeverity.Severe -> R.string.traffic_alert_severity_severe
    },
)

private val validityFormatter: DateTimeFormatter = DateTimeFormatter.ofLocalizedDateTime(FormatStyle.SHORT)

private fun formatEpochSeconds(epochSeconds: Long): String =
    Instant.ofEpochSecond(epochSeconds).atZone(ZoneId.systemDefault()).format(validityFormatter)

@Composable
internal fun alertValidityLabel(alert: TrafficAlert): String {
    val from = alert.validFrom?.let { formatEpochSeconds(it) }
    val until = alert.validUntil?.let { formatEpochSeconds(it) }
    return when {
        from != null && until != null -> stringResource(R.string.traffic_alert_validity_from_until, from, until)
        from != null -> stringResource(R.string.traffic_alert_validity_from, from)
        until != null -> stringResource(R.string.traffic_alert_validity_until, until)
        else -> stringResource(R.string.traffic_alert_validity_indefinite)
    }
}

/**
 * The FAB is always visible; the badge only appears once an alert is active, so a quiet network
 * does not draw attention to itself.
 */
@Composable
internal fun TrafficAlertFab(
    count: Int,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val badgeDescription = pluralStringResource(R.plurals.traffic_alert_count, count, count)
    FloatingActionButton(
        onClick = onClick,
        modifier = modifier.semantics { contentDescription = badgeDescription },
    ) {
        BadgedBox(badge = { if (count > 0) Badge { Text(count.toString()) } }) {
            Icon(Icons.Filled.Tram, contentDescription = null)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun TrafficAlertsSheet(
    state: TrafficAlertsState,
    lineFilter: String? = null,
    selectedAlertId: String? = null,
    lineColors: Map<String, String> = emptyMap(),
    onSelectAlert: (String) -> Unit,
    onDismissRequest: () -> Unit,
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    val visible = if (lineFilter == null) state.alerts else state.alerts.alertsForLine(lineFilter)
    val colorsByRef = remember(lineColors) {
        lineColors.mapKeys { (ref, _) -> normalizeLineRef(ref) }
    }
    ModalBottomSheet(
        onDismissRequest = onDismissRequest,
        sheetState = sheetState,
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = Spacing.md),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            Text(
                text = if (lineFilter == null) {
                    stringResource(R.string.traffic_alerts_title)
                } else {
                    stringResource(R.string.traffic_alerts_filtered_title, lineFilter)
                },
                style = MaterialTheme.typography.titleMedium,
            )
            if (visible.isEmpty()) {
                Text(
                    text = stringResource(R.string.traffic_alerts_empty),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            } else {
                AlertsList(
                    alerts = visible,
                    selectedAlertId = selectedAlertId,
                    colorsByRef = colorsByRef,
                    onSelectAlert = onSelectAlert,
                )
            }
        }
    }
}

@Composable
private fun AlertsList(
    alerts: List<TrafficAlert>,
    selectedAlertId: String?,
    colorsByRef: Map<String, String>,
    onSelectAlert: (String) -> Unit,
) {
    LazyColumn(
        modifier = Modifier.heightIn(
            max = (LocalConfiguration.current.screenHeightDp * SHEET_MAX_HEIGHT_FRACTION).dp,
        ),
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        items(alerts, key = { it.id }) { alert ->
            TrafficAlertRow(
                alert = alert,
                selected = alert.id == selectedAlertId,
                colorsByRef = colorsByRef,
                onClick = { onSelectAlert(alert.id) },
            )
        }
    }
}

@Composable
private fun TrafficAlertRow(
    alert: TrafficAlert,
    selected: Boolean,
    colorsByRef: Map<String, String>,
    onClick: () -> Unit,
) {
    Surface(
        onClick = onClick,
        shape = MaterialTheme.shapes.small,
        color = if (selected) {
            MaterialTheme.colorScheme.primaryContainer
        } else {
            MaterialTheme.colorScheme.surfaceContainerHigh
        },
        contentColor = if (selected) {
            MaterialTheme.colorScheme.onPrimaryContainer
        } else {
            MaterialTheme.colorScheme.onSurface
        },
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = Spacing.sm, vertical = Spacing.sm),
            verticalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
            ) {
                SeverityDot(alert.severity)
                Text(
                    text = alert.title,
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f),
                )
            }
            AlertLineChips(alert = alert, colorsByRef = colorsByRef)
            Text(
                text = alertValidityLabel(alert),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (alert.body.isNotBlank()) {
                Text(text = alert.body, style = MaterialTheme.typography.bodyMedium)
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun AlertLineChips(alert: TrafficAlert, colorsByRef: Map<String, String>) {
    if (alert.lineRefs.isEmpty()) return
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(6.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        alert.lineRefs.forEach { ref ->
            LineBadge(
                ref = ref,
                color = colorsByRef[normalizeLineRef(ref)] ?: UNKNOWN_LINE_COLOR,
            )
        }
    }
}

@Composable
private fun SeverityDot(severity: TrafficAlertSeverity) {
    val label = severityLabel(severity)
    Box(
        modifier = Modifier
            .size(SEVERITY_DOT_SIZE_DP.dp)
            .semantics { contentDescription = label }
            .background(severityColor(severity), CircleShape),
    )
}

@Preview(showBackground = true)
@Composable
private fun TrafficAlertFabPreview() {
    MaterialTheme {
        TrafficAlertFab(count = 3, onClick = {})
    }
}
