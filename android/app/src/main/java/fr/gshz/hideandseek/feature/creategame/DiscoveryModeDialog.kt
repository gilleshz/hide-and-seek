package fr.gshz.hideandseek.feature.creategame

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.Spacing

@Composable
internal fun DiscoveryModeDialog(
    selection: Set<String>,
    onToggleMode: (String) -> Unit,
    onToggleAll: () -> Unit,
    onSearch: () -> Unit,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.mode_dialog_title)) },
        text = { DiscoveryModeList(selection, onToggleMode, onToggleAll) },
        confirmButton = {
            TextButton(onClick = onSearch, enabled = selection.isNotEmpty()) {
                Text(stringResource(R.string.area_search_button))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text(stringResource(R.string.zone_cancel_button))
            }
        },
    )
}

@Composable
private fun DiscoveryModeList(
    selection: Set<String>,
    onToggleMode: (String) -> Unit,
    onToggleAll: () -> Unit,
) {
    Column(
        modifier = Modifier
            .heightIn(max = 360.dp)
            .verticalScroll(rememberScrollState()),
    ) {
        ModeCheckboxRow(
            label = stringResource(R.string.mode_dialog_all),
            checked = selection.containsAll(ALL_ROUTE_TYPES),
            onToggle = onToggleAll,
        )
        ALL_ROUTE_TYPES.forEach { mode ->
            ModeCheckboxRow(
                label = routeTypeLabel(mode),
                checked = mode in selection,
                onToggle = { onToggleMode(mode) },
            )
        }
    }
}

@Composable
private fun ModeCheckboxRow(
    label: String,
    checked: Boolean,
    onToggle: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onToggle)
            .padding(vertical = Spacing.xs),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Checkbox(checked = checked, onCheckedChange = { onToggle() })
        Spacer(Modifier.width(Spacing.sm))
        Text(text = label, style = MaterialTheme.typography.bodyMedium)
    }
}
