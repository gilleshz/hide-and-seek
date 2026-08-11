package fr.gshz.hideandseek.core.navigation

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.model.withoutLocationTopics
import fr.gshz.hideandseek.core.util.ERROR_KEY_PLAYER_LEFT
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.domain.repository.ConnectionRepository
import fr.gshz.hideandseek.domain.repository.GameRepository
import fr.gshz.hideandseek.domain.repository.SessionRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import retrofit2.HttpException

sealed interface SessionExpiredOutcome {
    data class Rejoined(val gameUuid: String) : SessionExpiredOutcome
    data class NeedsJoin(
        val gameUuid: String?,
        val errorKey: String?,
    ) : SessionExpiredOutcome
}

@HiltViewModel
class RootViewModel @Inject constructor(
    private val connectionRepository: ConnectionRepository,
    private val sessionRepository: SessionRepository,
    private val gameRepository: GameRepository,
    private val navigationRequestStore: NavigationRequestStore,
) : ViewModel() {

    private val _startDestination = MutableStateFlow<String?>(null)
    val startDestination: StateFlow<String?> = _startDestination.asStateFlow()

    val pendingChatGameUuid: StateFlow<String?> = navigationRequestStore.pendingChatGameUuid

    val sessionExpiredRequest: StateFlow<String?> = navigationRequestStore.sessionExpiredRequest

    init {
        viewModelScope.launch {
            val connection = connectionRepository.observeConnection().first()
            if (connection == null) {
                _startDestination.value = HideAndSeekDestinations.CONNECT
                return@launch
            }

            if (connectionRepository.accountCredential() == null) {
                _startDestination.value = HideAndSeekDestinations.CONNECT
                return@launch
            }

            val session = sessionRepository.observeSession().first()
            _startDestination.value = if (session != null) {
                HideAndSeekDestinations.lobbyRoute(session.gameUuid)
            } else {
                HideAndSeekDestinations.HOME
            }
        }
    }

    fun consumeChatRequest() {
        navigationRequestStore.consumeChatRequest()
    }

    /**
     * A server-side identity rejection wipes the session and tries to rejoin silently with the account
     * credential; when that fails (wrong password, game gone, offline) the user lands on the join screen.
     */
    @Suppress("SwallowedException")
    suspend fun handleSessionExpired(errorKey: String? = null): SessionExpiredOutcome {
        val gameUuid = sessionRepository.currentSession()?.gameUuid
        sessionRepository.clear()
        navigationRequestStore.consumeSessionExpired()
        val account = connectionRepository.accountCredential()
        if (account == null || errorKey == ERROR_KEY_PLAYER_LEFT || gameUuid == null) {
            return SessionExpiredOutcome.NeedsJoin(gameUuid, if (account == null) null else errorKey)
        }
        return try {
            val join = gameRepository.joinGame(gameUuid, account.name, account.password)
            sessionRepository.saveSession(
                PlayerSession(
                    gameUuid = join.gameUuid,
                    roundUuid = join.roundUuid,
                    playerUuid = join.playerUuid,
                    displayName = join.displayName,
                    mercureToken = join.mercureToken,
                    side = null,
                    topics = join.topics.withoutLocationTopics(),
                ),
            )
            SessionExpiredOutcome.Rejoined(join.gameUuid)
        } catch (e: HttpException) {
            SessionExpiredOutcome.NeedsJoin(gameUuid, e.serverErrorKey())
        } catch (e: IOException) {
            SessionExpiredOutcome.NeedsJoin(gameUuid, null)
        }
    }

    fun disconnect() {
        viewModelScope.launch {
            connectionRepository.disconnect()
            sessionRepository.clear()
        }
    }
}
