package fr.gshz.hideandseek.feature.map

import android.util.Log
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.ManualConstraint
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.repository.ManualConstraintAddedEvent
import fr.gshz.hideandseek.domain.repository.ManualConstraintRepository
import fr.gshz.hideandseek.domain.repository.ManualConstraintRemovedEvent
import fr.gshz.hideandseek.domain.repository.QuestionRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.getAndUpdate
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import retrofit2.HttpException

/** The seeker's constraint polygon and the hider's street trace, plus the manual constraints they edit. */
@HiltViewModel
class DrawingViewModel @Inject constructor(
    private val streetNetworkLoader: StreetNetworkLoader,
    private val manualConstraintSource: ManualConstraintSource,
    private val questionRepository: QuestionRepository,
    private val traceImageWriter: TraceImageWriter,
    private val sessionEvents: MapSessionEvents,
    private val zonePlacementCancelSignal: ZonePlacementCancelSignal,
) : ViewModel() {

    private val gameUuid: String = sessionEvents.gameUuid

    private val drawingState = MutableStateFlow(DrawingState())
    private val traceReview = MutableStateFlow<TraceReviewState?>(null)
    private val manualConstraints = MutableStateFlow<List<ManualConstraint>>(emptyList())

    /** Built once when the network lands, then read on every tap. */
    private val streetGraph = MutableStateFlow<StreetGraph?>(null)
    private val streetStatus = MutableStateFlow(StreetDataStatus.Loading)

    /** The seeker gates read the side eagerly, so they stay correct without a collector in sight. */
    private val side: StateFlow<Side?> = sessionEvents.sessionRepository.observeSession()
        .map { it?.side?.let(Side::fromWireValue) }
        .stateIn(viewModelScope, SharingStarted.Eagerly, null)

    // The trace request retries the fetch, so the guard stops the two entry points fetching twice.
    private var streetNetworkJob: Job? = null

    /** Chat was popped off the back stack on the way here, so a sent trace navigates back to it. */
    private val _traceSent = MutableSharedFlow<Unit>(replay = 0, extraBufferCapacity = 1)
    val traceSent: SharedFlow<Unit> = _traceSent.asSharedFlow()

    val uiState: StateFlow<MapDrawingUiState> = combine(
        drawingState, traceReview, manualConstraints,
    ) { drawing, review, constraints ->
        MapDrawingUiState(
            drawing = drawing.toUiState(),
            selectedManualConstraintUuid = drawing.selectedManualConstraintUuid,
            traceReview = review,
            manualConstraints = constraints,
        )
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(STOP_TIMEOUT_MS), MapDrawingUiState())

    init {
        loadManualConstraints()
        observeManualConstraintEvents()
        observeTraceRequests()
        observeSessionChanges()
        loadStreetNetwork()
        viewModelScope.launch {
            sessionEvents.gameEventRepository.reconnectedEvents.collect {
                loadManualConstraints()
            }
        }
    }

    /** One entry point for the sheet's field edits: each call rewrites the in-progress drawing. */
    fun updateDrawing(transform: (DrawingState) -> DrawingState) {
        drawingState.update(transform)
    }

    /** A tap toggles the nearest whole street; out of range it selects nothing and is a no-op. */
    fun toggleEdgeAt(latitude: Double, longitude: Double) {
        val id = streetGraph.value?.nearestEdgeId(ZonePin(latitude, longitude)) ?: return
        drawingState.update {
            val next = if (id in it.selectedEdgeIds) it.selectedEdgeIds - id else it.selectedEdgeIds + id
            it.copy(selectedEdgeIds = next).withTrace(streetGraph.value)
        }
    }

    /** Only a seeker draws manual possible-area constraints; entering resets any prior draft. */
    fun enterDrawing() {
        if (!isSeeker()) return
        drawingState.value = DrawingState(isActive = true)
    }

    fun cancelDrawing() {
        drawingState.value = DrawingState()
        traceReview.value = null
    }

    fun confirmDrawing() {
        val state = drawingState.value
        if (!state.isActive || state.kind != DrawKind.Area) return
        if (state.vertices.size < MIN_POLYGON_VERTICES) return
        addManualConstraint(polygonRingGeoJson(state.vertices), state.mode)
        drawingState.value = DrawingState()
    }

    /**
     * The flag is raised before the coroutine starts, so a second tap on Confirm finds the render already
     * in flight and cannot race the first one for the cache file.
     */
    @Suppress("TooGenericExceptionCaught")
    fun confirmTrace(edition: Edition) {
        val previous = drawingState.getAndUpdate { state ->
            if (canRenderTrace(state, edition)) state.copy(isRendering = true, renderError = null) else state
        }
        if (!canRenderTrace(previous, edition)) return
        val paths = previous.toUiState(edition).selectedPaths
        viewModelScope.launch {
            try {
                val uri = traceImageWriter.write(paths)
                val stillRendering = drawingState.getAndUpdate { it.copy(isRendering = false) }.isRendering
                if (stillRendering) traceReview.value = TraceReviewState(imageUri = uri.toString())
            } catch (e: IOException) {
                Log.w(TAG, "Failed to render the trace", e)
                traceReview.update { null }
                drawingState.update { it.copy(isRendering = false, renderError = ErrorType.Unknown) }
            } catch (e: OutOfMemoryError) {
                // The 4:3 sheet is a 4 MB ARGB_8888 allocation: a low-memory device must be told, not killed.
                Log.w(TAG, "Failed to render the trace", e)
                traceReview.update { null }
                drawingState.update { it.copy(isRendering = false, renderError = ErrorType.Unknown) }
            } catch (e: Exception) {
                Log.w(TAG, "Failed to render the trace", e)
                traceReview.update { null }
                drawingState.update { it.copy(isRendering = false, renderError = ErrorType.Unknown) }
            }
        }
    }

    /**
     * Sending is flipped on before the coroutine starts because the session lookup suspends: a second tap
     * would otherwise post the same answer twice and write a stale review back over the cleared one.
     */
    @Suppress("TooGenericExceptionCaught")
    fun sendTrace() {
        val questionUuid = drawingState.value.questionUuid ?: return
        val previous = traceReview.getAndUpdate { review ->
            if (review == null || review.isSending) review else review.copy(isSending = true, sendFailed = false)
        }
        if (previous == null || previous.isSending) return
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession()
            if (session == null) {
                Log.w(TAG, "Failed to send the trace", IllegalStateException("No session for the trace answer"))
                traceReview.update { it?.copy(isSending = false, sendFailed = true) }
                return@launch
            }
            try {
                questionRepository.revealPhotoQuestion(questionUuid, session.playerUuid, previous.imageUri)
                traceReview.update { null }
                drawingState.update { DrawingState() }
                _traceSent.tryEmit(Unit)
            } catch (e: IOException) {
                Log.w(TAG, "Failed to send the trace", e)
                traceReview.update { it?.copy(isSending = false, sendFailed = true) }
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to send the trace", e)
                traceReview.update { it?.copy(isSending = false, sendFailed = true) }
            } catch (e: Exception) {
                Log.w(TAG, "Failed to send the trace", e)
                traceReview.update { it?.copy(isSending = false, sendFailed = true) }
            }
        }
    }

    /** Keeping the vertices is what makes this an edit loop rather than starting the trace over. */
    fun resumeTraceEditing() {
        traceReview.value = null
    }

    fun deleteSelectedManualConstraint() {
        val uuid = drawingState.value.selectedManualConstraintUuid ?: return
        deleteManualConstraint(uuid)
        drawingState.update { it.copy(selectedManualConstraintUuid = null) }
    }

    /**
     * Hider-only and silent on failure: tracing needs this network, so a fetch that never lands marks the
     * overlay Unavailable for the panel to explain rather than raising an error on a clock.
     */
    private fun loadStreetNetwork() {
        if (streetGraph.value != null || streetNetworkJob?.isActive == true) return
        streetNetworkJob = viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession()
            if (session == null || session.side != Side.Hider.wireValue) {
                publishStreetGraph(null, StreetDataStatus.Unavailable)
                return@launch
            }
            repeat(STREET_NETWORK_ATTEMPTS) { attempt ->
                if (attempt > 0) delay(STREET_NETWORK_RETRY_DELAY_MS)
                val fetched = streetNetworkLoader.fetch(session)
                if (fetched != StreetNetworkFetch.Warming) {
                    val graph = fetched.graph
                    val status = if (graph == null) StreetDataStatus.Unavailable else StreetDataStatus.Available
                    publishStreetGraph(graph, status)
                    return@launch
                }
            }
            publishStreetGraph(null, StreetDataStatus.Unavailable)
        }
    }

    private fun publishStreetGraph(graph: StreetGraph?, status: StreetDataStatus) {
        streetGraph.value = graph
        streetStatus.value = status
        drawingState.update { it.copy(streetStatus = status).withTrace(graph) }
    }

    private fun isSeeker(): Boolean = side.value == Side.Seeker

    /** The possible area, including manual constraints, is shared with hiders, so fetch for all roles. */
    private fun loadManualConstraints() {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            try {
                manualConstraints.value =
                    manualConstraintSource.manualConstraintRepository.getManualConstraints(session.roundUuid)
            } catch (e: IOException) {
                Log.w(TAG, "Failed to load manual constraints", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to load manual constraints", e)
            }
        }
    }

    private fun observeManualConstraintEvents() {
        viewModelScope.launch {
            sessionEvents.gameEventRepository.manualConstraintAdded.collect { event ->
                val known = manualConstraints.value.any { it.uuid == event.uuid }
                manualConstraints.update { current -> current.upsert(event) }
                if (!known) manualConstraintSource.possibleAreaRefreshFlow.refresh.update { it + 1 }
            }
        }
        viewModelScope.launch {
            sessionEvents.gameEventRepository.manualConstraintRemoved.collect { event ->
                val known = manualConstraints.value.any { it.uuid == event.uuid }
                manualConstraints.update { current -> current.filterNot { it.uuid == event.uuid } }
                if (known) manualConstraintSource.possibleAreaRefreshFlow.refresh.update { it + 1 }
            }
        }
    }

    fun addManualConstraint(geoJson: String, mode: ConstraintMode, label: String? = null) {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            if (!isSeeker()) return@launch
            try {
                val constraint = manualConstraintSource.manualConstraintRepository.addManualConstraint(
                    session.roundUuid, session.playerUuid, geoJson, mode, label,
                )
                manualConstraints.update { current ->
                    if (current.any { it.uuid == constraint.uuid }) current else current + constraint
                }
                manualConstraintSource.possibleAreaRefreshFlow.refresh.update { it + 1 }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to add manual constraint", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to add manual constraint", e)
            }
        }
    }

    fun deleteManualConstraint(constraintUuid: String) {
        viewModelScope.launch {
            val session = sessionEvents.sessionRepository.currentSession() ?: return@launch
            if (!isSeeker()) return@launch
            try {
                manualConstraintSource.manualConstraintRepository.deleteManualConstraint(
                    session.roundUuid, constraintUuid, session.playerUuid,
                )
                manualConstraints.update { current -> current.filterNot { it.uuid == constraintUuid } }
                manualConstraintSource.possibleAreaRefreshFlow.refresh.update { it + 1 }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to delete manual constraint", e)
            } catch (e: HttpException) {
                sessionEvents.handleSessionExpiry(e)
                Log.w(TAG, "Failed to delete manual constraint", e)
            }
        }
    }

    /**
     * The hider answers a traced-streets question by drawing on the map, so the request arrives from
     * chat. Consuming it at once stops a configuration change from re-entering trace mode later.
     */
    private fun observeTraceRequests() {
        viewModelScope.launch {
            sessionEvents.navigationRequestStore.pendingTraceRequest.collect { request ->
                if (request == null) return@collect
                if (request.gameUuid != gameUuid) return@collect
                zonePlacementCancelSignal.requestCancellation()
                drawingState.value = DrawingState(
                    isActive = true,
                    kind = DrawKind.Trace,
                    questionUuid = request.questionUuid,
                    photoTarget = request.photoTarget,
                    streetStatus = streetStatus.value,
                ).withTrace(streetGraph.value)
                traceReview.value = null
                loadStreetNetwork()
                sessionEvents.navigationRequestStore.consumeTraceRequest()
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
                        manualConstraints.value = emptyList()
                        drawingState.value = DrawingState()
                        traceReview.value = null
                        streetNetworkJob?.cancel()
                        streetGraph.value = null
                        streetStatus.value = StreetDataStatus.Loading
                        loadStreetNetwork()
                        loadManualConstraints()
                    }
                }
        }
    }

    private companion object {
        const val TAG = "DrawingViewModel"
        const val STOP_TIMEOUT_MS = 5_000L
        const val MIN_POLYGON_VERTICES = 3
        // Three tries ~10 s apart covers the server's 30 s warm window without polling a dead network.
        const val STREET_NETWORK_ATTEMPTS = 3
        const val STREET_NETWORK_RETRY_DELAY_MS = 10_000L
    }
}

data class DrawingState(
    val isActive: Boolean = false,
    val kind: DrawKind = DrawKind.Area,
    val mode: ConstraintMode = ConstraintMode.Exclude,
    val vertices: List<ZonePin> = emptyList(),
    val draggingVertexIndex: Int? = null,
    val selectedManualConstraintUuid: String? = null,
    val questionUuid: String? = null,
    val photoTarget: PhotoTarget? = null,
    val isRendering: Boolean = false,
    val renderError: ErrorType? = null,
    val selectedEdgeIds: Set<Int> = emptySet(),
    val streetStatus: StreetDataStatus = StreetDataStatus.Loading,
    val selectedPaths: List<List<ZonePin>> = emptyList(),
    val networkPaths: List<List<ZonePin>> = emptyList(),
    val selectionLengthMeters: Double = 0.0,
    val traceShape: TraceShape = TraceShape.Empty,
)

data class MapDrawingUiState(
    val drawing: DrawingUiState = DrawingUiState(),
    val selectedManualConstraintUuid: String? = null,
    val traceReview: TraceReviewState? = null,
    val manualConstraints: List<ManualConstraint> = emptyList(),
)

private fun DrawingState.toUiState(edition: Edition = Edition.Metric) = DrawingUiState(
    isActive = isActive,
    kind = kind,
    mode = mode,
    vertices = vertices,
    draggingVertexIndex = draggingVertexIndex,
    questionUuid = questionUuid,
    photoTarget = photoTarget,
    edition = edition,
    isRendering = isRendering,
    renderError = renderError,
    selectedEdgeIds = selectedEdgeIds,
    selectedPaths = selectedPaths,
    networkPaths = networkPaths,
    lengthMeters = selectionLengthMeters,
    traceShape = traceShape,
    streetStatus = streetStatus,
)

private fun canRenderTrace(state: DrawingState, edition: Edition): Boolean =
    state.kind == DrawKind.Trace && state.toUiState(edition).canConfirmTrace

/**
 * Stored rather than derived: the state combine emits far more often than the selection changes, and
 * reading the polylines off the graph walks its edges on every call.
 */
private fun DrawingState.withTrace(graph: StreetGraph?): DrawingState =
    if (graph == null) {
        copy(
            selectedPaths = emptyList(),
            networkPaths = emptyList(),
            selectionLengthMeters = 0.0,
            traceShape = TraceShape.Empty,
        )
    } else {
        copy(
            selectedPaths = graph.selectedPolylines(selectedEdgeIds),
            networkPaths = graph.allPolylines(),
            selectionLengthMeters = graph.lengthMeters(selectedEdgeIds),
            traceShape = graph.shape(selectedEdgeIds),
        )
    }

private fun List<ManualConstraint>.upsert(event: ManualConstraintAddedEvent): List<ManualConstraint> =
    if (any { it.uuid == event.uuid }) {
        map { if (it.uuid == event.uuid) it.copy(mode = event.mode, geoJson = event.geoJson) else it }
    } else {
        this + ManualConstraint(event.uuid, event.mode, event.geoJson, label = "", createdByName = null)
    }
