package fr.gshz.hideandseek.feature.chat

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.net.Uri
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts.TakePicture
import androidx.core.content.FileProvider
import java.io.File

import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Reply
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.AttachFile
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.snapshotFlow
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil3.compose.AsyncImage
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.i18n.resolveError
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.ImageSourceDialog
import fr.gshz.hideandseek.core.ui.newCameraOutputUri
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.Side
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

private const val AUTOSCROLL_SLACK_ITEMS = 2

private enum class Powerup { Veto, Randomize }

/**
 * A powerup is a physical card, so playing one is gated on photographing it: the question uuid waits
 * here until the camera or the picker comes back with the picture that goes out with the play.
 */
private data class PendingPowerup(val kind: Powerup, val questionUuid: String)

@Composable
private fun ChatToasts(
    uiState: ChatUiState,
    onDismissSendError: () -> Unit,
    onDismissRevealError: () -> Unit,
    onDismissPowerupError: () -> Unit,
    onDismissDownloadOutcome: () -> Unit,
) {
    val context = LocalContext.current
    // Resolve the strings at composition so the resource lookups never run in a LaunchedEffect.
    val sendFailedText = stringResource(R.string.chat_send_failed)
    val revealFailedText = stringResource(R.string.chat_reveal_failed)
    val powerupFailedText = stringResource(R.string.chat_powerup_failed)
    val downloadOutcomeText = uiState.downloadOutcome?.let { stringResource(it.toMessageRes()) }
    LaunchedEffect(uiState.sendError) {
        if (uiState.sendError != null) {
            Toast.makeText(context, sendFailedText, Toast.LENGTH_LONG).show()
            onDismissSendError()
        }
    }
    val revealRaceText = uiState.revealErrorKey?.let { resolveError(it, uiState.revealErrorArgs) }
    LaunchedEffect(uiState.revealError, uiState.revealErrorKey) {
        if (uiState.revealError || uiState.revealErrorKey != null) {
            // A rejection we cannot name already fixed itself in the resync, so it stays quiet.
            val text = revealRaceText ?: revealFailedText.takeIf { uiState.revealError }
            text?.let { Toast.makeText(context, it, Toast.LENGTH_LONG).show() }
            onDismissRevealError()
        }
    }
    val powerupErrorText = uiState.powerupErrorKey?.let { resolveError(it, uiState.powerupErrorArgs) }
    LaunchedEffect(uiState.powerupError, uiState.powerupErrorKey) {
        if (uiState.powerupError || uiState.powerupErrorKey != null) {
            val text = powerupErrorText ?: powerupFailedText
            Toast.makeText(context, text, Toast.LENGTH_LONG).show()
            onDismissPowerupError()
        }
    }
    LaunchedEffect(uiState.downloadOutcome) {
        uiState.downloadOutcome?.let { outcome ->
            downloadOutcomeText?.let { Toast.makeText(context, it, Toast.LENGTH_SHORT).show() }
            onDismissDownloadOutcome()
        }
    }
}

@Composable
fun ChatScreen(
    onBackClick: () -> Unit,
    onNavigateToMap: (String) -> Unit,
    viewModel: ChatViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_RESUME -> viewModel.onScreenResumed()
                Lifecycle.Event.ON_PAUSE -> viewModel.onScreenPaused()
                else -> Unit
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose {
            lifecycleOwner.lifecycle.removeObserver(observer)
            viewModel.onScreenPaused()
        }
    }
    ChatToasts(
        uiState = uiState,
        onDismissSendError = viewModel::dismissSendError,
        onDismissRevealError = viewModel::dismissRevealError,
        onDismissPowerupError = viewModel::dismissPowerupError,
        onDismissDownloadOutcome = viewModel::dismissDownloadOutcome,
    )
    ChatContent(
        uiState = uiState,
        onSendMessage = { body, replyToUuid -> viewModel.sendMessage(body, replyToUuid) },
        onSendImage = { uri, caption, replyToUuid -> viewModel.sendImage(uri, caption, replyToUuid) },
        onBackClick = onBackClick,
        onRevealClick = viewModel::revealQuestion,
        onAnswerPhoto = { questionUuid, imageUri ->
            viewModel.revealPhotoQuestion(questionUuid, imageUri)
        },
        onDrawTrace = { questionUuid ->
            viewModel.requestTrace(questionUuid)
            onNavigateToMap(uiState.gameUuid)
        },
        onVetoClick = viewModel::vetoQuestion,
        onRandomizeClick = viewModel::randomizeQuestion,
        onCountdownExpired = viewModel::onCountdownExpired,
        onDownloadClick = viewModel::downloadImage,
        onDownloadPermissionDenied = viewModel::onDownloadPermissionDenied,
        onSetReplyTarget = viewModel::setReplyTarget,
        onShowMessageInfo = viewModel::openMessageInfo,
        onDismissMessageInfo = viewModel::closeMessageInfo,
        onDeleteMessage = viewModel::deleteMessage,
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Suppress("LongParameterList", "LongMethod")
@Composable
internal fun ChatContent(
    uiState: ChatUiState,
    onSendMessage: (String, String?) -> Unit,
    onSendImage: (String, String?, String?) -> Unit,
    onBackClick: () -> Unit,
    onRevealClick: (String) -> Unit,
    onAnswerPhoto: (String, String) -> Unit,
    onDrawTrace: (String) -> Unit,
    onVetoClick: (String, String) -> Unit,
    onRandomizeClick: (String, String) -> Unit,
    onCountdownExpired: () -> Unit,
    onDownloadClick: (String) -> Unit,
    onDownloadPermissionDenied: () -> Unit,
    onSetReplyTarget: (ChatMessage?) -> Unit,
    onShowMessageInfo: (String) -> Unit,
    onDismissMessageInfo: () -> Unit,
    onDeleteMessage: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    var composeText by remember { mutableStateOf("") }
    var fullscreenImageUrl by remember { mutableStateOf<String?>(null) }
    var powerupTargetUuid by remember { mutableStateOf<String?>(null) }
    var pendingPhotoAnswerUuid by rememberSaveable { mutableStateOf<String?>(null) }
    var pendingPowerup by remember { mutableStateOf<PendingPowerup?>(null) }
    var showImageSourceDialog by remember { mutableStateOf(false) }
    var pendingCameraUri by remember { mutableStateOf<Uri?>(null) }
    var stagedAttachment by remember { mutableStateOf<Uri?>(null) }
    val context = LocalContext.current
    val listState = rememberLazyListState()
    val coroutineScope = rememberCoroutineScope()
    val consumePhoto: (Uri?) -> Unit = { uri ->
        val powerup = pendingPowerup
        val answerUuid = pendingPhotoAnswerUuid
        pendingPowerup = null
        pendingPhotoAnswerUuid = null
        when {
            uri == null -> Unit
            powerup != null -> when (powerup.kind) {
                Powerup.Veto -> onVetoClick(powerup.questionUuid, uri.toString())
                Powerup.Randomize -> onRandomizeClick(powerup.questionUuid, uri.toString())
            }
            answerUuid != null -> onAnswerPhoto(answerUuid, uri.toString())
            // Staged, not sent: the caption and any reply target travel with it on send.
            else -> stagedAttachment = uri
        }
    }
    val imagePickerLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.PickVisualMedia(),
    ) { uri -> consumePhoto(uri) }
    val cameraLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.TakePicture(),
    ) { isSuccess ->
        val uri = pendingCameraUri
        pendingCameraUri = null
        consumePhoto(if (isSuccess) uri else null)
    }
    val sendPending = {
        val text = composeText.trim()
        if (stagedAttachment != null || text.isNotEmpty()) {
            dispatchSend(text, stagedAttachment, uiState.replyTarget?.uuid, onSendMessage, onSendImage)
            composeText = ""
            stagedAttachment = null
            onSetReplyTarget(null)
        }
    }
    ChatAutoScroll(messages = uiState.messages, listState = listState)
    Scaffold(
        modifier = modifier,
        topBar = { ChatTopBar(gameName = uiState.gameName, onBackClick = onBackClick) },
        bottomBar = {
            ChatBottomBar(
                composeText = composeText,
                onComposeTextChange = { composeText = it },
                onSend = sendPending,
                uiState = uiState,
                onAttachClick = { showImageSourceDialog = true },
                stagedAttachment = stagedAttachment,
                onCancelAttachment = { stagedAttachment = null },
                onCancelReply = { onSetReplyTarget(null) },
            )
        },
    ) { innerPadding ->
        ChatMessageList(
            uiState = uiState,
            onRevealClick = onRevealClick,
            onAnswerPhotoClick = { questionUuid ->
                pendingPhotoAnswerUuid = questionUuid
                showImageSourceDialog = true
            },
            onPowerupClick = { powerupTargetUuid = it },
            onCountdownExpired = onCountdownExpired,
            onImageClick = { fullscreenImageUrl = it },
            onReplySwipe = onSetReplyTarget,
            onQuoteClick = { targetUuid ->
                coroutineScope.launch { listState.scrollToMessage(uiState.messages, targetUuid) }
            },
            onShowMessageInfo = onShowMessageInfo,
            modifier = Modifier.padding(innerPadding),
            listState = listState,
        )
    }
    uiState.messageInfo?.let { info ->
        MessageInfoSheet(
            info = info,
            uiState = uiState,
            onDismiss = onDismissMessageInfo,
            onDelete = onDeleteMessage,
        )
    }
    powerupTargetUuid?.let { questionUuid ->
        PowerupDialog(
            questionUuid = questionUuid,
            showVeto = !uiState.isTravelingThermometer(questionUuid),
            onVetoClick = {
                pendingPowerup = PendingPowerup(Powerup.Veto, it)
                powerupTargetUuid = null
                showImageSourceDialog = true
            },
            onRandomizeClick = {
                pendingPowerup = PendingPowerup(Powerup.Randomize, it)
                powerupTargetUuid = null
                showImageSourceDialog = true
            },
            onDismiss = { powerupTargetUuid = null },
        )
    }
    if (showImageSourceDialog) {
        ImageSourceDialog(
            onCameraClick = {
                showImageSourceDialog = false
                val uri = newCameraOutputUri(context)
                pendingCameraUri = uri
                cameraLauncher.launch(uri)
            },
            onGalleryClick = {
                showImageSourceDialog = false
                imagePickerLauncher.launch(
                    PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly),
                )
            },
            onDismiss = {
                showImageSourceDialog = false
                pendingPhotoAnswerUuid = null
                pendingPowerup = null
            },
            onDrawClick = uiState.drawTraceHandler(pendingPhotoAnswerUuid) { questionUuid ->
                showImageSourceDialog = false
                pendingPhotoAnswerUuid = null
                onDrawTrace(questionUuid)
            },
        )
    }
    fullscreenImageUrl?.let { url ->
        fullscreenImageOverlay(
            imageUrl = url,
            onDismiss = { fullscreenImageUrl = null },
            onDownloadClick = onDownloadClick,
            onDownloadPermissionDenied = onDownloadPermissionDenied,
        )
    }
}

/**
 * Walks the same date-grouped layout the list renders so the flat item index accounts for
 * the sticky day headers sitting between message runs.
 */
private suspend fun LazyListState.scrollToMessage(messages: List<ChatMessage>, targetUuid: String) {
    var index = 0
    for ((_, group) in groupMessagesByDate(messages)) {
        index++
        val found = group.indexOfFirst { it.uuid == targetUuid }
        if (found >= 0) {
            animateScrollToItem(index + found)
            return
        }
        index += group.size
    }
}

/**
 * A staged attachment turns the send action into an image upload that carries the typed
 * text as its caption; without one it stays a plain text message.
 */
private fun dispatchSend(
    text: String,
    attachment: Uri?,
    replyToUuid: String?,
    onSendMessage: (String, String?) -> Unit,
    onSendImage: (String, String?, String?) -> Unit,
) {
    if (attachment != null) {
        onSendImage(attachment.toString(), text.ifEmpty { null }, replyToUuid)
    } else {
        onSendMessage(text, replyToUuid)
    }
}

@Composable
private fun PowerupDialog(
    questionUuid: String,
    showVeto: Boolean,
    onVetoClick: (String) -> Unit,
    onRandomizeClick: (String) -> Unit,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.powerup_dialog_title)) },
        text = {
            Column {
                Text(
                    text = stringResource(R.string.powerup_dialog_hint),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(modifier = Modifier.height(Spacing.sm))
                if (showVeto) {
                    Button(
                        onClick = { onVetoClick(questionUuid) },
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text(stringResource(R.string.question_veto_button))
                    }
                    Spacer(modifier = Modifier.height(Spacing.sm))
                }
                Button(
                    onClick = { onRandomizeClick(questionUuid) },
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(stringResource(R.string.question_randomize_button))
                }
            }
        },
        confirmButton = {},
        dismissButton = {
            androidx.compose.material3.TextButton(onClick = onDismiss) {
                Text(stringResource(R.string.powerup_dialog_dismiss))
            }
        },
    )
}

@Composable
private fun fullscreenImageOverlay(
    imageUrl: String,
    onDismiss: () -> Unit,
    onDownloadClick: (String) -> Unit,
    onDownloadPermissionDenied: () -> Unit,
) {
    val context = LocalContext.current
    var pendingDownloadUrl by remember { mutableStateOf<String?>(null) }
    val permissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission(),
    ) { granted ->
        val url = pendingDownloadUrl
        pendingDownloadUrl = null
        if (granted && url != null) onDownloadClick(url) else onDownloadPermissionDenied()
    }
    FullscreenImageViewer(
        imageUrl = imageUrl,
        onDismiss = onDismiss,
        onDownloadClick = { url ->
            if (writePermissionMissing(context)) {
                pendingDownloadUrl = url
                permissionLauncher.launch(Manifest.permission.WRITE_EXTERNAL_STORAGE)
            } else {
                onDownloadClick(url)
            }
        },
    )
}

private fun writePermissionMissing(context: Context): Boolean =
    Build.VERSION.SDK_INT <= Build.VERSION_CODES.P &&
        ContextCompat.checkSelfPermission(context, Manifest.permission.WRITE_EXTERNAL_STORAGE) !=
        PackageManager.PERMISSION_GRANTED

private fun DownloadOutcome.toMessageRes(): Int = when (this) {
    DownloadOutcome.Saved -> R.string.chat_image_saved
    DownloadOutcome.Failed -> R.string.chat_image_save_failed
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ChatTopBar(gameName: String, onBackClick: () -> Unit) {
    TopAppBar(
        title = { Text(gameName.ifBlank { stringResource(R.string.chat_title) }) },
        navigationIcon = {
            IconButton(onClick = onBackClick) {
                Icon(
                    imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                    contentDescription = stringResource(R.string.nav_back),
                )
            }
        },
    )
}

@OptIn(ExperimentalFoundationApi::class)
@Suppress("LongParameterList")
@Composable
private fun ChatMessageList(
    uiState: ChatUiState,
    onRevealClick: (String) -> Unit,
    onAnswerPhotoClick: (String) -> Unit,
    onPowerupClick: (String) -> Unit,
    onCountdownExpired: () -> Unit,
    onImageClick: (String) -> Unit,
    onReplySwipe: (ChatMessage) -> Unit = {},
    onQuoteClick: (String) -> Unit = {},
    onShowMessageInfo: (String) -> Unit = {},
    modifier: Modifier = Modifier,
    listState: LazyListState = rememberLazyListState(),
) {
    if (uiState.messages.isEmpty()) {
        Box(modifier = modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Text(
                text = stringResource(R.string.chat_empty),
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
                modifier = Modifier.padding(Spacing.lg),
            )
        }
    } else {
        val groups = remember(uiState.messages) { groupMessagesByDate(uiState.messages) }
        LazyColumn(
            modifier = modifier.fillMaxSize().padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
            state = listState,
        ) {
            groups.forEach { group ->
                stickyHeader(key = group.first.toString()) {
                    DateDividerChip(dateLabel = formatDateDivider(group.first))
                }
                items(items = group.second, key = { it.uuid }) { message ->
                    MessageBubble(
                        message = message,
                        uiState = uiState,
                        onRevealClick = onRevealClick,
                        onAnswerPhotoClick = onAnswerPhotoClick,
                        onPowerupClick = onPowerupClick,
                        onCountdownExpired = onCountdownExpired,
                        onImageClick = onImageClick,
                        onReplySwipe = if (!message.isSystem && message.senderUuid != null) {
                            { onReplySwipe(message) }
                        } else null,
                        onQuoteClick = if (message.replyToUuid != null) {
                            { onQuoteClick(it) }
                        } else null,
                        onLongPress = if (uiState.isOwnMessage(message)) {
                            { onShowMessageInfo(message.uuid) }
                        } else null,
                    )
                }
            }
        }
    }
}

/**
 * Keeps the newest message in view.
 *
 * `layoutInfo` describes the previous frame when the effect resumes, so the item count is
 * awaited instead of an index. The saveable list state restores the old offset on re-entry,
 * so the first landing is forced rather than conditional on being near the bottom.
 */
@Composable
private fun ChatAutoScroll(messages: List<ChatMessage>, listState: LazyListState) {
    var landed by remember { mutableStateOf(false) }
    LaunchedEffect(messages.size) {
        if (messages.isEmpty()) return@LaunchedEffect
        val wasNearBottom = listState.isNearBottom()
        val total = snapshotFlow { listState.layoutInfo.totalItemsCount }.first { it >= messages.size }
        when {
            !landed -> {
                listState.scrollToItem(total - 1)
                landed = true
            }
            wasNearBottom -> listState.animateScrollToItem(total - 1)
        }
    }
}

private fun LazyListState.isNearBottom(): Boolean {
    val lastVisible = layoutInfo.visibleItemsInfo.lastOrNull() ?: return true
    return lastVisible.index >= layoutInfo.totalItemsCount - AUTOSCROLL_SLACK_ITEMS
}

@Suppress("LongMethod")
@Preview
@Composable
private fun ChatContentPreview() {
    AppTheme {
        ChatContent(
            uiState = ChatUiState(
                gameUuid = "g-1",
                gameName = "Berlin",
                messages = listOf(
                    ChatMessage(
                        uuid = "1", senderUuid = "p2", type = "text", body = "Hey!",
                        imageRef = null, createdAt = "2026-07-03T10:00:00Z",
                    ),
                    ChatMessage(
                        uuid = "1b", senderUuid = "p1", type = "text", body = "Hi Marc!",
                        imageRef = null, createdAt = "2026-07-03T10:01:00Z",
                    ),
                    ChatMessage(
                        uuid = "2", senderUuid = null, type = "system",
                        body = "Round started.", imageRef = null,
                        createdAt = "2026-07-05T12:02:00Z",
                    ),
                    ChatMessage(
                        uuid = "3", senderUuid = "p2", type = "question",
                        body = "Are you within 500 m of me?", imageRef = null,
                        createdAt = "2026-07-05T12:03:00Z", questionUuid = "q1",
                    ),
                    ChatMessage(
                        uuid = "4", senderUuid = "p1", type = "answer",
                        body = "No, not within range", imageRef = null,
                        createdAt = "2026-07-05T12:04:00Z", questionUuid = "q1",
                        replyToUuid = "3",
                    ),
                ),
                roster = mapOf("p1" to "Alice", "p2" to "Marc"),
                selfPlayerUuid = "p1",
                apiUrl = "https://api.example.com",
                side = Side.Hider,
                questionsByUuid = mapOf(
                    "q1" to AskedQuestion(
                        uuid = "q1",
                        roundUuid = "round-1",
                        category = QuestionCategory.Radar,
                        askedAt = "2026-07-05T12:03:00Z",
                        revealDeadlineAt = "2026-07-05T12:08:00Z",
                        revealedAt = "2026-07-05T12:04:00Z",
                        radarAnswer = false,
                        thermometerResult = null,
                        radiusMeters = 500.0,
                        seekerLat = 52.52,
                        seekerLng = 13.405,
                    ),
                ),
                selfLocation = DeviceLocation(latitude = 52.50, longitude = 13.40),
            ),
            onSendMessage = { _, _ -> },
            onSendImage = { _, _, _ -> },
            onBackClick = {},
            onRevealClick = {},
            onAnswerPhoto = { _, _ -> },
            onDrawTrace = {},
            onVetoClick = { _, _ -> },
            onRandomizeClick = { _, _ -> },
            onCountdownExpired = {},
            onDownloadClick = {},
            onDownloadPermissionDenied = {},
            onSetReplyTarget = {},
            onShowMessageInfo = {},
            onDismissMessageInfo = {},
            onDeleteMessage = {},
        )
    }
}
