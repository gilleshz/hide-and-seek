package fr.gshz.hideandseek.feature.map

import android.util.Log
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.domain.model.SeekerMarker
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.repository.SeekerMarkerRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import retrofit2.HttpException

/** The seeker's suspected-station markers, seeded over REST and kept live by the SSE candidates. */
@HiltViewModel
class SeekerMarkersViewModel @Inject constructor(
    private val seekerMarkerRepository: SeekerMarkerRepository,
    private val sessionEvents: MapSessionEvents,
) : ViewModel() {

    private val seekerMarkers = MutableStateFlow<List<SeekerMarker>>(emptyList())

    /** The seeker gates read the side eagerly, so they stay correct without a collector in sight. */
    private val side: StateFlow<Side?> = sessionEvents.sessionRepository.observeSession()
        .map { it?.side?.let(Side::fromWireValue) }
        .stateIn(viewModelScope, SharingStarted.Eagerly, null)

    val uiState: StateFlow<List<SeekerMarker>> = seekerMarkers

    init {
        loadSeekerMarkers()
        observeSeekerCandidateEvents()
        observeSessionChanges()
        observeReconnects()
    }

    private fun loadSeekerMarkers() {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            if (session.side != Side.Seeker.wireValue) return@launch
            try {
                seekerMarkers.value = seekerMarkerRepository.listMarkers(session.roundUuid)
            } catch (e: IOException) {
                Log.w(TAG, "Failed to load seeker markers", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to load seeker markers", e)
            }
        }
    }

    private fun observeSeekerCandidateEvents() {
        viewModelScope.launch {
            sessionEvents.gameEventRepository.seekerCandidateAdded.collect { event ->
                if (!isSeeker()) return@collect
                seekerMarkers.update { current ->
                    if (current.any { it.uuid == event.uuid }) {
                        current
                    } else {
                        current + SeekerMarker(event.uuid, event.playerUuid, event.lat, event.lng)
                    }
                }
            }
        }
        viewModelScope.launch {
            sessionEvents.gameEventRepository.seekerCandidateRemoved.collect { event ->
                seekerMarkers.update { current -> current.filterNot { it.uuid == event.uuid } }
            }
        }
    }

    fun markSuspectedStation(lat: Double, lng: Double) {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            if (!isSeeker()) return@launch
            try {
                val marker = seekerMarkerRepository.addMarker(session.roundUuid, session.playerUuid, lat, lng)
                seekerMarkers.update { current ->
                    if (current.any { it.uuid == marker.uuid }) current else current + marker
                }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to mark suspected station", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to mark suspected station", e)
            }
        }
    }

    fun unmarkStation(markerUuid: String) {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            if (!isSeeker()) return@launch
            try {
                seekerMarkerRepository.deleteMarker(session.roundUuid, markerUuid)
                seekerMarkers.update { current -> current.filterNot { it.uuid == markerUuid } }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to unmark station", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to unmark station", e)
            }
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
                        seekerMarkers.value = emptyList()
                        loadSeekerMarkers()
                    }
                }
        }
    }

    private fun observeReconnects() {
        viewModelScope.launch {
            sessionEvents.gameEventRepository.reconnectedEvents.collect {
                loadSeekerMarkers()
            }
        }
    }

    private fun isSeeker(): Boolean = side.value == Side.Seeker

    private companion object {
        const val TAG = "SeekerMarkersViewModel"
    }
}
