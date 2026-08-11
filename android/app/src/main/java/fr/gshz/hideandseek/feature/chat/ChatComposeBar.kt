package fr.gshz.hideandseek.feature.chat

import android.net.Uri
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Reply
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.AttachFile
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.ChatMessage

private const val ATTACHMENT_THUMB_SIZE_DP = 40
private const val ACCESSORY_ICON_SIZE_DP = 20
private const val ACCESSORY_BUTTON_SIZE_DP = 32
private const val UPLOAD_PILL_WIDTH_FRACTION = 0.6f

@Suppress("LongParameterList")
@Composable
internal fun ChatBottomBar(
    composeText: String,
    onComposeTextChange: (String) -> Unit,
    onSend: () -> Unit,
    uiState: ChatUiState,
    onAttachClick: () -> Unit,
    stagedAttachment: Uri? = null,
    onCancelAttachment: () -> Unit = {},
    onCancelReply: () -> Unit = {},
) {
    Column {
        if (uiState.isSending) {
            UploadingPill()
        }
        uiState.replyTarget?.let { target ->
            ReplyPreviewBar(target = target, uiState = uiState, onCancel = onCancelReply)
        }
        stagedAttachment?.let { uri ->
            AttachmentPreviewBar(uri = uri, onCancel = onCancelAttachment)
        }
        ComposeInputRow(
            composeText = composeText,
            onComposeTextChange = onComposeTextChange,
            onSend = onSend,
            canSend = stagedAttachment != null || composeText.isNotBlank(),
            isSending = uiState.isSending,
            hasAttachment = stagedAttachment != null,
            onAttachClick = onAttachClick,
        )
    }
}

@Suppress("LongParameterList")
@Composable
private fun ComposeInputRow(
    composeText: String,
    onComposeTextChange: (String) -> Unit,
    onSend: () -> Unit,
    canSend: Boolean,
    isSending: Boolean,
    hasAttachment: Boolean,
    onAttachClick: () -> Unit,
) {
    val hint = if (hasAttachment) R.string.chat_caption_hint else R.string.chat_compose_hint
    Surface(
        tonalElevation = 2.dp,
        shadowElevation = 2.dp,
        modifier = Modifier.navigationBarsPadding(),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(onClick = onAttachClick, enabled = !isSending) {
                Icon(
                    imageVector = Icons.Default.AttachFile,
                    contentDescription = stringResource(R.string.chat_attach_image),
                )
            }
            OutlinedTextField(
                value = composeText,
                onValueChange = onComposeTextChange,
                placeholder = { Text(stringResource(hint)) },
                modifier = Modifier.weight(1f),
                singleLine = true,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Send),
                keyboardActions = KeyboardActions(onSend = { if (canSend && !isSending) onSend() }),
            )
            Spacer(modifier = Modifier.width(Spacing.sm))
            IconButton(onClick = onSend, enabled = canSend && !isSending) {
                Icon(
                    imageVector = Icons.AutoMirrored.Filled.Send,
                    contentDescription = stringResource(R.string.chat_send_button),
                )
            }
        }
    }
}

/**
 * The shared chrome for the strips that sit between the message list and the input row:
 * a divider, a tinted surface, and a trailing dismiss button.
 */
@Composable
private fun ComposeAccessoryBar(
    cancelDescription: String,
    onCancel: () -> Unit,
    content: @Composable RowScope.() -> Unit,
) {
    Column {
        HorizontalDivider(color = MaterialTheme.colorScheme.surfaceVariant)
        Surface(color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.4f)) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = Spacing.md, vertical = Spacing.xs),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                content()
                IconButton(onClick = onCancel, modifier = Modifier.size(ACCESSORY_BUTTON_SIZE_DP.dp)) {
                    Icon(
                        imageVector = Icons.Default.Close,
                        contentDescription = cancelDescription,
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.size(ACCESSORY_ICON_SIZE_DP.dp),
                    )
                }
            }
        }
    }
}

@Composable
private fun ReplyPreviewBar(target: ChatMessage, uiState: ChatUiState, onCancel: () -> Unit) {
    val senderName = uiState.senderNameOf(target).orEmpty()
    ComposeAccessoryBar(
        cancelDescription = stringResource(R.string.chat_reply_cancel),
        onCancel = onCancel,
    ) {
        Icon(
            imageVector = Icons.AutoMirrored.Filled.Reply,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(ACCESSORY_ICON_SIZE_DP.dp),
        )
        Spacer(modifier = Modifier.width(Spacing.sm))
        val preview = target.quotePreview()
        target.imageRef?.let { imageRef ->
            QuoteThumbnail(uiState.apiUrl, uiState.gameUuid, imageRef)
            Spacer(modifier = Modifier.width(Spacing.sm))
        }
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = senderName,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.primary,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                text = preview.text,
                style = MaterialTheme.typography.bodySmall.copy(fontStyle = preview.fontStyle),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

@Composable
private fun AttachmentPreviewBar(uri: Uri, onCancel: () -> Unit) {
    ComposeAccessoryBar(
        cancelDescription = stringResource(R.string.chat_attachment_remove),
        onCancel = onCancel,
    ) {
        AsyncImage(
            model = uri,
            contentDescription = stringResource(R.string.chat_image_content_description),
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .size(ATTACHMENT_THUMB_SIZE_DP.dp)
                .clip(MaterialTheme.shapes.small),
        )
        Spacer(modifier = Modifier.width(Spacing.sm))
        Text(
            text = stringResource(R.string.chat_quote_photo),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
private fun UploadingPill() {
    Box(
        modifier = Modifier.fillMaxWidth(),
        contentAlignment = Alignment.Center,
    ) {
        Surface(
            modifier = Modifier.fillMaxWidth(UPLOAD_PILL_WIDTH_FRACTION),
            shape = RoundedCornerShape(24.dp),
            color = MaterialTheme.colorScheme.primary,
        ) {
            LinearProgressIndicator(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = Spacing.lg, vertical = 10.dp),
                color = MaterialTheme.colorScheme.onPrimary.copy(alpha = 0.6f),
                trackColor = MaterialTheme.colorScheme.onPrimary.copy(alpha = 0.2f),
            )
        }
    }
}
