@file:Suppress("TooManyFunctions")

package fr.gshz.hideandseek.feature.map

import androidx.annotation.StringRes
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.TransitDeparture
import fr.gshz.hideandseek.domain.model.TransitDepartureGroup
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import kotlin.math.abs

private const val NEXT_MAX_HEIGHT_FRACTION = 0.5f
private const val FULL_DAY_MAX_HEIGHT_FRACTION = 0.9f
private const val SECONDS_PER_MINUTE = 60
private const val PAST_ALPHA = 0.5f

private val stationTimeFormatter: DateTimeFormatter = DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT)

@Composable
internal fun TimetableSheet(
    state: TimetableUiState,
    onRetry: () -> Unit,
    onClose: () -> Unit,
    onToggleFullDay: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val fraction = if (state.showFullDay) FULL_DAY_MAX_HEIGHT_FRACTION else NEXT_MAX_HEIGHT_FRACTION
    val maxSheetHeight = (LocalConfiguration.current.screenHeightDp * fraction).dp
    Surface(
        modifier = modifier.heightIn(max = maxSheetHeight),
        shape = MaterialTheme.shapes.large,
        tonalElevation = 6.dp,
        shadowElevation = 4.dp,
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .then(if (state.showFullDay) Modifier.fillMaxHeight() else Modifier)
                .padding(horizontal = Spacing.md, vertical = Spacing.sm),
        ) {
            TimetableHeader(state = state, onToggleFullDay = onToggleFullDay, onClose = onClose)
            val bodyModifier = if (state.showFullDay) {
                Modifier.weight(1f)
            } else {
                Modifier.weight(1f, fill = false)
            }
            when {
                state.isLoading && state.groups.isEmpty() -> TimetableLoading()
                state.error == TimetableError.Unavailable -> TimetableMessage(R.string.timetable_unavailable)
                state.groups.isEmpty() && state.error == TimetableError.Network -> TimetableNetworkError(onRetry)
                state.groups.isEmpty() -> TimetableMessage(R.string.timetable_empty)
                state.showFullDay -> TimetableDayBody(state = state, onRetry = onRetry, modifier = bodyModifier)
                else -> TimetableNextBody(state = state, onRetry = onRetry, modifier = bodyModifier)
            }
        }
    }
}

@Composable
private fun TimetableHeader(
    state: TimetableUiState,
    onToggleFullDay: () -> Unit,
    onClose: () -> Unit,
) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = state.stationName.ifBlank { stringResource(R.string.timetable_title) },
                style = MaterialTheme.typography.titleMedium,
            )
            Text(
                text = stringResource(
                    if (state.showFullDay) R.string.timetable_full_day else R.string.timetable_subtitle,
                ),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        TextButton(onClick = onToggleFullDay) {
            Text(
                stringResource(
                    if (state.showFullDay) R.string.timetable_action else R.string.timetable_full_day,
                ),
            )
        }
        IconButton(onClick = onClose) {
            Icon(Icons.Filled.Close, contentDescription = stringResource(R.string.nav_back))
        }
    }
}

@Composable
private fun TimetableNextBody(state: TimetableUiState, onRetry: () -> Unit, modifier: Modifier = Modifier) {
    Column(
        modifier = modifier.verticalScroll(rememberScrollState()),
        verticalArrangement = Arrangement.spacedBy(Spacing.md),
    ) {
        if (state.error == TimetableError.Network) TimetableNetworkError(onRetry)
        state.groups.forEach { group ->
            TimetableGroup(group = group, timezone = state.timezone)
        }
    }
}

@Composable
private fun TimetableDayBody(state: TimetableUiState, onRetry: () -> Unit, modifier: Modifier = Modifier) {
    val dayItems = remember(state.groups, state.serverNow) {
        buildDayItems(state.groups, state.serverNow)
    }
    val listState = rememberLazyListState()
    var didScroll by remember(state.stationId, state.showFullDay) { mutableStateOf(false) }
    LaunchedEffect(dayItems) {
        if (!didScroll) {
            val nowIndex = dayItems.indexOfFirst { it is DayItem.Now }
            if (nowIndex >= 0) {
                listState.scrollToItem(nowIndex)
                didScroll = true
            }
        }
    }
    LazyColumn(
        state = listState,
        modifier = modifier,
        verticalArrangement = Arrangement.spacedBy(Spacing.xs),
        contentPadding = PaddingValues(vertical = Spacing.sm),
    ) {
        if (state.error == TimetableError.Network) {
            item(key = "network-error") { TimetableNetworkError(onRetry) }
        }
        items(dayItems, key = { it.key }) { item ->
            when (item) {
                is DayItem.Now -> NowDivider()
                is DayItem.Departure -> DayDepartureRow(
                    group = item.group,
                    departure = item.departure,
                    timezone = state.timezone,
                    isPast = item.isPast,
                )
            }
        }
    }
}

private sealed interface DayItem {
    val key: String

    data class Now(override val key: String) : DayItem

    data class Departure(
        val group: TransitDepartureGroup,
        val departure: TransitDeparture,
        val isPast: Boolean,
        override val key: String,
    ) : DayItem
}

private fun buildDayItems(groups: List<TransitDepartureGroup>, serverNow: Long): List<DayItem> {
    val items = mutableListOf<DayItem>()
    var nowInserted = false
    groups.flatMap { group -> group.departures.map { departure -> group to departure } }
        .sortedBy { (_, departure) -> departure.at }
        .forEachIndexed { index, (group, departure) ->
            if (!nowInserted && departure.at >= serverNow) {
                items += DayItem.Now("now")
                nowInserted = true
            }
            items += DayItem.Departure(
                group = group,
                departure = departure,
                isPast = departure.at < serverNow,
                key = "departure-${group.routeId}-${group.directionId}-${departure.at}-$index",
            )
        }
    if (!nowInserted) {
        items += DayItem.Now("now-end")
    }

    return items
}

@Composable
private fun TimetableLoading() {
    Box(modifier = Modifier.fillMaxWidth().padding(Spacing.md), contentAlignment = Alignment.Center) {
        CircularProgressIndicator()
    }
}

@Composable
private fun TimetableMessage(@StringRes text: Int) {
    Text(
        text = stringResource(text),
        modifier = Modifier.padding(vertical = Spacing.sm),
        style = MaterialTheme.typography.bodyMedium,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
}

@Composable
private fun TimetableNetworkError(onRetry: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(
            text = stringResource(R.string.timetable_error),
            modifier = Modifier.weight(1f),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.error,
        )
        TextButton(onClick = onRetry) {
            Text(stringResource(R.string.timetable_retry))
        }
    }
}

@Composable
private fun TimetableGroup(group: TransitDepartureGroup, timezone: String) {
    val multipleHeadsigns = group.departures
        .map { it.headsign }
        .filter { it.isNotBlank() }
        .distinct()
        .size > 1
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        TimetableGroupHeader(group)
        group.departures.forEach { departure ->
            DepartureRow(
                departure = departure,
                timezone = timezone,
                groupHeadsign = if (multipleHeadsigns) "" else group.headsign,
            )
        }
    }
}

@Composable
private fun TimetableGroupHeader(group: TransitDepartureGroup) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        LineBadge(ref = group.lineRef, color = group.colour)
        Text(
            text = groupTitle(group),
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = FontWeight.SemiBold,
        )
    }
}

@Composable
private fun groupTitle(group: TransitDepartureGroup): String {
    val headsigns = buildList {
        if (group.headsign.isNotBlank()) add(group.headsign)
        group.departures.forEach { departure ->
            if (departure.headsign.isNotBlank() && departure.headsign !in this) {
                add(departure.headsign)
            }
        }
    }
    return when {
        headsigns.isNotEmpty() -> headsigns.joinToString(" / ")
        group.lineName.isNotBlank() -> group.lineName
        else -> stringResource(R.string.timetable_direction, group.directionId)
    }
}

@Composable
private fun NowDivider() {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        HorizontalDivider(modifier = Modifier.weight(1f))
        Text(
            text = stringResource(R.string.timetable_now),
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.primary,
        )
        HorizontalDivider(modifier = Modifier.weight(1f))
    }
}

@Composable
private fun DayDepartureRow(
    group: TransitDepartureGroup,
    departure: TransitDeparture,
    timezone: String,
    isPast: Boolean,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .alpha(if (isPast) PAST_ALPHA else 1f),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        Text(
            text = formatDepartureTime(departure.at, timezone),
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = FontWeight.Medium,
        )
        LineBadge(ref = group.lineRef, color = group.colour)
        Text(
            text = departure.headsign.ifBlank { group.headsign },
            modifier = Modifier.weight(1f),
            style = MaterialTheme.typography.bodySmall,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        if (!isPast) {
            if (departure.realtime) LiveDot()
            Text(
                text = relativeLabel(departure.inSeconds),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (departure.delaySeconds != 0) {
                Text(
                    text = delayLabel(departure.delaySeconds),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

@Composable
private fun DepartureRow(
    departure: TransitDeparture,
    timezone: String,
    isPast: Boolean = false,
    groupHeadsign: String = "",
) {
    val showHeadsign = departure.headsign.isNotBlank() && departure.headsign != groupHeadsign
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .alpha(if (isPast) PAST_ALPHA else 1f),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        Text(
            text = formatDepartureTime(departure.at, timezone),
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = FontWeight.Medium,
        )
        Text(
            text = if (showHeadsign) departure.headsign else "",
            modifier = Modifier.weight(1f),
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        if (!isPast) {
            if (departure.realtime) LiveDot()
            Text(
                text = relativeLabel(departure.inSeconds),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (departure.delaySeconds != 0) {
                Text(
                    text = delayLabel(departure.delaySeconds),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

@Composable
private fun LiveDot() {
    val label = stringResource(R.string.timetable_live)
    Box(
        modifier = Modifier
            .size(6.dp)
            .semantics { contentDescription = label }
            .background(MaterialTheme.colorScheme.primary, CircleShape),
    )
}

@Composable
private fun relativeLabel(inSeconds: Int): String =
    if (inSeconds < SECONDS_PER_MINUTE) {
        stringResource(R.string.timetable_due)
    } else {
        stringResource(R.string.timetable_in_minutes, inSeconds / SECONDS_PER_MINUTE)
    }

@Composable
private fun delayLabel(delaySeconds: Int): String {
    val minutes = ((abs(delaySeconds) + SECONDS_PER_MINUTE - 1) / SECONDS_PER_MINUTE).coerceAtLeast(1)
    return if (delaySeconds > 0) {
        stringResource(R.string.timetable_delay_late, minutes)
    } else {
        stringResource(R.string.timetable_delay_early, minutes)
    }
}

private fun formatDepartureTime(epochSeconds: Long, timezone: String): String {
    val zone = runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.systemDefault())
    return Instant.ofEpochSecond(epochSeconds).atZone(zone).format(stationTimeFormatter)
}

@Preview(showBackground = true)
@Composable
private fun TimetableSheetPreview() {
    MaterialTheme {
        TimetableSheet(
            state = TimetableUiState(
                isVisible = true,
                stationId = "S_49.0944_6.1354",
                stationName = "ALGER",
                timezone = "Europe/Paris",
                groups = listOf(sampleGroup()),
            ),
            onRetry = {},
            onClose = {},
            onToggleFullDay = {},
        )
    }
}

@Preview(showBackground = true)
@Composable
private fun TimetableFullDayPreview() {
    MaterialTheme {
        TimetableSheet(
            state = TimetableUiState(
                isVisible = true,
                stationId = "S_49.0944_6.1354",
                stationName = "ALGER",
                timezone = "Europe/Paris",
                serverNow = 1_760_000_000L,
                showFullDay = true,
                groups = listOf(sampleGroup()),
            ),
            onRetry = {},
            onClose = {},
            onToggleFullDay = {},
        )
    }
}

@Suppress("MagicNumber")
private fun sampleGroup() = TransitDepartureGroup(
    routeId = "1",
    lineRef = "L1",
    lineName = "MOULINS TOURNEBRIDE - LA CORCHADE",
    colour = "B22E75",
    headsign = "La Corchade",
    directionId = 0,
    realtime = true,
    departures = listOf(
        TransitDeparture(1_759_999_400, 0, false, 0, "La Corchade"),
        TransitDeparture(1_760_000_300, 300, true, 60, "La Corchade"),
        TransitDeparture(1_760_000_500, 500, false, 0, "La Corchade"),
    ),
)
