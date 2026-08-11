package fr.gshz.hideandseek.feature.map

import android.util.Log
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.FeatureAskRequest
import fr.gshz.hideandseek.domain.model.FeatureType
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.QuestionStatus
import fr.gshz.hideandseek.domain.model.ThermometerAskRequest
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.repository.LocationRepository
import fr.gshz.hideandseek.domain.repository.PossibleAreaData
import fr.gshz.hideandseek.domain.repository.PossibleAreaRepository
import fr.gshz.hideandseek.domain.repository.QuestionPreviewRepository
import fr.gshz.hideandseek.domain.repository.QuestionPreviewRequest
import fr.gshz.hideandseek.domain.repository.QuestionRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.Job
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.flow.updateAndGet
import kotlin.math.roundToInt
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import retrofit2.HttpException

/**
 * The question sheet (ask and preview), the questions poll and the possible-area boundary: one concern
 * because asking, awaiting the reveal and narrowing the map all move through the same poll gates.
 */
@HiltViewModel
class QuestionViewModel @Inject constructor(
    private val questionSources: MapQuestionSources,
    private val locationRepository: LocationRepository,
    private val sessionEvents: MapSessionEvents,
    private val possibleAreaRefreshFlow: PossibleAreaRefreshFlow,
) : ViewModel() {

    private val simulationState = MutableStateFlow<SimulationState?>(null)

    /** Bumping this restarts the polls, so an answered question narrows the map at once, not one poll later. */
    private val questionPollTrigger = MutableStateFlow(0)

    /** Keeps the questions poll alive after the sheet closes, so the pending chip tracks the reveal. */
    private val hasOutstandingQuestion = MutableStateFlow(false)

    private val selfGps: StateFlow<ZonePin?> = locationRepository.lastKnownLocation
        .map { loc -> loc?.let { ZonePin(it.latitude, it.longitude) } }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), null)

    private var previewDebounceJob: Job? = null

    @OptIn(ExperimentalCoroutinesApi::class)
    private val possibleArea: StateFlow<PossibleAreaData?> = possibleAreaRefreshFlow.refresh.flatMapLatest {
        flow {
            while (currentCoroutineContext().isActive) {
                val roundUuid = sessionEvents.sessionRepository.currentSession()?.roundUuid
                if (roundUuid != null) {
                    try {
                        emit(questionSources.possibleAreaRepository.getPossibleArea(roundUuid))
                    } catch (e: IOException) {
                        Log.w(TAG, "Failed to poll possible area", e)
                    } catch (e: HttpException) {
                        sessionEvents.handleSessionExpiry(e)
                        Log.w(TAG, "Failed to poll possible area", e)
                    }
                } else {
                    emit(null)
                }
                delay(POSSIBLE_AREA_POLL_MS)
            }
        }
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), null)

    /** Polls while the question sheet is open or a question awaits reveal; otherwise never hits the API. */
    @OptIn(ExperimentalCoroutinesApi::class)
    private val questions: StateFlow<List<AskedQuestion>> = combine(
        questionPollTrigger,
        simulationState.map { it != null }.distinctUntilChanged(),
        hasOutstandingQuestion,
    ) { trigger, sheetOpen, outstanding -> trigger to (sheetOpen || outstanding) }
        .distinctUntilChanged()
        .flatMapLatest { (_, active) ->
            flow {
                val session = sessionEvents.sessionRepository.currentSession()
                val roundUuid = session?.roundUuid
                if (!active || roundUuid == null) {
                    emit(emptyList())
                    return@flow
                }
                while (currentCoroutineContext().isActive) {
                    try {
                        val list = questionSources.questionRepository.listQuestions(roundUuid)
                        hasOutstandingQuestion.value = list.any { it.isOutstanding }
                        emit(list)
                    } catch (e: IOException) {
                        Log.w(TAG, "Failed to poll questions", e)
                    } catch (e: HttpException) {
                        sessionEvents.handleSessionExpiry(e)
                        Log.w(TAG, "Failed to poll questions", e)
                    }
                    delay(QUESTION_POLL_INTERVAL_MS)
                }
            }
        }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), emptyList())

    val uiState: StateFlow<MapQuestionUiState> = combine(possibleArea, questions, simulationState) { pa, qs, sim ->
        MapQuestionUiState(
            possibleAreaGeoJson = pa?.possibleAreaGeoJson?.takeUnless(::isEmptyGeoJsonGeometry),
            exclusionGeoJson = pa?.exclusionGeoJson,
            outstandingQuestion = qs.firstOrNull { it.isOutstanding },
            askedQuestions = qs.filter { q ->
                q.status != QuestionStatus.Randomized &&
                    (q.revealedAt != null || q.status == QuestionStatus.Vetoed)
            },
            simulation = sim,
        )
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), MapQuestionUiState())

    init {
        seedOutstandingQuestion()
        observeGpsForAskPreview()
        observeChatQuestionEvents()
        observeSessionChanges()
        viewModelScope.launch {
            sessionEvents.gameEventRepository.reconnectedEvents.collect {
                seedOutstandingQuestion()
            }
        }
        viewModelScope.launch {
            sessionEvents.gameEventRepository.possibleAreaEvents.collect {
                Log.d(TAG, "Possible-area event received, refreshing boundary")
                possibleAreaRefreshFlow.refresh.update { it + 1 }
            }
        }
    }

    fun enterSimulation(category: QuestionCategory, selectedTransitLines: List<TransitLine>) {
        val traveling = questions.value.any { it.isTravelingThermometer }
        simulationState.update {
            if (traveling) {
                SimulationState(category = QuestionCategory.Thermometer, mode = QuestionSheetMode.Ask)
            } else {
                SimulationState(
                    category = category,
                    mode = QuestionSheetMode.Ask,
                    availableTransitLines = selectedTransitLines,
                )
            }
        }
        questionPollTrigger.update { it + 1 }
    }

    fun exitSimulation() {
        previewDebounceJob?.cancel()
        simulationState.update { null }
    }

    fun setSimCategory(category: QuestionCategory, selectedTransitLines: List<TransitLine>) {
        previewDebounceJob?.cancel()
        simulationState.update { prev ->
            SimulationState(
                category = category,
                mode = if (category == QuestionCategory.Photos) QuestionSheetMode.Ask
                    else prev?.mode ?: QuestionSheetMode.Ask,
                availableTransitLines = selectedTransitLines,
            )
        }
    }

    fun onCustomRadiusChange(text: String, edition: Edition) {
        val parsed = parseCustomRadius(text, edition)
        val next = simulationState.value?.copy(
            customRadiusText = text,
            radiusMeters = parsed,
        )
        simulationState.value = next
        if (parsed != null) next?.let { updateSimulationGeometry(it) }
    }

    /**
     * One entry point for the sheet's field edits: the transform is applied to the open sheet state, and
     * the preview shape follows whenever the geometry depends on what changed.
     */
    fun updateSimulation(refreshGeometry: Boolean = true, transform: (SimulationState) -> SimulationState) {
        val next = simulationState.updateAndGet { it?.let(transform) }
        if (refreshGeometry) next?.let { updateSimulationGeometry(it) }
    }

    fun askSheetQuestion() {
        val state = simulationState.value ?: return
        if (state.mode == QuestionSheetMode.Ask) {
            askRealQuestion(state)
        } else {
            viewModelScope.launch {
                val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
                try {
                    askSimulatedFor(state, session.roundUuid, session.playerUuid, questionSources.questionRepository)
                    hasOutstandingQuestion.value = true
                    questionPollTrigger.update { it + 1 }
                    exitSimulation()
                } catch (_: IOException) {
                } catch (e: HttpException) {
                    sessionEvents.handleSessionExpiry(e)
                }
            }
        }
    }

    /**
     * Radar, features and photos submit through this path; Thermometer submits through
     * startThermometer/confirmThermometerArrival.
     */
    private fun askRealQuestion(state: SimulationState) {
        if (state.category == QuestionCategory.Thermometer) return
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            simulationState.update { it?.copy(isSubmitting = true, error = null, errorKey = null, errorArgs = null) }
            try {
                val submitted = submitRealAsk(state, session)
                simulationState.update { it?.copy(isSubmitting = false) }
                if (submitted) {
                    hasOutstandingQuestion.value = true
                    questionPollTrigger.update { it + 1 }
                    exitSimulation()
                }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to ask question", e)
                simulationState.update { it?.copy(isSubmitting = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to ask question", e)
                simulationState.update {
                    it?.copy(
                        isSubmitting = false,
                        error = e.toErrorType(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    private suspend fun submitRealAsk(state: SimulationState, session: PlayerSession): Boolean =
        when (state.category) {
            QuestionCategory.Radar -> {
                val location = currentLocationOrNull()
                location != null &&
                    askRealRadar(
                        state, session.roundUuid, session.playerUuid, location,
                        questionSources.questionRepository,
                    )
            }
            QuestionCategory.Measuring, QuestionCategory.Matching, QuestionCategory.Tentacles -> {
                val location = currentLocationOrNull()
                location != null &&
                    askRealFeature(
                        state, session.roundUuid, session.playerUuid, location,
                        questionSources.questionRepository,
                    )
            }
            QuestionCategory.Photos ->
                askRealPhoto(state, session.roundUuid, session.playerUuid, questionSources.questionRepository)
            else -> false
        }

    fun startThermometer() {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            val distanceMeters = simulationState.value?.distanceMeters ?: return@launch
            val location = currentLocationOrNull() ?: return@launch
            simulationState.update {
                it?.copy(
                    isSubmitting = true,
                    error = null,
                    errorKey = null,
                    errorArgs = null,
                    locationPermissionMissing = false,
                )
            }
            try {
                val asked = questionSources.questionRepository.askThermometerQuestion(
                    ThermometerAskRequest(
                        roundUuid = session.roundUuid,
                        askerPlayerUuid = session.playerUuid,
                        startLat = location.latitude,
                        startLng = location.longitude,
                        distanceMeters = distanceMeters.toDouble(),
                    ),
                )
                hasOutstandingQuestion.value = true
                questionPollTrigger.update { it + 1 }
                // Hold isSubmitting until the poll reflects the ask, else Start re-enables for one round-trip.
                withTimeoutOrNull(THERMOMETER_CONFIRM_TIMEOUT_MS) {
                    questions.first { list -> list.any { it.uuid == asked.uuid } }
                }
                simulationState.update { it?.copy(isSubmitting = false) }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to start thermometer", e)
                simulationState.update { it?.copy(isSubmitting = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to start thermometer", e)
                simulationState.update {
                    it?.copy(
                        isSubmitting = false,
                        error = e.toErrorType(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    fun confirmThermometerArrival() {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            val traveling = questions.value.firstOrNull { it.isTravelingThermometer } ?: return@launch
            val location = currentLocationOrNull() ?: return@launch
            simulationState.update { it?.copy(isSubmitting = true, error = null, errorKey = null, errorArgs = null) }
            try {
                questionSources.questionRepository.completeThermometer(
                    questionUuid = traveling.uuid,
                    askerPlayerUuid = session.playerUuid,
                    endLat = location.latitude,
                    endLng = location.longitude,
                )
                simulationState.update { it?.copy(isSubmitting = false) }
                hasOutstandingQuestion.value = true
                questionPollTrigger.update { it + 1 }
                exitSimulation()
            } catch (e: IOException) {
                Log.w(TAG, "Failed to complete thermometer", e)
                simulationState.update { it?.copy(isSubmitting = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to complete thermometer", e)
                simulationState.update {
                    it?.copy(
                        isSubmitting = false,
                        error = e.toErrorType(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    fun cancelQuestion() {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            val outstanding = questions.value.firstOrNull { it.revealedAt == null } ?: return@launch
            simulationState.update { it?.copy(isSubmitting = true, error = null, errorKey = null, errorArgs = null) }
            try {
                questionSources.questionRepository.cancelQuestion(outstanding.uuid, session.playerUuid)
                simulationState.update { it?.copy(isSubmitting = false) }
                questionPollTrigger.update { it + 1 }
            } catch (e: IOException) {
                Log.w(TAG, "Cancel failed", e)
                simulationState.update { it?.copy(isSubmitting = false, error = ErrorType.Network) }
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Cancel failed", e)
                simulationState.update {
                    it?.copy(
                        isSubmitting = false,
                        error = e.toErrorType(),
                        errorKey = e.serverErrorKey(),
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    private fun updateSimulationGeometry(state: SimulationState) {
        val anchor = state.seeker
            ?: selfGps.value.takeIf { state.mode == QuestionSheetMode.Ask }
        if (anchor != null) {
            val geoJson = buildSimGeoJson(state, anchor)
            simulationState.update { it?.copy(previewGeoJson = geoJson) }
        }
        if (state.mode == QuestionSheetMode.Preview) {
            previewDebounceJob?.cancel()
            previewDebounceJob = viewModelScope.launch {
                delay(PREVIEW_DEBOUNCE_MS)
                refreshPreviewReadout()
            }
        }
    }

    fun fetchCandidateFeatures() {
        val state = simulationState.value ?: return
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            val featureType = state.featureType ?: return@launch
            try {
                val conn = questionSources.connectionStore.current() ?: return@launch
                val url = conn.apiUrl.trimEnd('/') +
                    "/api/rounds/${session.roundUuid}/features" +
                    "?type=$featureType&playerUuid=${session.playerUuid}"
                val dtos = questionSources.questionPreviewRepository.getFeatures(url)
                val features = dtos.map { dto ->
                    FeatureSummary(dto.uuid, dto.name, dto.lat, dto.lng)
                }
                val anchor = state.seeker
                    ?: selfGps.value.takeIf { state.mode == QuestionSheetMode.Ask }
                val nearest = anchor?.let { seeker ->
                    features.minByOrNull {
                        haversineMeters(seeker.latitude, seeker.longitude, it.latitude, it.longitude)
                    }
                }
                simulationState.update { prev ->
                    if (prev?.featureType != featureType) return@update prev
                    prev.copy(
                        candidateFeatures = features,
                        chosenFeatureId = nearest?.uuid,
                    )
                }
                simulationState.value?.let { updateSimulationGeometry(it) }
            } catch (_: IOException) {
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
            }
        }
    }

    private suspend fun refreshPreviewReadout() {
        val session = sessionEvents.sessionRepository.currentSession() ?: return
        val snapshot = simulationState.value?.takeIf {
            it.seeker != null && it.answer != null
        } ?: return
        try {
            val seeker = checkNotNull(snapshot.seeker)
            val answer = checkNotNull(snapshot.answer)
            val result = questionSources.questionPreviewRepository.preview(
                QuestionPreviewRequest(
                    roundUuid = session.roundUuid,
                    askerPlayerUuid = session.playerUuid,
                    category = snapshot.category.wireValue,
                    seekerLat = seeker.latitude,
                    seekerLng = seeker.longitude,
                    endLat = snapshot.end?.latitude,
                    endLng = snapshot.end?.longitude,
                    radiusMeters = snapshot.radiusMeters,
                    featureType = snapshot.featureType,
                    hypotheticalFeatureId = snapshot.chosenFeatureId,
                    withinMeters = snapshot.withinMeters,
                    hypotheticalAnswer = answer.wireValue,
                ),
            )
            simulationState.update { prev ->
                prev?.copy(
                    currentAreaKm2 = result.currentAreaKm2,
                    projectedAreaKm2 = result.projectedAreaKm2,
                    previewGeoJson = result.excludedPossibleAreaGeoJson
                        ?: prev.previewGeoJson,
                )
            }
        } catch (_: IOException) {
        } catch (e: HttpException) {
            sessionEvents.handleSessionExpiry(e)
        }
    }

    /** Ask mode anchors the preview shape to GPS; rebuild it when a fix arrives or moves. */
    private fun observeGpsForAskPreview() {
        viewModelScope.launch {
            selfGps.collect { gps ->
                if (gps == null) return@collect
                val sim = simulationState.value ?: return@collect
                if (sim.mode != QuestionSheetMode.Ask) return@collect
                if (sim.travelingThermometer != null) return@collect
                updateSimulationGeometry(sim)
            }
        }
    }

    /** One-shot discovery of a question asked while the app was dead or SSE was down (survives restarts). */
    private fun seedOutstandingQuestion() {
        viewModelScope.launch {
            val roundUuid = sessionEvents.sessionRepository.currentSession()?.roundUuid ?: return@launch
            try {
                val outstanding = questionSources.questionRepository.listQuestions(roundUuid).any { it.isOutstanding }
                if (outstanding) hasOutstandingQuestion.value = true
            } catch (e: IOException) {
                Log.w(TAG, "Failed to seed outstanding question", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to seed outstanding question", e)
            }
        }
    }

    /** A question posted to chat means the hider chip must wake; a reply means it may clear. */
    private fun observeChatQuestionEvents() {
        viewModelScope.launch {
            sessionEvents.gameEventRepository.chatEvents.collect { event ->
                if (event.messageType == CHAT_TYPE_QUESTION) {
                    hasOutstandingQuestion.value = true
                    questionPollTrigger.update { it + 1 }
                } else if (event.replyToUuid != null) {
                    questionPollTrigger.update { it + 1 }
                }
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
                        possibleAreaRefreshFlow.refresh.update { it + 1 }
                        questionPollTrigger.update { it + 1 }
                    }
                }
        }
    }

    private suspend fun currentLocationOrNull(): DeviceLocation? {
        val location = try {
            locationRepository.getCurrentLocation()
        } catch (e: SecurityException) {
            Log.w(TAG, "Location permission was revoked", e)
            null
        }
        if (location == null) {
            simulationState.update { it?.copy(locationPermissionMissing = true) }
        }
        return location
    }

    private companion object {
        const val TAG = "QuestionViewModel"
        const val CHAT_TYPE_QUESTION = "question"
        const val STOP_TIMEOUT_MS = 5_000L
        const val POSSIBLE_AREA_POLL_MS = 120_000L
        // A safety net, not a mechanism: chat events already announce an ask, a reveal and a power-up.
        const val QUESTION_POLL_INTERVAL_MS = 120_000L
        /** Bounds the wait on the refetch that asking triggers, so it does not depend on the poll interval. */
        const val THERMOMETER_CONFIRM_TIMEOUT_MS = 35_000L
        const val PREVIEW_DEBOUNCE_MS = 250L
    }
}

data class MapQuestionUiState(
    val possibleAreaGeoJson: String? = null,
    val exclusionGeoJson: String? = null,
    val outstandingQuestion: AskedQuestion? = null,
    val askedQuestions: List<AskedQuestion> = emptyList(),
    val simulation: SimulationState? = null,
)

private suspend fun askSimulatedFor(
    simState: SimulationState,
    roundUuid: String,
    playerUuid: String,
    questionRepository: QuestionRepository,
) {
    when (simState.category) {
        QuestionCategory.Radar -> askSimRadar(simState, roundUuid, playerUuid, questionRepository)
        QuestionCategory.Thermometer -> askSimThermometer(simState, roundUuid, playerUuid, questionRepository)
        QuestionCategory.Measuring -> askSimMeasuring(simState, roundUuid, playerUuid, questionRepository)
        QuestionCategory.Matching -> askSimMatching(simState, roundUuid, playerUuid, questionRepository)
        QuestionCategory.Tentacles -> askSimTentacles(simState, roundUuid, playerUuid, questionRepository)
        else -> {}
    }
}

private suspend fun askSimRadar(
    simState: SimulationState,
    roundUuid: String,
    playerUuid: String,
    questionRepository: QuestionRepository,
) {
    val pin = simState.seeker ?: return
    questionRepository.askRadarQuestion(
        roundUuid = roundUuid,
        askerPlayerUuid = playerUuid,
        radiusMeters = simState.radiusMeters?.toDouble() ?: SIM_DEFAULT_RADIUS_M,
        seekerLat = pin.latitude,
        seekerLng = pin.longitude,
        isCustomRadius = simState.isCustomRadius,
    )
}

private suspend fun askSimThermometer(
    simState: SimulationState,
    roundUuid: String,
    playerUuid: String,
    questionRepository: QuestionRepository,
) {
    val start = simState.seeker ?: return
    simState.end ?: return
    questionRepository.askThermometerQuestion(
        ThermometerAskRequest(
            roundUuid = roundUuid,
            askerPlayerUuid = playerUuid,
            startLat = start.latitude,
            startLng = start.longitude,
            distanceMeters = simState.distanceMeters?.toDouble()
                ?: SIM_DEFAULT_RADIUS_M,
        ),
    )
}

private suspend fun askSimMeasuring(
    simState: SimulationState,
    roundUuid: String,
    playerUuid: String,
    questionRepository: QuestionRepository,
) {
    val pin = simState.seeker ?: return
    val request = if (simState.seaLevelSelected) {
        FeatureAskRequest(
            roundUuid = roundUuid,
            askerPlayerUuid = playerUuid,
            category = QuestionCategory.Measuring,
            seekerLat = pin.latitude,
            seekerLng = pin.longitude,
            seaLevel = true,
        )
    } else {
        simState.featureType?.let { FeatureType.fromWireValue(it) }?.let { ft ->
            FeatureAskRequest(
                roundUuid = roundUuid,
                askerPlayerUuid = playerUuid,
                category = QuestionCategory.Measuring,
                featureType = ft,
                seekerLat = pin.latitude,
                seekerLng = pin.longitude,
            )
        }
    }
    request?.let { questionRepository.askFeatureQuestion(it) }
}

private suspend fun askSimMatching(
    simState: SimulationState,
    roundUuid: String,
    playerUuid: String,
    questionRepository: QuestionRepository,
) {
    val pin = simState.seeker ?: return
    val request = featureAskRequest(simState, roundUuid, playerUuid, pin.latitude, pin.longitude) ?: return
    questionRepository.askFeatureQuestion(request)
}

private suspend fun askSimTentacles(
    simState: SimulationState,
    roundUuid: String,
    playerUuid: String,
    questionRepository: QuestionRepository,
) {
    val pin = simState.seeker ?: return
    val ft = simState.featureType
        ?.let { FeatureType.fromWireValue(it) } ?: return
    questionRepository.askFeatureQuestion(
        FeatureAskRequest(
            roundUuid = roundUuid,
            askerPlayerUuid = playerUuid,
            category = QuestionCategory.Tentacles,
            featureType = ft,
            seekerLat = pin.latitude,
            seekerLng = pin.longitude,
        ),
    )
}

private suspend fun askRealRadar(
    state: SimulationState,
    roundUuid: String,
    playerUuid: String,
    location: DeviceLocation,
    questionRepository: QuestionRepository,
): Boolean {
    questionRepository.askRadarQuestion(
        roundUuid = roundUuid,
        askerPlayerUuid = playerUuid,
        radiusMeters = state.radiusMeters?.toDouble() ?: SIM_DEFAULT_RADIUS_M,
        seekerLat = location.latitude,
        seekerLng = location.longitude,
        isCustomRadius = state.isCustomRadius,
    )
    return true
}

private suspend fun askRealFeature(
    state: SimulationState,
    roundUuid: String,
    playerUuid: String,
    location: DeviceLocation,
    questionRepository: QuestionRepository,
): Boolean {
    val request = featureAskRequest(
        state, roundUuid, playerUuid, location.latitude, location.longitude, location.altitude,
    )
    return if (request != null) {
        questionRepository.askFeatureQuestion(request)
        true
    } else {
        false
    }
}

/**
 * Transit Line is a Matching option with a null feature type: send the chosen line's OSM ids instead.
 * Sea Level is the equivalent Measuring special: no feature type, the seeker's altitude instead.
 */
private fun featureAskRequest(
    state: SimulationState,
    roundUuid: String,
    playerUuid: String,
    seekerLat: Double,
    seekerLng: Double,
    seekerAltitude: Double? = null,
): FeatureAskRequest? =
    if (state.category == QuestionCategory.Measuring && state.seaLevelSelected) {
        FeatureAskRequest(
            roundUuid = roundUuid,
            askerPlayerUuid = playerUuid,
            category = QuestionCategory.Measuring,
            seekerLat = seekerLat,
            seekerLng = seekerLng,
            seaLevel = true,
            seekerAltitude = seekerAltitude,
        )
    } else if (state.category == QuestionCategory.Matching && state.stationNameLengthSelected) {
        FeatureAskRequest(
            roundUuid = roundUuid,
            askerPlayerUuid = playerUuid,
            category = QuestionCategory.Matching,
            seekerLat = seekerLat,
            seekerLng = seekerLng,
            stationNameLength = true,
        )
    } else if (state.category == QuestionCategory.Matching && state.transitLineSelected) {
        state.selectedTransitLine?.let { line ->
            FeatureAskRequest(
                roundUuid = roundUuid,
                askerPlayerUuid = playerUuid,
                category = QuestionCategory.Matching,
                seekerLat = seekerLat,
                seekerLng = seekerLng,
                transitLineOsmId = line.osmId,
                transitLineOsmType = line.osmType,
            )
        }
    } else {
        state.featureType?.let { FeatureType.fromWireValue(it) }?.let { ft ->
            FeatureAskRequest(
                roundUuid = roundUuid,
                askerPlayerUuid = playerUuid,
                category = state.category,
                featureType = ft,
                seekerLat = seekerLat,
                seekerLng = seekerLng,
                withinMeters = state.withinMeters?.toDouble(),
            )
        }
    }

private suspend fun askRealPhoto(
    state: SimulationState,
    roundUuid: String,
    playerUuid: String,
    questionRepository: QuestionRepository,
): Boolean {
    val photoTarget = state.photoTarget ?: return false
    questionRepository.askPhotoQuestion(
        roundUuid = roundUuid,
        askerPlayerUuid = playerUuid,
        photoTarget = photoTarget,
    )
    return true
}

private fun parseCustomRadius(text: String, edition: Edition): Int? {
    val cleaned = text.trim().replace(',', '.')
    val meters = cleaned.toDoubleOrNull()
        ?.takeIf { it > 0 }
        ?.let { number ->
            val m = when (edition) {
                Edition.Imperial -> number * METERS_PER_MILE
                else -> number * METERS_PER_KM
            }
            m.takeIf { it <= Int.MAX_VALUE.toDouble() }
        }
        ?.roundToInt()
    return meters
}

private fun buildSimGeoJson(state: SimulationState, seeker: ZonePin): String? = when (state.category) {
    QuestionCategory.Radar -> state.radiusMeters?.let {
        circlePolygonGeoJson(seeker.latitude, seeker.longitude, it.toDouble())
    }
    QuestionCategory.Thermometer -> {
        val end = state.end
        if (end != null) {
            bisectorLineGeoJson(
                seeker.latitude, seeker.longitude,
                end.latitude, end.longitude,
            )
        } else {
            state.distanceMeters?.let { dist ->
                circlePolygonGeoJson(seeker.latitude, seeker.longitude, dist.toDouble())
            }
        }
    }
    QuestionCategory.Measuring, QuestionCategory.Matching, QuestionCategory.Tentacles ->
        state.previewGeoJson
    else -> null
}

private const val SIM_DEFAULT_RADIUS_M = 500.0
private const val METERS_PER_KM = 1000.0
private const val METERS_PER_MILE = 1609.344
