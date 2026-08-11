package fr.gshz.hideandseek.data

import android.util.Log
import fr.gshz.hideandseek.data.mapper.toEpochMillisOrNull
import fr.gshz.hideandseek.data.remote.dto.LocationEventDto
import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.TimeTrap
import fr.gshz.hideandseek.domain.model.TimeTrapStatus
import fr.gshz.hideandseek.domain.repository.ChatDeletedEvent
import fr.gshz.hideandseek.domain.repository.ChatEvent
import fr.gshz.hideandseek.domain.repository.ChatReadEvent
import fr.gshz.hideandseek.domain.repository.EndgameEvent
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.domain.repository.ManualConstraintAddedEvent
import fr.gshz.hideandseek.domain.repository.ManualConstraintRemovedEvent
import fr.gshz.hideandseek.domain.repository.PossibleAreaEvent
import fr.gshz.hideandseek.domain.repository.ReconnectedEvent
import fr.gshz.hideandseek.domain.repository.RosterChanged
import fr.gshz.hideandseek.domain.repository.SeekerCandidateAdded
import fr.gshz.hideandseek.domain.repository.SeekerCandidateRemoved
import fr.gshz.hideandseek.domain.repository.TimeTrapEvent
import fr.gshz.hideandseek.domain.repository.TimerEvent
import fr.gshz.hideandseek.domain.repository.ZoneChanged
import fr.gshz.hideandseek.domain.repository.ZoneRadiusChanged
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.decodeFromJsonElement
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.logging.HttpLoggingInterceptor
import okhttp3.sse.EventSource
import okhttp3.sse.EventSourceListener
import okhttp3.sse.EventSources

@Singleton
class GameEventRepositoryImpl @Inject constructor(
    private val json: Json,
    private val okHttpClient: OkHttpClient,
) : GameEventRepository {

    private val _locationUpdates = MutableSharedFlow<LocationUpdate>(extraBufferCapacity = 64)
    override val locationUpdates: SharedFlow<LocationUpdate> = _locationUpdates

    private val _timerEvents = MutableSharedFlow<TimerEvent>(extraBufferCapacity = 8)
    override val timerEvents: SharedFlow<TimerEvent> = _timerEvents

    private val _chatEvents = MutableSharedFlow<ChatEvent>(extraBufferCapacity = 64)
    override val chatEvents: SharedFlow<ChatEvent> = _chatEvents

    private val _chatReadEvents = MutableSharedFlow<ChatReadEvent>(extraBufferCapacity = 64)
    override val chatReadEvents: SharedFlow<ChatReadEvent> = _chatReadEvents

    private val _chatDeletedEvents = MutableSharedFlow<ChatDeletedEvent>(extraBufferCapacity = 16)
    override val chatDeletedEvents: SharedFlow<ChatDeletedEvent> = _chatDeletedEvents

    private val _possibleAreaEvents = MutableSharedFlow<PossibleAreaEvent>(extraBufferCapacity = 8)
    override val possibleAreaEvents: SharedFlow<PossibleAreaEvent> = _possibleAreaEvents

    private val _endgameEvents = MutableSharedFlow<EndgameEvent>(extraBufferCapacity = 4)
    override val endgameEvents: SharedFlow<EndgameEvent> = _endgameEvents

    private val _reconnectedEvents = MutableSharedFlow<ReconnectedEvent>(extraBufferCapacity = 4)
    override val reconnectedEvents: SharedFlow<ReconnectedEvent> = _reconnectedEvents

    private val _seekerCandidateAdded = MutableSharedFlow<SeekerCandidateAdded>(extraBufferCapacity = 32)
    override val seekerCandidateAdded: SharedFlow<SeekerCandidateAdded> = _seekerCandidateAdded

    private val _seekerCandidateRemoved = MutableSharedFlow<SeekerCandidateRemoved>(extraBufferCapacity = 32)
    override val seekerCandidateRemoved: SharedFlow<SeekerCandidateRemoved> = _seekerCandidateRemoved

    private val _manualConstraintAdded = MutableSharedFlow<ManualConstraintAddedEvent>(extraBufferCapacity = 32)
    override val manualConstraintAdded: SharedFlow<ManualConstraintAddedEvent> = _manualConstraintAdded

    private val _manualConstraintRemoved = MutableSharedFlow<ManualConstraintRemovedEvent>(extraBufferCapacity = 32)
    override val manualConstraintRemoved: SharedFlow<ManualConstraintRemovedEvent> = _manualConstraintRemoved

    private val _zoneRadiusChanged = MutableSharedFlow<ZoneRadiusChanged>(extraBufferCapacity = 8)
    override val zoneRadiusChanged: SharedFlow<ZoneRadiusChanged> = _zoneRadiusChanged

    private val _zoneChanged = MutableSharedFlow<ZoneChanged>(extraBufferCapacity = 8)
    override val zoneChanged: SharedFlow<ZoneChanged> = _zoneChanged

    private val _timeTrapEvents = MutableSharedFlow<TimeTrapEvent>(extraBufferCapacity = 16)
    override val timeTrapEvents: SharedFlow<TimeTrapEvent> = _timeTrapEvents

    private val _rosterEvents = MutableSharedFlow<RosterChanged>(extraBufferCapacity = 8)
    override val rosterEvents: SharedFlow<RosterChanged> = _rosterEvents

    private var eventSource: EventSource? = null
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var connectionParams: Triple<String, String, List<String>>? = null
    private var reconnectJob: Job? = null
    private var reconnectAttempts = 0

    /**
     * OkHttp still reports onClosed/onFailure for a stream we cancelled ourselves, so a replaced
     * stream would schedule a reconnect that cancels the healthy one, and so on every second. Each
     * stream carries the epoch it was opened with and stays quiet once a newer one supersedes it.
     */
    private val streamEpoch = AtomicInteger(0)

    @Volatile
    private var intentionallyClosed = false

    override fun connect(apiUrl: String, mercureToken: String, topics: List<String>) {
        intentionallyClosed = false
        connectionParams = Triple(apiUrl, mercureToken, topics)
        reconnectJob?.cancel()
        openStream(apiUrl, mercureToken, topics)
    }

    private fun openStream(apiUrl: String, mercureToken: String, topics: List<String>) {
        val epoch = streamEpoch.incrementAndGet()
        eventSource?.cancel()

        val mercureBase = apiUrl.toHttpUrl().let { parsed ->
            okhttp3.HttpUrl.Builder()
                .scheme(parsed.scheme)
                .host(parsed.host)
                .port(parsed.port)
                .addPathSegments(MERCURE_PATH)
                .build()
        }
        val subscribeUrl = mercureBase.newBuilder()
            .apply { topics.forEach { topic -> addQueryParameter("topic", topic) } }
            .build()

        val request = Request.Builder()
            .url(subscribeUrl)
            .header("Authorization", "Bearer $mercureToken")
            .build()
        Log.d(TAG, "SSE connecting to ${topics.size} topics")

        val listener = object : EventSourceListener() {
            override fun onOpen(eventSource: EventSource, response: Response) {
                if (epoch != streamEpoch.get()) return
                val wasReconnect = reconnectAttempts > 0
                reconnectAttempts = 0
                if (wasReconnect) {
                    scope.launch { _reconnectedEvents.emit(ReconnectedEvent) }
                }
            }

            override fun onEvent(eventSource: EventSource, id: String?, type: String?, data: String) {
                if (epoch != streamEpoch.get()) return
                dispatchEvent(data)
            }

            override fun onClosed(eventSource: EventSource) {
                if (epoch != streamEpoch.get()) return
                scheduleReconnect()
            }

            override fun onFailure(eventSource: EventSource, t: Throwable?, response: Response?) {
                if (epoch != streamEpoch.get()) return
                Log.w(TAG, "SSE connection failed", t)
                scheduleReconnect()
            }
        }

        val sseClient = okHttpClient.newBuilder()
            .connectTimeout(SSE_CONNECT_TIMEOUT_SEC, TimeUnit.SECONDS)
            .readTimeout(SSE_READ_TIMEOUT_SEC, TimeUnit.SECONDS)
            .also { builder -> builder.interceptors().removeAll { it is HttpLoggingInterceptor } }
            .build()
        eventSource = EventSources.createFactory(sseClient).newEventSource(request, listener)
    }

    override fun disconnect() {
        intentionallyClosed = true
        streamEpoch.incrementAndGet()
        reconnectJob?.cancel()
        eventSource?.cancel()
        eventSource = null
    }

    private fun scheduleReconnect() {
        val params = connectionParams
        if (intentionallyClosed || reconnectJob?.isActive == true || params == null) return
        reconnectJob = scope.launch {
            val baseBackoff = (RECONNECT_BASE_MS shl reconnectAttempts.coerceAtMost(MAX_BACKOFF_SHIFT))
                .coerceAtMost(RECONNECT_MAX_MS)
            reconnectAttempts++
            val jittered = (
                baseBackoff * (JITTER_MIN_FACTOR + kotlin.random.Random.nextDouble() * JITTER_RANGE)
            ).toLong()
            delay(jittered)
            if (!intentionallyClosed) openStream(params.first, params.second, params.third)
        }
    }

    private fun dispatchEvent(data: String) {
        scope.launch {
            try {
                val obj = json.parseToJsonElement(data) as? JsonObject ?: return@launch
                val eventType = obj.stringField("type") ?: return@launch
                when (eventType) {
                    "location" -> {
                        val dto = json.decodeFromJsonElement<LocationEventDto>(obj)
                        _locationUpdates.emit(
                            LocationUpdate(dto.playerUuid, dto.lat, dto.lng, dto.recordedAt),
                        )
                    }
                    "timer" -> dispatchTimer(obj)
                    "chat" -> dispatchChat(obj)
                    "chat-read", "chat-deleted" -> dispatchChatReceipt(eventType, obj)
                    "possible-area", "possible-area-cleared" -> dispatchPossibleArea(obj)
                    "endgame" -> _endgameEvents.emit(EndgameEvent)
                    "roster" -> dispatchRoster(obj)
                    "seeker-candidate-added", "seeker-candidate-removed",
                    "manual-constraint-added", "manual-constraint-removed",
                    -> dispatchOverlayEvent(eventType, obj)
                    "zone-radius", "zone" -> dispatchZoneEvent(eventType, obj)
                    "time-trap" -> dispatchTimeTrap(obj)
                }
            } catch (e: IllegalArgumentException) {
                // Backstop for SerializationException (an IAE subclass) and raw kotlinx-json IAEs alike.
                Log.d(TAG, "Failed to decode SSE event", e)
            }
        }
    }

    private suspend fun dispatchOverlayEvent(eventType: String, obj: JsonObject) {
        when (eventType) {
            "seeker-candidate-added" -> dispatchSeekerCandidateAdded(obj)
            "seeker-candidate-removed" -> dispatchSeekerCandidateRemoved(obj)
            "manual-constraint-added" -> dispatchManualConstraintAdded(obj)
            "manual-constraint-removed" -> dispatchManualConstraintRemoved(obj)
        }
    }

    private suspend fun dispatchSeekerCandidateAdded(obj: JsonObject) {
        val uuid = obj.stringField("uuid")
        val lat = obj.doubleField("lat")
        val lng = obj.doubleField("lng")
        if (uuid != null && lat != null && lng != null) {
            _seekerCandidateAdded.emit(SeekerCandidateAdded(uuid, obj.stringField("playerUuid"), lat, lng))
        }
    }

    private suspend fun dispatchSeekerCandidateRemoved(obj: JsonObject) {
        obj.stringField("uuid")?.let { _seekerCandidateRemoved.emit(SeekerCandidateRemoved(it)) }
    }

    private suspend fun dispatchManualConstraintAdded(obj: JsonObject) {
        val uuid = obj.stringField("uuid")
        val mode = ConstraintMode.fromWireValueOrNull(obj.stringField("mode"))
        val geoJson = obj.stringField("geoJson")
        if (uuid != null && mode != null && geoJson != null) {
            _manualConstraintAdded.emit(ManualConstraintAddedEvent(uuid, mode, geoJson))
        }
    }

    private suspend fun dispatchManualConstraintRemoved(obj: JsonObject) {
        obj.stringField("uuid")?.let { _manualConstraintRemoved.emit(ManualConstraintRemovedEvent(it)) }
    }

    // Only "zone" carries the station point, and only the hider-only topic publishes it.
    private suspend fun dispatchZoneEvent(eventType: String, obj: JsonObject) {
        val radiusMeters = obj.doubleField("radiusMeters") ?: return
        val lat = obj.doubleField("lat")
        val lng = obj.doubleField("lng")
        when {
            eventType == "zone-radius" -> _zoneRadiusChanged.emit(ZoneRadiusChanged(radiusMeters))
            lat != null && lng != null ->
                _zoneChanged.emit(ZoneChanged(obj.stringField("roundUuid"), lat, lng, radiusMeters))
        }
    }

    /**
     * A detection publishes the frozen figure as well as the live one, and the frozen one is what a
     * pending trap is worth, so it wins whenever the server sent it.
     */
    private suspend fun dispatchTimeTrap(obj: JsonObject) {
        val action = obj.stringField("action")
        val uuid = obj.stringField("uuid")
        val lat = obj.doubleField("lat")
        val lng = obj.doubleField("lng")
        if (action == null || uuid == null) return
        if (lat == null || lng == null) return
        _timeTrapEvents.emit(
            TimeTrapEvent(
                action = action,
                trap = TimeTrap(
                    uuid = uuid,
                    roundUuid = obj.stringField("roundUuid") ?: "",
                    stationName = obj.stringField("stationName"),
                    lat = lat,
                    lng = lng,
                    placedAtEpochMs = obj.stringField("placedAt")?.toEpochMillisOrNull()
                        ?: System.currentTimeMillis(),
                    status = TimeTrapStatus.fromWireValueOrNull(obj.stringField("status"))
                        ?: TimeTrapStatus.Armed,
                    valueSeconds = obj.intField("frozenValueSeconds") ?: obj.intField("valueSeconds") ?: 0,
                    intervalMinutes = obj.intField("intervalMinutes") ?: 0,
                    incrementMinutes = obj.intField("incrementMinutes") ?: 0,
                    detectedByName = obj.stringField("detectedByName"),
                    awardedSeconds = obj.intField("awardedSeconds"),
                ),
            ),
        )
    }

    private suspend fun dispatchTimer(obj: JsonObject) {
        _timerEvents.emit(
            TimerEvent(
                status = obj.stringField("status") ?: "",
                hidingPeriodEndsAt = obj.stringField("hidingPeriodEndsAt"),
                seekingEndedAt = obj.stringField("seekingEndedAt"),
                roundUuid = obj.stringField("roundUuid"),
                hidingPeriodStartedAt = obj.stringField("hidingPeriodStartedAt"),
                bankedSeekingSeconds = obj.intField("bankedSeekingSeconds") ?: 0,
                inMovePeriod = obj.boolField("inMovePeriod"),
                hidingTimeSeconds = obj.intField("hidingTimeSeconds"),
                scoreSeconds = obj.intField("scoreSeconds"),
                hasHidingZone = obj.boolField("hasHidingZone"),
                hidingRadiusMeters = obj.doubleField("hidingRadiusMeters"),
            ),
        )
    }

    private suspend fun dispatchRoster(obj: JsonObject) {
        val players = (obj["players"] as? JsonArray)?.mapNotNull { element ->
            val player = element as? JsonObject ?: return@mapNotNull null
            val uuid = player.stringField("uuid") ?: return@mapNotNull null
            val displayName = player.stringField("displayName") ?: return@mapNotNull null
            Player(uuid, displayName, Side.fromWireValueOrNull(player.stringField("side")))
        } ?: return
        _rosterEvents.emit(RosterChanged(players))
    }

    private suspend fun dispatchChat(obj: JsonObject) {
        val bodyArgsObj = obj["bodyArgs"] as? JsonObject
        val bodyArgs: Map<String, String>? = bodyArgsObj?.mapValues { (_, v) ->
            (v as? JsonPrimitive)?.contentOrNull ?: v.toString()
        }
        _chatEvents.emit(
            ChatEvent(
                uuid = obj.stringField("uuid") ?: "",
                senderUuid = obj.stringField("senderUuid"),
                senderName = obj.stringField("senderName"),
                messageType = obj.stringField("messageType") ?: "",
                body = obj.stringField("body"),
                bodyKey = obj.stringField("bodyKey"),
                bodyArgs = bodyArgs,
                imageRef = obj.stringField("imageRef"),
                createdAt = obj.stringField("createdAt") ?: "",
                questionUuid = obj.stringField("questionUuid"),
                replyToUuid = obj.stringField("replyToUuid"),
            ),
        )
    }

    private suspend fun dispatchChatReceipt(eventType: String, obj: JsonObject) {
        val readUpTo = obj.stringField("readUpTo")
        val playerUuid = obj.stringField("playerUuid")
        val messageUuid = obj.stringField("uuid")
        when {
            eventType == "chat-read" && readUpTo != null && playerUuid != null -> _chatReadEvents.emit(
                ChatReadEvent(
                    playerUuid = playerUuid,
                    playerName = obj.stringField("playerName"),
                    readUpTo = readUpTo,
                ),
            )
            eventType == "chat-deleted" && messageUuid != null ->
                _chatDeletedEvents.emit(ChatDeletedEvent(messageUuid))
        }
    }

    private suspend fun dispatchPossibleArea(obj: JsonObject) {
        _possibleAreaEvents.emit(
            PossibleAreaEvent(
                questionUuid = obj.stringField("questionUuid") ?: "",
            ),
        )
    }

    fun destroy() {
        disconnect()
        scope.cancel()
    }

    private companion object {
        const val TAG = "GameEventRepository"
        const val MERCURE_PATH = ".well-known/mercure"
        const val RECONNECT_BASE_MS = 1_000L
        const val RECONNECT_MAX_MS = 30_000L
        const val MAX_BACKOFF_SHIFT = 5
        const val SSE_CONNECT_TIMEOUT_SEC = 5L

        // Strictly above the server's 30s Mercure heartbeat: a shorter window would tear down a live
        // stream between beats, a longer-than-necessary one delays spotting a silently dropped one.
        const val SSE_READ_TIMEOUT_SEC = 45L
        const val JITTER_MIN_FACTOR = 0.75
        const val JITTER_RANGE = 0.5
    }
}

private fun JsonObject.stringField(key: String): String? = (this[key] as? JsonPrimitive)?.contentOrNull

private fun JsonObject.doubleField(key: String): Double? = stringField(key)?.toDoubleOrNull()

private fun JsonObject.intField(key: String): Int? = stringField(key)?.toIntOrNull()

private fun JsonObject.boolField(key: String): Boolean = stringField(key)?.toBooleanStrictOrNull() == true
