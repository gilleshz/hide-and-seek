package fr.gshz.hideandseek.core.notification

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import dagger.hilt.android.qualifiers.ApplicationContext
import fr.gshz.hideandseek.MainActivity
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.i18n.resolveMessage
import fr.gshz.hideandseek.domain.repository.ChatEvent
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.launchIn
import kotlinx.coroutines.flow.onEach

@Singleton
class ChatNotifier @Inject constructor(
    @ApplicationContext private val context: Context,
    private val visibilityTracker: ChatVisibilityTracker,
) {

    private data class Stack(val lines: List<String>, val count: Int)

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private val pendingLines = ArrayDeque<String>()
    private var pendingCount = 0

    init {
        visibilityTracker.visibleChatGameUuid
            .onEach { visible -> if (visible != null) clear() }
            .launchIn(scope)
    }

    fun onChatEvent(event: ChatEvent, selfPlayerUuid: String, gameUuid: String) {
        val body = notifiableBody(event, selfPlayerUuid, gameUuid) ?: return
        val title = event.senderName ?: context.getString(R.string.chat_notification_system_title)
        val stack = appendLine(context.getString(R.string.chat_notification_line, title, body))
        post(title, body, stack, gameUuid)
    }

    fun onDismissed() {
        resetAccumulator()
        NotificationManagerCompat.from(context).cancel(CHAT_SUMMARY_NOTIFICATION_ID)
    }

    fun clear() {
        resetAccumulator()
        NotificationManagerCompat.from(context).cancel(CHAT_NOTIFICATION_ID)
        NotificationManagerCompat.from(context).cancel(CHAT_SUMMARY_NOTIFICATION_ID)
    }

    private fun notifiableBody(event: ChatEvent, selfPlayerUuid: String, gameUuid: String): String? {
        val suppressed = !ChatNotificationText.shouldNotify(
            event,
            selfPlayerUuid,
            visibilityTracker.visibleChatGameUuid.value,
            gameUuid,
        ) || !NotificationManagerCompat.from(context).areNotificationsEnabled()
        if (suppressed) return null
        val resolvedBody = resolveMessage(context, event.bodyKey, event.bodyArgs, event.body)
        return when (val line = ChatNotificationText.lineFor(event, resolvedBody)) {
            is ChatNotificationText.Line.Body -> line.text
            ChatNotificationText.Line.ImagePlaceholder ->
                context.getString(R.string.chat_notification_image_placeholder)
            ChatNotificationText.Line.Skip -> null
        }
    }

    private fun appendLine(line: String): Stack = synchronized(this) {
        pendingLines.addLast(line)
        if (pendingLines.size > MAX_INBOX_LINES) pendingLines.removeFirst()
        pendingCount++
        Stack(pendingLines.toList(), pendingCount)
    }

    private fun resetAccumulator() = synchronized(this) {
        pendingLines.clear()
        pendingCount = 0
    }

    // An explicit group opts out of system autogrouping, whose summary opens the launcher intent instead of chat.
    private fun post(title: String, body: String, stack: Stack, gameUuid: String) {
        ensureChannel()
        val manager = context.getSystemService(NotificationManager::class.java)
        manager.notify(CHAT_NOTIFICATION_ID, chatNotification(title, body, stack, gameUuid))
        manager.notify(CHAT_SUMMARY_NOTIFICATION_ID, summaryNotification(title, body, stack, gameUuid))
    }

    private fun chatNotification(title: String, body: String, stack: Stack, gameUuid: String): Notification {
        val builder = NotificationCompat.Builder(context, CHAT_CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_dialog_email)
            .setAutoCancel(true)
            .setGroup(CHAT_GROUP_KEY)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setContentIntent(chatPendingIntent(gameUuid))
            .setDeleteIntent(dismissPendingIntent())
        if (stack.count == 1) {
            builder.setContentTitle(title).setContentText(body)
        } else {
            val style = NotificationCompat.InboxStyle()
            stack.lines.forEach { style.addLine(it) }
            builder
                .setContentTitle(stackedTitle(stack))
                .setContentText(stack.lines.last())
                .setStyle(style)
                .setNumber(stack.count)
        }
        return builder.build()
    }

    private fun summaryNotification(title: String, body: String, stack: Stack, gameUuid: String): Notification =
        NotificationCompat.Builder(context, CHAT_CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_dialog_email)
            .setAutoCancel(true)
            .setGroup(CHAT_GROUP_KEY)
            .setGroupSummary(true)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setContentIntent(chatPendingIntent(gameUuid))
            .setDeleteIntent(dismissPendingIntent())
            .setContentTitle(if (stack.count == 1) title else stackedTitle(stack))
            .setContentText(if (stack.count == 1) body else stack.lines.last())
            .build()

    private fun stackedTitle(stack: Stack): String = context.resources.getQuantityString(
        R.plurals.chat_notification_new_messages,
        stack.count,
        stack.count,
    )

    private fun ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val channel = NotificationChannel(
            CHAT_CHANNEL_ID,
            context.getString(R.string.chat_notification_channel_name),
            NotificationManager.IMPORTANCE_DEFAULT,
        ).apply { description = context.getString(R.string.chat_notification_channel_description) }
        context.getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
    }

    private fun chatPendingIntent(gameUuid: String): PendingIntent {
        val intent = Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            .putExtra(EXTRA_FROM_NOTIFICATION, true)
            .putExtra(EXTRA_OPEN_CHAT_GAME_UUID, gameUuid)
        return PendingIntent.getActivity(
            context,
            0,
            intent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
    }

    private fun dismissPendingIntent(): PendingIntent = PendingIntent.getBroadcast(
        context,
        0,
        Intent(context, ChatNotificationDismissReceiver::class.java),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
    )

    companion object {
        const val EXTRA_FROM_NOTIFICATION = "fr.gshz.hideandseek.extra.FROM_NOTIFICATION"
        const val EXTRA_OPEN_CHAT_GAME_UUID = "fr.gshz.hideandseek.extra.OPEN_CHAT_GAME_UUID"
        private const val CHAT_CHANNEL_ID = "chat"
        private const val CHAT_GROUP_KEY = "fr.gshz.hideandseek.group.CHAT"
        private const val CHAT_NOTIFICATION_ID = 1003
        private const val CHAT_SUMMARY_NOTIFICATION_ID = 1004
        private const val MAX_INBOX_LINES = 5
    }
}
