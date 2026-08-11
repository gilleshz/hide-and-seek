package fr.gshz.hideandseek.domain.model

data class ChatMessage(
    val uuid: String,
    val senderUuid: String?,
    val senderName: String? = null,
    val type: String,
    val body: String?,
    val bodyKey: String? = null,
    val bodyArgs: Map<String, String>? = null,
    val imageRef: String?,
    val createdAt: String,
    val questionUuid: String? = null,
    val replyToUuid: String? = null,
    val deleted: Boolean = false,
) {
    val isSystem: Boolean get() = type == "system"
    val isText: Boolean get() = type == "text"
    val isImage: Boolean get() = type == "image"
    val isQuestion: Boolean get() = type == "question"
    val isQuestionInfo: Boolean get() = type == "question_info"
    val isAnswer: Boolean get() = type == "answer"

    /** Matches the server rule: question and answer messages are game log, only chatter is retractable. */
    val isDeletable: Boolean get() = !deleted && (isText || isImage)

    /** Mirrors the server-side scrub, so a retracted message keeps no content locally either. */
    fun asDeleted() = copy(deleted = true, body = null, bodyKey = null, bodyArgs = null, imageRef = null)
}

/** How far a player has read the game's chat, as the timestamp of the newest message they saw. */
data class ChatReadCursor(
    val playerUuid: String,
    val playerName: String,
    val readUpTo: String?,
)

data class ChatMessageReader(
    val playerUuid: String,
    val playerName: String,
    val readAt: String,
)
