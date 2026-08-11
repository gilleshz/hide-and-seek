package fr.gshz.hideandseek.feature.map

import android.util.Log
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.domain.model.HidingZone
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.ZoneCard
import fr.gshz.hideandseek.domain.model.ZonePlacement
import fr.gshz.hideandseek.domain.repository.LocationRepository
import fr.gshz.hideandseek.domain.repository.ZoneRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import retrofit2.HttpException

/** The hiding zone: placement, submission, the live radius and the player's own location pin. */
@HiltViewModel
class ZoneViewModel @Inject constructor(
    private val zoneRepository: ZoneRepository,
    private val locationRepository: LocationRepository,
    private val sessionEvents: MapSessionEvents,
    private val roundRefreshFlow: RoundRefreshFlow,
    private val zonePlacementCancelSignal: ZonePlacementCancelSignal,
) : ViewModel() {

    private val zoneState = MutableStateFlow(ZoneState())

    /** Live hider radius from the zone-radius SSE; falls back to the round's seeded value. */
    private val liveZoneRadius = MutableStateFlow<Double?>(null)

    private val selfGps: StateFlow<ZonePin?> = locationRepository.lastKnownLocation
        .map { loc -> loc?.let { ZonePin(it.latitude, it.longitude) } }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), null)

    /** The player's own stream location, so placement seeds from the freshest fix the map knows. */
    private val selfStreamLocation = MutableStateFlow<ZonePin?>(null)

    /** Guards the stream seed against replayed/out-of-order pings, like the roster markers' map. */
    @Volatile
    private var lastSelfStreamRecordedAt: String? = null

    val uiState: StateFlow<MapZoneUiState> = combine(zoneState, liveZoneRadius) { zone, live ->
        MapZoneUiState(
            isPlacingZone = zone.isPlacingZone,
            pendingZonePin = zone.pendingZonePin,
            pendingZoneStationName = zone.pendingStationName,
            selectedZoneRadiusMeters = zone.selectedRadiusMeters,
            customZoneRadiusText = zone.customRadiusText,
            customZoneRadiusMeters = zone.customRadiusMeters,
            submittedZone = zone.submittedZone,
            isSubmittingZone = zone.isSubmitting,
            zoneError = zone.error,
            zoneErrorKey = zone.errorKey,
            zoneErrorArgs = zone.errorArgs,
            currentZoneRadiusMeters = live,
        )
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), MapZoneUiState())

    init {
        loadHidingZone()
        observeZoneRadiusEvents()
        observeZoneEvents()
        observeSelfLocation()
        observeSessionChanges()
        observeTraceRequestCancellations()
    }

    /**
     * The zone arrives live on the hider topic, so an app that restarts mid-round would otherwise draw no
     * circle and lose the outside-zone warnings until a teammate happened to change it.
     */
    private fun loadHidingZone() {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            if (session.side != Side.Hider.wireValue) return@launch
            try {
                val zone = zoneRepository.currentHidingZone(session.roundUuid)
                    ?: return@launch
                zoneState.update { if (it.submittedZone == null) it.copy(submittedZone = zone) else it }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to load the hiding zone", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to load the hiding zone", e)
            }
        }
    }

    private fun observeZoneRadiusEvents() {
        viewModelScope.launch {
            sessionEvents.gameEventRepository.zoneRadiusChanged.collect { event ->
                liveZoneRadius.value = event.radiusMeters
            }
        }
    }

    /** The hiding team shares one zone row, so a teammate's station move must redraw this hider's circle. */
    private fun observeZoneEvents() {
        viewModelScope.launch {
            sessionEvents.gameEventRepository.zoneChanged.collect { event ->
                liveZoneRadius.value = event.radiusMeters
                zoneState.update { state ->
                    state.copy(
                        submittedZone = HidingZone(
                            roundUuid = event.roundUuid ?: state.submittedZone?.roundUuid ?: "",
                            lat = event.lat,
                            lng = event.lng,
                            radiusMeters = event.radiusMeters,
                        ),
                    )
                }
            }
        }
    }

    private fun observeSelfLocation() {
        viewModelScope.launch {
            try {
                locationRepository.observeLocationUpdates().collect { update ->
                    val selfUuid = sessionEvents.sessionRepository.currentSession()?.playerUuid
                    if (update.playerUuid != selfUuid) return@collect
                    val last = lastSelfStreamRecordedAt
                    if (last == null || update.recordedAt >= last) {
                        selfStreamLocation.value = ZonePin(update.latitude, update.longitude)
                        lastSelfStreamRecordedAt = update.recordedAt
                    }
                }
            } catch (e: IOException) {
                Log.w(TAG, "Location stream failed", e)
            }
        }
    }

    /** A round rekey means another round's zone: the live radius must not linger until a new event lands. */
    private fun observeSessionChanges() {
        viewModelScope.launch {
            var lastRoundUuid = sessionEvents.sessionRepository.currentSession()?.roundUuid
            sessionEvents.sessionRepository.observeSession()
                .map { session -> session?.let { it.roundUuid to it.side } }
                .distinctUntilChanged()
                .drop(1)
                .collect { roundAndSide ->
                    if (roundAndSide?.first != lastRoundUuid) {
                        lastRoundUuid = roundAndSide?.first
                        liveZoneRadius.value = null
                        selfStreamLocation.value = null
                        lastSelfStreamRecordedAt = null
                    }
                }
        }
    }

    /** A trace request answers a question instead: any open zone placement panel would cover its controls. */
    private fun observeTraceRequestCancellations() {
        viewModelScope.launch {
            zonePlacementCancelSignal.cancellations.collect {
                cancelZonePlacement()
            }
        }
    }

    fun enterZonePlacementMode() {
        zoneState.update {
            it.copy(
                isPlacingZone = true,
                pendingZonePin = it.submittedZone?.let { zone -> ZonePin(zone.lat, zone.lng) }
                    ?: it.pendingZonePin
                    ?: selfGpsPin(),
                pendingStationName = it.submittedZone?.stationName ?: it.pendingStationName,
                selectedRadiusMeters = it.submittedZone?.radiusMeters,
                error = null,
                errorKey = null,
                errorArgs = null,
            )
        }
    }

    private fun selfGpsPin(): ZonePin? = selfStreamLocation.value ?: selfGps.value

    fun cancelZonePlacement() {
        zoneState.update { it.copy(isPlacingZone = false, error = null, errorKey = null, errorArgs = null) }
    }

    /**
     * The zone centers on a transit station, so the tap records which station the hider hit on the
     * transit overlay. Null when they placed the pin where the overlay shows none.
     */
    fun placeZonePin(latitude: Double, longitude: Double, stationName: String? = null) {
        zoneState.update { it.copy(pendingZonePin = ZonePin(latitude, longitude), pendingStationName = stationName) }
    }

    fun selectZoneRadius(radiusMeters: Double?) {
        zoneState.update { it.copy(selectedRadiusMeters = radiusMeters) }
    }

    fun onCustomZoneRadiusChange(text: String) {
        val meters = text.toDoubleOrNull()
        zoneState.update {
            it.copy(
                customRadiusText = text,
                customRadiusMeters = meters,
                selectedRadiusMeters = if (meters != null) meters else it.selectedRadiusMeters,
            )
        }
    }

    fun confirmZone() {
        viewModelScope.launch {
            val pin = zoneState.value.pendingZonePin ?: return@launch
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            zoneState.update { it.copy(isSubmitting = true, error = null, errorKey = null, errorArgs = null) }
            try {
                val radiusMeters = zoneState.value.customRadiusMeters ?: zoneState.value.selectedRadiusMeters
                val zone = zoneRepository.setHidingZone(
                    roundUuid = session.roundUuid,
                    playerUuid = session.playerUuid,
                    placement = ZonePlacement(
                        lat = pin.latitude,
                        lng = pin.longitude,
                        radiusMeters = radiusMeters,
                        stationName = zoneState.value.pendingStationName,
                    ),
                )
                zoneState.update { it.copy(isSubmitting = false, isPlacingZone = false, submittedZone = zone) }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to submit hiding zone", e)
                zoneState.update { it.copy(isSubmitting = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to submit hiding zone", e)
                zoneState.update {
                    it.copy(
                        isSubmitting = false,
                        error = e.toErrorType(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    /**
     * Once the seekers are hunting the zone stops being free to nudge: it only changes by playing one of
     * the three cards, each with a photo of it. Which card was played is the server's business, so the
     * client posts the card name and takes back whatever radius it decides.
     */
    fun playZoneCard(card: ZoneCard, cardPhotoUri: String) {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            zoneState.update { it.copy(isSubmitting = true, error = null, errorKey = null, errorArgs = null) }
            try {
                val zone = zoneRepository.playZoneCard(session.roundUuid, session.playerUuid, card, cardPhotoUri)
                zoneState.update { it.copy(isSubmitting = false, submittedZone = zone) }
                roundRefreshFlow.refresh.update { it + 1 }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to play zone card", e)
                zoneState.update { it.copy(isSubmitting = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to play zone card", e)
                zoneState.update {
                    it.copy(
                        isSubmitting = false,
                        error = e.toErrorType(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    private data class ZoneState(
        val isPlacingZone: Boolean = false,
        val pendingZonePin: ZonePin? = null,
        val pendingStationName: String? = null,
        val selectedRadiusMeters: Double? = null,
        val customRadiusText: String = "",
        val customRadiusMeters: Double? = null,
        val submittedZone: HidingZone? = null,
        val isSubmitting: Boolean = false,
        val error: ErrorType? = null,
        val errorKey: String? = null,
        val errorArgs: Map<String, String>? = null,
    )

    private companion object {
        const val TAG = "ZoneViewModel"
        const val STOP_TIMEOUT_MS = 5_000L
    }
}

data class MapZoneUiState(
    val isPlacingZone: Boolean = false,
    val pendingZonePin: ZonePin? = null,
    val pendingZoneStationName: String? = null,
    val selectedZoneRadiusMeters: Double? = null,
    val customZoneRadiusText: String = "",
    val customZoneRadiusMeters: Double? = null,
    val submittedZone: HidingZone? = null,
    val isSubmittingZone: Boolean = false,
    val zoneError: ErrorType? = null,
    val zoneErrorKey: String? = null,
    val zoneErrorArgs: Map<String, String>? = null,
    val currentZoneRadiusMeters: Double? = null,
)
