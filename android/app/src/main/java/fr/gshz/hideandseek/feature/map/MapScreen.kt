@file:Suppress("TooManyFunctions")
package fr.gshz.hideandseek.feature.map

import android.graphics.PointF
import android.view.MotionEvent
import android.view.View
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Chat
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Map
import androidx.compose.material.icons.filled.MyLocation
import androidx.compose.material.icons.filled.Place
import androidx.compose.material.icons.filled.QuestionMark
import androidx.compose.material.icons.filled.Stop
import fr.gshz.hideandseek.core.ui.PresetChip
import fr.gshz.hideandseek.core.ui.theme.BrandColors
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.core.ui.theme.extendedColors
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.MutableState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.formatDistance
import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.ManualConstraint
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.ScoreDeclaration
import fr.gshz.hideandseek.domain.model.SeekerMarker
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.model.ZoneCard
import fr.gshz.hideandseek.feature.question.CUSTOM_RADAR_SENTINEL
import org.maplibre.android.MapLibre
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.geojson.Feature
import org.maplibre.geojson.Geometry
import org.maplibre.geojson.MultiPolygon
import org.maplibre.geojson.Polygon
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.sp
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlin.math.hypot
import kotlin.math.roundToInt
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style

internal data class LineRef(
    val ref: String,
    val color: String,
)

internal data class StationTooltipData(
    val label: String,
    val lines: List<LineRef>,
    val lat: Double,
    val lng: Double,
    val screenX: Float,
    val screenY: Float,
)

private class TooltipHost(
    val data: StationTooltipData?,
    val onTapped: (StationTooltipData) -> Unit,
    val onDismiss: () -> Unit,
)

internal data class SeekerActionData(
    val label: String,
    val lat: Double,
    val lng: Double,
    val screenX: Float,
    val screenY: Float,
    val existingMarkerUuid: String?,
)

internal data class SeekerMarkerOverlay(
    val markers: List<SeekerMarker> = emptyList(),
    val radiusMeters: Double? = null,
    val isSeeker: Boolean = false,
    val onMark: (Double, Double) -> Unit = { _, _ -> },
    val onUnmark: (String) -> Unit = {},
)

internal data class MapNavigation(
    val onOpenChatClick: (gameUuid: String) -> Unit,
)

internal data class DrawingOverlayState(
    val drawing: DrawingUiState = DrawingUiState(),
    val manualConstraints: List<ManualConstraint> = emptyList(),
    val onAddVertex: (Double, Double) -> Unit = { _, _ -> },
    val onMoveVertex: (Int, Double, Double) -> Unit = { _, _, _ -> },
    val onStartVertexDrag: (Int) -> Unit = {},
    val onEndVertexDrag: () -> Unit = {},
    val onRemoveVertex: (Int) -> Unit = {},
    val onInsertVertex: (Int, Double, Double) -> Unit = { _, _, _ -> },
    val onToggleEdge: (Double, Double) -> Unit = { _, _ -> },
    val onSelectConstraint: (String?) -> Unit = {},
)

/** The six concern ViewModels the map screen combines, one group so the composable stays short. */
data class MapViewModels(
    val session: MapSessionViewModel,
    val zone: ZoneViewModel,
    val drawing: DrawingViewModel,
    val timeTrap: TimeTrapViewModel,
    val seekerMarkers: SeekerMarkersViewModel,
    val question: QuestionViewModel,
)

@Composable
private fun mapViewModels(): MapViewModels = MapViewModels(
    session = hiltViewModel(),
    zone = hiltViewModel(),
    drawing = hiltViewModel(),
    timeTrap = hiltViewModel(),
    seekerMarkers = hiltViewModel(),
    question = hiltViewModel(),
)

@Composable
fun MapScreen(
    onOpenChatClick: (gameUuid: String) -> Unit,
    onNavigateToLobby: () -> Unit = {},
    viewModels: MapViewModels = mapViewModels(),
) {
    val sessionViewModel = viewModels.session
    val zoneViewModel = viewModels.zone
    val drawingViewModel = viewModels.drawing
    val timeTrapViewModel = viewModels.timeTrap
    val seekerMarkersViewModel = viewModels.seekerMarkers
    val questionViewModel = viewModels.question
    val uiState = assembleScreenState(viewModels)
    val sessionState by sessionViewModel.uiState.collectAsStateWithLifecycle()
    val currentGameUuid by rememberUpdatedState(uiState.gameUuid)
    LaunchedEffect(Unit) {
        sessionViewModel.navigateToLobby.collect { onNavigateToLobby() }
    }
    LaunchedEffect(Unit) {
        drawingViewModel.traceSent.collect { onOpenChatClick(currentGameUuid) }
    }
    MapContent(
        uiState = uiState,
        navigation = MapNavigation(onOpenChatClick = onOpenChatClick),
        onEndRound = sessionViewModel::endRound,
        actions = ZoneActions(
            onEnterZonePlacement = zoneViewModel::enterZonePlacementMode,
            onCancelZonePlacement = zoneViewModel::cancelZonePlacement,
            onPlaceZonePin = zoneViewModel::placeZonePin,
            onSelectZoneRadius = zoneViewModel::selectZoneRadius,
            onConfirmZone = zoneViewModel::confirmZone,
            onCustomZoneRadiusChange = zoneViewModel::onCustomZoneRadiusChange,
            onPlayZoneCard = zoneViewModel::playZoneCard,
        ),
        trapActions = TimeTrapActions(
            onEnterPlacement = timeTrapViewModel::enterTimeTrapPlacement,
            onCancelPlacement = timeTrapViewModel::cancelTimeTrapPlacement,
            onPlacePin = timeTrapViewModel::placeTimeTrapPin,
            onConfirm = timeTrapViewModel::confirmTimeTrap,
            onResolve = timeTrapViewModel::resolveTimeTrap,
        ),
        simActions = simulationActionsFor(questionViewModel, sessionState.edition, sessionState.selectedTransitLines),
        drawingActions = drawingActionsFor(drawingViewModel, sessionState.edition),
        onEnterSimulation = { category ->
            questionViewModel.enterSimulation(category, sessionState.selectedTransitLines)
        },
        onStyleSelected = sessionViewModel::setMapStyle,
        onMarkSuspectedStation = seekerMarkersViewModel::markSuspectedStation,
        onUnmarkStation = seekerMarkersViewModel::unmarkStation,
    )
}

@Composable
private fun assembleScreenState(viewModels: MapViewModels): MapUiState {
    val sessionState by viewModels.session.uiState.collectAsStateWithLifecycle()
    val zoneState by viewModels.zone.uiState.collectAsStateWithLifecycle()
    val drawingState by viewModels.drawing.uiState.collectAsStateWithLifecycle()
    val trapState by viewModels.timeTrap.uiState.collectAsStateWithLifecycle()
    val seekerState by viewModels.seekerMarkers.uiState.collectAsStateWithLifecycle()
    val questionState by viewModels.question.uiState.collectAsStateWithLifecycle()
    return remember(
        sessionState, zoneState, drawingState, trapState, seekerState, questionState,
    ) {
        assembleMapUiState(sessionState, zoneState, drawingState, trapState, seekerState, questionState)
    }
}

private fun simulationActionsFor(
    viewModel: QuestionViewModel,
    edition: Edition,
    selectedTransitLines: List<TransitLine>,
) = SimulationActions(
    onSetSeekerPin = { lat, lng -> viewModel.updateSimulation { it.copy(seeker = ZonePin(lat, lng)) } },
    onSetEndPin = { lat, lng -> viewModel.updateSimulation { it.copy(end = ZonePin(lat, lng)) } },
    onSetRadius = { radiusMeters ->
        val isCustom = radiusMeters == CUSTOM_RADAR_SENTINEL.toInt()
        viewModel.updateSimulation(refreshGeometry = !isCustom) {
            it.copy(
                radiusMeters = if (isCustom) null else radiusMeters,
                isCustomRadius = isCustom,
                customRadiusText = if (isCustom) it.customRadiusText else "",
            )
        }
    },
    onSetDistance = { distanceMeters -> viewModel.updateSimulation { it.copy(distanceMeters = distanceMeters) } },
    onCustomRadiusChange = { text -> viewModel.onCustomRadiusChange(text, edition) },
    onSetAnswer = { answer -> viewModel.updateSimulation { it.copy(answer = answer) } },
    onSetChosenFeature = { featureId -> viewModel.updateSimulation { it.copy(chosenFeatureId = featureId) } },
    onSetWithinMeters = { withinMeters -> viewModel.updateSimulation { it.copy(withinMeters = withinMeters) } },
    onSetFeatureType = { featureType ->
        viewModel.updateSimulation(refreshGeometry = featureType != null) {
            it.selectFeatureType(featureType)
        }
        if (featureType != null) viewModel.fetchCandidateFeatures()
    },
    onSelectTransitLineOption = {
        viewModel.updateSimulation(refreshGeometry = false) { it.selectTransitLineOption() }
    },
    onSelectStationNameLengthOption = {
        viewModel.updateSimulation(refreshGeometry = false) { it.selectStationNameLengthOption() }
    },
    onSelectSeaLevelOption = {
        viewModel.updateSimulation(refreshGeometry = false) { it.selectSeaLevelOption() }
    },
    onSetTransitLine = { line ->
        viewModel.updateSimulation(refreshGeometry = false) { it.copy(selectedTransitLine = line) }
    },
    onSetCategory = { category -> viewModel.setSimCategory(category, selectedTransitLines) },
    onSetPhotoTarget = { photoTarget ->
        viewModel.updateSimulation(refreshGeometry = false) { it.copy(photoTarget = photoTarget) }
    },
    onAsk = viewModel::askSheetQuestion,
    onDismiss = viewModel::exitSimulation,
    onTogglePreviewMode = {
        viewModel.updateSimulation(refreshGeometry = false) { it.togglePreviewMode() }
    },
    onStartThermometer = viewModel::startThermometer,
    onConfirmThermometerArrival = viewModel::confirmThermometerArrival,
    onCancelQuestion = viewModel::cancelQuestion,
)

private fun SimulationState.selectFeatureType(featureType: String?) = copy(
    featureType = featureType,
    chosenFeatureId = null,
    transitLineSelected = false,
    stationNameLengthSelected = false,
    seaLevelSelected = false,
    selectedTransitLine = null,
)

private fun SimulationState.selectTransitLineOption() = copy(
    transitLineSelected = true,
    stationNameLengthSelected = false,
    featureType = null,
    chosenFeatureId = null,
    candidateFeatures = emptyList(),
)

private fun SimulationState.selectStationNameLengthOption() = copy(
    stationNameLengthSelected = true,
    featureType = null,
    transitLineSelected = false,
    selectedTransitLine = null,
    chosenFeatureId = null,
    candidateFeatures = emptyList(),
)

private fun SimulationState.selectSeaLevelOption() = copy(
    seaLevelSelected = true,
    featureType = null,
    chosenFeatureId = null,
    candidateFeatures = emptyList(),
)

private fun SimulationState.togglePreviewMode() = copy(
    mode = if (mode == QuestionSheetMode.Ask) QuestionSheetMode.Preview else QuestionSheetMode.Ask,
)


private fun drawingActionsFor(viewModel: DrawingViewModel, edition: Edition) = DrawingActions(
    onEnterDrawing = viewModel::enterDrawing,
    onSetDrawMode = { mode -> viewModel.updateDrawing { it.copy(mode = mode) } },
    onAddVertex = { lat, lng -> viewModel.updateDrawing { it.addVertex(lat, lng) } },
    onMoveVertex = { index, lat, lng -> viewModel.updateDrawing { it.moveVertex(index, lat, lng) } },
    onStartVertexDrag = { index -> viewModel.updateDrawing { it.startVertexDrag(index) } },
    onEndVertexDrag = { viewModel.updateDrawing { it.endVertexDrag() } },
    onRemoveVertex = { index -> viewModel.updateDrawing { it.removeVertex(index) } },
    onInsertVertex = { afterIndex, lat, lng -> viewModel.updateDrawing { it.insertVertex(afterIndex, lat, lng) } },
    onConfirmDrawing = viewModel::confirmDrawing,
    onCancelDrawing = viewModel::cancelDrawing,
    onSelectManualConstraint = { uuid -> viewModel.updateDrawing { it.copy(selectedManualConstraintUuid = uuid) } },
    onDeleteSelectedManualConstraint = viewModel::deleteSelectedManualConstraint,
    onConfirmTrace = { viewModel.confirmTrace(edition) },
    onSendTrace = viewModel::sendTrace,
    onResumeTraceEditing = viewModel::resumeTraceEditing,
    onToggleEdge = viewModel::toggleEdgeAt,
)

private fun DrawingState.addVertex(latitude: Double, longitude: Double) =
    if (!isActive) this else copy(vertices = vertices + ZonePin(latitude, longitude))

private fun DrawingState.moveVertex(index: Int, latitude: Double, longitude: Double) =
    if (index !in vertices.indices) {
        this
    } else {
        copy(vertices = vertices.mapIndexed { i, v -> if (i == index) ZonePin(latitude, longitude) else v })
    }

private fun DrawingState.startVertexDrag(index: Int) =
    if (index in vertices.indices) copy(draggingVertexIndex = index) else this

private fun DrawingState.endVertexDrag() = copy(draggingVertexIndex = null)

private fun DrawingState.removeVertex(index: Int) =
    if (index !in vertices.indices) {
        this
    } else {
        copy(
            vertices = vertices.filterIndexed { i, _ -> i != index },
            draggingVertexIndex = null,
        )
    }

private fun DrawingState.insertVertex(afterIndex: Int, latitude: Double, longitude: Double) =
    if (!isActive || afterIndex !in vertices.indices) {
        this
    } else {
        copy(
            vertices = vertices.toMutableList().apply { add(afterIndex + 1, ZonePin(latitude, longitude)) },
        )
    }

internal data class ZoneActions(
    val onEnterZonePlacement: () -> Unit,
    val onCancelZonePlacement: () -> Unit,
    val onPlaceZonePin: (latitude: Double, longitude: Double, stationName: String?) -> Unit,
    val onSelectZoneRadius: (Double?) -> Unit,
    val onConfirmZone: () -> Unit,
    val onCustomZoneRadiusChange: (String) -> Unit,
    val onPlayZoneCard: (ZoneCard, String) -> Unit,
)

internal data class TimeTrapActions(
    val onEnterPlacement: () -> Unit = {},
    val onCancelPlacement: () -> Unit = {},
    val onPlacePin: (latitude: Double, longitude: Double, stationName: String?) -> Unit = { _, _, _ -> },
    val onConfirm: (photoUri: String) -> Unit = {},
    val onResolve: (trapUuid: String, confirmed: Boolean) -> Unit = { _, _ -> },
)

@OptIn(ExperimentalMaterial3Api::class)
@Suppress("LongParameterList", "LongMethod")
@Composable
internal fun MapContent(
    uiState: MapUiState,
    navigation: MapNavigation,
    onEndRound: (ScoreDeclaration) -> Unit,
    actions: ZoneActions,
    trapActions: TimeTrapActions = TimeTrapActions(),
    simActions: SimulationActions = SimulationActions(),
    drawingActions: DrawingActions = DrawingActions(),
    onEnterSimulation: (QuestionCategory) -> Unit = {},
    onStyleSelected: (MapStyle) -> Unit = {},
    onMarkSuspectedStation: (Double, Double) -> Unit = { _, _ -> },
    onUnmarkStation: (String) -> Unit = {},
    modifier: Modifier = Modifier,
) {
    val recenterCounter = remember { mutableStateOf(0) }
    // Freezing the timer here keeps the bonus tally out of the score.
    val frozenHidingSeconds = remember { mutableStateOf<Long?>(null) }
    Scaffold(
        modifier = modifier,
        topBar = {
            Column {
                TopAppBar(title = { Text(uiState.gameName.ifBlank { stringResource(R.string.map_title) }) })
                RoundBanner(uiState.roundStatus, uiState.roundTimerSeconds)
                if (frozenHidingSeconds.value == null) {
                    ErrorText(uiState.roundError, errorKey = uiState.roundErrorKey, errorArgs = uiState.roundErrorArgs)
                }
                // A card is played with no panel open, so its refusal has nowhere else to surface.
                if (!uiState.isPlacingZone) {
                    ErrorText(uiState.zoneError, errorKey = uiState.zoneErrorKey, errorArgs = uiState.zoneErrorArgs)
                }
                // A seeker resolving a detection has no trap panel, so their refusals surface here.
                if (!uiState.showTimeTrapPanel) {
                    ErrorText(
                        uiState.timeTrapError,
                        errorKey = uiState.timeTrapErrorKey,
                        errorArgs = uiState.timeTrapErrorArgs,
                    )
                }
            }
        },
        floatingActionButton = { MapFabColumn(
            uiState = uiState,
            actions = actions,
            navigation = navigation,
            onEndRoundClick = { frozenHidingSeconds.value = uiState.roundTimerSeconds ?: 0L },
            onEnterDrawing = drawingActions.onEnterDrawing,
            fabActions = MapFabActions(
                onRecenter = { recenterCounter.value++ },
                onEnterSimulation = onEnterSimulation,
                onStyleSelected = onStyleSelected,
                onEnterTimeTrapPlacement = trapActions.onEnterPlacement,
            ),
        ) },
    ) { innerPadding ->
        Box(modifier = Modifier.fillMaxSize().padding(innerPadding)) {
            MapContentLayout(
                uiState = uiState,
                actions = actions,
                trapActions = trapActions,
                simActions = simActions,
                drawingActions = drawingActions,
                onEnterSimulation = onEnterSimulation,
                recenterCounter = recenterCounter,
                styleSource = uiState.currentStyleSource,
                navigation = navigation,
                seekerOverlay = SeekerMarkerOverlay(
                    markers = uiState.seekerMarkers,
                    radiusMeters = uiState.currentZoneRadiusMeters,
                    isSeeker = uiState.side == Side.Seeker,
                    onMark = onMarkSuspectedStation,
                    onUnmark = onUnmarkStation,
                ),
            )
        }
    }
    uiState.pendingTrapDetection?.let { trap ->
        TimeTrapDetectionDialog(
            trap = trap,
            onResolve = { confirmed -> trapActions.onResolve(trap.uuid, confirmed) },
        )
    }
    uiState.traceReview?.let { review ->
        TracePreviewDialog(
            review = review,
            onSend = drawingActions.onSendTrace,
            onKeepEditing = drawingActions.onResumeTraceEditing,
        )
    }
    frozenHidingSeconds.value?.let { frozen ->
        EndRoundDialog(
            uiState = uiState,
            hidingSeconds = frozen,
            onConfirm = onEndRound,
            onDismiss = { frozenHidingSeconds.value = null },
        )
    }
    LaunchedEffect(uiState.roundStatus) {
        if (uiState.roundStatus == RoundStatus.Ended) frozenHidingSeconds.value = null
    }
}

@Suppress("LongParameterList")
@Composable
private fun BoxScope.MapContentLayout(
    uiState: MapUiState,
    actions: ZoneActions,
    trapActions: TimeTrapActions,
    simActions: SimulationActions,
    drawingActions: DrawingActions,
    onEnterSimulation: (QuestionCategory) -> Unit,
    recenterCounter: MutableState<Int>,
    styleSource: StyleSource,
    navigation: MapNavigation,
    seekerOverlay: SeekerMarkerOverlay = SeekerMarkerOverlay(),
) {
    val isPreviewMode = uiState.simulation?.mode == QuestionSheetMode.Preview
    MapLibreMapView(
        markers = uiState.markers,
        possibleAreaGeoJson = uiState.possibleAreaGeoJson,
        boundary = uiState.boundary,
        transitOverlayGeoJson = uiState.transitOverlayGeoJson,
        boundaryGeoJson = uiState.boundaryGeoJson,
        recenterCounter = recenterCounter,
        simulationGeoJson = uiState.simulation?.previewGeoJson,
        candidatePoiGeoJson = candidatePoiGeoJson(uiState.simulation),
        simulationPinGeoJson = simulationPinsGeoJson(uiState.simulation),
        styleSource = styleSource,
        zoneOverlay = zoneOverlayState(uiState, actions, trapActions, simActions, isPreviewMode),
        seekerOverlay = seekerOverlay,
        drawingOverlay = drawingOverlayState(uiState, drawingActions),
        trapPins = trapPins(uiState),
        modifier = Modifier.fillMaxSize(),
    )
    MapOverlays(
        uiState = uiState,
        overlayActions = MapOverlayActions(actions, trapActions, simActions, drawingActions),
        onEnterSimulation = onEnterSimulation,
        navigation = navigation,
    )
}

private fun zoneOverlayState(
    uiState: MapUiState,
    actions: ZoneActions,
    trapActions: TimeTrapActions,
    simActions: SimulationActions,
    isPreviewMode: Boolean,
) = ZoneOverlayState(
    pin = if (uiState.isPlacingZone) uiState.pendingZonePin
        else uiState.submittedZone?.let { ZonePin(it.lat, it.lng) },
    canPlacePin = uiState.side == Side.Hider && (uiState.isPlacingZone || uiState.isPlacingTimeTrap)
        || isPreviewMode,
    onPinPlaced = { lat, lng, stationName ->
        routePlacedPin(uiState, actions, trapActions, simActions, ZonePin(lat, lng), stationName)
    },
    radiusMeters = if (uiState.isPlacingZone) {
        uiState.customZoneRadiusMeters ?: uiState.selectedZoneRadiusMeters
    } else {
        uiState.submittedZone?.radiusMeters
    },
    onCandidatePoiTapped = { featureId -> simActions.onSetChosenFeature(featureId) },
)

@Suppress("LongParameterList")
private fun routePlacedPin(
    uiState: MapUiState,
    actions: ZoneActions,
    trapActions: TimeTrapActions,
    simActions: SimulationActions,
    pin: ZonePin,
    stationName: String?,
) {
    val sim = uiState.simulation
    when {
        sim != null && sim.category == QuestionCategory.Thermometer && sim.seeker != null ->
            simActions.onSetEndPin(pin.latitude, pin.longitude)
        sim != null -> simActions.onSetSeekerPin(pin.latitude, pin.longitude)
        uiState.isPlacingTimeTrap -> trapActions.onPlacePin(pin.latitude, pin.longitude, stationName)
        else -> actions.onPlaceZonePin(pin.latitude, pin.longitude, stationName)
    }
}

/**
 * The pending pin joins the placed traps so the hider sees where the tap snapped. It carries no
 * value, so the ticking figure never rebuilds this GeoJSON.
 */
private fun trapPins(uiState: MapUiState): List<TrapPin> {
    val placed = uiState.timeTraps.map { trap ->
        TrapPin(trap.uuid, trap.lat, trap.lng, trap.stationName ?: "")
    }
    val pending = uiState.pendingTrapPin?.takeIf { uiState.isPlacingTimeTrap }?.let { pin ->
        TrapPin(PENDING_TRAP_ID, pin.latitude, pin.longitude, uiState.pendingTrapStationName ?: "", isPending = true)
    }
    return placed + listOfNotNull(pending)
}

private const val PENDING_TRAP_ID = "pending-time-trap"

internal data class MapOverlayActions(
    val zone: ZoneActions,
    val trap: TimeTrapActions = TimeTrapActions(),
    val sim: SimulationActions = SimulationActions(),
    val drawing: DrawingActions = DrawingActions(),
)

private fun drawingOverlayState(uiState: MapUiState, drawingActions: DrawingActions) = DrawingOverlayState(
    drawing = uiState.drawing,
    manualConstraints = uiState.manualConstraints,
    onAddVertex = drawingActions.onAddVertex,
    onMoveVertex = drawingActions.onMoveVertex,
    onStartVertexDrag = drawingActions.onStartVertexDrag,
    onEndVertexDrag = drawingActions.onEndVertexDrag,
    onRemoveVertex = drawingActions.onRemoveVertex,
    onInsertVertex = drawingActions.onInsertVertex,
    onToggleEdge = drawingActions.onToggleEdge,
    onSelectConstraint = drawingActions.onSelectManualConstraint,
)

private const val PENDING_CHIP_ALPHA = 0.88f

@Composable
@Suppress("LongMethod")
private fun BoxScope.MapOverlays(
    uiState: MapUiState,
    overlayActions: MapOverlayActions,
    onEnterSimulation: (QuestionCategory) -> Unit = {},
    navigation: MapNavigation = MapNavigation(onOpenChatClick = {}),
) {
    val actions = overlayActions.zone
    val simActions = overlayActions.sim
    val drawingActions = overlayActions.drawing
    DrawingOverlays(uiState, drawingActions)
    TimeTrapValueChips(
        traps = uiState.timeTraps,
        modifier = Modifier.align(Alignment.TopStart).padding(Spacing.sm),
    )
    val outstanding = uiState.outstandingQuestion
    if (outstanding != null && uiState.simulation == null && uiState.side == Side.Seeker) {
        PendingQuestionChip(
            category = outstanding.category,
            onClick = { onEnterSimulation(outstanding.category) },
            modifier = Modifier.align(Alignment.TopCenter).padding(top = Spacing.sm),
        )
    }
    if (uiState.showHiderQuestionChip) {
        HiderQuestionChip(
            onClick = { navigation.onOpenChatClick(uiState.gameUuid) },
            modifier = Modifier.align(Alignment.TopCenter).padding(top = Spacing.sm),
        )
    }
    // A frozen seeker must not travel or ask, and the app cannot stop them walking: say so.
    if (uiState.showSeekersFrozenBanner) {
        Surface(
            modifier = Modifier
                .align(Alignment.TopCenter)
                .padding(top = 56.dp, start = 16.dp, end = 16.dp),
            color = extendedColors.warningContainer,
            shape = MaterialTheme.shapes.small,
        ) {
            Text(
                text = stringResource(R.string.zone_card_seekers_frozen),
                modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
                style = MaterialTheme.typography.labelMedium,
                color = extendedColors.onWarningContainer,
            )
        }
    }
    if (uiState.side == Side.Hider) {
        if (uiState.showTimeTrapPanel) {
            TimeTrapPlacementPanel(
                uiState = uiState,
                onConfirm = overlayActions.trap.onConfirm,
                onCancel = overlayActions.trap.onCancelPlacement,
                modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth(),
            )
        } else if (uiState.showZonePlacementPanel) {
            ZonePlacementPanel(
                uiState = uiState,
                actions = actions,
                modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth(),
            )
        } else if (uiState.submittedZone != null && uiState.outsideZone) {
            Surface(
                modifier = Modifier
                    .align(Alignment.TopCenter)
                    .padding(top = 56.dp, start = 16.dp, end = 16.dp),
                color = MaterialTheme.colorScheme.errorContainer,
                shape = MaterialTheme.shapes.small,
            ) {
                Text(
                    text = stringResource(R.string.zone_outside_warning),
                    modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onErrorContainer,
                )
            }
        }
    }
    if (uiState.simulation != null) {
        SimulationSheet(
            state = uiState.simulation,
            edition = uiState.edition,
            gameSize = uiState.gameSize,
            actions = simActions,
            modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth(),
            askedQuestions = uiState.askedQuestions,
        )
    }
}

@Composable
private fun PendingQuestionChip(
    category: QuestionCategory,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        onClick = onClick,
        modifier = modifier,
        shape = MaterialTheme.shapes.small,
        color = MaterialTheme.colorScheme.surface.copy(alpha = PENDING_CHIP_ALPHA),
        contentColor = MaterialTheme.colorScheme.onSurface,
        shadowElevation = 2.dp,
    ) {
        Text(
            text = stringResource(R.string.question_pending_chip, categoryLabel(category)),
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
            style = MaterialTheme.typography.labelMedium,
        )
    }
}

@Composable
private fun HiderQuestionChip(
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        onClick = onClick,
        modifier = modifier,
        shape = MaterialTheme.shapes.small,
        color = MaterialTheme.colorScheme.surface.copy(alpha = PENDING_CHIP_ALPHA),
        contentColor = MaterialTheme.colorScheme.onSurface,
        shadowElevation = 2.dp,
    ) {
        Text(
            text = stringResource(R.string.question_hider_chip),
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
            style = MaterialTheme.typography.labelMedium,
        )
    }
}

private data class MapFabActions(
    val onRecenter: () -> Unit = {},
    val onEnterSimulation: (QuestionCategory) -> Unit = {},
    val onStyleSelected: (MapStyle) -> Unit = {},
    val onEnterTimeTrapPlacement: () -> Unit = {},
)

/**
 * A time trap needs no hiding zone, so the menu opens mid-hunt even when the zone cards do not, and
 * free placement stays available for a first zone set late.
 */
@Composable
private fun HiderFabs(uiState: MapUiState, actions: ZoneActions, onEnterTimeTrapPlacement: () -> Unit) {
    if (uiState.canPlayZoneCards || uiState.canPlaceTimeTraps) {
        ZoneCardFab(
            uiState = uiState,
            onPlayZoneCard = actions.onPlayZoneCard,
            onEnterTimeTrapPlacement = onEnterTimeTrapPlacement,
        )
    }
    if (!uiState.canPlayZoneCards && uiState.side == Side.Hider) {
        FloatingActionButton(onClick = actions.onEnterZonePlacement) {
            Icon(
                Icons.Filled.Place,
                contentDescription = stringResource(
                    if (uiState.submittedZone != null) R.string.zone_move_button
                    else R.string.zone_set_button,
                ),
            )
        }
    }
}

@Composable
private fun MapFabColumn(
    uiState: MapUiState,
    actions: ZoneActions,
    navigation: MapNavigation,
    onEndRoundClick: () -> Unit,
    onEnterDrawing: () -> Unit = {},
    fabActions: MapFabActions = MapFabActions(),
) {
    val showingDeletePrompt = uiState.side == Side.Seeker && uiState.selectedManualConstraintUuid != null
    val hidden = uiState.isPlacingZone || uiState.isPlacingTimeTrap ||
        uiState.simulation != null || uiState.drawing.isActive
    if (hidden || showingDeletePrompt) return
    val styles = remember(uiState) { availableMapStyles(uiState) }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp), horizontalAlignment = Alignment.End) {
        if (uiState.side == Side.Seeker) {
            FloatingActionButton(onClick = { fabActions.onEnterSimulation(QuestionCategory.Radar) }) {
                Icon(Icons.Filled.QuestionMark, contentDescription = stringResource(R.string.map_open_questions))
            }
            FloatingActionButton(onClick = onEnterDrawing) {
                Icon(Icons.Filled.Edit, contentDescription = stringResource(R.string.draw_constraint_button))
            }
        }
        FloatingActionButton(onClick = { navigation.onOpenChatClick(uiState.gameUuid) }) {
            Icon(Icons.Filled.Chat, contentDescription = stringResource(R.string.map_open_chat))
        }
        if (styles.size > 1) {
            MapStyleSwitcher(styles = styles, onStyleSelected = fabActions.onStyleSelected)
        }
        FloatingActionButton(onClick = fabActions.onRecenter) {
            Icon(Icons.Filled.MyLocation, contentDescription = stringResource(R.string.map_recenter_button))
        }
        HiderFabs(uiState, actions, fabActions.onEnterTimeTrapPlacement)
        if (uiState.canEndRound) {
            FloatingActionButton(onClick = { if (!uiState.isEndingRound) onEndRoundClick() }) {
                Icon(Icons.Filled.Stop, contentDescription = stringResource(R.string.round_end_button))
            }
        }
    }
}

private const val FRANCE_SW_LAT = 41.3
private const val FRANCE_SW_LNG = -5.2
private const val FRANCE_NE_LAT = 51.15
private const val FRANCE_NE_LNG = 9.7

private fun availableMapStyles(uiState: MapUiState): List<MapStyle> = buildList {
    if (uiState.mapStyleAvailable && uiState.stadiaApiKey != null) {
        add(MapStyle.OsmBright)
    }
    if (uiState.thunderforestApiKey != null) {
        add(MapStyle.Atlas)
    }
    add(MapStyle.Standard)
    if (uiState.boundary?.intersectsFrance() == true) {
        add(MapStyle.IgnPlan)
    }
    if (uiState.mapStyleAvailable && uiState.stadiaApiKey != null) {
        add(MapStyle.Dark)
    }
    if (uiState.maptilerApiKey != null) {
        add(MapStyle.Satellite)
    }
    if (uiState.boundary?.intersectsFrance() == true) {
        add(MapStyle.IgnOrtho)
    }
}

private fun MapBounds.intersectsFrance(): Boolean =
    neLat > FRANCE_SW_LAT && swLat < FRANCE_NE_LAT &&
        neLng > FRANCE_SW_LNG && swLng < FRANCE_NE_LNG

@Composable
private fun MapStyleSwitcher(styles: List<MapStyle>, onStyleSelected: (MapStyle) -> Unit) {
    val expanded = remember { mutableStateOf(false) }
    Box {
        FloatingActionButton(onClick = { expanded.value = true }) {
            Icon(Icons.Filled.Map, contentDescription = stringResource(R.string.map_style_button))
        }
        DropdownMenu(
            expanded = expanded.value,
            onDismissRequest = { expanded.value = false },
        ) {
            styles.forEach { style ->
                DropdownMenuItem(
                    text = { Text(mapStyleLabel(style)) },
                    onClick = {
                        expanded.value = false
                        onStyleSelected(style)
                    },
                )
            }
        }
    }
}

@Composable
private fun mapStyleLabel(style: MapStyle): String = when (style) {
    MapStyle.Standard -> stringResource(R.string.map_style_standard)
    MapStyle.OsmBright -> stringResource(R.string.map_style_bright)
    MapStyle.Dark -> stringResource(R.string.map_style_dark)
    MapStyle.Atlas -> stringResource(R.string.map_style_atlas)
    MapStyle.Satellite -> stringResource(R.string.map_style_satellite)
    MapStyle.IgnPlan -> stringResource(R.string.map_style_ign_plan)
    MapStyle.IgnOrtho -> stringResource(R.string.map_style_ign_satellite)
}

@Composable
private fun ZonePlacementPanel(uiState: MapUiState, actions: ZoneActions, modifier: Modifier = Modifier) {
    Surface(
        modifier = modifier,
        shape = MaterialTheme.shapes.large,
        tonalElevation = 4.dp,
    ) {
        Column(
            modifier = Modifier.padding(horizontal = Spacing.lg, vertical = Spacing.md),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            ErrorText(uiState.zoneError, errorKey = uiState.zoneErrorKey, errorArgs = uiState.zoneErrorArgs)
            Text(
                text = stringResource(R.string.zone_placement_instructions),
                style = MaterialTheme.typography.bodySmall,
            )
            // Showing which station the pin snapped to is how the hider confirms the Move reveal is right.
            uiState.pendingZoneStationName?.let { station ->
                Text(
                    text = stringResource(R.string.zone_placement_station, station),
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.Bold,
                )
            }
            ZoneRadiusSection(
                state = ZoneRadiusSectionState(
                    edition = uiState.edition,
                    selectedRadiusMeters = uiState.selectedZoneRadiusMeters,
                    customRadiusText = uiState.customZoneRadiusText,
                    customRadiusMeters = uiState.customZoneRadiusMeters,
                    enabled = !uiState.isSubmittingZone,
                ),
                onSelectRadius = actions.onSelectZoneRadius,
                onCustomTextChange = actions.onCustomZoneRadiusChange,
            )

            Row(horizontalArrangement = Arrangement.spacedBy(Spacing.sm)) {
                OutlinedButton(onClick = actions.onCancelZonePlacement, modifier = Modifier.weight(1f)) {
                    Text(stringResource(R.string.zone_cancel_button))
                }
                Button(
                    onClick = actions.onConfirmZone,
                    enabled = uiState.pendingZonePin != null && !uiState.isSubmittingZone,
                    modifier = Modifier.weight(1f),
                ) {
                    Text(stringResource(R.string.zone_confirm_button))
                }
            }
        }
    }
}

@Composable
private fun BoxScope.DrawingOverlays(uiState: MapUiState, drawingActions: DrawingActions) {
    if (uiState.drawing.isActive) {
        DrawingPanel(
            drawing = uiState.drawing,
            actions = drawingActions,
            modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth(),
        )
        return
    }
    if (uiState.side == Side.Seeker && uiState.selectedManualConstraintUuid != null) {
        DeleteConstraintChip(
            onDelete = drawingActions.onDeleteSelectedManualConstraint,
            onDismiss = { drawingActions.onSelectManualConstraint(null) },
            modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth().padding(Spacing.md),
        )
    }
}

@Composable
private fun DrawingPanel(drawing: DrawingUiState, actions: DrawingActions, modifier: Modifier = Modifier) {
    val isTrace = drawing.kind == DrawKind.Trace
    Surface(modifier = modifier, shape = MaterialTheme.shapes.large, tonalElevation = 4.dp) {
        Column(
            modifier = Modifier.padding(horizontal = Spacing.lg, vertical = Spacing.md),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            if (isTrace) {
                ErrorText(drawing.renderError)
                TraceReadout(drawing)
                if (drawing.streetDataUnavailable) {
                    Text(
                        text = stringResource(R.string.trace_streets_unavailable),
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            } else {
                Text(stringResource(R.string.draw_instructions), style = MaterialTheme.typography.bodySmall)
                InvertAreaSwitch(drawing, actions)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(Spacing.sm)) {
                OutlinedButton(onClick = actions.onCancelDrawing, modifier = Modifier.weight(1f)) {
                    Text(stringResource(R.string.draw_cancel_button))
                }
                Button(
                    onClick = if (isTrace) actions.onConfirmTrace else actions.onConfirmDrawing,
                    enabled = if (isTrace) drawing.canConfirmTrace else drawing.canClose,
                    modifier = Modifier.weight(1f),
                ) {
                    Text(stringResource(R.string.draw_close_button))
                }
            }
        }
    }
}

@Composable
private fun TraceReadout(drawing: DrawingUiState) {
    Text(stringResource(R.string.trace_instructions), style = MaterialTheme.typography.bodySmall)
    Text(
        text = stringResource(R.string.trace_length_label, formatDistance(drawing.lengthMeters, drawing.edition)),
        style = MaterialTheme.typography.bodyMedium,
    )
    val minimum = drawing.minimumMeters
    if (minimum != null && drawing.lengthMeters < minimum) {
        Text(
            text = stringResource(R.string.trace_too_short, formatDistance(minimum, drawing.edition)),
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.error,
        )
    }
    val shapeNote = when (drawing.traceShape) {
        TraceShape.Disconnected -> R.string.trace_shape_disconnected
        TraceShape.Branches -> R.string.trace_shape_branches
        else -> null
    }
    if (shapeNote != null) {
        Text(
            text = stringResource(shapeNote),
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun InvertAreaSwitch(drawing: DrawingUiState, actions: DrawingActions) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        Switch(
            checked = drawing.mode == ConstraintMode.Include,
            onCheckedChange = {
                actions.onSetDrawMode(if (it) ConstraintMode.Include else ConstraintMode.Exclude)
            },
        )
        Text(stringResource(R.string.draw_invert_label), style = MaterialTheme.typography.bodyMedium)
    }
}

@Composable
private fun DeleteConstraintChip(onDelete: () -> Unit, onDismiss: () -> Unit, modifier: Modifier = Modifier) {
    Surface(modifier = modifier, shape = MaterialTheme.shapes.large, tonalElevation = 4.dp) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.lg, vertical = Spacing.md),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            Text(
                text = stringResource(R.string.constraint_delete_prompt),
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier.weight(1f),
            )
            OutlinedButton(onClick = onDismiss) { Text(stringResource(R.string.draw_cancel_button)) }
            Button(onClick = onDelete) { Text(stringResource(R.string.constraint_delete_button)) }
        }
    }
}

internal data class ZoneOverlayState(
    val pin: ZonePin?,
    val canPlacePin: Boolean,
    val onPinPlaced: (latitude: Double, longitude: Double, stationName: String?) -> Unit,
    val radiusMeters: Double? = null,
    val onCandidatePoiTapped: (String) -> Unit = {},
)

@Composable
@Suppress("LongParameterList", "LongMethod")
private fun MapLibreMapView(
    markers: List<PlayerMarker>,
    possibleAreaGeoJson: String?,
    boundary: MapBounds?,
    zoneOverlay: ZoneOverlayState,
    transitOverlayGeoJson: String? = null,
    boundaryGeoJson: String? = null,
    simulationGeoJson: String? = null,
    candidatePoiGeoJson: String? = null,
    simulationPinGeoJson: String? = null,
    recenterCounter: MutableState<Int>? = null,
    styleSource: StyleSource = StyleSource.Uri(MapConstants.STYLE_URL),
    seekerOverlay: SeekerMarkerOverlay = SeekerMarkerOverlay(),
    drawingOverlay: DrawingOverlayState = DrawingOverlayState(),
    trapPins: List<TrapPin> = emptyList(),
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val state = remember { MapViewState() }
    val stationTooltip = remember { mutableStateOf<StationTooltipData?>(null) }
    val seekerAction = remember { mutableStateOf<SeekerActionData?>(null) }
    val seekerCircles = if (seekerOverlay.isSeeker) {
        seekerOverlay.markers.map { ZonePin(it.lat, it.lng) }
    } else {
        emptyList()
    }

    val mapView = remember {
        MapLibre.getInstance(context)
        MapView(context)
    }

    StyleReloadEffect(state, styleSource)

    Box(modifier = modifier) {
        MapWithTooltipOverlay(
            mapView = mapView,
            state = state,
            lifecycle = lifecycle,
            tapCallbacks = MapTapCallbacks(
                canPlacePin = zoneOverlay.canPlacePin,
                onPinPlaced = zoneOverlay.onPinPlaced,
                onCandidateTapped = zoneOverlay.onCandidatePoiTapped,
            ),
            tooltip = TooltipHost(
                data = stationTooltip.value,
                onTapped = { stationTooltip.value = it },
                onDismiss = { stationTooltip.value = null },
            ),
            seekerHost = seekerOverlay,
            seekerAction = seekerAction,
            drawingOverlay = drawingOverlay,
            styleSource = styleSource,
        )
    }

    LaunchedEffect(stationTooltip.value) {
        if (stationTooltip.value != null) {
            delay(4000)
            stationTooltip.value = null
        }
    }

    // Keep the tooltip pinned to the station when the map camera moves.
    StationTooltipTracker(stationTooltip = stationTooltip, mapRef = state.mapRef)
    SeekerActionTracker(seekerAction = seekerAction, mapRef = state.mapRef)

    LaunchedEffect(
        state.styleReady.value, markers, possibleAreaGeoJson, boundary,
        zoneOverlay.pin, transitOverlayGeoJson, zoneOverlay.radiusMeters,
        boundaryGeoJson, simulationGeoJson, candidatePoiGeoJson, simulationPinGeoJson,
        seekerCircles, seekerOverlay.radiusMeters,
        drawingOverlay.drawing, drawingOverlay.manualConstraints, trapPins,
    ) {
        state.syncSources(
            markers, possibleAreaGeoJson, boundary,
            zoneOverlay.pin, transitOverlayGeoJson, zoneOverlay.radiusMeters,
            boundaryGeoJson, simulationGeoJson, candidatePoiGeoJson, simulationPinGeoJson,
            seekerCircles, seekerOverlay.radiusMeters,
            drawingOverlay.drawing, drawingOverlay.manualConstraints, trapPins,
        )
    }

    // Keyed on the counter alone so location updates that rebuild markers never re-trigger the recenter.
    LaunchedEffect(recenterCounter?.value) {
        if (recenterCounter != null && recenterCounter.value > 0) {
            state.recenterOnSelf(markers)
        }
    }
}

private class MapViewState {
    var stationClickListenerRegistered = false
    var longClickListenerRegistered = false
    var touchListenerRegistered = false
    val styleReady = mutableStateOf(false)
    private val styleRequested = mutableStateOf(false)
    private val centeredOnSelf = mutableStateOf(false)
    private val framedArea = mutableStateOf(false)
    val mapRef = mutableStateOf<MapLibreMap?>(null)
    @Volatile var destroyed = false

    fun ensureStyleRequested(map: MapLibreMap, styleSource: StyleSource) {
        mapRef.value = map
        if (styleRequested.value) return
        styleRequested.value = true
        loadStyle(map, styleSource)
    }

    fun switchStyle(map: MapLibreMap, styleSource: StyleSource) {
        styleReady.value = false
        stationClickListenerRegistered = false
        longClickListenerRegistered = false
        loadStyle(map, styleSource)
    }

    private fun loadStyle(map: MapLibreMap, styleSource: StyleSource) {
        val builder = Style.Builder()
        when (styleSource) {
            is StyleSource.Uri -> builder.fromUri(styleSource.url)
            is StyleSource.Json -> builder.fromJson(styleSource.json)
        }
        map.setStyle(builder) { style ->
            if (destroyed) return@setStyle
            attachOverlays(style)
            styleReady.value = true
        }
    }

    private fun attachOverlays(style: Style) {
        style.ensureExclusionLayer()
        style.ensureBoundaryLayer()
        style.ensureZoneLayer()
        style.ensureSimulationLayer()
        style.ensureCandidatePoiLayer()
        style.ensureSimulationPinLayer()
        style.ensureSeekerMarkerLayer()
        style.ensureTimeTrapLayer()
        style.ensureManualConstraintLayer()
        style.ensureMarkerLayers()
        style.ensureDrawingLayer()
    }

    @Suppress("LongParameterList")
    fun syncSources(
        markers: List<PlayerMarker>,
        possibleAreaGeoJson: String?,
        boundary: MapBounds?,
        zonePin: ZonePin?,
        transitOverlayGeoJson: String?,
        zoneRadiusMeters: Double?,
        boundaryGeoJson: String?,
        simulationGeoJson: String? = null,
        candidatePoiGeoJson: String? = null,
        simulationPinGeoJson: String? = null,
        seekerCircles: List<ZonePin> = emptyList(),
        seekerRadiusMeters: Double? = null,
        drawing: DrawingUiState = DrawingUiState(),
        manualConstraints: List<ManualConstraint> = emptyList(),
        trapPins: List<TrapPin> = emptyList(),
    ) {
        val map = mapRef.value?.takeIf { styleReady.value } ?: return
        val style = map.style ?: return
        if (transitOverlayGeoJson != null) {
            style.ensureTransitOverlayLayer(transitOverlayGeoJson)
        }
        style.updateMarkerSources(markers)
        style.updateZoneSource(zonePin, zoneRadiusMeters ?: DEFAULT_ZONE_RADIUS_METERS)
        style.updateSeekerMarkerSource(seekerCircles, seekerRadiusMeters ?: DEFAULT_ZONE_RADIUS_METERS)
        style.updateTimeTrapSource(trapPins)
        style.updateExclusionSource(possibleAreaGeoJson, boundaryGeoJson)
        // Hide the static boundary border when the possible area has its own outline.
        style.updateBoundarySource(if (possibleAreaGeoJson != null) null else boundaryGeoJson)
        style.updateSimulationSource(simulationGeoJson)
        style.updateCandidatePoiSource(candidatePoiGeoJson)
        style.updateSimulationPinSource(simulationPinGeoJson)
        style.updateManualConstraintSource(manualConstraints)
        style.updateDrawingSource(
            if (drawing.isActive) drawing.vertices else emptyList(),
            if (drawing.isActive) drawing.selectedPaths else emptyList(),
            if (drawing.isActive) drawing.networkPaths else emptyList(),
            drawing.mode,
            drawing.kind,
        )
        map.frameCameraIfNeeded(markers, boundary, centeredOnSelf, framedArea)
        map.triggerRepaint()
    }

    fun recenterOnSelf(markers: List<PlayerMarker>) {
        val map = mapRef.value?.takeIf { styleReady.value } ?: return
        map.animateRecenter(markers, centeredOnSelf)
    }

    companion object {
        const val DEFAULT_ZONE_RADIUS_METERS = 500.0
    }
}

private fun mapViewLifecycleObserver(
    mapView: MapView,
    state: MapViewState,
) = LifecycleEventObserver { _, event ->
    when (event) {
        Lifecycle.Event.ON_CREATE -> mapView.onCreate(null)
        Lifecycle.Event.ON_START -> mapView.onStart()
        Lifecycle.Event.ON_RESUME -> mapView.onResume()
        Lifecycle.Event.ON_PAUSE -> mapView.onPause()
        Lifecycle.Event.ON_STOP -> mapView.onStop()
        Lifecycle.Event.ON_DESTROY -> {
            state.destroyed = true
            mapView.onDestroy()
        }
        else -> Unit
    }
}

private data class ZoneRadiusSectionState(
    val edition: Edition,
    val selectedRadiusMeters: Double?,
    val customRadiusText: String,
    val customRadiusMeters: Double?,
    val enabled: Boolean,
)

@Composable
private fun ZoneRadiusSection(
    state: ZoneRadiusSectionState,
    onSelectRadius: (Double?) -> Unit,
    onCustomTextChange: (String) -> Unit,
) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        PresetChip(
            label = stringResource(
                R.string.zone_radius_default_with_value,
                defaultZoneRadiusLabel(state.edition),
            ),
            selected = state.selectedRadiusMeters == null,
            onClick = { onSelectRadius(null) },
            enabled = state.enabled,
            modifier = Modifier.weight(1f),
        )
        PresetChip(
            label = stringResource(R.string.zone_radius_custom),
            selected = state.selectedRadiusMeters != null,
            onClick = { onSelectRadius(state.customRadiusMeters ?: 500.0) },
            enabled = state.enabled,
            modifier = Modifier.weight(0.5f),
        )
    }
    if (state.selectedRadiusMeters != null) {
        OutlinedTextField(
            value = state.customRadiusText,
            onValueChange = onCustomTextChange,
            suffix = { Text(stringResource(R.string.unit_meters_short)) },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            enabled = state.enabled,
        )
    }
}

@Composable
private fun StationTooltipTracker(
    stationTooltip: MutableState<StationTooltipData?>,
    mapRef: MutableState<MapLibreMap?>,
) {
    val currentTooltip by rememberUpdatedState(stationTooltip.value)
    DisposableEffect(stationTooltip.value != null, mapRef.value) {
        val map = mapRef.value
        if (map != null && stationTooltip.value != null) {
            val listener = MapLibreMap.OnCameraMoveListener {
                val td = currentTooltip ?: return@OnCameraMoveListener
                val screen = map.projection.toScreenLocation(
                    org.maplibre.android.geometry.LatLng(td.lat, td.lng),
                )
                stationTooltip.value = td.copy(screenX = screen.x, screenY = screen.y)
            }
            map.addOnCameraMoveListener(listener)
            onDispose { map.removeOnCameraMoveListener(listener) }
        } else {
            onDispose { }
        }
    }
}

@Composable
private fun StyleReloadEffect(state: MapViewState, styleSource: StyleSource) {
    LaunchedEffect(styleSource) {
        val map = state.mapRef.value ?: return@LaunchedEffect
        if (state.styleReady.value) state.switchStyle(map, styleSource)
    }
}

private data class MapTapCallbacks(
    val canPlacePin: Boolean,
    val onPinPlaced: (Double, Double, String?) -> Unit,
    val onCandidateTapped: (String) -> Unit,
)

@Composable
@Suppress("LongParameterList", "LongMethod")
private fun MapWithTooltipOverlay(
    mapView: MapView,
    state: MapViewState,
    lifecycle: Lifecycle,
    tapCallbacks: MapTapCallbacks,
    tooltip: TooltipHost,
    seekerHost: SeekerMarkerOverlay,
    seekerAction: MutableState<SeekerActionData?>,
    drawingOverlay: DrawingOverlayState = DrawingOverlayState(),
    styleSource: StyleSource = StyleSource.Uri(MapConstants.STYLE_URL),
) {
    // Listeners register once; callbacks are read live so entering simulation enables pin taps.
    val currentTap by rememberUpdatedState(tapCallbacks)
    val currentTooltip by rememberUpdatedState(tooltip)
    val currentSeekerHost by rememberUpdatedState(seekerHost)
    val currentDrawing by rememberUpdatedState(drawingOverlay)
    val density = LocalDensity.current

    DisposableEffect(lifecycle, mapView) {
        val observer = mapViewLifecycleObserver(mapView, state)
        lifecycle.addObserver(observer)
        onDispose {
            state.destroyed = true
            lifecycle.removeObserver(observer)
        }
    }

    AndroidView(
        factory = { mapView },
        modifier = Modifier.fillMaxSize(),
        update = { view ->
            view.getMapAsync { map ->
                state.ensureStyleRequested(map, styleSource)
                if (!state.stationClickListenerRegistered) {
                    state.stationClickListenerRegistered = true
                    map.addOnMapClickListener { latLng ->
                        seekerAction.value = null
                        handleMapTap(map, latLng, currentTap, currentTooltip, currentDrawing, density)
                    }
                }
                if (!state.longClickListenerRegistered) {
                    state.longClickListenerRegistered = true
                    map.addOnMapLongClickListener { latLng ->
                        handleMapLongPress(map, latLng, currentSeekerHost) { seekerAction.value = it }
                    }
                }
                if (!state.touchListenerRegistered) {
                    state.touchListenerRegistered = true
                    view.setOnTouchListener(
                        VertexDragTouchListener(
                            density = density,
                            mapProvider = { state.mapRef.value },
                            drawingProvider = { currentDrawing },
                        ),
                    )
                }
            }
        },
    )

    tooltip.data?.let { td ->
        StationTooltip(
            label = td.label,
            lines = td.lines,
            screenX = td.screenX,
            screenY = td.screenY,
            onDismiss = tooltip.onDismiss,
        )
    }
    seekerAction.value?.let { action ->
        StationActionTooltip(
            action = action,
            onMark = { seekerHost.onMark(action.lat, action.lng); seekerAction.value = null },
            onUnmark = { uuid -> seekerHost.onUnmark(uuid); seekerAction.value = null },
            onDismiss = { seekerAction.value = null },
        )
    }
}

private fun handleMapTap(
    map: MapLibreMap,
    latLng: org.maplibre.android.geometry.LatLng,
    tapCallbacks: MapTapCallbacks,
    tooltip: TooltipHost,
    drawing: DrawingOverlayState,
    density: Density,
): Boolean {
    // In drawing mode a tap edits the shape; it beats station/pin logic.
    if (drawing.drawing.isActive) {
        // A trace toggles the whole street the tap lands on; Area places/inserts vertices.
        if (drawing.drawing.kind == DrawKind.Trace) {
            drawing.onToggleEdge(latLng.latitude, latLng.longitude)
        } else {
            val screen = map.projection.toScreenLocation(latLng)
            val edge = nearestEdgeIndex(map, drawing.drawing.vertices, screen, density, drawing.drawing.kind)
            if (edge != null) {
                drawing.onInsertVertex(edge, latLng.latitude, latLng.longitude)
            } else {
                drawing.onAddVertex(latLng.latitude, latLng.longitude)
            }
        }
        return true
    }
    val screen = map.projection.toScreenLocation(latLng)
    val candidateId = parseCandidatePoiTapAtPoint(map, screen)
    // Placing a pin wins over the station tooltip, so you can drop a pin on a station.
    return when {
        candidateId != null -> { tapCallbacks.onCandidateTapped(candidateId); true }
        tapCallbacks.canPlacePin -> {
            // The pin snaps to the station and carries its name, so the zone records which one it centers on.
            val station = parseStationTapAtPoint(map, screen)
            tapCallbacks.onPinPlaced(
                station?.lat ?: latLng.latitude,
                station?.lng ?: latLng.longitude,
                station?.label,
            )
            true
        }
        else -> parseStationTapAtPoint(map, screen)?.let { tooltip.onTapped(it); true }
            ?: selectManualConstraintAtPoint(map, screen, drawing, density)
    }
}

private fun selectManualConstraintAtPoint(
    map: MapLibreMap,
    screen: android.graphics.PointF,
    drawing: DrawingOverlayState,
    density: Density,
): Boolean {
    val radiusPx = with(density) { CONSTRAINT_HIT_RADIUS_DP.dp.toPx() }
    val uuid = map.manualConstraintUuidAt(screen, radiusPx)
    drawing.onSelectConstraint(uuid)
    return uuid != null
}

private const val VERTEX_HIT_RADIUS_DP = 32f
private const val CONSTRAINT_HIT_RADIUS_DP = 8f
private const val EDGE_HIT_RADIUS_DP = 20f
private const val TAP_SLOP_DP = 10f
private const val MIN_EDGE_VERTICES = 2
private const val CLOSING_EDGE_MIN_VERTICES = 3

/**
 * Nearest edge by screen distance whose midpoint the tap lands on, or null. Returns the index of the
 * edge's first vertex, so inserting after it places the new point between the endpoints. The closing
 * edge exists only for a polygon; counting it for a trace would swallow taps meant to extend a free end.
 */
private fun nearestEdgeIndex(
    map: MapLibreMap,
    vertices: List<ZonePin>,
    tap: android.graphics.PointF,
    density: Density,
    kind: DrawKind,
): Int? {
    if (vertices.size < MIN_EDGE_VERTICES) return null
    val thresholdPx = with(density) { EDGE_HIT_RADIUS_DP.dp.toPx() }
    val screens = vertices.map { map.projection.toScreenLocation(LatLng(it.latitude, it.longitude)) }
    val hasClosingEdge = kind == DrawKind.Area && vertices.size >= CLOSING_EDGE_MIN_VERTICES
    val edgeCount = if (hasClosingEdge) vertices.size else vertices.size - 1
    return (0 until edgeCount)
        .map { i -> i to distanceToSegment(tap, screens[i], screens[(i + 1) % vertices.size]) }
        .filter { it.second <= thresholdPx }
        .minByOrNull { it.second }
        ?.first
}

private fun distanceToSegment(p: PointF, a: PointF, b: PointF): Float {
    val dx = b.x - a.x
    val dy = b.y - a.y
    val lengthSquared = dx * dx + dy * dy
    if (lengthSquared == 0f) return hypot(p.x - a.x, p.y - a.y)
    val t = (((p.x - a.x) * dx + (p.y - a.y) * dy) / lengthSquared).coerceIn(0f, 1f)
    return hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy))
}

/**
 * Raw touch handling for vertex editing. A hit on a vertex handle disables map scroll and claims the
 * touch so the map neither pans nor fires its tap listener; a miss lets the MapView pan or fire its
 * click listener. A hit that never leaves the touch slop removes the vertex; a real drag commits it.
 */
private class VertexDragTouchListener(
    private val density: Density,
    private val mapProvider: () -> MapLibreMap?,
    private val drawingProvider: () -> DrawingOverlayState,
) : View.OnTouchListener {
    private var draggingIndex: Int? = null
    private var downX = 0f
    private var downY = 0f
    private var moved = false
    private val slopPx = with(density) { TAP_SLOP_DP.dp.toPx() }

    override fun onTouch(v: View, event: MotionEvent): Boolean {
        val map = mapProvider()
        val overlay = drawingProvider()
        if (map == null || !overlay.drawing.isActive) return false
        return when (event.actionMasked) {
            MotionEvent.ACTION_DOWN -> onDown(map, overlay, event)
            MotionEvent.ACTION_MOVE -> onMove(map, overlay, event)
            MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> onUp(map, overlay)
            else -> false
        }
    }

    private fun onDown(map: MapLibreMap, overlay: DrawingOverlayState, event: MotionEvent): Boolean {
        val index = hitVertex(map, overlay.drawing.vertices, event.x, event.y) ?: return false
        draggingIndex = index
        downX = event.x
        downY = event.y
        moved = false
        map.uiSettings.isScrollGesturesEnabled = false
        overlay.onStartVertexDrag(index)
        return true
    }

    private fun onMove(map: MapLibreMap, overlay: DrawingOverlayState, event: MotionEvent): Boolean {
        val index = draggingIndex ?: return false
        if (moved || hypot(event.x - downX, event.y - downY) >= slopPx) {
            moved = true
            val latLng = map.projection.fromScreenLocation(PointF(event.x, event.y))
            overlay.onMoveVertex(index, latLng.latitude, latLng.longitude)
        }
        return true
    }

    private fun onUp(map: MapLibreMap, overlay: DrawingOverlayState): Boolean {
        val index = draggingIndex ?: return false
        draggingIndex = null
        map.uiSettings.isScrollGesturesEnabled = true
        if (moved) overlay.onEndVertexDrag() else overlay.onRemoveVertex(index)
        return true
    }

    private fun hitVertex(map: MapLibreMap, vertices: List<ZonePin>, x: Float, y: Float): Int? {
        if (vertices.isEmpty()) return null
        val radiusPx = with(density) { VERTEX_HIT_RADIUS_DP.dp.toPx() }
        val maxSquared = radiusPx * radiusPx
        return vertices.indices
            .map { i ->
                val screen = map.projection.toScreenLocation(LatLng(vertices[i].latitude, vertices[i].longitude))
                i to ((screen.x - x) * (screen.x - x) + (screen.y - y) * (screen.y - y))
            }
            .filter { it.second <= maxSquared }
            .minByOrNull { it.second }
            ?.first
    }
}

private fun handleMapLongPress(
    map: MapLibreMap,
    latLng: org.maplibre.android.geometry.LatLng,
    seekerHost: SeekerMarkerOverlay,
    onAction: (SeekerActionData) -> Unit,
): Boolean {
    val screen = map.projection.toScreenLocation(latLng)
    val station = (if (seekerHost.isSeeker) parseStationTapAtPoint(map, screen) else null) ?: return false
    val centroid = parseStationCentroidAtPoint(map, screen) ?: LatLng(station.lat, station.lng)
    val existing = seekerHost.markers.firstOrNull {
        haversineMeters(centroid.latitude, centroid.longitude, it.lat, it.lng) <= MARKER_MATCH_RADIUS_M
    }
    onAction(
        SeekerActionData(
            label = station.label,
            lat = centroid.latitude,
            lng = centroid.longitude,
            screenX = screen.x,
            screenY = screen.y,
            existingMarkerUuid = existing?.uuid,
        ),
    )
    return true
}

@Composable
private fun SeekerActionTracker(
    seekerAction: MutableState<SeekerActionData?>,
    mapRef: MutableState<MapLibreMap?>,
) {
    val current by rememberUpdatedState(seekerAction.value)
    DisposableEffect(seekerAction.value != null, mapRef.value) {
        val map = mapRef.value
        if (map != null && seekerAction.value != null) {
            val listener = MapLibreMap.OnCameraMoveListener {
                val data = current ?: return@OnCameraMoveListener
                val screen = map.projection.toScreenLocation(LatLng(data.lat, data.lng))
                seekerAction.value = data.copy(screenX = screen.x, screenY = screen.y)
            }
            map.addOnCameraMoveListener(listener)
            onDispose { map.removeOnCameraMoveListener(listener) }
        } else {
            onDispose { }
        }
    }
}

private const val STATION_HIT_RADIUS_PX = 24f
private const val CANDIDATE_HIT_RADIUS_PX = 24f

private fun parseCandidatePoiTapAtPoint(map: MapLibreMap, point: android.graphics.PointF): String? {
    val hitRect = android.graphics.RectF(
        point.x - CANDIDATE_HIT_RADIUS_PX, point.y - CANDIDATE_HIT_RADIUS_PX,
        point.x + CANDIDATE_HIT_RADIUS_PX, point.y + CANDIDATE_HIT_RADIUS_PX,
    )
    val feature = map.queryRenderedFeatures(hitRect).firstOrNull { f ->
        f.properties()?.get("poiType")?.asString == "candidate"
    }
    return feature?.properties()?.get("uuid")?.asString?.takeIf { it.isNotEmpty() }
}

// Backend emits exact station coords (stationLat/stationLng); fall back to the polygon centroid.
private fun stationCenter(feature: Feature): LatLng? {
    val props = feature.properties()
    val realPoint = if (props != null && props.has("stationLat") && props.has("stationLng")) {
        runCatching { LatLng(props.get("stationLat").asDouble, props.get("stationLng").asDouble) }.getOrNull()
    } else {
        null
    }
    return realPoint ?: polygonCentroid(feature.geometry())
}

// Dense areas straddle several station polygons; pick the one whose real centre sits closest to the tap.
private fun nearestStationAtPoint(map: MapLibreMap, point: android.graphics.PointF): Pair<Feature, LatLng>? {
    val hitRect = android.graphics.RectF(
        point.x - STATION_HIT_RADIUS_PX, point.y - STATION_HIT_RADIUS_PX,
        point.x + STATION_HIT_RADIUS_PX, point.y + STATION_HIT_RADIUS_PX,
    )
    val tap = map.projection.fromScreenLocation(point)
    return map.queryRenderedFeatures(hitRect)
        .filter { it.properties()?.get("stationLabel")?.asString?.isNotEmpty() == true }
        .mapNotNull { feature -> stationCenter(feature)?.let { feature to it } }
        .minByOrNull { (_, c) -> haversineMeters(tap.latitude, tap.longitude, c.latitude, c.longitude) }
}

private fun parseStationTapAtPoint(map: MapLibreMap, point: android.graphics.PointF): StationTooltipData? {
    val (feature, center) = nearestStationAtPoint(map, point) ?: return null
    val props = feature.properties()
    val label = props?.get("stationLabel")?.asString?.takeIf { it.isNotEmpty() }
    return label?.let {
        val lines = parseLineRefs(props?.getAsJsonArray("lines"))
        StationTooltipData(it, lines, center.latitude, center.longitude, point.x, point.y)
    }
}

private fun parseLineRefs(linesArr: com.google.gson.JsonArray?): List<LineRef> {
    if (linesArr == null) return emptyList()
    return (0 until linesArr.size()).mapNotNull { i ->
        val obj = linesArr.get(i) as? JsonObject
        val ref = obj?.get("ref")?.asString?.takeIf { it.isNotEmpty() }
        if (obj != null && ref != null) LineRef(ref, obj.get("color")?.asString ?: "") else null
    }
}

private fun parseStationCentroidAtPoint(map: MapLibreMap, screen: android.graphics.PointF): LatLng? =
    nearestStationAtPoint(map, screen)?.second

private fun polygonCentroid(geometry: Geometry?): LatLng? {
    val ring = when (geometry) {
        is Polygon -> geometry.coordinates().firstOrNull()
        is MultiPolygon -> geometry.coordinates().firstOrNull()?.firstOrNull()
        else -> null
    }?.takeIf { it.isNotEmpty() } ?: return null
    return LatLng(ring.map { it.latitude() }.average(), ring.map { it.longitude() }.average())
}

private const val MARKER_MATCH_RADIUS_M = 15.0
private const val UUID_PREFIX_LEN = 8

private fun simulationPinsGeoJson(simulation: SimulationState?): String? {
    val pins = listOfNotNull(simulation?.seeker, simulation?.end)
    if (pins.isEmpty()) return null
    val features = pins.joinToString(",") {
        """{"type":"Feature","geometry":{"type":"Point","coordinates":[${it.longitude},${it.latitude}]},""" +
            """"properties":{}}"""
    }
    return """{"type":"FeatureCollection","features":[$features]}"""
}

private fun candidatePoiGeoJson(simulation: SimulationState?): String? {
    val features = simulation?.candidateFeatures?.takeIf { it.isNotEmpty() } ?: return null
    val sb = StringBuilder()
    sb.append("""{"type":"FeatureCollection","features":[""")
    features.forEachIndexed { i, f ->
        val label = f.name ?: f.uuid.take(UUID_PREFIX_LEN)
        sb.append("""{"type":"Feature","geometry":{"type":"Point","coordinates":[""")
        sb.append("${f.longitude},${f.latitude}")
        sb.append("""]},"properties":{"uuid":""")
        sb.append(""""${f.uuid}",""")
        sb.append(""""name":"$label",""")
        sb.append(""""poiType":"candidate"""")
        sb.append("}}")
        if (i < features.size - 1) sb.append(",")
    }
    sb.append("]}")
    return sb.toString()
}

