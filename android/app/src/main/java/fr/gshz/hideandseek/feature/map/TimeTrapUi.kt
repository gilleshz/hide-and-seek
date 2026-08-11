package fr.gshz.hideandseek.feature.map

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.DialogProperties
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.formatDurationWords
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.TimeTrap

private const val TRAP_CHIP_ALPHA = 0.88f

/**
 * Station first, photo on confirm: the reverse of a zone card, because the trap must be
 * pinned to a station before the server can tell the hider which one it snapped to.
 */
@Composable
internal fun TimeTrapPlacementPanel(
    uiState: MapUiState,
    onConfirm: (String) -> Unit,
    onCancel: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val showOwnZoneWarning = rememberSaveable { mutableStateOf(false) }
    val pickPhoto = rememberCardPhotoPicker(onPicked = onConfirm, onCancelled = {})

    Surface(modifier = modifier, shape = MaterialTheme.shapes.large, tonalElevation = 4.dp) {
        Column(
            modifier = Modifier.padding(horizontal = Spacing.lg, vertical = Spacing.md),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            ErrorText(
                uiState.timeTrapError,
                errorKey = uiState.timeTrapErrorKey,
                errorArgs = uiState.timeTrapErrorArgs,
            )
            Text(
                text = stringResource(R.string.time_trap_placement_instructions),
                style = MaterialTheme.typography.bodySmall,
            )
            uiState.pendingTrapStationName?.let { station ->
                Text(
                    text = stringResource(R.string.zone_placement_station, station),
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.Bold,
                )
            }
            Row(horizontalArrangement = Arrangement.spacedBy(Spacing.sm)) {
                OutlinedButton(onClick = onCancel, modifier = Modifier.weight(1f)) {
                    Text(stringResource(R.string.zone_cancel_button))
                }
                Button(
                    onClick = { if (uiState.trapTargetsOwnZone) showOwnZoneWarning.value = true else pickPhoto() },
                    enabled = uiState.pendingTrapPin != null && !uiState.isSubmittingTimeTrap,
                    modifier = Modifier.weight(1f),
                ) {
                    Text(stringResource(R.string.zone_confirm_button))
                }
            }
        }
    }

    if (showOwnZoneWarning.value) {
        TimeTrapOwnZoneWarningDialog(
            onConfirm = {
                showOwnZoneWarning.value = false
                pickPhoto()
            },
            onDismiss = { showOwnZoneWarning.value = false },
        )
    }
}

@Composable
private fun TimeTrapOwnZoneWarningDialog(onConfirm: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.time_trap_own_zone_warning_title)) },
        text = { Text(stringResource(R.string.time_trap_own_zone_warning)) },
        confirmButton = {
            TextButton(onClick = onConfirm) { Text(stringResource(R.string.zone_card_play_anyway)) }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(android.R.string.cancel)) }
        },
    )
}

/**
 * Seekers rule on a detection because they know whether they visited, rode through
 * or walked past. Both answers go to chat via the server, so a stray tap cannot pick
 * one: dismissing publicly refuses the hiders' claim and re-arms the trap on a cooldown.
 */
@Composable
internal fun TimeTrapDetectionDialog(trap: TimeTrap, onResolve: (Boolean) -> Unit) {
    val station = trap.stationName ?: stringResource(R.string.time_trap_station_unknown)
    AlertDialog(
        onDismissRequest = {},
        properties = DialogProperties(dismissOnBackPress = false, dismissOnClickOutside = false),
        title = { Text(stringResource(R.string.time_trap_detected_title)) },
        text = {
            Text(
                stringResource(
                    R.string.time_trap_detected_body,
                    station,
                    formatDurationWords(trap.valueSeconds.toLong()),
                ),
            )
        },
        confirmButton = {
            TextButton(onClick = { onResolve(true) }) {
                Text(stringResource(R.string.time_trap_detected_confirm))
            }
        },
        dismissButton = {
            TextButton(onClick = { onResolve(false) }) {
                Text(stringResource(R.string.time_trap_detected_dismiss))
            }
        },
    )
}

/**
 * The figure lives here rather than on the map label: it steps on the ViewModel's
 * ticker, and a value inside the GeoJSON would rebuild every source it shares.
 */
@Composable
internal fun TimeTrapValueChips(traps: List<TimeTrap>, modifier: Modifier = Modifier) {
    if (traps.isEmpty()) return
    Column(modifier = modifier, verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        traps.forEach { trap ->
            TimeTrapValueChip(trap)
        }
    }
}

@Composable
private fun TimeTrapValueChip(trap: TimeTrap) {
    val station = trap.stationName ?: stringResource(R.string.time_trap_station_unknown)
    Surface(
        shape = MaterialTheme.shapes.small,
        color = MaterialTheme.colorScheme.surface.copy(alpha = TRAP_CHIP_ALPHA),
        contentColor = MaterialTheme.colorScheme.onSurface,
        shadowElevation = 2.dp,
    ) {
        Text(
            text = stringResource(
                R.string.time_trap_value_chip,
                station,
                formatDurationWords(trap.valueSeconds.toLong()),
            ),
            modifier = Modifier.padding(horizontal = Spacing.sm, vertical = Spacing.xs),
            style = MaterialTheme.typography.labelSmall,
        )
    }
}
