package fr.gshz.hideandseek.feature.chat

import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.ChatMessageReader
import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.QuestionStatus
import fr.gshz.hideandseek.domain.model.Side
import java.time.OffsetDateTime

enum class DownloadOutcome { Saved, Failed }

/** How far an outgoing message has travelled: sent, read by some of the group, read by everyone. */
enum class ReadState { Sent, SomeRead, AllRead }

data class MessageInfoState(
    val messageUuid: String,
    val readers: List<ChatMessageReader> = emptyList(),
    val isLoading: Boolean = true,
    val failed: Boolean = false,
)

data class ChatUiState(
    val gameUuid: String = "",
    val gameName: String = "",
    val messages: List<ChatMessage> = emptyList(),
    val roster: Map<String, String> = emptyMap(),
    val selfPlayerUuid: String = "",
    val apiUrl: String = "",
    val isSending: Boolean = false,
    val sendError: ErrorType? = null,
    val side: Side? = null,
    val edition: Edition = Edition.Metric,
    val questionsByUuid: Map<String, AskedQuestion> = emptyMap(),
    val selfLocation: DeviceLocation? = null,
    val isRevealing: Boolean = false,
    val revealError: Boolean = false,
    val revealErrorKey: String? = null,
    val revealErrorArgs: Map<String, String>? = null,
    val isPlayingPowerup: Boolean = false,
    val powerupError: Boolean = false,
    val powerupErrorKey: String? = null,
    val powerupErrorArgs: Map<String, String>? = null,
    val downloadOutcome: DownloadOutcome? = null,
    val replyTarget: ChatMessage? = null,
    val readCursors: Map<String, String> = emptyMap(),
    val messageInfo: MessageInfoState? = null,
) {
    val selfDisplayName: String get() = roster[selfPlayerUuid] ?: selfPlayerUuid
    val isHider: Boolean get() = side == Side.Hider

    /**
     * The roster is the live source but can lag a player who joined after the fetch, so the
     * server-stamped name on the message covers that gap, then the raw uuid. Null is a system message.
     */
    fun senderNameOf(message: ChatMessage): String? =
        message.senderUuid?.let { uuid -> roster[uuid] ?: message.senderName ?: uuid }

    fun joinedQuestion(message: ChatMessage): AskedQuestion? = message.questionUuid?.let { questionsByUuid[it] }

    fun isPendingQuestion(message: ChatMessage): Boolean =
        message.isQuestion &&
            messages.none { it.replyToUuid == message.uuid } &&
            joinedQuestion(message)?.let { q ->
                q.revealedAt == null && q.status == QuestionStatus.Open
            } ?: false

    fun isTravelingThermometer(questionUuid: String): Boolean =
        questionsByUuid[questionUuid]?.let { q ->
            q.category == QuestionCategory.Thermometer &&
                q.revealDeadlineAt == null &&
                q.revealedAt == null &&
                q.status == QuestionStatus.Open
        } ?: false

    /** Non-null only for the photo questions a hider can answer by drawing on the map. */
    fun traceableTargetOf(questionUuid: String): PhotoTarget? =
        questionsByUuid[questionUuid]?.photoTarget?.takeIf { it.isTraceable }

    fun isPendingTravelingThermometer(message: ChatMessage): Boolean =
        message.questionUuid?.let { isTravelingThermometer(it) } == true &&
            messages.none { it.replyToUuid == message.uuid }

    fun isOwnMessage(message: ChatMessage): Boolean =
        message.senderUuid != null && message.senderUuid == selfPlayerUuid

    /**
     * Read receipts come from each player's read watermark, not per-message data, so the history
     * payload stays free of receipt bookkeeping. Leaving players keep their cursor but drop off
     * the roster; counting them would mark a message read by everyone before it is.
     */
    fun readerUuidsOf(message: ChatMessage): List<String> {
        val sentAt = parseIsoMillis(message.createdAt) ?: return emptyList()
        return readCursors.filter { (playerUuid, readUpTo) ->
            playerUuid != message.senderUuid &&
                playerUuid in roster &&
                (parseIsoMillis(readUpTo) ?: 0L) >= sentAt
        }.keys.toList()
    }

    fun readStateOf(message: ChatMessage): ReadState {
        val recipients = roster.keys.count { it != message.senderUuid }
        val readers = readerUuidsOf(message).size
        return when {
            readers == 0 -> ReadState.Sent
            readers >= recipients -> ReadState.AllRead
            else -> ReadState.SomeRead
        }
    }

    fun unreadRecipientNames(message: ChatMessage, readerUuids: Set<String>): List<String> =
        roster.filterKeys { it != message.senderUuid && it !in readerUuids }
            .values
            .sorted()
}

/**
 * Draw action of the shared image-source dialog: non-null only when a pending photo answer is
 * a traceable street question, so powerup and plain-attachment flows never get it.
 */
internal fun ChatUiState.drawTraceHandler(
    pendingPhotoAnswerUuid: String?,
    onDraw: (String) -> Unit,
): (() -> Unit)? = pendingPhotoAnswerUuid
    ?.takeIf { traceableTargetOf(it) != null }
    ?.let { questionUuid -> { onDraw(questionUuid) } }

internal fun parseIsoMillis(iso: String): Long? =
    runCatching { OffsetDateTime.parse(iso).toInstant().toEpochMilli() }.getOrNull()
