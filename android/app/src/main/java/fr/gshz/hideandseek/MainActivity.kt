package fr.gshz.hideandseek

import android.content.Intent
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import dagger.hilt.android.AndroidEntryPoint
import fr.gshz.hideandseek.core.data.SettingsStore
import fr.gshz.hideandseek.core.navigation.HideAndSeekNavHost
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.core.notification.ChatNotifier
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import javax.inject.Inject

/**
 * The single Activity that hosts all Compose UI.
 *
 * Modern Android apps use a single-Activity architecture: navigation between
 * screens happens inside Compose, not by launching new Activities.
 * [@AndroidEntryPoint] lets Hilt inject dependencies into this Activity.
 */
@AndroidEntryPoint
class MainActivity : AppCompatActivity() {

    @Inject
    lateinit var navigationRequestStore: NavigationRequestStore

    @Inject
    lateinit var settingsStore: SettingsStore

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        if (savedInstanceState == null) {
            handleChatIntent(intent)
        }
        setContent {
            val themeMode by settingsStore.themeMode.collectAsStateWithLifecycle(
                initialValue = "system",
            )
            AppTheme(themeMode = themeMode) {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background,
                ) {
                    HideAndSeekNavHost()
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleChatIntent(intent)
    }

    private fun handleChatIntent(intent: Intent?) {
        // Only our chat notification may force chat navigation; the activity is exported for the launcher.
        if (intent?.getBooleanExtra(ChatNotifier.EXTRA_FROM_NOTIFICATION, false) != true) return
        intent.getStringExtra(ChatNotifier.EXTRA_OPEN_CHAT_GAME_UUID)?.let { gameUuid ->
            intent.removeExtra(ChatNotifier.EXTRA_OPEN_CHAT_GAME_UUID)
            navigationRequestStore.requestChat(gameUuid)
        }
    }
}
