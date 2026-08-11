package fr.gshz.hideandseek.feature.lobby

import android.widget.Toast
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Map
import androidx.compose.material.icons.filled.PersonRemove
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.QrCode2
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.RoleBadge
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.Player

@Composable
internal fun RosterList(
    roster: List<Player>,
    onPlayerLongPress: (Player) -> Unit = {},
    modifier: Modifier = Modifier,
) {
    Column(modifier = modifier, verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        roster.forEach { player ->
            RosterRow(player = player, onPlayerLongPress = onPlayerLongPress)
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun RosterRow(
    player: Player,
    onPlayerLongPress: (Player) -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier
            .fillMaxWidth()
            .combinedClickable(onClick = {}, onLongClick = { onPlayerLongPress(player) }),
        color = MaterialTheme.colorScheme.surfaceContainer,
        contentColor = MaterialTheme.colorScheme.onSurface,
        shape = MaterialTheme.shapes.small,
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text = player.displayName,
                style = MaterialTheme.typography.bodyLarge,
                modifier = Modifier.weight(1f),
            )
            RoleBadge(side = player.side)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun PlayerActionsSheet(
    playerName: String,
    onRemovePlayer: () -> Unit,
    onDismiss: () -> Unit,
    modifier: Modifier = Modifier,
) {
    ModalBottomSheet(onDismissRequest = onDismiss, modifier = modifier) {
        Column(modifier = Modifier.fillMaxWidth().padding(bottom = Spacing.md)) {
            Text(
                text = playerName,
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
            )
            HorizontalDivider()
            TextButton(
                onClick = onRemovePlayer,
                modifier = Modifier.fillMaxWidth().padding(horizontal = Spacing.md),
                colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error),
            ) {
                Icon(Icons.Filled.PersonRemove, contentDescription = null)
                Spacer(Modifier.width(Spacing.sm))
                Text(stringResource(R.string.lobby_remove_player_action))
            }
        }
    }
}

internal data class RemovePlayerDialogState(
    val targetName: String,
    val isRemoving: Boolean,
    val error: ErrorType?,
    val errorKey: String?,
    val errorArgs: Map<String, String>?,
)

@Composable
internal fun RemovePlayerDialog(
    state: RemovePlayerDialogState,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.lobby_remove_player_dialog_title, state.targetName)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
                Text(
                    text = stringResource(R.string.lobby_remove_player_dialog_body),
                    style = MaterialTheme.typography.bodyMedium,
                )
                ErrorText(state.error, errorKey = state.errorKey, errorArgs = state.errorArgs)
            }
        },
        confirmButton = {
            TextButton(
                onClick = onConfirm,
                enabled = !state.isRemoving,
                colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error),
            ) {
                Text(stringResource(R.string.lobby_remove_player_confirm))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss, enabled = !state.isRemoving) {
                Text(stringResource(R.string.lobby_ingest_features_dialog_cancel))
            }
        },
    )
}

@Composable
internal fun GameCodeCard(
    code: String?,
    isQrVisible: Boolean,
    onToggleQrClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        color = MaterialTheme.colorScheme.secondaryContainer,
        contentColor = MaterialTheme.colorScheme.onSecondaryContainer,
        shape = MaterialTheme.shapes.medium,
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = stringResource(R.string.lobby_game_code_label),
                    style = MaterialTheme.typography.bodySmall,
                )
                SelectionContainer {
                    Text(
                        text = code ?: stringResource(R.string.lobby_code_loading),
                        style = MaterialTheme.typography.titleLarge.copy(
                            fontFamily = FontFamily.Monospace,
                            letterSpacing = 2.sp,
                        ),
                    )
                }
            }
            CopyButton(
                value = code,
                contentDescription = stringResource(R.string.lobby_code_copy),
                copiedMessage = stringResource(R.string.lobby_code_copied),
            )
            IconButton(onClick = onToggleQrClick) {
                Icon(
                    imageVector = if (isQrVisible) Icons.Filled.Close else Icons.Filled.QrCode2,
                    contentDescription = stringResource(
                        if (isQrVisible) R.string.lobby_hide_qr else R.string.lobby_show_qr,
                    ),
                )
            }
        }
    }
}

@Composable
private fun CopyButton(value: String?, contentDescription: String, copiedMessage: String) {
    val clipboard = LocalClipboardManager.current
    val context = LocalContext.current
    IconButton(
        enabled = value != null,
        onClick = {
            value?.let {
                clipboard.setText(AnnotatedString(it))
                Toast.makeText(context, copiedMessage, Toast.LENGTH_SHORT).show()
            }
        },
    ) {
        Icon(imageVector = Icons.Filled.ContentCopy, contentDescription = contentDescription)
    }
}

@Composable
internal fun OpenMapButton(onOpenMapClick: () -> Unit, showHint: Boolean) {
    FilledTonalButton(onClick = onOpenMapClick, modifier = Modifier.fillMaxWidth()) {
        Icon(imageVector = Icons.Filled.Map, contentDescription = null, modifier = Modifier.size(20.dp))
        Text(text = stringResource(R.string.lobby_open_map), modifier = Modifier.padding(start = Spacing.sm))
    }
    if (showHint) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            Icon(
                imageVector = Icons.Filled.Info,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.size(16.dp),
            )
            Text(
                text = stringResource(R.string.lobby_background_location_hint),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
internal fun HidingTimeField(value: String, onValueChange: (String) -> Unit, modifier: Modifier = Modifier) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(stringResource(R.string.lobby_hiding_time_label)) },
        suffix = { Text(stringResource(R.string.lobby_hiding_time_unit)) },
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
        modifier = modifier.fillMaxWidth(),
    )
}

@Composable
internal fun StartRoundButton(
    isStarting: Boolean,
    allSidesChosen: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Button(
        onClick = onClick,
        enabled = allSidesChosen && !isStarting,
        modifier = modifier.fillMaxWidth(),
    ) {
        Icon(imageVector = Icons.Filled.PlayArrow, contentDescription = null, modifier = Modifier.size(20.dp))
        Text(text = stringResource(R.string.lobby_start_round), modifier = Modifier.padding(start = Spacing.sm))
    }
    if (!allSidesChosen) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            Icon(
                imageVector = Icons.Filled.Info,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.size(16.dp),
            )
            Text(
                text = stringResource(R.string.lobby_start_round_needs_sides),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}
