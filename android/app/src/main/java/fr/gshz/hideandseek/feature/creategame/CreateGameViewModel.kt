package fr.gshz.hideandseek.feature.creategame

import android.util.Log
import androidx.annotation.StringRes
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.AccountCredential
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.UiText
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.model.withoutLocationTopics
import fr.gshz.hideandseek.core.util.ERROR_KEY_JOIN_PASSWORD_INVALID
import fr.gshz.hideandseek.core.util.ERROR_KEY_JOIN_PASSWORD_REQUIRED
import fr.gshz.hideandseek.core.util.serverDetail
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.repository.AreaInfo
import fr.gshz.hideandseek.domain.repository.GameRepository
import fr.gshz.hideandseek.domain.repository.GtfsLineSelection
import fr.gshz.hideandseek.domain.repository.SessionRepository
import java.io.IOException
import javax.inject.Inject
import fr.gshz.hideandseek.di.DefaultDispatcher
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.FlowPreview
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.debounce
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import retrofit2.HttpException

@OptIn(FlowPreview::class)
@Suppress("TooManyFunctions")
@HiltViewModel
class CreateGameViewModel @Inject constructor(
    private val gameRepository: GameRepository,
    private val sessionRepository: SessionRepository,
    private val connectionStore: ConnectionStore,
    @DefaultDispatcher private val parsingDispatcher: CoroutineDispatcher,
) : ViewModel() {

    private val _uiState = MutableStateFlow(CreateGameUiState())
    val uiState: StateFlow<CreateGameUiState> = _uiState.asStateFlow()

    private val transitPreviewRetries = MutableStateFlow(0)

    /**
     * osmId to its GeoJSON feature, blank when the line has no drawable geometry. Ticking fetches only
     * what is missing; unticking never hits the network.
     */
    private val previewedLineFeatures = mutableMapOf<String, String>()

    init {
        observeSelectedAreas()
        observeSelectedTransitLines()
    }

    /**
     * Both previews follow the selection rather than a button, so they are debounced: a burst of
     * ticks costs one request, and collectLatest cancels a fetch the next tick already invalidated.
     */
    private fun observeSelectedAreas() {
        viewModelScope.launch {
            _uiState.map { it.selectedAreas }
                .distinctUntilChanged()
                .debounce(PREVIEW_DEBOUNCE_MS)
                .collectLatest { loadBoundaryPreview(it) }
        }
    }

    private fun observeSelectedTransitLines() {
        viewModelScope.launch {
            combine(
                _uiState.map { it.selectedTransitLines }.distinctUntilChanged(),
                _uiState.map { it.transitLines }.distinctUntilChanged(),
                transitPreviewRetries,
            ) { selected, lines, _ -> expandSelectedLineIds(lines, selected) }
                .debounce(PREVIEW_DEBOUNCE_MS)
                .collectLatest { loadTransitPreview(it) }
        }
    }

    fun onNameChange(value: String) {
        _uiState.update { it.copy(name = value, error = null) }
    }

    fun onSizeChange(value: GameSize) {
        _uiState.update { it.copy(size = value) }
    }

    fun onEditionChange(value: Edition) {
        _uiState.update { it.copy(edition = value) }
    }

    fun createGame() {
        val state = _uiState.value
        if (state.name.isBlank()) {
            _uiState.update { it.copy(error = ErrorType.Validation) }
            return
        }

        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true, error = null, errorKey = null, errorArgs = null) }
            val account = connectionStore.currentAccount()
            if (account == null) {
                _uiState.update { it.copy(isLoading = false, needsAccount = true) }
                return@launch
            }
            performCreate(state, account)
        }
    }

    @Suppress("LongMethod")
    private suspend fun performCreate(state: CreateGameUiState, account: AccountCredential) {
        try {
            val expandedIds = state.expandedSelectedLineIds
            val selectedLines = state.transitLines.filter {
                it.osmId in expandedIds
            }.ifEmpty { null }

            val selectedGtfs = resolveGtfsSelections(state)

            val game = gameRepository.createGame(
                state.name.trim(),
                state.size,
                state.edition,
                areas = state.selectedAreas.ifEmpty { null },
                selectedTransitLines = selectedLines,
                selectedGtfsLines = selectedGtfs,
            )
            val join = gameRepository.joinGame(game.uuid, account.name, account.password)
            sessionRepository.saveSession(
                PlayerSession(
                    gameUuid = game.uuid,
                    roundUuid = game.roundUuid,
                    playerUuid = join.playerUuid,
                    displayName = join.displayName,
                    mercureToken = join.mercureToken,
                    side = null,
                    topics = join.topics.withoutLocationTopics(),
                ),
            )
            _uiState.update { it.copy(isLoading = false, createdGameUuid = game.uuid) }
        } catch (e: IOException) {
            Log.w(TAG, "Failed to create or join game", e)
            _uiState.update { it.copy(isLoading = false, error = ErrorType.Network) }
        } catch (e: HttpException) {
            Log.w(TAG, "Failed to create or join game", e)
            val errorKey = e.serverErrorKey()
            if (errorKey == ERROR_KEY_JOIN_PASSWORD_REQUIRED || errorKey == ERROR_KEY_JOIN_PASSWORD_INVALID) {
                _uiState.update { it.copy(isLoading = false, needsAccount = true, needAccountErrorKey = errorKey) }
            } else {
                _uiState.update {
                    it.copy(
                        isLoading = false,
                        error = e.toErrorType(),
                        errorKey = errorKey,
                        errorArgs = e.serverErrorArgs(),
                    )
                }
            }
        }
    }

    fun onAreaSearchQueryChange(query: String) {
        _uiState.update { it.copy(areaSearchQuery = query) }
    }

    fun searchAreas() {
        val query = _uiState.value.areaSearchQuery.trim()
        if (query.isBlank()) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSearchingAreas = true) }
            try {
                val results = gameRepository.searchAreas(query)
                _uiState.update { it.copy(isSearchingAreas = false, areaSearchResults = results) }
            } catch (e: IOException) {
                Log.w(TAG, "Area search failed", e)
                _uiState.update { it.copy(isSearchingAreas = false) }
            } catch (e: HttpException) {
                Log.w(TAG, "Area search failed", e)
                _uiState.update { it.copy(isSearchingAreas = false) }
            }
        }
    }

    fun toggleArea(area: AreaInfo) {
        _uiState.update { state ->
            val current = state.selectedAreas.toMutableList()
            val existing = current.find { it.osmId == area.osmId }
            if (existing != null) {
                current.remove(existing)
            } else {
                current.add(area)
            }
            state.copy(
                selectedAreas = current.toList(),
                transitLines = emptyList(),
                selectedTransitLines = emptySet(),
                hasDiscoveredTransit = false,
                discoveryModeSelection = DEFAULT_DISCOVERY_MODES,
                discoveryError = null,
            )
        }
    }

    fun toggleTransitLineGroup(group: TransitLineGroup) {
        _uiState.update { state ->
            val current = state.selectedTransitLines.toMutableSet()
            if (group.osmIds.any { it in current }) {
                current.removeAll(group.osmIds)
            } else {
                current.addAll(group.osmIds)
            }
            state.copy(selectedTransitLines = current)
        }
    }

    fun toggleTransitLineGroups(groups: List<TransitLineGroup>) {
        _uiState.update { state ->
            val current = state.selectedTransitLines.toMutableSet()
            val allSelected = groups.all { group -> group.osmIds.any { it in current } }
            val ids = groups.flatMap { it.osmIds }
            if (allSelected) current.removeAll(ids.toSet()) else current.addAll(ids)
            state.copy(selectedTransitLines = current)
        }
    }

    fun retryTransitPreview() {
        transitPreviewRetries.update { it + 1 }
    }

    private suspend fun loadTransitPreview(osmIds: Set<String>) {
        previewedLineFeatures.keys.retainAll(osmIds)
        if (osmIds.isEmpty()) {
            _uiState.update {
                it.copy(isLoadingTransitPreview = false, transitPreviewGeoJson = null, transitPreviewError = null)
            }
            return
        }

        val missing = osmIds - previewedLineFeatures.keys
        if (missing.isEmpty()) {
            publishTransitPreview()
            return
        }

        _uiState.update { it.copy(isLoadingTransitPreview = true, transitPreviewError = null) }
        fetchLineFeatures(missing)
    }

    private suspend fun fetchLineFeatures(missing: Set<String>) {
        try {
            previewedLineFeatures.putAll(featuresByOsmId(gameRepository.transitLinePreview(missing), missing))
            publishTransitPreview()
        } catch (e: IOException) {
            Log.w(TAG, "Transit line preview failed", e)
            failTransitPreview(UiText.fromResource(R.string.transit_preview_network_error))
        } catch (e: IllegalArgumentException) {
            Log.w(TAG, "Transit line preview returned a body we cannot read", e)
            failTransitPreview(UiText.fromResource(R.string.transit_preview_failed))
        } catch (e: HttpException) {
            Log.w(TAG, "Transit line preview failed", e)
            val errorKey = e.serverErrorKey()
            // The server has ruled on these ids: remembering that spares a refetch on every later tick.
            if (errorKey == ERROR_KEY_NO_GEOMETRY) {
                missing.forEach { previewedLineFeatures[it] = "" }
            }
            failTransitPreview(UiText.fromResource(previewErrorResource(errorKey)))
        }
    }

    private fun publishTransitPreview() {
        val features = previewedLineFeatures.values.filter { it.isNotEmpty() }
        val geoJson = if (features.isEmpty()) {
            null
        } else {
            features.joinToString(separator = ",", prefix = FEATURE_COLLECTION_PREFIX, postfix = "]}")
        }
        _uiState.update {
            it.copy(isLoadingTransitPreview = false, transitPreviewGeoJson = geoJson, transitPreviewError = null)
        }
    }

    private suspend fun failTransitPreview(message: UiText) {
        currentCoroutineContext().ensureActive()
        _uiState.update { it.copy(isLoadingTransitPreview = false, transitPreviewError = message) }
    }

    /** Every requested id gets an entry, blank when it came back without geometry, so it is not refetched. */
    private suspend fun featuresByOsmId(geoJson: String, requested: Set<String>): Map<String, String> =
        withContext(parsingDispatcher) {
            val byId = requested.associateWith { "" }.toMutableMap()
            val features = Json.parseToJsonElement(geoJson).jsonObject["features"]?.jsonArray.orEmpty()
            for (feature in features) {
                val osmId = feature.jsonObject["properties"]?.jsonObject?.get("osmId")
                    ?.jsonPrimitive?.contentOrNull.orEmpty()
                if (osmId.isNotEmpty()) {
                    byId[osmId] = feature.toString()
                }
            }
            byId
        }

    @StringRes
    private fun previewErrorResource(errorKey: String?): Int = when (errorKey) {
        ERROR_KEY_NO_GEOMETRY -> R.string.transit_preview_no_geometry
        else -> R.string.transit_preview_failed
    }

    fun toggleModeFilter(routeType: String) {
        _uiState.update { state ->
            val current = state.transitModeFilter.toMutableSet()
            if (routeType in current) {
                current.remove(routeType)
            } else {
                current.add(routeType)
            }
            state.copy(transitModeFilter = current)
        }
    }

    fun toggleDiscoveryMode(routeType: String) {
        _uiState.update { state ->
            val current = state.discoveryModeSelection.toMutableSet()
            if (routeType in current) {
                current.remove(routeType)
            } else {
                current.add(routeType)
            }
            state.copy(discoveryModeSelection = current)
        }
    }

    fun toggleAllDiscoveryModes() {
        _uiState.update { state ->
            val allSelected = state.discoveryModeSelection.containsAll(ALL_ROUTE_TYPES)
            state.copy(discoveryModeSelection = if (allSelected) emptySet() else ALL_ROUTE_TYPES)
        }
    }

    fun showDiscoveryModeDialog() {
        _uiState.update { it.copy(showDiscoveryModeDialog = true) }
    }

    fun dismissDiscoveryModeDialog() {
        _uiState.update { it.copy(showDiscoveryModeDialog = false) }
    }

    fun onGtfsFileError(message: String) {
        _uiState.update { it.copy(gtfsError = UiText.fromDynamic(message)) }
    }

    fun showGtfsDialog() {
        _uiState.update { it.copy(showGtfsDialog = true, gtfsError = null) }
    }

    fun dismissGtfsDialog() {
        _uiState.update { it.copy(showGtfsDialog = false, gtfsError = null) }
    }

    fun onGtfsUrlChange(value: String) {
        _uiState.update { it.copy(gtfsUrlInput = value, gtfsError = null) }
    }

    fun onGtfsDialogModeChange(mode: GtfsDialogMode) {
        _uiState.update { it.copy(gtfsDialogMode = mode, gtfsError = null) }
    }

    fun uploadGtfsFromUrl() {
        val url = _uiState.value.gtfsUrlInput.trim()
        if (url.isBlank()) {
            _uiState.update { it.copy(gtfsError = UiText.fromResource(R.string.gtfs_url_required)) }
            return
        }
        viewModelScope.launch {
            _uiState.update { it.copy(gtfsUploading = true, gtfsError = null) }
            try {
                val feedName = url.substringAfterLast("/")
                    .take(GTFS_FEED_NAME_MAX_LENGTH).ifBlank { DEFAULT_GTFS_FEED_NAME }
                val source = gameRepository.uploadGtfsFromUrl(url, feedName)
                _uiState.update { state ->
                    state.copy(
                        gtfsUploading = false,
                        showGtfsDialog = false,
                        gtfsUrlInput = "",
                        gtfsSources = state.gtfsSources + source,
                    )
                }
            } catch (e: IOException) {
                Log.w(TAG, "GTFS URL upload failed", e)
                val error = UiText.fromResource(R.string.gtfs_network_error, e.message ?: "")
                _uiState.update { it.copy(gtfsUploading = false, gtfsError = error) }
            } catch (e: HttpException) {
                Log.w(TAG, "GTFS URL upload failed", e)
                val detail = e.serverDetail() ?: e.message()
                _uiState.update { it.copy(gtfsUploading = false, gtfsError = UiText.fromDynamic(detail)) }
            }
        }
    }

    fun uploadGtfsFromFile(bytes: ByteArray, fileName: String) {
        viewModelScope.launch {
            _uiState.update { it.copy(gtfsUploading = true, gtfsError = null) }
            try {
                val feedName = fileName.removeSuffix(".zip")
                    .take(GTFS_FEED_NAME_MAX_LENGTH).ifBlank { DEFAULT_GTFS_FEED_NAME }
                val source = gameRepository.uploadGtfsFromFile(bytes, fileName, feedName)
                _uiState.update { state ->
                    state.copy(
                        gtfsUploading = false,
                        showGtfsDialog = false,
                        gtfsSources = state.gtfsSources + source,
                    )
                }
            } catch (e: IOException) {
                Log.w(TAG, "GTFS file upload failed", e)
                val error = UiText.fromResource(R.string.gtfs_network_error, e.message ?: "")
                _uiState.update { it.copy(gtfsUploading = false, gtfsError = error) }
            } catch (e: HttpException) {
                Log.w(TAG, "GTFS file upload failed", e)
                val detail = e.serverDetail() ?: e.message()
                _uiState.update { it.copy(gtfsUploading = false, gtfsError = UiText.fromDynamic(detail)) }
            }
        }
    }

    fun removeGtfsSource(sourceUuid: String) {
        _uiState.update { state ->
            val newSources = state.gtfsSources.filter { it.uuid != sourceUuid }
            val prefix = "$sourceUuid|"
            val newKeys = state.selectedGtfsRouteKeys.filterNot { it.startsWith(prefix) }
            state.copy(gtfsSources = newSources, selectedGtfsRouteKeys = newKeys.toSet())
        }
    }

    fun toggleGtfsRoute(sourceUuid: String, routeId: String) {
        val key = "$sourceUuid|$routeId"
        _uiState.update { state ->
            val current = state.selectedGtfsRouteKeys.toMutableSet()
            if (key in current) {
                current.remove(key)
            } else {
                current.add(key)
            }
            state.copy(selectedGtfsRouteKeys = current)
        }
    }

    private fun resolveGtfsSelections(state: CreateGameUiState): List<GtfsLineSelection>? {
        return state.selectedGtfsRouteKeys
            .flatMap { key ->
                val parts = key.split("|", limit = 2)
                val sourceUuid = parts.getOrNull(0) ?: return@flatMap emptyList()
                val routeId = parts.getOrNull(1) ?: return@flatMap emptyList()
                val source = state.gtfsSources.find { it.uuid == sourceUuid } ?: return@flatMap emptyList()
                val route = source.routes.find { it.routeId == routeId } ?: return@flatMap emptyList()
                listOf(GtfsLineSelection(sourceUuid = source.uuid, route = route))
            }
            .ifEmpty { null }
    }

    private suspend fun loadBoundaryPreview(areas: List<AreaInfo>) {
        if (areas.isEmpty()) {
            _uiState.update { it.copy(boundaryGeoJson = null) }
            return
        }

        try {
            val geoJson = gameRepository.boundaryPreview(areas)
            _uiState.update { it.copy(boundaryGeoJson = geoJson) }
        } catch (e: IOException) {
            Log.w(TAG, "Boundary preview failed", e)
            clearBoundaryPreview()
        } catch (e: HttpException) {
            Log.w(TAG, "Boundary preview failed", e)
            clearBoundaryPreview()
        }
    }

    /** A cancelled request is a superseded selection, not a failure: leave the drawn boundary alone. */
    private suspend fun clearBoundaryPreview() {
        currentCoroutineContext().ensureActive()
        _uiState.update { it.copy(boundaryGeoJson = null) }
    }

    fun discoverOsmTransit() {
        val areas = _uiState.value.selectedAreas
        if (areas.isEmpty()) return
        val areaFingerprint = areas.map { "${it.osmType}:${it.osmId}" }.sorted()
        val selectedModes = _uiState.value.discoveryModeSelection.toList()
        viewModelScope.launch {
            fun areasUnchanged(): Boolean =
                _uiState.value.selectedAreas.map { "${it.osmType}:${it.osmId}" }.sorted() == areaFingerprint

            if (!areasUnchanged()) return@launch
            _uiState.update { it.copy(isDiscoveringTransit = true, discoveryError = null) }
            try {
                val lines = gameRepository.discoverTransitLines(areas, selectedModes)
                if (areasUnchanged()) {
                    _uiState.update { applyDiscoveryResult(it, lines) }
                }
            } catch (e: IOException) {
                Log.w(TAG, "Transit discovery failed", e)
                if (areasUnchanged()) {
                    _uiState.update {
                        it.copy(
                            isDiscoveringTransit = false,
                            discoveryError = UiText.fromResource(R.string.transit_discovery_network_error),
                        )
                    }
                }
            } catch (e: HttpException) {
                Log.w(TAG, "Transit discovery failed", e)
                if (areasUnchanged()) {
                    val detail = e.serverDetail() ?: e.message()
                    _uiState.update {
                        it.copy(
                            isDiscoveringTransit = false,
                            discoveryError = UiText.fromDynamic(detail),
                        )
                    }
                }
            }
        }
    }

    private fun applyDiscoveryResult(state: CreateGameUiState, lines: List<TransitLine>): CreateGameUiState =
        if (lines.isEmpty()) {
            state.copy(
                isDiscoveringTransit = false,
                hasDiscoveredTransit = false,
                discoveryError = UiText.fromResource(R.string.transit_discovery_empty),
            )
        } else {
            state.copy(
                isDiscoveringTransit = false,
                transitLines = lines,
                hasDiscoveredTransit = true,
                discoveryError = null,
            )
        }

    private companion object {
        const val TAG = "CreateGameViewModel"
        const val GTFS_FEED_NAME_MAX_LENGTH = 50
        const val DEFAULT_GTFS_FEED_NAME = "GTFS feed"

        /** Long enough to swallow a run of ticks, short enough that the map still feels live. */
        const val PREVIEW_DEBOUNCE_MS = 500L
        const val FEATURE_COLLECTION_PREFIX = """{"type":"FeatureCollection","features":["""
        const val ERROR_KEY_NO_GEOMETRY = "transit.preview_no_geometry"
    }
}
