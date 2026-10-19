package fr.gshz.hideandseek.feature.map

import android.util.Log
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.domain.model.TransitDepartureGroup
import fr.gshz.hideandseek.domain.repository.GameRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import retrofit2.HttpException

enum class TimetableError { Unavailable, Network }

data class TimetableUiState(
    val isVisible: Boolean = false,
    val stationId: String? = null,
    val stationName: String = "",
    val timezone: String = "",
    val groups: List<TransitDepartureGroup> = emptyList(),
    val isLoading: Boolean = false,
    val error: TimetableError? = null,
    val serverNow: Long = 0,
    val showFullDay: Boolean = false,
)

@HiltViewModel
class TimetableViewModel @Inject constructor(
    private val gameRepository: GameRepository,
    private val sessionEvents: MapSessionEvents,
) : ViewModel() {

    private val _uiState = MutableStateFlow(TimetableUiState())
    val uiState: StateFlow<TimetableUiState> = _uiState.asStateFlow()

    private var pollingJob: Job? = null

    fun open(stationId: String, stationName: String) {
        pollingJob?.cancel()
        _uiState.update {
            val sameStation = it.stationId == stationId
            it.copy(
                isVisible = true,
                stationId = stationId,
                stationName = stationName,
                timezone = if (sameStation) it.timezone else "",
                groups = if (sameStation) it.groups else emptyList(),
                serverNow = if (sameStation) it.serverNow else 0,
                showFullDay = if (sameStation) it.showFullDay else false,
                isLoading = true,
                error = null,
            )
        }
        pollingJob = viewModelScope.launch {
            while (isActive) {
                refresh(stationId)
                if (_uiState.value.error == TimetableError.Unavailable) return@launch
                delay(POLL_INTERVAL_MS)
            }
        }
    }

    fun close() {
        pollingJob?.cancel()
        pollingJob = null
        _uiState.update {
            it.copy(
                isVisible = false,
                isLoading = false,
                showFullDay = false,
                groups = emptyList(),
                serverNow = 0,
            )
        }
    }

    fun retry() {
        val state = _uiState.value
        val stationId = state.stationId ?: return
        open(stationId, state.stationName)
    }

    fun toggleFullDay() {
        val state = _uiState.value
        val stationId = state.stationId ?: return
        if (!state.isVisible) return
        _uiState.update {
            it.copy(
                showFullDay = !it.showFullDay,
                groups = emptyList(),
                serverNow = 0,
                isLoading = true,
                error = null,
            )
        }
        viewModelScope.launch { refresh(stationId) }
    }

    private suspend fun refresh(stationId: String) {
        try {
            val fullDay = _uiState.value.showFullDay
            val departures = gameRepository.getStationDepartures(
                sessionEvents.gameUuid,
                stationId,
                fullDay,
            )
            _uiState.update {
                it.copy(
                    stationName = departures.stationName.ifBlank { it.stationName },
                    timezone = departures.timezone,
                    groups = departures.groups,
                    isLoading = false,
                    error = null,
                    serverNow = departures.serverNow,
                )
            }
        } catch (e: HttpException) {
            sessionEvents.handleSessionExpiry(e)
            Log.w(TAG, "Failed to load station departures", e)
            val error = if (e.code() == HTTP_NOT_FOUND) TimetableError.Unavailable else TimetableError.Network
            _uiState.update {
                it.copy(
                    isLoading = false,
                    error = error,
                    groups = if (error == TimetableError.Unavailable) emptyList() else it.groups,
                )
            }
        } catch (e: IOException) {
            Log.w(TAG, "Failed to load station departures", e)
            _uiState.update { it.copy(isLoading = false, error = TimetableError.Network) }
        }
    }

    private companion object {
        const val TAG = "TimetableViewModel"
        const val POLL_INTERVAL_MS = 30_000L
        const val HTTP_NOT_FOUND = 404
    }
}
