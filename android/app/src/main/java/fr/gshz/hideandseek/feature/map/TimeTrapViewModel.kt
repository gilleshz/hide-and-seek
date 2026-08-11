package fr.gshz.hideandseek.feature.map

import android.util.Log
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.TimeTrap
import fr.gshz.hideandseek.domain.model.TimeTrapStatus
import fr.gshz.hideandseek.domain.model.valueSecondsAt
import fr.gshz.hideandseek.domain.repository.TimeTrapRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.flow.updateAndGet
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import retrofit2.HttpException

/** Time traps: the live values, placement and the seeker's detection prompt. */
@HiltViewModel
class TimeTrapViewModel @Inject constructor(
    private val timeTrapRepository: TimeTrapRepository,
    private val savedStateHandle: SavedStateHandle,
    private val sessionEvents: MapSessionEvents,
    private val roundRefreshFlow: RoundRefreshFlow,
) : ViewModel() {

    private val timeTrapState = MutableStateFlow(TimeTrapState())

    /** The 1 Hz ticker is the only clock the accruing trap value reads, so no composable owns a timer. */
    private val ticker: StateFlow<Long> = flow {
        while (currentCoroutineContext().isActive) {
            emit(System.currentTimeMillis())
            delay(TICK_INTERVAL_MS)
        }
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), System.currentTimeMillis())

    private val side: StateFlow<Side?> = sessionEvents.sessionRepository.observeSession()
        .map { it?.side?.let(Side::fromWireValue) }
        .stateIn(viewModelScope, SharingStarted.Eagerly, null)

    val uiState: StateFlow<MapTimeTrapUiState> = combine(timeTrapState, ticker, side) { state, nowMillis, side ->
        MapTimeTrapUiState(
            timeTraps = state.traps.map { it.copy(valueSeconds = it.valueSecondsAt(nowMillis)) },
            isPlacingTimeTrap = state.isPlacing,
            pendingTrapPin = state.pendingPin,
            pendingTrapStationName = state.pendingStationName,
            pendingTrapDetection = pendingDetectionFor(side, state.traps),
            isSubmittingTimeTrap = state.isSubmitting,
            timeTrapError = state.error,
            timeTrapErrorKey = state.errorKey,
            timeTrapErrorArgs = state.errorArgs,
        )
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), MapTimeTrapUiState())

    init {
        loadTimeTraps()
        observeTimeTrapEvents()
        restoreTrapPlacement()
        observeSessionChanges()
        observeReconnects()
    }

    /** The SharedFlows have no replay, so a client that arrives mid-round seeds the list over REST. */
    private fun loadTimeTraps() {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            try {
                val traps = timeTrapRepository.listTimeTraps(session.roundUuid)
                timeTrapState.update { it.copy(traps = traps.filterNot { t -> t.status == TimeTrapStatus.Sprung }) }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to load time traps", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to load time traps", e)
            }
        }
    }

    /**
     * The game topic outlives a round, so a trap published for a round this player already left must not
     * appear on their map.
     */
    private fun observeTimeTrapEvents() {
        viewModelScope.launch {
            sessionEvents.gameEventRepository.timeTrapEvents.collect { event ->
                val roundUuid = sessionEvents.sessionRepository.currentSession()?.roundUuid
                if (event.trap.roundUuid.isNotEmpty() && roundUuid != null && event.trap.roundUuid != roundUuid) {
                    return@collect
                }
                timeTrapState.update { it.copy(traps = it.traps.applyTrap(event.trap)) }
                // A sprung trap banks its minutes into the round's score, so the stored total is stale.
                if (event.trap.status == TimeTrapStatus.Sprung) roundRefreshFlow.refresh.update { it + 1 }
            }
        }
    }

    fun enterTimeTrapPlacement() {
        if (side.value != Side.Hider) return
        updateTrapPlacement {
            it.copy(isPlacing = true, pendingPin = null, pendingStationName = null, error = null, errorKey = null)
        }
    }

    fun cancelTimeTrapPlacement() {
        updateTrapPlacement {
            it.copy(isPlacing = false, pendingPin = null, pendingStationName = null, error = null, errorKey = null)
        }
    }

    /** The tap snaps to a station on the transit overlay; the server pins the trap to the nearest one. */
    fun placeTimeTrapPin(latitude: Double, longitude: Double, stationName: String? = null) {
        updateTrapPlacement { state ->
            if (!state.isPlacing) {
                state
            } else {
                state.copy(pendingPin = ZonePin(latitude, longitude), pendingStationName = stationName)
            }
        }
    }

    fun confirmTimeTrap(cardPhotoUri: String) {
        viewModelScope.launch {
            val pin = timeTrapState.value.pendingPin ?: return@launch
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            timeTrapState.update { it.copy(isSubmitting = true, error = null, errorKey = null, errorArgs = null) }
            try {
                val trap = timeTrapRepository.placeTimeTrap(
                    session.roundUuid, session.playerUuid, pin.latitude, pin.longitude, cardPhotoUri,
                )
                updateTrapPlacement {
                    it.copy(
                        isSubmitting = false,
                        isPlacing = false,
                        pendingPin = null,
                        pendingStationName = null,
                        traps = it.traps.applyTrap(trap),
                    )
                }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to place a time trap", e)
                timeTrapState.update { it.copy(isSubmitting = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to place a time trap", e)
                timeTrapState.update { it.failed(e) }
            }
        }
    }

    /**
     * The prompt is derived from the trap's own status, so a refused call leaves it Pending and the dialog
     * stays up rather than losing a detection nobody can re-trigger.
     */
    fun resolveTimeTrap(trapUuid: String, confirmed: Boolean) {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            timeTrapState.update { it.copy(error = null, errorKey = null, errorArgs = null) }
            try {
                val trap = timeTrapRepository.resolveTimeTrap(
                    session.roundUuid, trapUuid, session.playerUuid, confirmed,
                )
                timeTrapState.update { it.copy(traps = it.traps.applyTrap(trap)) }
                if (confirmed) roundRefreshFlow.refresh.update { it + 1 }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to resolve a time trap", e)
                timeTrapState.update { it.copy(error = ErrorType.Network) }
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to resolve a time trap", e)
                timeTrapState.update { it.failed(e) }
            }
        }
    }

    /**
     * The camera can take the process down with it, and the picker only registers while the panel is
     * composed, so the draft has to outlive the ViewModel or the photo comes back to nothing to place.
     */
    private fun updateTrapPlacement(transform: (TimeTrapState) -> TimeTrapState) {
        val next = timeTrapState.updateAndGet(transform)
        savedStateHandle[KEY_TRAP_PLACING] = next.isPlacing
        savedStateHandle[KEY_TRAP_LAT] = next.pendingPin?.latitude
        savedStateHandle[KEY_TRAP_LNG] = next.pendingPin?.longitude
        savedStateHandle[KEY_TRAP_STATION] = next.pendingStationName
    }

    private fun restoreTrapPlacement() {
        if (savedStateHandle.get<Boolean>(KEY_TRAP_PLACING) != true) return
        val lat = savedStateHandle.get<Double>(KEY_TRAP_LAT)
        val lng = savedStateHandle.get<Double>(KEY_TRAP_LNG)
        timeTrapState.update {
            it.copy(
                isPlacing = true,
                pendingPin = if (lat != null && lng != null) ZonePin(lat, lng) else null,
                pendingStationName = savedStateHandle[KEY_TRAP_STATION],
            )
        }
    }

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
                        updateTrapPlacement { TimeTrapState() }
                        loadTimeTraps()
                    }
                }
        }
    }

    private fun observeReconnects() {
        viewModelScope.launch {
            sessionEvents.gameEventRepository.reconnectedEvents.collect {
                loadTimeTraps()
            }
        }
    }

    private data class TimeTrapState(
        val traps: List<TimeTrap> = emptyList(),
        val isPlacing: Boolean = false,
        val pendingPin: ZonePin? = null,
        val pendingStationName: String? = null,
        val isSubmitting: Boolean = false,
        val error: ErrorType? = null,
        val errorKey: String? = null,
        val errorArgs: Map<String, String>? = null,
    ) {
        fun failed(e: HttpException) = copy(
            isSubmitting = false,
            error = e.toErrorType(),
            errorKey = e.serverErrorKey(),
            errorArgs = e.serverErrorArgs(),
        )
    }

    private companion object {
        const val TAG = "TimeTrapViewModel"
        const val STOP_TIMEOUT_MS = 5_000L
        const val TICK_INTERVAL_MS = 1_000L
        const val KEY_TRAP_PLACING = "trapPlacing"
        const val KEY_TRAP_LAT = "trapPendingLat"
        const val KEY_TRAP_LNG = "trapPendingLng"
        const val KEY_TRAP_STATION = "trapPendingStation"
    }
}

data class MapTimeTrapUiState(
    val timeTraps: List<TimeTrap> = emptyList(),
    val isPlacingTimeTrap: Boolean = false,
    val pendingTrapPin: ZonePin? = null,
    val pendingTrapStationName: String? = null,
    val pendingTrapDetection: TimeTrap? = null,
    val isSubmittingTimeTrap: Boolean = false,
    val timeTrapError: ErrorType? = null,
    val timeTrapErrorKey: String? = null,
    val timeTrapErrorArgs: Map<String, String>? = null,
)

/** A sprung trap is spent: it leaves the map rather than lingering as a marker worth nothing. */
private fun List<TimeTrap>.applyTrap(trap: TimeTrap): List<TimeTrap> = when {
    trap.status == TimeTrapStatus.Sprung -> filterNot { it.uuid == trap.uuid }
    any { it.uuid == trap.uuid } -> map { if (it.uuid == trap.uuid) trap else it }
    else -> this + trap
}

/**
 * Derived rather than stored: a detection can only be resolved while the trap says Pending, so a
 * second detection, a restart or a refused call can never orphan one.
 */
private fun pendingDetectionFor(side: Side?, traps: List<TimeTrap>): TimeTrap? =
    if (side != Side.Seeker) null else traps.firstOrNull { it.status == TimeTrapStatus.Pending }
