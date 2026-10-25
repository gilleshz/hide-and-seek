package fr.gshz.hideandseek.feature.map

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.util.isSessionExpired
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.domain.model.FeatureType
import fr.gshz.hideandseek.domain.repository.QuestionPreviewRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import retrofit2.HttpException

/** The hider's read-only POI browser: no anchor, no answer, no selection. Null means closed. */
data class PoiBrowseState(
    val featureType: FeatureType? = null,
    val features: List<FeatureSummary> = emptyList(),
    val isLoading: Boolean = false,
    val error: ErrorType? = null,
    val errorKey: String? = null,
    val errorArgs: Map<String, String>? = null,
)

@HiltViewModel
class PoiBrowseViewModel @Inject constructor(
    private val sessionEvents: MapSessionEvents,
    private val questionPreviewRepository: QuestionPreviewRepository,
    private val connectionStore: ConnectionStore,
) : ViewModel() {

    private val browseState = MutableStateFlow<PoiBrowseState?>(null)
    val uiState: StateFlow<PoiBrowseState?> = browseState.asStateFlow()

    fun open() {
        browseState.update { it ?: PoiBrowseState() }
    }

    fun close() {
        browseState.value = null
    }

    fun selectFeatureType(type: FeatureType?) {
        browseState.update { current ->
            current?.copy(
                featureType = type,
                features = emptyList(),
                isLoading = type != null,
                error = null,
                errorKey = null,
                errorArgs = null,
            )
        }
        if (type != null && browseState.value?.featureType == type) {
            viewModelScope.launch { fetch(type) }
        }
    }

    private suspend fun fetch(type: FeatureType) {
        val connection = connectionStore.current()
        val roundUuid = sessionEvents.sessionRepository.currentSession()?.roundUuid
        if (connection == null || roundUuid == null) {
            applyResult(type) { it.copy(isLoading = false) }
            return
        }
        try {
            val url = connection.apiUrl.trimEnd('/') +
                "/api/rounds/$roundUuid/features?type=${type.wireValue}"
            val features = questionPreviewRepository.getFeatures(url).map { dto ->
                FeatureSummary(dto.uuid, dto.name, dto.lat, dto.lng)
            }
            applyResult(type) { it.copy(features = features, isLoading = false) }
        } catch (_: IOException) {
            applyResult(type) {
                it.copy(features = emptyList(), isLoading = false, error = ErrorType.Network)
            }
        } catch (e: HttpException) {
            sessionEvents.handleSessionExpiry(e)
            if (!e.isSessionExpired()) {
                applyResult(type) {
                    it.copy(
                        features = emptyList(),
                        isLoading = false,
                        error = e.toErrorType(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    /** A slow answer for a previous type must not replace the newer selection. */
    private fun applyResult(type: FeatureType, transform: (PoiBrowseState) -> PoiBrowseState) {
        browseState.update { current ->
            if (current?.featureType != type) current else transform(current)
        }
    }
}
