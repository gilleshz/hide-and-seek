package fr.gshz.hideandseek.feature.creategame

import android.util.Log
import androidx.annotation.StringRes
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.GameStateCache
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.UiText
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.model.withoutSideGrants
import fr.gshz.hideandseek.core.util.geoJsonBoundingBox
import fr.gshz.hideandseek.core.util.serverDetail
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.domain.model.BoundingBox
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GameSummary
import fr.gshz.hideandseek.domain.model.GtfsRealtimeProbe
import fr.gshz.hideandseek.domain.model.GtfsRealtimeSelection
import fr.gshz.hideandseek.domain.model.RulesVariant
import fr.gshz.hideandseek.domain.model.TransitCatalogSuggestion
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
import kotlinx.coroutines.Job
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
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
import kotlinx.serialization.SerializationException
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import retrofit2.HttpException

@OptIn(FlowPreview::class)
@Suppress("TooManyFunctions", "LargeClass")
@HiltViewModel
class CreateGameViewModel @Inject constructor(
    private val gameRepository: GameRepository,
    private val sessionRepository: SessionRepository,
    private val connectionStore: ConnectionStore,
    private val gameStateCache: GameStateCache,
    @DefaultDispatcher private val parsingDispatcher: CoroutineDispatcher,
) : ViewModel() {

    private val _uiState = MutableStateFlow(CreateGameUiState())
    val uiState: StateFlow<CreateGameUiState> = _uiState.asStateFlow()

    private val transitPreviewRetries = MutableStateFlow(0)

    private var catalogJob: Job? = null
    private var catalogBboxCache: Pair<String, BoundingBox?>? = null

    /**
     * osmId to its GeoJSON feature, blank when the line has no drawable geometry. Ticking fetches only
     * what is missing; unticking never hits the network.
     */
    private val previewedLineFeatures = mutableMapOf<String, String>()

    /**
     * GTFS shapes carry no route identity, so the whole selection is one blob: refetched when the
     * selected routes change, kept as-is when only OSM lines are ticked.
     */
    private var gtfsPreviewFeatures: List<String> = emptyList()
    private var previewedGtfsLines: List<GtfsLineSelection> = emptyList()

    init {
        loadSiriProfiles()
        observeBoundaryInput()
        observeSelectedTransitLines()
        observeRealtimeProbe()
        observeCatalogBoundary()
    }

    private fun loadSiriProfiles() {
        viewModelScope.launch {
            val apiUrl = connectionStore.current()?.apiUrl ?: return@launch
            try {
                val profiles = gameStateCache.getClientConfig(apiUrl).siriProfiles
                _uiState.update { it.copy(siriProfiles = profiles) }
            } catch (e: IOException) {
                Log.w(TAG, "Client config failed", e)
            } catch (e: HttpException) {
                Log.w(TAG, "Client config failed", e)
            } catch (e: SerializationException) {
                Log.w(TAG, "Client config schema mismatch", e)
            }
        }
    }

    /**
     * Both previews follow the selection rather than a button, so they are debounced: a burst of
     * ticks costs one request, and collectLatest cancels a fetch the next tick already invalidated.
     */
    private fun observeBoundaryInput() {
        viewModelScope.launch {
            _uiState.map { boundaryInputOf(it) }
                .distinctUntilChanged()
                .debounce {
                    if (it.useLineBoundary && it.hasLines) LINE_BOUNDARY_DEBOUNCE_MS else PREVIEW_DEBOUNCE_MS
                }
                .collectLatest { loadBoundaryPreview(it) }
        }
    }

    private fun observeSelectedTransitLines() {
        viewModelScope.launch {
            combine(
                _uiState.map { previewSelection(it) }.distinctUntilChanged(),
                transitPreviewRetries,
            ) { selection, _ -> selection }
                .debounce(PREVIEW_DEBOUNCE_MS)
                .collectLatest { loadTransitPreview(it) }
        }
    }

    private fun previewSelection(state: CreateGameUiState) = TransitPreviewSelection(
        osmIds = expandSelectedLineIds(state.transitLines, state.selectedTransitLines),
        gtfsLines = resolveGtfsSelections(state).orEmpty(),
    )

    private fun observeRealtimeProbe() {
        viewModelScope.launch {
            _uiState.map { it.liveDataProvider to it.gtfsRealtimeUrlInput }
                .distinctUntilChanged()
                .debounce(REALTIME_PROBE_DEBOUNCE_MS)
                .collectLatest { (provider, raw) ->
                    if (provider == LiveDataProvider.GtfsRtUrl) {
                        checkRealtimeFeed(raw.trim())
                    } else {
                        _uiState.update { it.copy(realtimeProbe = null) }
                    }
                }
        }
    }

    /** The catalog follows the drawn boundary: reopening the dialog or redrawing the area refetches it. */
    private fun observeCatalogBoundary() {
        viewModelScope.launch {
            _uiState.map { it.showGtfsDialog to it.boundaryGeoJson }
                .distinctUntilChanged()
                .collect { (show, _) -> if (show) launchCatalogLoad() }
        }
    }

    private suspend fun checkRealtimeFeed(url: String) {
        when {
            url.isEmpty() -> _uiState.update { it.copy(realtimeProbe = null) }
            !url.startsWith(REALTIME_URL_PREFIX) ->
                _uiState.update { it.copy(realtimeProbe = RealtimeProbeState.InvalidUrl) }
            else -> {
                _uiState.update { it.copy(realtimeProbe = RealtimeProbeState.Checking) }
                val probe = try {
                    gameRepository.probeGtfsRealtime(url)
                } catch (e: IOException) {
                    Log.w(TAG, "GTFS-RT probe failed", e)
                    null
                } catch (e: HttpException) {
                    Log.w(TAG, "GTFS-RT probe failed", e)
                    null
                }
                _uiState.update {
                    it.copy(realtimeProbe = probe?.toProbeState() ?: RealtimeProbeState.Unreachable)
                }
            }
        }
    }

    private fun GtfsRealtimeProbe.toProbeState(): RealtimeProbeState = when (status) {
        "ok" -> RealtimeProbeState.Ok(tripCount, headerAgeSeconds)
        "stale" -> headerAgeSeconds?.let { RealtimeProbeState.Stale(it) } ?: RealtimeProbeState.Invalid
        "wrong_type" -> RealtimeProbeState.WrongType(feedType)
        "invalid_url" -> RealtimeProbeState.InvalidUrl
        "invalid" -> RealtimeProbeState.Invalid
        else -> RealtimeProbeState.Unreachable
    }

    fun onNameChange(value: String) {
        _uiState.update { it.copy(name = value, error = null) }
    }

    fun onSizeChange(value: GameSize) {
        _uiState.update {
            it.copy(
                size = value,
                compactMap = it.compactMap && value == GameSize.Small,
                useLineBoundary = it.useLineBoundary && value != GameSize.Large,
            )
        }
    }

    fun onEditionChange(value: Edition) {
        _uiState.update { it.copy(edition = value) }
    }

    fun onCompactMapChange(checked: Boolean) {
        _uiState.update { it.copy(compactMap = checked) }
    }

    fun createGame() {
        val state = _uiState.value
        if (state.name.isBlank()) {
            _uiState.update { it.copy(error = ErrorType.Validation) }
            return
        }

        viewModelScope.launch {
            _uiState.update {
                it.copy(isLoading = true, error = null, errorKey = null, errorArgs = null, errorDetail = null)
            }
            val name = connectionStore.currentPlayerName()
            if (name == null) {
                _uiState.update { it.copy(isLoading = false, needsConnect = true) }
                return@launch
            }
            performCreate(state, name)
        }
    }

    @Suppress("LongMethod")
    private suspend fun performCreate(state: CreateGameUiState, name: String) {
        var created: GameSummary? = null
        try {
            val expandedIds = state.expandedSelectedLineIds
            val selectedLines = state.transitLines.filter {
                it.osmId in expandedIds
            }.ifEmpty { null }

            val selectedGtfs = resolveGtfsSelections(state)

            val rulesVariant = if (state.compactMap && state.size == GameSize.Small) {
                RulesVariant.Compact
            } else {
                RulesVariant.Official
            }

            val game = gameRepository.createGame(
                state.name.trim(),
                state.size,
                state.edition,
                rulesVariant = rulesVariant,
                areas = if (state.useLineBoundary && state.hasSelectedLines) {
                    emptyList()
                } else {
                    state.selectedAreas.ifEmpty { null }
                },
                selectedTransitLines = selectedLines,
                selectedGtfsLines = selectedGtfs,
            )
            created = game
            val join = gameRepository.joinGame(game.uuid, name)
            sessionRepository.saveSession(
                PlayerSession(
                    gameUuid = game.uuid,
                    roundUuid = game.roundUuid,
                    playerUuid = join.playerUuid,
                    displayName = join.displayName,
                    mercureToken = join.mercureToken,
                    side = null,
                    topics = join.topics.withoutSideGrants(),
                ),
            )
            _uiState.update { it.copy(isLoading = false, createdGameUuid = game.uuid) }
        } catch (e: IOException) {
            Log.w(TAG, "Failed to create or join game", e)
            val createdGame = created
            _uiState.update {
                if (createdGame != null) {
                    it.copy(
                        isLoading = false,
                        needsConnect = true,
                        needConnectErrorKey = null,
                        needConnectJoinCode = createdGame.joinCode,
                    )
                } else {
                    it.copy(isLoading = false, error = ErrorType.Network, errorDetail = null)
                }
            }
        } catch (e: HttpException) {
            Log.w(TAG, "Failed to create or join game", e)
            val errorKey = e.serverErrorKey()
            val createdGame = created
            _uiState.update {
                if (createdGame != null) {
                    it.copy(
                        isLoading = false,
                        needsConnect = true,
                        needConnectErrorKey = errorKey,
                        needConnectJoinCode = createdGame.joinCode,
                    )
                } else {
                    it.copy(
                        isLoading = false,
                        error = e.toErrorType(),
                        errorKey = errorKey,
                        errorArgs = e.serverErrorArgs(),
                        errorDetail = e.serverDetail(),
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
            val lineMode = state.useLineBoundary && state.hasSelectedLines
            state.copy(
                selectedAreas = current.toList(),
                boundaryGeoJson = if (lineMode) state.boundaryGeoJson else null,
                discoveryModeSelection = DEFAULT_DISCOVERY_MODES,
                discoveryError = null,
            )
        }
    }

    fun onLineBoundaryChange(enabled: Boolean) {
        _uiState.update {
            it.copy(useLineBoundary = enabled, boundaryError = null)
        }
    }

    private fun boundaryInputOf(state: CreateGameUiState): BoundaryInput {
        val osmLineIds = state.expandedSelectedLineIds
        val gtfsLines = resolveGtfsSelections(state).orEmpty()
        val lineMode = state.useLineBoundary && (osmLineIds.isNotEmpty() || gtfsLines.isNotEmpty())
        return BoundaryInput(
            useLineBoundary = state.useLineBoundary,
            areas = if (lineMode) emptyList() else state.selectedAreas,
            osmLineIds = osmLineIds,
            gtfsLines = gtfsLines,
            size = state.size,
            edition = state.edition,
        )
    }

    private suspend fun loadBoundaryPreview(input: BoundaryInput) {
        val lineMode = input.useLineBoundary && input.hasLines
        if (!lineMode && input.areas.isEmpty()) {
            _uiState.update {
                it.copy(boundaryGeoJson = null, isLoadingBoundary = false, boundaryError = null)
            }
            return
        }

        _uiState.update {
            it.copy(isLoadingBoundary = true, boundaryError = null)
        }
        try {
            val geoJson = if (lineMode) {
                gameRepository.boundaryPreviewFromLines(
                    osmLineIds = input.osmLineIds,
                    gtfsLines = input.gtfsLines,
                    size = input.size,
                    edition = input.edition,
                )
            } else {
                gameRepository.boundaryPreview(input.areas)
            }
            if (boundaryInputOf(_uiState.value) == input) {
                _uiState.update {
                    it.copy(boundaryGeoJson = geoJson, isLoadingBoundary = false, boundaryError = null)
                }
            }
        } catch (e: IOException) {
            Log.w(TAG, "Boundary preview failed", e)
            failBoundaryPreview(input, UiText.fromResource(R.string.area_fit_network_error))
        } catch (e: HttpException) {
            Log.w(TAG, "Boundary preview failed", e)
            failBoundaryPreview(input, boundaryPreviewError(e))
        }
    }

    private suspend fun failBoundaryPreview(input: BoundaryInput, message: UiText) {
        currentCoroutineContext().ensureActive()
        if (boundaryInputOf(_uiState.value) == input) {
            _uiState.update {
                it.copy(
                    boundaryGeoJson = null,
                    isLoadingBoundary = false,
                    boundaryError = message,
                )
            }
        }
    }

    private fun boundaryPreviewError(e: HttpException): UiText = when (e.serverErrorKey()) {
        ERROR_KEY_AREA_FIT_NO_LINES -> UiText.fromResource(R.string.area_fit_no_lines)
        ERROR_KEY_AREA_FIT_NO_GEOMETRY -> UiText.fromResource(R.string.area_fit_no_geometry)
        ERROR_KEY_AREA_FIT_TOO_LARGE -> UiText.fromResource(R.string.area_fit_too_large)
        else -> UiText.fromDynamic(e.serverDetail() ?: e.message())
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
        previewedLineFeatures.clear()
        previewedGtfsLines = emptyList()
        transitPreviewRetries.update { it + 1 }
    }

    private suspend fun loadTransitPreview(selection: TransitPreviewSelection) {
        previewedLineFeatures.keys.retainAll(selection.osmIds)
        if (selection.isEmpty) {
            previewedGtfsLines = emptyList()
            gtfsPreviewFeatures = emptyList()
            _uiState.update {
                it.copy(isLoadingTransitPreview = false, transitPreviewGeoJson = null, transitPreviewError = null)
            }
            return
        }
        if (selection.gtfsLines.isEmpty()) {
            gtfsPreviewFeatures = emptyList()
            previewedGtfsLines = emptyList()
        }

        val missing = selection.osmIds - previewedLineFeatures.keys
        val gtfsStale = selection.gtfsLines != previewedGtfsLines
        if (missing.isEmpty() && !gtfsStale) {
            publishTransitPreview()
            return
        }

        _uiState.update { it.copy(isLoadingTransitPreview = true, transitPreviewError = null) }
        fetchLineFeatures(missing, if (gtfsStale) selection.gtfsLines else emptyList())
    }

    private suspend fun fetchLineFeatures(missing: Set<String>, gtfsLines: List<GtfsLineSelection>) {
        try {
            val parsed = parsePreviewFeatures(
                gameRepository.transitLinePreview(missing, gtfsLines),
                missing,
            )
            previewedLineFeatures.putAll(parsed.byOsmId)
            if (gtfsLines.isNotEmpty()) {
                gtfsPreviewFeatures = parsed.gtfs
                previewedGtfsLines = gtfsLines
            }
            publishTransitPreview()
        } catch (e: IOException) {
            Log.w(TAG, "Transit line preview failed", e)
            failTransitPreview(UiText.fromResource(R.string.transit_preview_network_error), gtfsLines)
        } catch (e: IllegalArgumentException) {
            Log.w(TAG, "Transit line preview returned a body we cannot read", e)
            failTransitPreview(UiText.fromResource(R.string.transit_preview_failed), gtfsLines)
        } catch (e: HttpException) {
            Log.w(TAG, "Transit line preview failed", e)
            val errorKey = e.serverErrorKey()
            // The server has ruled on these ids: remembering that spares a refetch on every later tick.
            if (errorKey == ERROR_KEY_NO_GEOMETRY) {
                missing.forEach { previewedLineFeatures[it] = "" }
            }
            failTransitPreview(UiText.fromResource(previewErrorResource(errorKey)), gtfsLines)
        }
    }

    private fun publishTransitPreview() {
        val features = previewedLineFeatures.values.filter { it.isNotEmpty() } + gtfsPreviewFeatures
        val geoJson = if (features.isEmpty()) {
            null
        } else {
            features.joinToString(separator = ",", prefix = FEATURE_COLLECTION_PREFIX, postfix = "]}")
        }
        _uiState.update {
            it.copy(isLoadingTransitPreview = false, transitPreviewGeoJson = geoJson, transitPreviewError = null)
        }
    }

    private suspend fun failTransitPreview(message: UiText, gtfsLines: List<GtfsLineSelection>) {
        currentCoroutineContext().ensureActive()
        if (gtfsLines.isNotEmpty()) {
            // Stale shapes cannot be trimmed to the new routes, so drop them and refetch next time.
            gtfsPreviewFeatures = emptyList()
            previewedGtfsLines = emptyList()
        }
        _uiState.update { it.copy(isLoadingTransitPreview = false, transitPreviewError = message) }
    }

    private data class ParsedPreview(val byOsmId: Map<String, String>, val gtfs: List<String>)

    /**
     * OSM features echo their osmId and are cached per line; GTFS shapes carry no id, so they are
     * kept as one list.
     */
    private suspend fun parsePreviewFeatures(geoJson: String, requested: Set<String>): ParsedPreview =
        withContext(parsingDispatcher) {
            val byId = requested.associateWith { "" }.toMutableMap()
            val gtfs = mutableListOf<String>()
            val features = Json.parseToJsonElement(geoJson).jsonObject["features"]?.jsonArray.orEmpty()
            for (feature in features) {
                val osmId = feature.jsonObject["properties"]?.jsonObject?.get("osmId")
                    ?.jsonPrimitive?.contentOrNull.orEmpty()
                if (osmId.isEmpty()) {
                    gtfs.add(feature.toString())
                } else {
                    byId[osmId] = feature.toString()
                }
            }
            ParsedPreview(byId, gtfs)
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
            state.copy(transitModeFilter = current, gtfsSelectionError = null)
        }
    }

    fun onTransitLineQueryChange(value: String) {
        _uiState.update { it.copy(transitLineQuery = value, gtfsSelectionError = null) }
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
        _uiState.update {
            it.copy(
                showGtfsDialog = true,
                gtfsDialogMode = GtfsDialogMode.Networks,
                gtfsError = null,
                catalogQuery = "",
                catalogError = null,
            )
        }
    }

    fun dismissGtfsDialog() {
        catalogJob?.cancel()
        _uiState.update { it.copy(showGtfsDialog = false, gtfsError = null, isCatalogLoading = false) }
    }

    fun onCatalogQueryChange(value: String) {
        _uiState.update { it.copy(catalogQuery = value, catalogError = null) }
        catalogJob?.cancel()
        catalogJob = viewModelScope.launch {
            delay(CATALOG_DEBOUNCE_MS)
            loadCatalog()
        }
    }

    fun retryCatalog() {
        _uiState.update { it.copy(catalogError = null) }
        launchCatalogLoad()
    }

    fun onSelectCatalogSuggestion(suggestion: TransitCatalogSuggestion) {
        val name = suggestion.name.ifBlank { suggestion.provider }
            .take(GTFS_FEED_NAME_MAX_LENGTH)
            .ifBlank { DEFAULT_GTFS_FEED_NAME }
        uploadGtfsUrl(
            url = suggestion.downloadUrl,
            name = name,
            selection = catalogLiveDataSelection(suggestion),
            fallbackUrl = suggestion.fallbackUrl.ifBlank { null },
        )
    }

    /** The server knows which profiles it can serve, so its suggestion wins over a stale local list. */
    private fun catalogLiveDataSelection(suggestion: TransitCatalogSuggestion): GtfsRealtimeSelection = when {
        suggestion.siriProfile.isNotBlank() -> GtfsRealtimeSelection(
            provider = LiveDataProvider.SiriLite.wireValue,
            profile = suggestion.siriProfile,
        )
        suggestion.realtimeUrl.startsWith(REALTIME_URL_PREFIX, ignoreCase = true) -> GtfsRealtimeSelection(
            provider = LiveDataProvider.GtfsRtUrl.wireValue,
            url = suggestion.realtimeUrl,
        )
        else -> GtfsRealtimeSelection.None
    }

    private fun launchCatalogLoad() {
        catalogJob?.cancel()
        catalogJob = viewModelScope.launch { loadCatalog() }
    }

    private suspend fun loadCatalog() {
        val state = _uiState.value
        if (!state.showGtfsDialog) return
        val areas = state.selectedAreas
        val boundary = state.boundaryGeoJson
        val bbox = boundary?.let { catalogBoundingBox(it) }
        if (boundary == null || bbox == null) {
            _uiState.update {
                it.copy(
                    isCatalogLoading = false,
                    catalogError = UiText.fromResource(R.string.gtfs_catalog_preparing_area),
                )
            }
            return
        }

        val query = state.catalogQuery
        _uiState.update { it.copy(isCatalogLoading = true, catalogError = null) }
        try {
            val suggestions = gameRepository.searchTransitCatalog(bbox, query)
            applyCatalogState(areas, boundary, query) {
                it.copy(isCatalogLoading = false, catalogSuggestions = suggestions, catalogError = null)
            }
        } catch (e: IOException) {
            Log.w(TAG, "GTFS catalog search failed", e)
            applyCatalogState(areas, boundary, query) {
                it.copy(isCatalogLoading = false, catalogError = UiText.fromResource(R.string.gtfs_catalog_error))
            }
        } catch (e: HttpException) {
            Log.w(TAG, "GTFS catalog search failed", e)
            val detail = e.serverDetail() ?: e.message()
            applyCatalogState(areas, boundary, query) {
                it.copy(isCatalogLoading = false, catalogError = UiText.fromDynamic(detail))
            }
        }
    }

    private suspend fun catalogBoundingBox(boundary: String): BoundingBox? {
        val cached = catalogBboxCache
        if (cached != null && cached.first == boundary) {
            return cached.second
        }

        return withContext(parsingDispatcher) { geoJsonBoundingBox(boundary) }
            .also { catalogBboxCache = boundary to it }
    }

    /** Applies a catalog response only while the dialog still shows the area and query it was fetched for. */
    private fun applyCatalogState(
        areas: List<AreaInfo>,
        boundary: String,
        query: String,
        transform: (CreateGameUiState) -> CreateGameUiState,
    ) {
        val state = _uiState.value
        val isCurrent = state.showGtfsDialog &&
            state.selectedAreas == areas &&
            state.catalogQuery == query &&
            state.boundaryGeoJson == boundary
        if (isCurrent) _uiState.update(transform)
    }

    fun onGtfsUrlChange(value: String) {
        _uiState.update { it.copy(gtfsUrlInput = value, gtfsError = null) }
    }

    fun onGtfsRealtimeUrlChange(value: String) {
        _uiState.update { it.copy(gtfsRealtimeUrlInput = value, gtfsError = null) }
    }

    fun onLiveDataProviderChange(provider: LiveDataProvider) {
        _uiState.update {
            it.copy(
                liveDataProvider = provider,
                realtimeProbe = if (provider == LiveDataProvider.GtfsRtUrl) it.realtimeProbe else null,
                gtfsError = null,
            )
        }
    }

    fun onLiveDataProfileChange(key: String) {
        _uiState.update { it.copy(liveDataProfile = key, gtfsError = null) }
    }

    private fun liveDataSelectionOrNull(): GtfsRealtimeSelection? {
        val state = _uiState.value
        return when (state.liveDataProvider) {
            LiveDataProvider.None -> GtfsRealtimeSelection.None
            LiveDataProvider.GtfsRtUrl -> gtfsRtSelection(state.gtfsRealtimeUrlInput.trim())
            LiveDataProvider.SiriLite -> siriSelection(state.liveDataProfile.trim())
        }
    }

    private fun gtfsRtSelection(url: String): GtfsRealtimeSelection? = when {
        url.isEmpty() -> failLiveDataSelection(R.string.gtfs_realtime_url_required)
        !url.startsWith(REALTIME_URL_PREFIX) -> failLiveDataSelection(R.string.gtfs_realtime_url_invalid)
        else -> GtfsRealtimeSelection(LiveDataProvider.GtfsRtUrl.wireValue, url = url)
    }

    private fun siriSelection(profile: String): GtfsRealtimeSelection? =
        if (profile.isEmpty()) {
            failLiveDataSelection(R.string.gtfs_realtime_profile_required)
        } else {
            GtfsRealtimeSelection(LiveDataProvider.SiriLite.wireValue, profile = profile)
        }

    private fun failLiveDataSelection(@StringRes message: Int): GtfsRealtimeSelection? {
        _uiState.update { it.copy(gtfsError = UiText.fromResource(message)) }
        return null
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
        val selection = liveDataSelectionOrNull() ?: return
        val feedName = url.substringAfterLast("/")
            .take(GTFS_FEED_NAME_MAX_LENGTH).ifBlank { DEFAULT_GTFS_FEED_NAME }
        uploadGtfsUrl(url, feedName, selection, fallbackUrl = null)
    }

    private fun uploadGtfsUrl(
        url: String,
        name: String,
        selection: GtfsRealtimeSelection,
        fallbackUrl: String?,
    ) {
        viewModelScope.launch {
            _uiState.update { it.copy(gtfsUploading = true, gtfsError = null) }
            try {
                val source = gameRepository.uploadGtfsFromUrl(url, name, selection, fallbackUrl)
                catalogJob?.cancel()
                _uiState.update { state ->
                    state.copy(
                        gtfsUploading = false,
                        showGtfsDialog = false,
                        isCatalogLoading = false,
                        gtfsUrlInput = "",
                        gtfsRealtimeUrlInput = "",
                        liveDataProvider = LiveDataProvider.None,
                        liveDataProfile = "",
                        realtimeProbe = null,
                        gtfsSources = state.gtfsSources + source,
                    )
                }
            } catch (e: IOException) {
                Log.w(TAG, "GTFS URL upload failed", e)
                val error = UiText.fromResource(R.string.gtfs_network_error, e.message ?: "")
                _uiState.update { it.copy(gtfsUploading = false, gtfsError = error) }
            } catch (e: HttpException) {
                Log.w(TAG, "GTFS URL upload failed", e)
                val error = gtfsUploadError(e.serverErrorKey(), e.serverDetail() ?: e.message())
                _uiState.update { it.copy(gtfsUploading = false, gtfsError = error) }
            }
        }
    }

    fun uploadGtfsFromFile(bytes: ByteArray, fileName: String) {
        val selection = liveDataSelectionOrNull() ?: return
        viewModelScope.launch {
            _uiState.update { it.copy(gtfsUploading = true, gtfsError = null) }
            try {
                val feedName = fileName.removeSuffix(".zip")
                    .take(GTFS_FEED_NAME_MAX_LENGTH).ifBlank { DEFAULT_GTFS_FEED_NAME }
                val source = gameRepository.uploadGtfsFromFile(bytes, fileName, feedName, selection)
                _uiState.update { state ->
                    state.copy(
                        gtfsUploading = false,
                        showGtfsDialog = false,
                        gtfsRealtimeUrlInput = "",
                        liveDataProvider = LiveDataProvider.None,
                        liveDataProfile = "",
                        realtimeProbe = null,
                        gtfsSources = state.gtfsSources + source,
                    )
                }
            } catch (e: IOException) {
                Log.w(TAG, "GTFS file upload failed", e)
                val error = UiText.fromResource(R.string.gtfs_network_error, e.message ?: "")
                _uiState.update { it.copy(gtfsUploading = false, gtfsError = error) }
            } catch (e: HttpException) {
                Log.w(TAG, "GTFS file upload failed", e)
                val error = gtfsUploadError(e.serverErrorKey(), e.serverDetail() ?: e.message())
                _uiState.update { it.copy(gtfsUploading = false, gtfsError = error) }
            }
        }
    }

    fun removeGtfsSource(sourceUuid: String) {
        _uiState.update { state ->
            val newSources = state.gtfsSources.filter { it.uuid != sourceUuid }
            val prefix = "$sourceUuid|"
            val newKeys = state.selectedGtfsRouteKeys.filterNot { it.startsWith(prefix) }
            state.copy(
                gtfsSources = newSources,
                selectedGtfsRouteKeys = newKeys.toSet(),
                gtfsSelectionError = null,
            )
        }
    }

    fun toggleGtfsRoute(sourceUuid: String, routeId: String) {
        val key = "$sourceUuid|$routeId"
        _uiState.update { state ->
            when {
                key in state.selectedGtfsRouteKeys -> state.copy(
                    selectedGtfsRouteKeys = state.selectedGtfsRouteKeys - key,
                    gtfsSelectionError = null,
                )
                !canSelectGtfsRoute(state.selectedGtfsRouteKeys) -> state.copy(
                    gtfsSelectionError = UiText.fromResource(R.string.gtfs_selection_too_large),
                )
                else -> state.copy(
                    selectedGtfsRouteKeys = state.selectedGtfsRouteKeys + key,
                    gtfsSelectionError = null,
                )
            }
        }
    }

    fun toggleAllGtfsRoutes(sourceUuid: String) {
        val filteredIds = _uiState.value.uniqueGtfsRoutes
            .filter { it.first.uuid == sourceUuid }
            .map { it.second.routeId }
        if (filteredIds.isEmpty()) return
        _uiState.update { state ->
            when (val updated = toggleGtfsBulkSelection(state.selectedGtfsRouteKeys, sourceUuid, filteredIds)) {
                null -> state.copy(
                    gtfsSelectionError = UiText.fromResource(R.string.gtfs_selection_too_large),
                )
                else -> state.copy(
                    selectedGtfsRouteKeys = updated,
                    gtfsSelectionError = null,
                )
            }
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

    private data class TransitPreviewSelection(
        val osmIds: Set<String>,
        val gtfsLines: List<GtfsLineSelection>,
    ) {
        val isEmpty: Boolean get() = osmIds.isEmpty() && gtfsLines.isEmpty()
    }

    private data class BoundaryInput(
        val useLineBoundary: Boolean,
        val areas: List<AreaInfo>,
        val osmLineIds: Set<String>,
        val gtfsLines: List<GtfsLineSelection>,
        val size: GameSize,
        val edition: Edition,
    ) {
        val hasLines: Boolean get() = osmLineIds.isNotEmpty() || gtfsLines.isNotEmpty()
    }

    private companion object {
        const val TAG = "CreateGameViewModel"
        const val GTFS_FEED_NAME_MAX_LENGTH = 50
        const val DEFAULT_GTFS_FEED_NAME = "GTFS feed"
        const val REALTIME_URL_PREFIX = "https://"

        /** Long enough to swallow a run of ticks, short enough that the map still feels live. */
        const val PREVIEW_DEBOUNCE_MS = 500L

        /** The line boundary is costly to build, so it waits for the selection to settle. */
        const val LINE_BOUNDARY_DEBOUNCE_MS = 2_000L
        const val REALTIME_PROBE_DEBOUNCE_MS = 600L
        const val CATALOG_DEBOUNCE_MS = 300L
        const val FEATURE_COLLECTION_PREFIX = """{"type":"FeatureCollection","features":["""
        const val ERROR_KEY_NO_GEOMETRY = "transit.preview_no_geometry"
    }
}

internal fun gtfsUploadError(serverKey: String?, detail: String?): UiText = when (serverKey) {
    ERROR_KEY_GTFS_SHAPES_MISSING -> UiText.fromResource(R.string.gtfs_shapes_missing)
    ERROR_KEY_GTFS_SELECTION_TOO_LARGE -> UiText.fromResource(R.string.gtfs_selection_too_large)
    ERROR_KEY_GTFS_SELECTION_EMPTY -> UiText.fromResource(R.string.gtfs_selection_empty)
    ERROR_KEY_GTFS_SUBSET_TOO_LARGE -> UiText.fromResource(R.string.gtfs_subset_too_large)
    ERROR_KEY_GTFS_SOURCE_TOO_LARGE -> UiText.fromResource(R.string.gtfs_source_too_large)
    ERROR_KEY_GTFS_SOURCE_UNCOMPRESSED_TOO_LARGE -> UiText.fromResource(R.string.gtfs_source_uncompressed_too_large)
    ERROR_KEY_GTFS_REALTIME_PROVIDER_INVALID -> UiText.fromResource(R.string.gtfs_realtime_provider_invalid)
    ERROR_KEY_GTFS_REALTIME_PROFILE_UNKNOWN -> UiText.fromResource(R.string.gtfs_realtime_profile_unknown)
    ERROR_KEY_GTFS_REALTIME_PROFILE_UNAVAILABLE -> UiText.fromResource(R.string.gtfs_realtime_profile_unavailable)
    ERROR_KEY_GTFS_REALTIME_URL_REQUIRED -> UiText.fromResource(R.string.gtfs_realtime_url_required)
    ERROR_KEY_GTFS_REALTIME_PROFILE_REQUIRED -> UiText.fromResource(R.string.gtfs_realtime_profile_required)
    else -> UiText.fromDynamic(detail ?: "")
}

private const val ERROR_KEY_GTFS_SHAPES_MISSING = "gtfs.shapes_missing"
private const val ERROR_KEY_GTFS_SELECTION_TOO_LARGE = "gtfs.selection_too_large"
private const val ERROR_KEY_GTFS_SELECTION_EMPTY = "gtfs.selection_empty"
private const val ERROR_KEY_GTFS_SUBSET_TOO_LARGE = "gtfs.subset_too_large"
private const val ERROR_KEY_GTFS_SOURCE_TOO_LARGE = "gtfs.source_too_large"
private const val ERROR_KEY_GTFS_SOURCE_UNCOMPRESSED_TOO_LARGE = "gtfs.source_uncompressed_too_large"
private const val ERROR_KEY_GTFS_REALTIME_PROVIDER_INVALID = "gtfs_source.realtime_provider_invalid"
private const val ERROR_KEY_GTFS_REALTIME_PROFILE_UNKNOWN = "gtfs_source.realtime_profile_unknown"
private const val ERROR_KEY_GTFS_REALTIME_PROFILE_UNAVAILABLE = "gtfs_source.realtime_profile_unavailable"
private const val ERROR_KEY_GTFS_REALTIME_URL_REQUIRED = "gtfs_source.realtime_url_required"
private const val ERROR_KEY_GTFS_REALTIME_PROFILE_REQUIRED = "gtfs_source.realtime_profile_required"
private const val ERROR_KEY_AREA_FIT_NO_LINES = "area_fit.no_lines"
private const val ERROR_KEY_AREA_FIT_NO_GEOMETRY = "area_fit.no_geometry"
private const val ERROR_KEY_AREA_FIT_TOO_LARGE = "area_fit.too_large"
