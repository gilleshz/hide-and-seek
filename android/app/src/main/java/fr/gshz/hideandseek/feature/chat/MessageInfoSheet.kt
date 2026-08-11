package fr.gshz.hideandseek.feature.chat

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.DeleteOutline
import androidx.compose.material.icons.filled.DoneAll
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.ChatMessageReader
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

private const val SECTION_ICON_SIZE_DP = 16

private val readAtFormatter: DateTimeFormatter =
    DateTimeFormatter.ofLocalizedDateTime(FormatStyle.SHORT, FormatStyle.SHORT)

/**
 * Who has read one message, and who has not yet, in the style of a messenger's message info view.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun MessageInfoSheet(
    info: MessageInfoState,
    uiState: ChatUiState,
    onDismiss: () -> Unit,
    onDelete: (String) -> Unit,
) {
    val message = uiState.messages.firstOrNull { it.uuid == info.messageUuid }
    var confirmingDelete by remember { mutableStateOf(false) }
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState()) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = Spacing.lg)
                .padding(bottom = Spacing.xl),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            Text(
                text = stringResource(R.string.chat_message_info_title),
                style = MaterialTheme.typography.titleMedium,
            )
            when {
                info.isLoading -> CircularProgressIndicator(modifier = Modifier.size(24.dp))
                info.failed -> Text(
                    text = stringResource(R.string.chat_message_info_failed),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.error,
                )
                else -> MessageInfoBody(info = info, uiState = uiState, message = message)
            }
            if (message != null && message.isDeletable && uiState.isOwnMessage(message)) {
                DeleteMessageButton(onClick = { confirmingDelete = true })
            }
        }
    }

    if (confirmingDelete) {
        DeleteMessageDialog(
            onConfirm = {
                confirmingDelete = false
                onDelete(info.messageUuid)
            },
            onDismiss = { confirmingDelete = false },
        )
    }
}

@Composable
private fun DeleteMessageButton(onClick: () -> Unit) {
    TextButton(
        onClick = onClick,
        colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error),
        modifier = Modifier.padding(top = Spacing.sm),
    ) {
        Icon(
            imageVector = Icons.Default.DeleteOutline,
            contentDescription = null,
            modifier = Modifier.size(SECTION_ICON_SIZE_DP.dp),
        )
        Spacer(modifier = Modifier.width(Spacing.xs))
        Text(text = stringResource(R.string.chat_message_delete))
    }
}

@Composable
private fun DeleteMessageDialog(onConfirm: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(text = stringResource(R.string.chat_message_delete_title)) },
        text = { Text(text = stringResource(R.string.chat_message_delete_body)) },
        confirmButton = {
            TextButton(
                onClick = onConfirm,
                colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error),
            ) {
                Text(text = stringResource(R.string.chat_message_delete_confirm))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text(text = stringResource(R.string.chat_message_delete_cancel))
            }
        },
    )
}

@Composable
private fun MessageInfoBody(info: MessageInfoState, uiState: ChatUiState, message: ChatMessage?) {
    val pending = message?.let {
        uiState.unreadRecipientNames(it, info.readers.map { reader -> reader.playerUuid }.toSet())
    }.orEmpty()
    Column(
        modifier = Modifier.fillMaxWidth(),
        verticalArrangement = Arrangement.spacedBy(Spacing.xs),
    ) {
        SectionHeader(
            icon = Icons.Default.DoneAll,
            label = stringResource(R.string.chat_message_info_read_by),
        )
        if (info.readers.isEmpty()) {
            EmptyLine(stringResource(R.string.chat_message_info_nobody_yet))
        }
        info.readers.forEach { reader -> ReaderRow(reader) }
        if (pending.isNotEmpty()) {
            SectionHeader(
                icon = Icons.Default.Schedule,
                label = stringResource(R.string.chat_message_info_not_read),
            )
            pending.forEach { name -> PendingRow(name) }
        }
    }
}

@Composable
private fun SectionHeader(icon: ImageVector, label: String) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
        modifier = Modifier.padding(top = Spacing.sm),
    ) {
        Icon(
            imageVector = icon,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(SECTION_ICON_SIZE_DP.dp),
        )
        Text(
            text = label,
            style = MaterialTheme.typography.labelLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun ReaderRow(reader: ChatMessageReader) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(text = reader.playerName, style = MaterialTheme.typography.bodyMedium)
        Text(
            text = formatReadAt(reader.readAt),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun PendingRow(name: String) {
    Text(
        text = name,
        style = MaterialTheme.typography.bodyMedium,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
}

@Composable
private fun EmptyLine(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
}

private fun formatReadAt(iso: String): String =
    runCatching {
        OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(readAtFormatter)
    }.getOrDefault(iso)
