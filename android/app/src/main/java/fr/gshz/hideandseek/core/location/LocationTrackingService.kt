package fr.gshz.hideandseek.core.location

import android.Manifest
import android.annotation.SuppressLint
import android.app.Notification
import android.os.Build
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.IBinder
import android.os.Looper
import android.os.SystemClock
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import dagger.hilt.android.AndroidEntryPoint
import fr.gshz.hideandseek.MainActivity
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.GameStateCache
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.core.notification.ChatNotifier
import fr.gshz.hideandseek.core.util.isSessionExpired
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.domain.repository.GameRepository
import fr.gshz.hideandseek.domain.repository.LocationRepository
import fr.gshz.hideandseek.domain.repository.RoundRepository
import fr.gshz.hideandseek.domain.repository.SessionRepository
import fr.gshz.hideandseek.domain.repository.TimerEvent
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.launchIn
import kotlinx.coroutines.flow.onEach
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import retrofit2.HttpException

@AndroidEntryPoint
class LocationTrackingService : Service() {

    @Inject
    lateinit var locationRepository: LocationRepository

    @Inject
    lateinit var sessionRepository: SessionRepository

    @Inject
    lateinit var gameRepository: GameRepository

    @Inject
    lateinit var roundRepository: RoundRepository

    @Inject
    lateinit var gameEventRepository: GameEventRepository

    @Inject
    lateinit var connectionStore: ConnectionStore

    @Inject
    lateinit var chatNotifier: ChatNotifier

    @Inject
    lateinit var gameStateCache: GameStateCache

    @Inject
    lateinit var navigationRequestStore: NavigationRequestStore

    private lateinit var locationManager: LocationManager
    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    @Volatile
    private var locationListener: LocationListener? = null

    @Volatile
    private var currentIntervalMs: Long = LocationCadence.DEFAULT_INTERVAL_MS

    @Volatile
    private var currentBaseIntervalMs: Long = LocationCadence.DEFAULT_INTERVAL_MS
    private val cadenceSelector = LocationCadenceSelector()

    @Volatile
    private var lastFixLat: Double? = null

    @Volatile
    private var lastFixLng: Double? = null

    @Volatile
    private var lastFixAt = 0L

    @Volatile
    private var currentPhase: RoundStatus? = null

    @Volatile
    private var currentSide: Side? = null

    @Volatile
    private var endgameProximity = false

    @Volatile
    private var gpsAllowed = true

    @Volatile
    private var sideResolutionPending = false
    private var collectorsStarted = false

    @Volatile
    private var timerEventSeen = false

    @Volatile
    private var connectedToken: String? = null

    @Volatile
    private var connectedTopics: Set<String>? = null
    private val pingErrorGate = PingErrorGate()
    private val pingBackoff = PingBackoff()

    @Volatile
    private var lastPostedLat: Double? = null

    @Volatile
    private var lastPostedLng: Double? = null

    override fun onCreate() {
        super.onCreate()
        locationManager = getSystemService(LocationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                getString(R.string.location_notification_channel_name),
                NotificationManager.IMPORTANCE_LOW,
            )
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }

        ServiceCompat.startForeground(
            this,
            NOTIFICATION_ID,
            buildNotification(searching = false),
            ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION,
        )
        startEventCollectors()
        serviceScope.launch { armGpsForPersistedRound() }
        return START_STICKY
    }

    private suspend fun armGpsForPersistedRound() {
        val session = sessionRepository.currentSession() ?: return
        currentSide = session.side?.let(Side::fromWireValue)
        var armable = true
        var fetchedPhase: RoundStatus? = null
        var sessionExpired = false
        try {
            fetchedPhase = roundRepository.getRound(session.roundUuid).status
            armable = fetchedPhase != RoundStatus.Ended
        } catch (e: IOException) {
            Log.w(TAG, "Round validation unreachable, arming at fallback cadence", e)
        } catch (e: HttpException) {
            if (e.isSessionExpired()) {
                navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                stopSelf()
                sessionExpired = true
            } else {
                // Only a client error proves the round is gone; a 5xx must not idle GPS for the whole round.
                armable = e.code() !in CLIENT_ERROR_RANGE
                if (armable) {
                    Log.w(TAG, "Round validation failed server-side, arming at fallback cadence", e)
                } else {
                    Log.w(TAG, "Persisted round rejected, idling until a timer event", e)
                }
            }
        }
        // The probe races live timer events; one that lost the race must not re-arm an ended round.
        synchronized(this) {
            if (timerEventSeen || sessionExpired) return
            currentPhase = fetchedPhase
            gpsAllowed = armable
            if (armable) {
                cadenceSelector.reset()
                startLocationUpdates(LocationCadence.selectInterval(currentSide, currentPhase, endgameProximity))
            }
        }
    }

    @SuppressLint("MissingPermission")
    @Synchronized
    private fun startLocationUpdates(intervalMs: Long) {
        // An endgame re-arm racing the Ended handler must lose; the flag is settled before stopLocationUpdates.
        if (!gpsAllowed) return
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            stopSelf()
            return
        }

        locationListener?.let(locationManager::removeUpdates)
        currentBaseIntervalMs = intervalMs
        val effectiveIntervalMs = cadenceSelector.onArm(intervalMs, !endgameProximity)
        currentIntervalMs = effectiveIntervalMs
        lastFixAt = SystemClock.elapsedRealtime()
        // Each arm opens a new generation; a stale 4xx from the callback removed above must not pause this one.
        val pingGeneration = pingErrorGate.arm()
        val listener = object : LocationListener {
            override fun onLocationChanged(location: Location) {
                lastFixAt = SystemClock.elapsedRealtime()
                val altitude = location.bestAltitudeMeters()
                locationRepository.updateLastKnownLocation(
                    DeviceLocation(location.latitude, location.longitude, altitude),
                )
                sendLocationPing(location.latitude, location.longitude, altitude, pingGeneration)
                val lastLat = lastFixLat
                val lastLng = lastFixLng
                lastFixLat = location.latitude
                lastFixLng = location.longitude
                if (lastLat != null && lastLng != null) {
                    val results = FloatArray(1)
                    Location.distanceBetween(lastLat, lastLng, location.latitude, location.longitude, results)
                    val desiredIntervalMs =
                        cadenceSelector.onFix(results[0].toDouble(), currentBaseIntervalMs, !endgameProximity)
                    if (desiredIntervalMs != currentIntervalMs) {
                        startLocationUpdates(currentBaseIntervalMs)
                    }
                }
            }
        }
        locationListener = listener
        locationManager.requestLocationUpdates(
            LocationManager.GPS_PROVIDER,
            effectiveIntervalMs,
            LocationCadence.MOVE_THRESHOLD_M.toFloat(),
            listener,
            Looper.getMainLooper(),
        )
    }

    private fun reconfigureLocationUpdates(newIntervalMs: Long) {
        if (newIntervalMs == currentIntervalMs || locationListener == null) return
        startLocationUpdates(newIntervalMs)
    }

    @Synchronized
    private fun stopLocationUpdates() {
        locationListener?.let(locationManager::removeUpdates)
        locationListener = null
    }

    private fun startEventCollectors() {
        if (collectorsStarted) return
        collectorsStarted = true
        sessionRepository.observeSession()
            .onEach { session -> onSessionChanged(session) }
            .launchIn(serviceScope)
        gameEventRepository.timerEvents
            .onEach { event -> handleTimerEvent(event) }
            .launchIn(serviceScope)
        gameEventRepository.chatEvents
            .onEach { event ->
                sessionRepository.currentSession()?.let { session ->
                    chatNotifier.onChatEvent(event, session.playerUuid, session.gameUuid)
                }
            }
            .launchIn(serviceScope)
        gameEventRepository.endgameEvents
            .onEach {
                // Endgame is mid-round, not game end; keep the service and SSE alive.
                showEndgameNotification()
                endgameProximity = true
                cadenceSelector.reset()
                reconfigureLocationUpdates(
                    LocationCadence.selectInterval(currentSide, currentPhase, endgameProximity = true),
                )
            }
            .launchIn(serviceScope)
        serviceScope.launch {
            var searching = false
            while (isActive) {
                delay(FIX_STATUS_POLL_MS)
                val nowSearching = locationListener != null &&
                    SystemClock.elapsedRealtime() - lastFixAt > currentIntervalMs + SEARCHING_FIX_MARGIN_MS
                if (nowSearching != searching) {
                    searching = nowSearching
                    ServiceCompat.startForeground(
                        this@LocationTrackingService,
                        NOTIFICATION_ID,
                        buildNotification(searching),
                        ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION,
                    )
                }
            }
        }
    }

    private suspend fun onSessionChanged(session: PlayerSession?) {
        if (session == null) {
            stopSelf()
            return
        }
        // clearSide() scrubs location grants from the topics without minting a token; follow both.
        val topics = session.topics.toSet()
        if (session.mercureToken != connectedToken || topics != connectedTopics) {
            connectedToken = session.mercureToken
            connectedTopics = topics
            connectionStore.current()?.let { config ->
                gameEventRepository.connect(config.apiUrl, session.mercureToken, session.topics)
            }
        }
        val side = session.side?.let(Side::fromWireValue)
        if (side != currentSide) {
            currentSide = side
            cadenceSelector.reset()
            reconfigureLocationUpdates(LocationCadence.selectInterval(currentSide, currentPhase, endgameProximity))
        }
    }

    private suspend fun handleTimerEvent(event: TimerEvent) {
        val newPhase = RoundStatus.fromWireValueOrNull(event.status)
        if (newPhase == null) {
            Log.w(TAG, "Ignoring timer event with unknown status: ${event.status}")
            return
        }
        timerEventSeen = true
        event.roundUuid?.let { rekeySession(it) }
        if (newPhase != RoundStatus.Seeking) {
            endgameProximity = false
        }
        if (newPhase == currentPhase) return
        currentPhase = newPhase
        cadenceSelector.reset()
        if (newPhase == RoundStatus.Ended) {
            gpsAllowed = false
            stopLocationUpdates()
        } else {
            gpsAllowed = true
            startLocationUpdates(LocationCadence.selectInterval(currentSide, newPhase, endgameProximity))
        }
    }

    private suspend fun rekeySession(roundUuid: String) {
        val session = sessionRepository.currentSession() ?: return
        val roundChanged = session.roundUuid != roundUuid
        if (!roundChanged && !sideResolutionPending) return
        if (roundChanged) {
            // The 24h game-scoped token may still grant hider-locations; drop the stream until re-scoped,
            // and clear the persisted side/grants so a service restart cannot resubscribe with them.
            gameEventRepository.disconnect()
            sessionRepository.updateRoundUuid(roundUuid)
            sessionRepository.clearSide()
            pingErrorGate.reset()
            pingBackoff.reset()
            cadenceSelector.reset()
            // The cached game still points at the previous roundUuid.
            gameStateCache.invalidateGame()
        }
        sideResolutionPending = false
        val rekeyed = session.copy(roundUuid = roundUuid)
        var sessionExpired = false
        val confirmed = try {
            resolveSeededSide(rekeyed)
        } catch (e: IOException) {
            Log.w(TAG, "Seeded-side resolution failed, retrying on the next timer event", e)
            sideResolutionPending = true
            false
        } catch (e: HttpException) {
            if (e.isSessionExpired()) {
                sessionExpired = true
                navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                stopSelf()
            } else {
                Log.w(TAG, "Seeded-side resolution failed, retrying on the next timer event", e)
                sideResolutionPending = true
            }
            false
        }
        if (!confirmed && !sessionExpired) {
            connectionStore.current()?.let { config ->
                val scrubbedTopics = rekeyed.topicsWithoutLocations()
                connectedToken = rekeyed.mercureToken
                connectedTopics = scrubbedTopics.toSet()
                gameEventRepository.connect(config.apiUrl, rekeyed.mercureToken, scrubbedTopics)
            }
        }
    }

    private suspend fun resolveSeededSide(rekeyed: PlayerSession): Boolean {
        val rekeyedPlayers = gameRepository.listPlayers(rekeyed.gameUuid)
        val seededSide = rekeyedPlayers.firstOrNull { it.uuid == rekeyed.playerUuid }?.side
        gameStateCache.setRoster(rekeyed.gameUuid, rekeyedPlayers)
        if (seededSide == null) {
            sessionRepository.clearSide()
        } else {
            val team = gameRepository.chooseTeam(rekeyed.roundUuid, rekeyed.playerUuid, seededSide)
            sessionRepository.updateSide(team.side, team.mercureToken, team.topics)
        }
        // onSessionChanged picks up the persisted side change and reconfigures the cadence.
        return seededSide != null
    }

    private fun showEndgameNotification() {
        val manager = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                ENDGAME_CHANNEL_ID,
                getString(R.string.endgame_notification_channel_name),
                NotificationManager.IMPORTANCE_HIGH,
            )
            manager.createNotificationChannel(channel)
        }

        manager.notify(
            ENDGAME_NOTIFICATION_ID,
            NotificationCompat.Builder(this, ENDGAME_CHANNEL_ID)
                .setContentTitle(getString(R.string.endgame_notification_title))
                .setContentText(getString(R.string.endgame_notification_body))
                .setSmallIcon(android.R.drawable.ic_dialog_alert)
                .setAutoCancel(true)
                .setContentIntent(openAppPendingIntent())
                .build(),
        )
    }

    private fun sendLocationPing(lat: Double, lng: Double, altitude: Double?, pingGeneration: Long) {
        serviceScope.launch {
            val session = sessionRepository.currentSession() ?: return@launch
            if (session.side == null || pingBackoff.active) return@launch
            val lastLat = lastPostedLat
            val lastLng = lastPostedLng
            if (lastLat != null && lastLng != null) {
                val results = FloatArray(1)
                android.location.Location.distanceBetween(lastLat, lastLng, lat, lng, results)
                if (results[0] < LocationCadence.MOVE_THRESHOLD_M) return@launch
            }
            try {
                val endgame = locationRepository.postLocationPing(
                    session.roundUuid,
                    session.playerUuid,
                    lat,
                    lng,
                    altitude,
                )
                lastPostedLat = lat
                lastPostedLng = lng
                pingErrorGate.reset()
                pingBackoff.reset()
                if (endgame && !endgameProximity) {
                    // Fail-safe for a dead SSE stream: the ping ack tells the triggering seeker directly.
                    showEndgameNotification()
                    endgameProximity = true
                    cadenceSelector.reset()
                    reconfigureLocationUpdates(
                        LocationCadence.selectInterval(currentSide, currentPhase, endgameProximity = true),
                    )
                }
            } catch (e: IOException) {
                // Dropped, not queued: a replayed ping is stale and moves the server's latest location backwards.
                Log.w(TAG, "Location ping failed, dropping", e)
                pingBackoff.recordFailure()
            } catch (e: HttpException) {
                if (e.isSessionExpired()) {
                    navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                    stopSelf()
                    return@launch
                }
                Log.w(TAG, "Location ping failed", e)
                pingBackoff.recordFailure()
                handlePingClientError(e, session, pingGeneration)
            }
        }
    }

    private suspend fun handlePingClientError(e: HttpException, session: PlayerSession, pingGeneration: Long) {
        if (e.code() !in CLIENT_ERROR_RANGE) {
            pingErrorGate.reset()
            return
        }
        // A 4xx from a callback torn down by a newer arm is stale; it must not count against the fresh one.
        val errors = pingErrorGate.countError(pingGeneration)
        if (errors == null || (e.code() == HTTP_NOT_FOUND && resyncRound(session))) return
        if (errors >= PAUSE_AFTER_CLIENT_ERRORS) {
            // Locked against startLocationUpdates so a phase-change re-arm racing the threshold is never torn down.
            synchronized(this) {
                if (pingErrorGate.isCurrent(pingGeneration)) {
                    Log.w(TAG, "Pings persistently rejected, pausing location updates until the next phase change")
                    pingErrorGate.reset()
                    stopLocationUpdates()
                }
            }
        }
    }

    private suspend fun resyncRound(session: PlayerSession): Boolean = try {
        val activeGame = gameRepository.getGame(session.gameUuid)
        gameStateCache.setGameSummary(activeGame)
        val activeRoundUuid = activeGame.roundUuid
        if (activeRoundUuid != session.roundUuid) {
            rekeySession(activeRoundUuid)
            true
        } else {
            false
        }
    } catch (e: IOException) {
        Log.w(TAG, "Round resync failed, will retry", e)
        false
    } catch (e: HttpException) {
        Log.w(TAG, "Round resync failed", e)
        false
    }

    override fun onDestroy() {
        locationListener?.let { locationManager.removeUpdates(it) }
        gameEventRepository.disconnect()
        serviceScope.cancel()
        super.onDestroy()
    }

    private fun buildNotification(searching: Boolean): Notification {
        val stopIntent = Intent(this, LocationTrackingService::class.java).setAction(ACTION_STOP)
        val stopPendingIntent = PendingIntent.getService(
            this,
            0,
            stopIntent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.location_notification_title))
            .setContentText(if (searching) getString(R.string.location_notification_searching_fix) else null)
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setOngoing(true)
            .setContentIntent(openAppPendingIntent())
            .addAction(0, getString(R.string.location_notification_stop), stopPendingIntent)
            .build()
    }

    private fun openAppPendingIntent(): PendingIntent {
        val openAppIntent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP
        }
        return PendingIntent.getActivity(
            this,
            0,
            openAppIntent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
    }

    companion object {
        private const val TAG = "LocationTrackingService"
        private const val CHANNEL_ID = "location_tracking"
        private const val ENDGAME_CHANNEL_ID = "endgame"
        private const val NOTIFICATION_ID = 1001
        private const val ENDGAME_NOTIFICATION_ID = 1002
        private const val FIX_STATUS_POLL_MS = 10_000L
        private const val SEARCHING_FIX_MARGIN_MS = 60_000L
        private const val PAUSE_AFTER_CLIENT_ERRORS = 5
        private const val HTTP_NOT_FOUND = 404
        private val CLIENT_ERROR_RANGE = 400..499
        const val ACTION_STOP = "fr.gshz.hideandseek.action.STOP_LOCATION_TRACKING"
    }
}
