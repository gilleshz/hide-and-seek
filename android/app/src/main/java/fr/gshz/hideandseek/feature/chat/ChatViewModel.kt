package fr.gshz.hideandseek.feature.chat

import android.util.Log
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.GameStateCache
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.core.navigation.TraceRequest
import fr.gshz.hideandseek.core.notification.ChatVisibilityTracker
import fr.gshz.hideandseek.core.util.isSessionExpired
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.data.ImageSaver
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.repository.ChatRepository
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.domain.repository.LocationRepository
import fr.gshz.hideandseek.domain.repository.QuestionRepository
import fr.gshz.hideandseek.domain.repository.SessionRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import retrofit2.HttpException

@HiltViewModel
@Suppress("LongParameterList", "TooManyFunctions")
class ChatViewModel @Inject constructor(
    private val chatRepository: ChatRepository,
    private val sessionRepository: SessionRepository,
    private val connectionStore: ConnectionStore,
    private val gameEventRepository: GameEventRepository,
    private val questionRepository: QuestionRepository,
    private val locationRepository: LocationRepository,
    private val chatVisibilityTracker: ChatVisibilityTracker,
    private val gameStateCache: GameStateCache,
    private val imageSaver: ImageSaver,
    private val navigationRequestStore: NavigationRequestStore,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val gameUuid: String = checkNotNull(savedStateHandle[HideAndSeekDestinations.CHAT_ARG])

    private val gameName = MutableStateFlow("")
    private val roster = MutableStateFlow<Map<String, String>>(emptyMap())
    private val selfPlayerUuid = MutableStateFlow("")
    private val apiUrl = MutableStateFlow("")
    private val sendInteraction = MutableStateFlow(SendInteraction())

    private val side = MutableStateFlow<Side?>(null)
    private val edition = MutableStateFlow(Edition.Metric)
    private val questionsByUuid = MutableStateFlow<Map<String, AskedQuestion>>(emptyMap())
    private val selfLocation = MutableStateFlow<DeviceLocation?>(null)
    private val questionActions = MutableStateFlow(QuestionActionInteraction())
    private val downloadOutcome = MutableStateFlow<DownloadOutcome?>(null)
    private val replyTarget = MutableStateFlow<ChatMessage?>(null)
    private val readCursors = MutableStateFlow<Map<String, String>>(emptyMap())
    private val messageInfo = MutableStateFlow<MessageInfoState?>(null)
    private var roundUuid: String? = null
    private var lastReportedReadUuid: String? = null

    private val rosterRefreshes = MutableSharedFlow<Unit>(extraBufferCapacity = 1)
    private val rosterHealAttempted = mutableSetOf<String>()

    private val _messages = MutableStateFlow<List<ChatMessage>>(emptyList())
    private val messages: StateFlow<List<ChatMessage>> = _messages

    private val baseState = combine(
        gameName, messages, roster, selfPlayerUuid, apiUrl,
    ) { name, msgList, rosterMap, selfUuid, url ->
        ChatBaseState(name, msgList, rosterMap, selfUuid, url)
    }

    private val questionState = combine(
        side, edition, questionsByUuid, selfLocation, questionActions,
    ) { sideValue, editionValue, questions, location, actions ->
        QuestionJoinState(sideValue, editionValue, questions, location, actions)
    }

    private val receiptState = combine(
        downloadOutcome, replyTarget, readCursors, messageInfo,
    ) { download, reply, cursors, info ->
        ReceiptJoinState(download, reply, cursors, info)
    }

    val uiState: StateFlow<ChatUiState> = combine(
        baseState, sendInteraction, questionState, receiptState,
    ) { base, send, question, receipt ->
        ChatUiState(
            gameUuid = gameUuid,
            gameName = base.name,
            messages = base.msgList,
            roster = base.rosterMap,
            selfPlayerUuid = base.selfUuid,
            apiUrl = base.url,
            isSending = send.isSending,
            sendError = send.error,
            side = question.side,
            edition = question.edition,
            questionsByUuid = question.questions,
            selfLocation = question.location,
            isRevealing = question.actions.isRevealing,
            revealError = question.actions.revealError,
            revealErrorKey = question.actions.revealErrorKey,
            revealErrorArgs = question.actions.revealErrorArgs,
            isPlayingPowerup = question.actions.isPlayingPowerup,
            powerupError = question.actions.powerupError,
            powerupErrorKey = question.actions.powerupErrorKey,
            powerupErrorArgs = question.actions.powerupErrorArgs,
            downloadOutcome = receipt.download,
            replyTarget = receipt.reply,
            readCursors = receipt.cursors,
            messageInfo = receipt.info,
        )
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), ChatUiState())

    init {
        loadGameInfo()
        viewModelScope.launch { fetchHistory() }
        viewModelScope.launch { fetchReadCursors() }
        observeSseEvents()
        observeChatReadEvents()
        observeChatDeletedEvents()
        observeRosterRefreshes()
        observeRosterEvents()
        observeReconnects()
    }

    fun onScreenResumed() {
        chatVisibilityTracker.onChatVisible(gameUuid)
        viewModelScope.launch { reportReadIfVisible() }
    }

    fun onScreenPaused() = chatVisibilityTracker.onChatHidden()

    fun refresh() {
        viewModelScope.launch { fetchHistory() }
        viewModelScope.launch { fetchQuestions() }
        viewModelScope.launch { fetchReadCursors() }
    }

    fun sendMessage(body: String, replyToUuid: String? = null) {
        if (body.isBlank()) return
        viewModelScope.launch {
            val playerUuid = selfPlayerUuid.value
            if (playerUuid.isBlank()) return@launch
            sendInteraction.update { it.copy(isSending = true) }
            runApiCall(
                block = {
                    val sent = chatRepository.postChatMessage(gameUuid, playerUuid, body, replyToUuid)
                    // The Mercure echo may land before the POST returns; same dedup as the SSE path.
                    _messages.update { current ->
                        if (current.any { it.uuid == sent.uuid }) current else current + sent
                    }
                    sendInteraction.update { it.copy(isSending = false) }
                    replyTarget.value = null
                },
                logMessage = "Failed to send chat message",
                onError = { error, _, _ ->
                    sendInteraction.update { it.copy(isSending = false, error = error) }
                    replyTarget.value = null
                },
            )
        }
    }

    fun setReplyTarget(message: ChatMessage?) {
        replyTarget.value = message
    }

    fun openMessageInfo(messageUuid: String) {
        messageInfo.value = MessageInfoState(messageUuid = messageUuid)
        viewModelScope.launch {
            runApiCall(
                block = {
                    val readers = chatRepository.getChatMessageReaders(gameUuid, messageUuid)
                    messageInfo.update { current ->
                        current?.takeIf { it.messageUuid == messageUuid }
                            ?.copy(readers = readers, isLoading = false)
                            ?: current
                    }
                },
                logMessage = "Failed to load message info",
                onError = { _, _, _ -> markMessageInfoFailed(messageUuid) },
            )
        }
    }

    fun closeMessageInfo() {
        messageInfo.value = null
    }

    fun deleteMessage(messageUuid: String) {
        viewModelScope.launch {
            val playerUuid = selfPlayerUuid.value
            if (playerUuid.isBlank()) return@launch
            runApiCall(
                block = {
                    chatRepository.deleteChatMessage(gameUuid, playerUuid, messageUuid)
                    applyDeletion(messageUuid)
                },
                logMessage = "Failed to delete chat message",
                onError = { error, _, _ -> sendInteraction.update { it.copy(error = error) } },
            )
        }
    }

    private fun markMessageInfoFailed(messageUuid: String) {
        messageInfo.update { current ->
            current?.takeIf { it.messageUuid == messageUuid }?.copy(isLoading = false, failed = true) ?: current
        }
    }

    @Suppress("TooGenericExceptionCaught")
    fun sendImage(imageUri: String, caption: String?, replyToUuid: String? = null) {
        viewModelScope.launch {
            val playerUuid = selfPlayerUuid.value
            if (playerUuid.isBlank()) return@launch
            sendInteraction.update { it.copy(isSending = true, error = null) }
            replyTarget.value = null
            try {
                runApiCall(
                    block = {
                        val sent = chatRepository.postChatImage(gameUuid, playerUuid, imageUri, caption, replyToUuid)
                        _messages.update { current ->
                            if (current.any { it.uuid == sent.uuid }) current else current + sent
                        }
                        sendInteraction.update { it.copy(isSending = false) }
                    },
                    logMessage = "Failed to send chat image (I/O)",
                    httpLogMessage = "Failed to send chat image (HTTP)",
                    onError = { error, _, _ ->
                        sendInteraction.update { it.copy(isSending = false, error = error) }
                    },
                )
            } catch (e: SecurityException) {
                Log.w(TAG, "Failed to read chat image (permission)", e)
                sendInteraction.update { it.copy(isSending = false, error = ErrorType.Unknown) }
            } catch (e: Exception) {
                Log.w(TAG, "Failed to send chat image (unexpected)", e)
                sendInteraction.update { it.copy(isSending = false, error = ErrorType.Unknown) }
            }
        }
    }

    fun dismissSendError() {
        sendInteraction.update { it.copy(error = null) }
    }

    @Suppress("TooGenericExceptionCaught")
    fun revealQuestion(questionUuid: String) {
        viewModelScope.launch {
            val playerUuid = selfPlayerUuid.value
            if (playerUuid.isBlank()) return@launch
            questionActions.update { it.copy(isRevealing = true) }
            try {
                runApiCall(
                    block = {
                        questionRepository.revealQuestion(questionUuid, playerUuid)
                        refreshQuestionsAndHistory()
                        questionActions.update { it.copy(isRevealing = false) }
                    },
                    logMessage = "Failed to reveal question",
                    httpLogMessage = "Reveal rejected, lost the race to a teammate or the deadline, resyncing",
                    onError = { error, key, args ->
                        if (error == ErrorType.Network) {
                            questionActions.update { it.copy(isRevealing = false, revealError = true) }
                        } else {
                            refreshQuestionsAndHistory()
                            questionActions.update { it.copyWithRevealRace(key, args) }
                        }
                    },
                )
            } catch (e: Exception) {
                Log.w(TAG, "Failed to reveal question (unexpected)", e)
                questionActions.update { it.copy(isRevealing = false, revealError = true) }
            }
        }
    }

    @Suppress("TooGenericExceptionCaught")
    fun revealPhotoQuestion(questionUuid: String, imageUri: String) {
        viewModelScope.launch {
            val playerUuid = selfPlayerUuid.value
            if (playerUuid.isBlank()) return@launch
            sendInteraction.update { it.copy(isSending = true) }
            questionActions.update { it.copy(isRevealing = true) }
            try {
                runApiCall(
                    block = {
                        questionRepository.revealPhotoQuestion(questionUuid, playerUuid, imageUri)
                        refreshQuestionsAndHistory()
                        sendInteraction.update { it.copy(isSending = false) }
                        questionActions.update { it.copy(isRevealing = false) }
                    },
                    logMessage = "Failed to answer photo question",
                    httpLogMessage = "Photo answer rejected, resyncing",
                    onError = { error, key, args ->
                        if (error == ErrorType.Network) {
                            sendInteraction.update { it.copy(isSending = false) }
                            questionActions.update { it.copy(isRevealing = false, revealError = true) }
                        } else {
                            refreshQuestionsAndHistory()
                            sendInteraction.update { it.copy(isSending = false) }
                            questionActions.update { it.copyWithRevealRace(key, args) }
                        }
                    },
                )
            } catch (e: Exception) {
                Log.w(TAG, "Failed to answer photo question (unexpected)", e)
                sendInteraction.update { it.copy(isSending = false) }
                questionActions.update { it.copy(isRevealing = false, revealError = true) }
            }
        }
    }

    /**
     * Hands a traced-streets question over to the map. Stored synchronously so the request is
     * parked before the map screen is reached.
     */
    fun requestTrace(questionUuid: String) {
        val target = uiState.value.traceableTargetOf(questionUuid) ?: return
        navigationRequestStore.requestTrace(TraceRequest(gameUuid, questionUuid, target))
    }

    fun onCountdownExpired() {
        // Refetching triggers the backend's lazy auto-reveal, then history picks up the answer.
        viewModelScope.launch { refreshQuestionsAndHistory() }
    }

    fun dismissRevealError() {
        questionActions.update { it.copy(revealError = false, revealErrorKey = null, revealErrorArgs = null) }
    }

    @Suppress("TooGenericExceptionCaught")
    fun vetoQuestion(questionUuid: String, cardPhotoUri: String) {
        viewModelScope.launch {
            val playerUuid = selfPlayerUuid.value
            if (playerUuid.isBlank()) return@launch
            questionActions.update { it.copy(isPlayingPowerup = true) }
            try {
                runApiCall(
                    block = {
                        questionRepository.vetoQuestion(questionUuid, playerUuid, cardPhotoUri)
                        refreshQuestionsAndHistory()
                        questionActions.update { it.copy(isPlayingPowerup = false) }
                    },
                    logMessage = "Failed to veto question",
                    httpLogMessage = "Veto rejected, resyncing",
                    onError = { error, key, args ->
                        if (error == ErrorType.Network) {
                            questionActions.update { it.copy(isPlayingPowerup = false, powerupError = true) }
                        } else {
                            refreshQuestionsAndHistory()
                            questionActions.update { it.copyWithPowerupError(key, args) }
                        }
                    },
                )
            } catch (e: Exception) {
                Log.w(TAG, "Failed to veto question (unexpected)", e)
                questionActions.update { it.copy(isPlayingPowerup = false, powerupError = true) }
            }
        }
    }

    @Suppress("TooGenericExceptionCaught")
    fun randomizeQuestion(questionUuid: String, cardPhotoUri: String) {
        viewModelScope.launch {
            val playerUuid = selfPlayerUuid.value
            if (playerUuid.isBlank()) return@launch
            questionActions.update { it.copy(isPlayingPowerup = true) }
            try {
                runApiCall(
                    block = {
                        questionRepository.randomizeQuestion(questionUuid, playerUuid, cardPhotoUri)
                        refreshQuestionsAndHistory()
                        questionActions.update { it.copy(isPlayingPowerup = false) }
                    },
                    logMessage = "Failed to randomize question",
                    httpLogMessage = "Randomize rejected, resyncing",
                    onError = { error, key, args ->
                        if (error == ErrorType.Network) {
                            questionActions.update { it.copy(isPlayingPowerup = false, powerupError = true) }
                        } else {
                            refreshQuestionsAndHistory()
                            questionActions.update { it.copyWithPowerupError(key, args) }
                        }
                    },
                )
            } catch (e: Exception) {
                Log.w(TAG, "Failed to randomize question (unexpected)", e)
                questionActions.update { it.copy(isPlayingPowerup = false, powerupError = true) }
            }
        }
    }

    fun dismissPowerupError() {
        questionActions.update {
            it.copy(powerupError = false, powerupErrorKey = null, powerupErrorArgs = null)
        }
    }

    fun downloadImage(url: String) {
        viewModelScope.launch {
            downloadOutcome.value = if (imageSaver.save(url).isSuccess) {
                DownloadOutcome.Saved
            } else {
                DownloadOutcome.Failed
            }
        }
    }

    fun onDownloadPermissionDenied() {
        downloadOutcome.value = DownloadOutcome.Failed
    }

    fun dismissDownloadOutcome() {
        downloadOutcome.value = null
    }

    private fun loadGameInfo() {
        viewModelScope.launch {
            val session = sessionRepository.currentSession()
            selfPlayerUuid.value = session?.playerUuid ?: ""
            roundUuid = session?.roundUuid
            side.value = Side.entries.firstOrNull { it.wireValue == session?.side }
            apiUrl.value = connectionStore.current()?.apiUrl ?: ""
            if (side.value == Side.Hider) trackSelfLocation()
            fetchQuestions()
            runApiCall(
                block = {
                    val game = gameStateCache.getGameSummary(gameUuid)
                    gameName.value = game.name
                    edition.value = game.edition
                    roster.value = gameStateCache.getRoster(gameUuid).associate { it.uuid to it.displayName }
                },
                logMessage = "Failed to load game info for chat",
            )
        }
    }

    private fun trackSelfLocation() {
        viewModelScope.launch {
            val oneShot = try {
                locationRepository.getCurrentLocation()
            } catch (e: SecurityException) {
                Log.w(TAG, "Location permission missing for chat distance subtext", e)
                null
            }
            if (oneShot != null && selfLocation.value == null) selfLocation.value = oneShot
        }
        viewModelScope.launch {
            locationRepository.lastKnownLocation.collect { location ->
                if (location != null) selfLocation.value = location
            }
        }
    }

    private data class ChatBaseState(
        val name: String,
        val msgList: List<ChatMessage>,
        val rosterMap: Map<String, String>,
        val selfUuid: String,
        val url: String,
    )

    private data class SendInteraction(
        val isSending: Boolean = false,
        val error: ErrorType? = null,
    )

    private data class QuestionActionInteraction(
        val isRevealing: Boolean = false,
        val revealError: Boolean = false,
        val revealErrorKey: String? = null,
        val revealErrorArgs: Map<String, String>? = null,
        val isPlayingPowerup: Boolean = false,
        val powerupError: Boolean = false,
        val powerupErrorKey: String? = null,
        val powerupErrorArgs: Map<String, String>? = null,
    ) {
        fun copyWithPowerupError(key: String?, args: Map<String, String>?): QuestionActionInteraction = copy(
            isPlayingPowerup = false,
            powerupError = key == null,
            powerupErrorKey = key,
            powerupErrorArgs = args,
        )

        /**
         * A rejected reveal means the answer is already out (teammate or deadline) and the resync
         * brings it in. revealError stays untouched: an unexplained rejection has fixed itself.
         */
        fun copyWithRevealRace(key: String?, args: Map<String, String>?): QuestionActionInteraction = copy(
            isRevealing = false,
            revealErrorKey = key,
            revealErrorArgs = args,
        )
    }

    private data class ReceiptJoinState(
        val download: DownloadOutcome?,
        val reply: ChatMessage?,
        val cursors: Map<String, String>,
        val info: MessageInfoState?,
    )

    private data class QuestionJoinState(
        val side: Side?,
        val edition: Edition,
        val questions: Map<String, AskedQuestion>,
        val location: DeviceLocation?,
        val actions: QuestionActionInteraction,
    )

    /** Keeps a watermark monotonic: reports and echoes can arrive out of order. */
    private fun Map<String, String>.advance(playerUuid: String, readUpTo: String): Map<String, String> {
        val known = this[playerUuid]?.let { parseIsoMillis(it) } ?: Long.MIN_VALUE
        val candidate = parseIsoMillis(readUpTo) ?: return this
        return if (candidate > known) this + (playerUuid to readUpTo) else this
    }

    private suspend fun fetchHistory() {
        runApiCall(
            block = {
                val fetched = chatRepository.getChatMessages(gameUuid)
                // Merge instead of replace: an SSE message appended while the GET was in flight must survive.
                _messages.update { current ->
                    val fetchedUuids = fetched.map { it.uuid }.toSet()
                    fetched + current.filter { it.uuid !in fetchedUuids }
                }
            },
            logMessage = "Failed to load chat history",
        )
        reportReadIfVisible()
    }

    private suspend fun fetchReadCursors() {
        runApiCall(
            block = {
                readCursors.update { current ->
                    chatRepository.getChatReadCursors(gameUuid)
                        .mapNotNull { cursor -> cursor.readUpTo?.let { cursor.playerUuid to it } }
                        .fold(current) { merged, (playerUuid, readUpTo) -> merged.advance(playerUuid, readUpTo) }
                }
            },
            logMessage = "Failed to load chat read cursors",
        )
    }

    /**
     * Reports the newest visible message as read, once per message.
     *
     * Reports are only worth sending while the conversation is actually on screen: the tracker is
     * the same one that suppresses chat notifications, so the two can never disagree about whether
     * the player is looking at the chat.
     */
    private suspend fun reportReadIfVisible() {
        if (chatVisibilityTracker.visibleChatGameUuid.value != gameUuid) return
        val playerUuid = selfPlayerUuid.value
        val newest = messages.value.lastOrNull()
        if (playerUuid.isBlank() || newest == null || newest.uuid == lastReportedReadUuid) return
        lastReportedReadUuid = newest.uuid
        runApiCall(
            block = {
                val cursor = chatRepository.markChatRead(gameUuid, playerUuid, newest.uuid)
                cursor.readUpTo?.let { readCursors.update { current -> current.advance(playerUuid, it) } }
            },
            logMessage = "Failed to report chat read",
            onError = { _, _, _ -> lastReportedReadUuid = null },
        )
    }

    private suspend fun fetchQuestions() {
        val round = roundUuid ?: return
        runApiCall(
            block = { questionsByUuid.value = questionRepository.listQuestions(round).associateBy { it.uuid } },
            logMessage = "Failed to load questions for chat",
        )
    }

    private suspend fun refreshQuestionsAndHistory() {
        fetchQuestions()
        fetchHistory()
    }

    private fun observeSseEvents() {
        viewModelScope.launch {
            gameEventRepository.chatEvents.collect { event ->
                val msg = ChatMessage(
                    uuid = event.uuid,
                    senderUuid = event.senderUuid,
                    senderName = event.senderName,
                    type = event.messageType,
                    body = event.body,
                    bodyKey = event.bodyKey,
                    bodyArgs = event.bodyArgs,
                    imageRef = event.imageRef,
                    createdAt = event.createdAt,
                    questionUuid = event.questionUuid,
                    replyToUuid = event.replyToUuid,
                )
                _messages.update { current ->
                    if (current.any { it.uuid == msg.uuid }) current else current + msg
                }
                chatRepository.cacheMessages(gameUuid, listOf(msg))
                // Heal the roster for senders who joined after our fetch, once per sender, or a
                // failing roster fetch costs a request per message.
                val sender = event.senderUuid
                if (sender != null && sender !in roster.value && rosterHealAttempted.add(sender)) {
                    requestRosterRefresh()
                }
                if (event.questionUuid != null) fetchQuestions()
                reportReadIfVisible()
            }
        }
    }

    private fun observeChatReadEvents() {
        viewModelScope.launch {
            gameEventRepository.chatReadEvents.collect { event ->
                readCursors.update { current -> current.advance(event.playerUuid, event.readUpTo) }
            }
        }
    }

    private fun observeChatDeletedEvents() {
        viewModelScope.launch {
            gameEventRepository.chatDeletedEvents.collect { event -> applyDeletion(event.messageUuid) }
        }
    }

    private suspend fun applyDeletion(messageUuid: String) {
        _messages.update { current ->
            current.map { message ->
                if (message.uuid == messageUuid) message.asDeleted() else message
            }
        }
        messageInfo.update { current -> current?.takeIf { it.messageUuid != messageUuid } }
        // Scrub the cache too, or an offline restart would hand back the retracted content.
        _messages.value.firstOrNull { it.uuid == messageUuid }
            ?.let { chatRepository.cacheMessages(gameUuid, listOf(it)) }
    }

    // The event carries the roster, so it is applied rather than answered with a fetch of its own.
    private fun observeRosterEvents() {
        viewModelScope.launch {
            gameEventRepository.rosterEvents.collect { event ->
                gameStateCache.setRoster(gameUuid, event.players)
                roster.value = event.players.associate { it.uuid to it.displayName }
            }
        }
    }

    // An SSE gap can swallow a roster event outright, so a fresh stream resyncs from scratch.
    private fun observeReconnects() {
        viewModelScope.launch {
            gameEventRepository.reconnectedEvents.collect {
                rosterHealAttempted.clear()
                refreshRoster()
                fetchHistory()
                fetchReadCursors()
            }
        }
    }

    /**
     * Roster refreshes run one at a time; racing them could let an older in-flight response
     * reinstate a player who has since left, in this screen and the shared cache.
     */
    private fun observeRosterRefreshes() {
        viewModelScope.launch {
            rosterRefreshes.collect { refreshRoster() }
        }
    }

    private fun requestRosterRefresh() {
        rosterRefreshes.tryEmit(Unit)
    }

    private suspend fun refreshRoster() {
        runApiCall(
            block = { roster.value = gameStateCache.refreshRoster(gameUuid).associate { it.uuid to it.displayName } },
            logMessage = "Failed to refresh the chat roster",
        )
    }

    /**
     * Runs a chat API call and maps the transport failures every call shares: an IOException is a
     * network problem, an HttpException is either an expired session or a server-rejected call.
     * The caller maps the failure to its own UI state via onError.
     */
    private suspend fun runApiCall(
        block: suspend () -> Unit,
        logMessage: String,
        httpLogMessage: String? = null,
        onError: (suspend (ErrorType, String?, Map<String, String>?) -> Unit)? = null,
    ) {
        try {
            block()
        } catch (e: IOException) {
            Log.w(TAG, logMessage, e)
            onError?.invoke(ErrorType.Network, null, null)
        } catch (e: HttpException) {
            if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
            Log.w(TAG, httpLogMessage ?: logMessage, e)
            onError?.invoke(e.toErrorType(), e.serverErrorKey(), e.serverErrorArgs())
        }
    }

    private companion object {
        const val TAG = "ChatViewModel"
        const val STOP_TIMEOUT_MS = 5_000L
    }
}
