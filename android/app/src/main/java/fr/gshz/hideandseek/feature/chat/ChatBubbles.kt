@file:Suppress("TooManyFunctions")

package fr.gshz.hideandseek.feature.chat

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.spring
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Reply
import androidx.compose.material.icons.filled.Done
import androidx.compose.material.icons.filled.DoneAll
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import coil3.compose.AsyncImagePainter
import coil3.request.ImageRequest
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.i18n.resolveMessage
import fr.gshz.hideandseek.core.ui.formatDistance
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.core.ui.theme.extendedColors
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.cardEconomy
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlin.math.PI
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.roundToInt
import kotlin.math.sin
import kotlin.math.sqrt
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val MSG_MAX_WIDTH_DP = 280
private const val IMAGE_MAX_HEIGHT_DP = 320
private const val REPLY_SWIPE_THRESHOLD_DP = 72
private const val QUOTE_ACCENT_WIDTH_DP = 3
private const val QUOTE_THUMB_SIZE_DP = 36
private const val RECEIPT_ICON_SIZE_DP = 14
private const val DEFAULT_IMAGE_RATIO = 4f / 3f
private const val MILLIS_PER_SECOND = 1_000L
private const val SECONDS_PER_MINUTE = 60L
private const val EARTH_RADIUS_METERS = 6_371_000.0
private const val DEG_TO_RAD = PI / 180.0

private val messageTimeFormatter = DateTimeFormatter.ofPattern("HH:mm")

@Composable
internal fun ChatMessage.resolvedBody(): String? {
    if (bodyKey != null) {
        return resolveMessage(bodyKey, bodyArgs, body)
    }
    return body
}

@Suppress("LongParameterList")
@Composable
internal fun MessageBubble(
    message: ChatMessage,
    uiState: ChatUiState,
    onRevealClick: (String) -> Unit,
    onAnswerPhotoClick: (String) -> Unit,
    onPowerupClick: (String) -> Unit,
    onCountdownExpired: () -> Unit,
    onImageClick: (String) -> Unit,
    onReplySwipe: (() -> Unit)? = null,
    onQuoteClick: ((String) -> Unit)? = null,
    onLongPress: (() -> Unit)? = null,
) {
    when {
        message.isQuestion -> QuestionBubble(
            message, uiState, onRevealClick, onAnswerPhotoClick,
            onPowerupClick, onCountdownExpired, onReplySwipe, onLongPress,
        )
        message.isQuestionInfo -> QuestionInfoBubble(message, uiState, onPowerupClick, onLongPress)
        else -> RegularBubble(message, uiState, onImageClick, onReplySwipe, onQuoteClick, onLongPress)
    }
}

@Composable
private fun RegularBubble(
    message: ChatMessage,
    uiState: ChatUiState,
    onImageClick: (String) -> Unit,
    onReplySwipe: (() -> Unit)? = null,
    onQuoteClick: ((String) -> Unit)? = null,
    onLongPress: (() -> Unit)? = null,
) {
    val isOwn = message.senderUuid == uiState.selfPlayerUuid
    // Answers outrank ownership: the question/answer pairing is what you scan the log for.
    val color = when {
        message.isSystem -> MaterialTheme.colorScheme.surfaceVariant
        message.isAnswer -> extendedColors.answerContainer
        isOwn -> MaterialTheme.colorScheme.primaryContainer
        else -> MaterialTheme.colorScheme.secondaryContainer
    }
    BubbleFrame(
        isOwn = isOwn,
        color = color,
        onReplySwipe = if (!message.isSystem) onReplySwipe else null,
        onLongPress = onLongPress,
    ) {
        SenderLabel(uiState.senderNameOf(message))
        message.replyToUuid?.let { uuid ->
            QuotedReplyBlock(
                replyToUuid = uuid,
                uiState = uiState,
                onClick = onQuoteClick?.let { { it(uuid) } },
            )
        }
        if (message.imageRef != null) {
            ChatImageBubble(uiState.apiUrl, uiState.gameUuid, message.imageRef, onImageClick)
        }
        if (message.deleted) {
            DeletedBody()
        } else {
            message.resolvedBody()?.let { bodyText ->
                val font = if (message.isSystem) FontStyle.Italic else FontStyle.Normal
                Text(text = bodyText, style = MaterialTheme.typography.bodyMedium.copy(fontStyle = font))
            }
        }
        MessageFooter(message, uiState)
    }
}

@Suppress("LongParameterList")
@Composable
private fun QuestionBubble(
    message: ChatMessage,
    uiState: ChatUiState,
    onRevealClick: (String) -> Unit,
    onAnswerPhotoClick: (String) -> Unit,
    onPowerupClick: (String) -> Unit,
    onCountdownExpired: () -> Unit,
    onReplySwipe: (() -> Unit)? = null,
    onLongPress: (() -> Unit)? = null,
) {
    val isOwn = message.senderUuid == uiState.selfPlayerUuid
    val pending = uiState.isPendingQuestion(message)
    BubbleFrame(
        isOwn = isOwn,
        color = MaterialTheme.colorScheme.tertiaryContainer,
        onReplySwipe = if (!message.isSystem) onReplySwipe else null,
        onLongPress = onLongPress,
    ) {
        SenderLabel(uiState.senderNameOf(message))
        message.resolvedBody()?.let { Text(text = it, style = MaterialTheme.typography.bodyMedium) }
        QuestionSubtexts(message, uiState, pending, onCountdownExpired)
        if (uiState.isHider && pending) {
            HiderQuestionActions(
                message, uiState, onRevealClick, onAnswerPhotoClick, onPowerupClick,
            )
        }
        MessageFooter(message, uiState)
    }
}

@Composable
private fun HiderQuestionActions(
    message: ChatMessage,
    uiState: ChatUiState,
    onRevealClick: (String) -> Unit,
    onAnswerPhotoClick: (String) -> Unit,
    onPowerupClick: (String) -> Unit,
) {
    val question = uiState.joinedQuestion(message)
    val isPhotoQuestion = question?.category == QuestionCategory.Photos
    Row(
        horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
        modifier = Modifier.padding(top = Spacing.xs),
    ) {
        when {
            isPhotoQuestion -> Button(
                onClick = { message.questionUuid?.let(onAnswerPhotoClick) },
                enabled = !uiState.isRevealing,
                modifier = Modifier.weight(1f),
            ) { Text(stringResource(R.string.question_photo_answer_button)) }
            else -> Button(
                onClick = { message.questionUuid?.let(onRevealClick) },
                enabled = !uiState.isRevealing,
                modifier = Modifier.weight(1f),
            ) { Text(stringResource(R.string.question_reveal_button)) }
        }
        if (!uiState.isPlayingPowerup) {
            IconButton(onClick = { message.questionUuid?.let(onPowerupClick) }) {
                Icon(
                    imageVector = Icons.Default.MoreVert,
                    contentDescription = stringResource(R.string.question_powerup_button),
                )
            }
        }
    }
}

@Composable
private fun QuestionInfoBubble(
    message: ChatMessage,
    uiState: ChatUiState,
    onPowerupClick: (String) -> Unit,
    onLongPress: (() -> Unit)? = null,
) {
    val isOwn = message.senderUuid == uiState.selfPlayerUuid
    val color = if (isOwn) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.secondaryContainer
    BubbleFrame(isOwn = isOwn, color = color, onLongPress = onLongPress) {
        SenderLabel(uiState.senderNameOf(message))
        message.resolvedBody()?.let { Text(text = it, style = MaterialTheme.typography.bodyMedium) }
        val question = uiState.joinedQuestion(message)
        if (uiState.isHider && question != null) {
            SeekerDistanceLine(message, uiState, question)
        }
        if (uiState.isHider && !uiState.isPlayingPowerup && uiState.isPendingTravelingThermometer(message)) {
            Row(
                horizontalArrangement = Arrangement.End,
                modifier = Modifier.fillMaxWidth().padding(top = Spacing.xs),
            ) {
                IconButton(onClick = { message.questionUuid?.let(onPowerupClick) }) {
                    Icon(
                        imageVector = Icons.Default.MoreVert,
                        contentDescription = stringResource(R.string.question_powerup_button),
                    )
                }
            }
        }
        MessageFooter(message, uiState)
    }
}

@Composable
private fun QuestionSubtexts(
    message: ChatMessage,
    uiState: ChatUiState,
    pending: Boolean,
    onCountdownExpired: () -> Unit,
) {
    val question = uiState.joinedQuestion(message)
    question?.transitLineLabel?.let { SubtextLine(stringResource(R.string.chat_transit_line, it)) }
    if (question?.revealedAt != null) {
        CardsLine(question.category, question.repeatCount)
        return
    }
    if (pending) {
        QuestionCountdown(question?.revealDeadlineAt, onCountdownExpired)
    }
    if (uiState.isHider && question != null) {
        SeekerDistanceLine(message, uiState, question)
    }
}

@Composable
private fun QuestionCountdown(deadlineIso: String?, onExpired: () -> Unit) {
    val deadlineMillis = deadlineIso?.let { iso -> remember(iso) { parseIsoMillis(iso) } } ?: return
    var remainingSecs by remember(deadlineIso) { mutableLongStateOf(remainingSeconds(deadlineMillis)) }
    LaunchedEffect(deadlineIso) {
        while (remainingSecs > 0) {
            delay(MILLIS_PER_SECOND)
            remainingSecs = remainingSeconds(deadlineMillis)
        }
        onExpired()
    }
    if (remainingSecs > 0) {
        SubtextLine(stringResource(R.string.chat_answer_window, formatCountdown(remainingSecs)))
    }
}

@Composable
private fun SeekerDistanceLine(message: ChatMessage, uiState: ChatUiState, question: AskedQuestion) {
    val self = uiState.selfLocation
    val point = seekerPointFor(message, question)
    if (self == null || point == null) return
    val meters = haversineMeters(self.latitude, self.longitude, point.first, point.second)
    val name = uiState.senderNameOf(message).orEmpty()
    SubtextLine(stringResource(R.string.chat_seeker_distance, name, formatDistance(meters, uiState.edition)))
}

@Composable
private fun CardsLine(category: QuestionCategory, repeatCount: Int = 1) {
    val (draw, keep) = cardEconomy.getValue(category)
    val text = when {
        repeatCount > 1 -> stringResource(R.string.chat_cards_line_repeat, draw, keep, repeatCount)
        draw == 1 -> stringResource(R.string.chat_cards_line_single)
        else -> stringResource(R.string.chat_cards_line, draw, keep)
    }
    SubtextLine(text)
}

/**
 * The one-line stand-in for a quoted message: its text when it has any, "Photo" for a
 * bare image, and the unavailable notice only when the original is truly not in the list.
 * Everything that stands in for words rather than being them is italic, the tombstone included.
 */
@Composable
internal fun ChatMessage?.quotePreview(): QuotePreview {
    val body = this?.resolvedBody()?.takeIf { it.isNotBlank() }
    return when {
        this?.deleted == true -> QuotePreview(stringResource(R.string.chat_message_deleted), FontStyle.Italic)
        body != null -> QuotePreview(body, FontStyle.Normal)
        this?.imageRef != null -> QuotePreview(stringResource(R.string.chat_quote_photo), FontStyle.Italic)
        else -> QuotePreview(stringResource(R.string.chat_quote_missing), FontStyle.Italic)
    }
}

internal data class QuotePreview(val text: String, val fontStyle: FontStyle)

@Composable
private fun QuotedReplyBlock(
    replyToUuid: String,
    uiState: ChatUiState,
    onClick: (() -> Unit)? = null,
) {
    val original = uiState.messages.firstOrNull { it.uuid == replyToUuid }
    Surface(
        color = MaterialTheme.colorScheme.surfaceVariant,
        shape = MaterialTheme.shapes.small,
        modifier = Modifier
            .padding(bottom = Spacing.xs)
            .then(if (onClick != null) Modifier.clickable { onClick() } else Modifier),
    ) {
        Row(
            modifier = Modifier.height(IntrinsicSize.Min),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                modifier = Modifier
                    .width(QUOTE_ACCENT_WIDTH_DP.dp)
                    .fillMaxHeight()
                    .background(MaterialTheme.colorScheme.primary),
            )
            val preview = original.quotePreview()
            original?.imageRef?.let { imageRef ->
                QuoteThumbnail(uiState.apiUrl, uiState.gameUuid, imageRef)
            }
            Text(
                text = preview.text,
                style = MaterialTheme.typography.bodySmall.copy(fontStyle = preview.fontStyle),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.padding(horizontal = Spacing.sm, vertical = Spacing.xs),
            )
        }
    }
}

@Composable
internal fun QuoteThumbnail(apiUrl: String, gameUuid: String, imageRef: String) {
    AsyncImage(
        model = ImageRequest.Builder(LocalContext.current)
            .data("$apiUrl/api/games/$gameUuid/chat/image/$imageRef")
            .build(),
        contentDescription = null,
        contentScale = ContentScale.Crop,
        modifier = Modifier
            .padding(start = Spacing.xs)
            .size(QUOTE_THUMB_SIZE_DP.dp)
            .clip(MaterialTheme.shapes.extraSmall),
    )
}

@Suppress("LongMethod")
@Composable
private fun BubbleFrame(
    isOwn: Boolean,
    color: Color,
    onReplySwipe: (() -> Unit)? = null,
    onLongPress: (() -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    val density = LocalDensity.current
    val thresholdPx = with(density) { REPLY_SWIPE_THRESHOLD_DP.dp.toPx() }
    val offsetX = remember { Animatable(0f) }
    val scope = rememberCoroutineScope()
    val layoutDirection = LocalLayoutDirection.current
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = if (isOwn) Arrangement.End else Arrangement.Start,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (onReplySwipe != null && !isOwn && offsetX.value > 0f) {
            val iconAlpha = (offsetX.value / thresholdPx).coerceIn(0f, 1f)
            Icon(
                imageVector = Icons.AutoMirrored.Filled.Reply,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = iconAlpha),
                modifier = Modifier
                    .padding(start = Spacing.sm)
                    .size(24.dp)
                    .alpha(iconAlpha),
            )
        }
        Surface(
            color = color,
            shape = MaterialTheme.shapes.medium,
            modifier = Modifier
                .widthIn(max = MSG_MAX_WIDTH_DP.dp)
                .then(
                    if (onLongPress != null) {
                        // Its own handler, so a long press and the reply swipe stay independent.
                        Modifier.pointerInput(onLongPress) {
                            detectTapGestures(onLongPress = { onLongPress() })
                        }
                    } else Modifier
                )
                .offset {
                    val x = if (layoutDirection == LayoutDirection.Ltr)
                        offsetX.value.roundToInt()
                    else
                        -offsetX.value.roundToInt()
                    IntOffset(x, 0)
                }
                .then(
                    if (onReplySwipe != null) {
                        Modifier.pointerInput(Unit) {
                            var currentOffset = 0f
                            detectHorizontalDragGestures(
                                onDragStart = {
                                    currentOffset = 0f
                                    scope.launch { offsetX.snapTo(0f) }
                                },
                                onHorizontalDrag = { _, dragAmount ->
                                    currentOffset = (currentOffset + dragAmount)
                                        .coerceIn(0f, thresholdPx)
                                    scope.launch { offsetX.snapTo(currentOffset) }
                                },
                                onDragEnd = {
                                    if (currentOffset >= thresholdPx) onReplySwipe()
                                    scope.launch { offsetX.animateTo(0f, spring()) }
                                },
                                onDragCancel = {
                                    scope.launch { offsetX.animateTo(0f, spring()) }
                                },
                            )
                        }
                    } else Modifier
                ),
        ) {
            Column(
                modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
                content = content,
            )
        }
    }
}

@Composable
private fun SenderLabel(senderName: String?) {
    Text(
        text = senderName ?: stringResource(R.string.chat_sender_system),
        style = MaterialTheme.typography.labelMedium,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
    Spacer(modifier = Modifier.height(Spacing.xs))
}

@Composable
private fun DeletedBody() {
    Text(
        text = stringResource(R.string.chat_message_deleted),
        style = MaterialTheme.typography.bodyMedium.copy(fontStyle = FontStyle.Italic),
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
}

@Composable
private fun MessageFooter(message: ChatMessage, uiState: ChatUiState) {
    Spacer(modifier = Modifier.height(2.dp))
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
    ) {
        Text(
            text = formatMessageTime(message.createdAt),
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        if (uiState.isOwnMessage(message)) {
            ReadIndicator(uiState.readStateOf(message))
        }
    }
}

@Composable
private fun ReadIndicator(state: ReadState) {
    val description = when (state) {
        ReadState.Sent -> R.string.chat_receipt_sent
        ReadState.SomeRead -> R.string.chat_receipt_some_read
        ReadState.AllRead -> R.string.chat_receipt_all_read
    }
    Icon(
        imageVector = if (state == ReadState.Sent) Icons.Default.Done else Icons.Default.DoneAll,
        contentDescription = stringResource(description),
        tint = if (state == ReadState.AllRead) {
            MaterialTheme.colorScheme.primary
        } else {
            MaterialTheme.colorScheme.onSurfaceVariant
        },
        modifier = Modifier.size(RECEIPT_ICON_SIZE_DP.dp),
    )
}

@Composable
private fun SubtextLine(text: String) {
    Spacer(modifier = Modifier.height(Spacing.xs))
    Text(text = text, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
}

@Composable
internal fun ChatImageBubble(
    apiUrl: String,
    gameUuid: String,
    imageRef: String,
    onImageClick: (String) -> Unit,
) {
    val context = LocalContext.current
    val imageUrl = "$apiUrl/api/games/$gameUuid/chat/image/$imageRef"
    var ratio by remember { mutableFloatStateOf(DEFAULT_IMAGE_RATIO) }
    var loading by remember { mutableStateOf(true) }
    Box(contentAlignment = Alignment.Center) {
        AsyncImage(
            model = ImageRequest.Builder(context).data(imageUrl).build(),
            contentDescription = stringResource(R.string.chat_image_content_description),
            onState = { state ->
                when (state) {
                    is AsyncImagePainter.State.Success -> {
                        val intrinsic = state.painter.intrinsicSize
                        if (intrinsic.height > 0f) {
                            ratio = intrinsic.width / intrinsic.height
                        }
                        loading = false
                    }
                    is AsyncImagePainter.State.Error -> {
                        loading = false
                    }
                    else -> {}
                }
            },
            modifier = Modifier
                .then(if (loading) {
                    Modifier.fillMaxWidth().heightIn(min = 120.dp)
                } else {
                    Modifier
                })
                .padding(top = Spacing.xs)
                .heightIn(max = IMAGE_MAX_HEIGHT_DP.dp)
                .aspectRatio(ratio, matchHeightConstraintsFirst = ratio < 1f)
                .clip(MaterialTheme.shapes.small)
                .clickable(enabled = !loading) { onImageClick(imageUrl) },
            contentScale = ContentScale.Fit,
        )
        if (loading) {
            CircularProgressIndicator(
                modifier = Modifier.size(32.dp),
                strokeWidth = 2.dp,
            )
        }
    }
}

private fun formatMessageTime(iso: String): String =
    runCatching {
        OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(messageTimeFormatter)
    }.getOrDefault(iso)

private fun remainingSeconds(deadlineMillis: Long?): Long =
    if (deadlineMillis == null) 0L
    else ((deadlineMillis - System.currentTimeMillis()) / MILLIS_PER_SECOND).coerceAtLeast(0L)

private fun formatCountdown(totalSeconds: Long): String =
    "%d:%02d".format(totalSeconds / SECONDS_PER_MINUTE, totalSeconds % SECONDS_PER_MINUTE)

private fun seekerPointFor(message: ChatMessage, question: AskedQuestion): Pair<Double, Double>? = when {
    question.category == QuestionCategory.Radar -> pointOrNull(question.seekerLat, question.seekerLng)
    question.category != QuestionCategory.Thermometer -> null
    message.isQuestionInfo -> pointOrNull(question.startLat, question.startLng)
    else -> pointOrNull(question.endLat, question.endLng)
}

private fun pointOrNull(lat: Double?, lng: Double?): Pair<Double, Double>? =
    if (lat != null && lng != null) lat to lng else null

private fun haversineMeters(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Double {
    val dLat = (lat2 - lat1) * DEG_TO_RAD
    val dLng = (lng2 - lng1) * DEG_TO_RAD
    val a = sin(dLat / 2.0).let { it * it } +
        cos(lat1 * DEG_TO_RAD) * cos(lat2 * DEG_TO_RAD) *
        sin(dLng / 2.0).let { it * it }
    return EARTH_RADIUS_METERS * 2.0 * atan2(sqrt(a), sqrt(1.0 - a))
}
