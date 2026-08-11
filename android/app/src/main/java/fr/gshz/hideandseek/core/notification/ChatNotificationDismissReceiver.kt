package fr.gshz.hideandseek.core.notification

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import dagger.hilt.android.AndroidEntryPoint
import javax.inject.Inject

@AndroidEntryPoint
class ChatNotificationDismissReceiver : BroadcastReceiver() {

    @Inject
    lateinit var chatNotifier: ChatNotifier

    override fun onReceive(context: Context, intent: Intent) {
        chatNotifier.onDismissed()
    }
}
