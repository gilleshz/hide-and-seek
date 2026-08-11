package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.HidingZone
import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.model.ManualConstraint
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.QuestionStatus
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.SeekerMarker
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.TimeTrap
import fr.gshz.hideandseek.domain.model.TransitLine
import kotlin.math.PI
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.sin
import kotlin.math.sqrt

enum class QuestionSheetMode { Ask, Preview }

// Server-derived: a thermometer without a reveal deadline is still traveling (survives restarts).
val AskedQuestion.isTravelingThermometer: Boolean
    get() = category == QuestionCategory.Thermometer && revealDeadlineAt == null && revealedAt == null

// Randomized/Vetoed rows keep revealedAt null forever; testing revealedAt alone would lock the seeker out.
val AskedQuestion.isOutstanding: Boolean
    get() = status == QuestionStatus.Open && revealedAt == null

data class SimulationState(
    val category: QuestionCategory,
    val mode: QuestionSheetMode = QuestionSheetMode.Ask,
    val seeker: ZonePin? = null,
    val end: ZonePin? = null,
    val radiusMeters: Int? = null,
    val isCustomRadius: Boolean = false,
    val customRadiusText: String = "",
    val distanceMeters: Int? = null,
    val featureType: String? = null,
    val answer: SimAnswer? = null,
    val previewGeoJson: String? = null,
    val currentAreaKm2: Double? = null,
    val projectedAreaKm2: Double? = null,
    val candidateFeatures: List<FeatureSummary> = emptyList(),
    val chosenFeatureId: String? = null,
    val withinMeters: Int? = null,
    val outstandingQuestion: AskedQuestion? = null,
    val isSubmitting: Boolean = false,
    val error: ErrorType? = null,
    val errorKey: String? = null,
    val errorArgs: Map<String, String>? = null,
    val locationPermissionMissing: Boolean = false,
    val photoTarget: PhotoTarget? = null,
    val thermometerTraveledMeters: Double? = null,
    val transitLineSelected: Boolean = false,
    val stationNameLengthSelected: Boolean = false,
    val seaLevelSelected: Boolean = false,
    val selectedTransitLine: TransitLine? = null,
    val availableTransitLines: List<TransitLine> = emptyList(),
    // Asking is a seeking-phase action; previewing stays open all round.
    val askingBlocked: Boolean = false,
) {
    val travelingThermometer: AskedQuestion? get() = outstandingQuestion?.takeIf { it.isTravelingThermometer }
}

data class FeatureSummary(
    val uuid: String,
    val name: String?,
    val latitude: Double,
    val longitude: Double,
)

enum class SimAnswer(val wireValue: String) {
    Inside("inside"),
    Outside("outside"),
    Hotter("hotter"),
    Colder("colder"),
    Closer("closer"),
    Further("further"),
    Same("same"),
    Different("different"),
    Nearest("nearest"),
    None("none"),
}

data class MapUiState(
    val gameUuid: String = "",
    val gameName: String = "",
    val markers: List<PlayerMarker> = emptyList(),
    val side: Side? = null,
    val edition: Edition = Edition.Metric,
    val gameSize: GameSize = GameSize.Small,
    val exclusionGeoJson: String? = null,
    val possibleAreaGeoJson: String? = null,
    val boundary: MapBounds? = null,
    val isPlacingZone: Boolean = false,
    val pendingZonePin: ZonePin? = null,
    val pendingZoneStationName: String? = null,
    val selectedZoneRadiusMeters: Double? = null,
    val customZoneRadiusText: String = "",
    val customZoneRadiusMeters: Double? = null,
    val submittedZone: HidingZone? = null,
    val hasHidingZone: Boolean = false,
    val isSubmittingZone: Boolean = false,
    val zoneError: ErrorType? = null,
    val zoneErrorKey: String? = null,
    val zoneErrorArgs: Map<String, String>? = null,
    val roundStatus: RoundStatus? = null,
    val roundTimerSeconds: Long? = null,
    val isEndingRound: Boolean = false,
    val roundError: ErrorType? = null,
    val roundErrorKey: String? = null,
    val roundErrorArgs: Map<String, String>? = null,
    val transitOverlayGeoJson: String? = null,
    val boundaryGeoJson: String? = null,
    val outsideZone: Boolean = false,
    val halfZoneExcludesSelf: Boolean = false,
    val inMovePeriod: Boolean = false,
    val simulation: SimulationState? = null,
    val outstandingQuestion: AskedQuestion? = null,
    val askedQuestions: List<AskedQuestion> = emptyList(),
    val mapStyleAvailable: Boolean = false,
    val selectedMapStyle: MapStyle = MapStyle.Standard,
    val stadiaApiKey: String? = null,
    val thunderforestApiKey: String? = null,
    val maptilerApiKey: String? = null,
    val seekerMarkers: List<SeekerMarker> = emptyList(),
    val manualConstraints: List<ManualConstraint> = emptyList(),
    val seekersAreHunting: Boolean = false,
    val currentZoneRadiusMeters: Double? = null,
    val drawing: DrawingUiState = DrawingUiState(),
    val selectedManualConstraintUuid: String? = null,
    val traceReview: TraceReviewState? = null,
    val timeTraps: List<TimeTrap> = emptyList(),
    val isPlacingTimeTrap: Boolean = false,
    val pendingTrapPin: ZonePin? = null,
    val pendingTrapStationName: String? = null,
    val pendingTrapDetection: TimeTrap? = null,
    val isSubmittingTimeTrap: Boolean = false,
    val timeTrapError: ErrorType? = null,
    val timeTrapErrorKey: String? = null,
    val timeTrapErrorArgs: Map<String, String>? = null,
) {
    /** Ending the round declares the hiders' bonus cards, so it is theirs to press. */
    val canEndRound: Boolean get() = roundStatus == RoundStatus.Seeking && side == Side.Hider

    /**
     * Once the seekers are hunting, a hider changes the zone by playing a card rather than by
     * dragging the pin. A move period puts them back in the free placement window.
     */
    val canPlayZoneCards: Boolean
        get() = side == Side.Hider && hasHidingZone && roundStatus == RoundStatus.Seeking

    /**
     * The zone panel wins z-order over the trace panel, so a hider who left placement mode open would
     * answer a question with the trace controls hidden under it.
     */
    val showZonePlacementPanel: Boolean get() = isPlacingZone && !drawing.isActive

    /** Station first, photo on confirm: the reverse of a zone card, so the panel outlives the tap. */
    val showTimeTrapPanel: Boolean get() = isPlacingTimeTrap && !drawing.isActive

    /**
     * Trapping the station the hiders' own zone is centred on hands the seekers that centre. Like
     * Tiny Home's half-radius check it only warns: a noisy fix must never block a legal play.
     */
    val trapTargetsOwnZone: Boolean
        get() {
            val pin = pendingTrapPin ?: return false
            val zone = submittedZone ?: return false
            return haversineMeters(pin, ZonePin(zone.lat, zone.lng)) <= zone.radiusMeters
        }

    /** Traps are drawn on both sides but placed only by a hider mid-hunt. */
    val canPlaceTimeTraps: Boolean
        get() = side == Side.Hider && roundStatus == RoundStatus.Seeking

    /**
     * The chip floats over the map and would swallow taps meant for trace vertices, sending the hider
     * to chat mid-drawing; while tracing they are already answering that question anyway.
     */
    val showHiderQuestionChip: Boolean
        get() = side == Side.Hider && simulation == null && !drawing.isActive &&
            outstandingQuestion?.revealDeadlineAt != null

    val seekersAreFrozen: Boolean get() = inMovePeriod

    /** A Move is the one freeze nothing else on screen explains, so the seekers get told. */
    val showSeekersFrozenBanner: Boolean get() = side == Side.Seeker && seekersAreFrozen
    val currentStyleSource: StyleSource
        get() = selectedMapStyle.resolveSource(stadiaApiKey, thunderforestApiKey, maptilerApiKey)
            ?: StyleSource.Uri(MapConstants.STYLE_URL)
}

data class PlayerMarker(
    val playerUuid: String,
    val displayName: String,
    val initials: String,
    val latitude: Double,
    val longitude: Double,
    val isSelf: Boolean,
)

data class ZonePin(val latitude: Double, val longitude: Double)

/**
 * Area is the seekers' point-by-point constraint polygon; Trace is the hider selecting whole real
 * streets to answer a photo question. They share the drawing overlay but not the interaction.
 */
enum class DrawKind { Area, Trace }

/** Tri-state on purpose: a fetch still in flight must not be reported as a network that will never come. */
enum class StreetDataStatus { Loading, Available, Unavailable }

data class DrawingUiState(
    val isActive: Boolean = false,
    val kind: DrawKind = DrawKind.Area,
    val mode: ConstraintMode = ConstraintMode.Exclude,
    val vertices: List<ZonePin> = emptyList(),
    val draggingVertexIndex: Int? = null,
    val questionUuid: String? = null,
    val photoTarget: PhotoTarget? = null,
    val edition: Edition = Edition.Metric,
    val isRendering: Boolean = false,
    val renderError: ErrorType? = null,
    val selectedEdgeIds: Set<Int> = emptySet(),
    val selectedPaths: List<List<ZonePin>> = emptyList(),
    val networkPaths: List<List<ZonePin>> = emptyList(),
    val lengthMeters: Double = 0.0,
    val traceShape: TraceShape = TraceShape.Empty,
    val streetStatus: StreetDataStatus = StreetDataStatus.Loading,
) {
    val canClose: Boolean get() = vertices.size >= MIN_POLYGON_VERTICES

    /** Nothing to tap until the network arrives, so the panel says so rather than leaving the map dead. */
    val streetDataUnavailable: Boolean get() = streetStatus == StreetDataStatus.Unavailable

    val minimumMeters: Double? get() = photoTarget?.let { minimumTraceMeters(it, edition) }

    /**
     * Permissive on shape: a disconnected or branching selection still confirms, only the
     * StreetsTraced minimum-length gate can block it. Rendering blocks a second confirm racing the PNG.
     */
    val canConfirmTrace: Boolean
        get() = !isRendering && selectedEdgeIds.isNotEmpty() &&
            minimumMeters?.let { lengthMeters >= it } != false

    companion object {
        const val MIN_POLYGON_VERTICES = 3
    }
}

data class TraceReviewState(
    val imageUri: String,
    val isSending: Boolean = false,
    val sendFailed: Boolean = false,
)

data class DrawingActions(
    val onEnterDrawing: () -> Unit = {},
    val onSetDrawMode: (ConstraintMode) -> Unit = {},
    val onAddVertex: (Double, Double) -> Unit = { _, _ -> },
    val onMoveVertex: (Int, Double, Double) -> Unit = { _, _, _ -> },
    val onStartVertexDrag: (Int) -> Unit = {},
    val onEndVertexDrag: () -> Unit = {},
    val onRemoveVertex: (Int) -> Unit = {},
    val onInsertVertex: (Int, Double, Double) -> Unit = { _, _, _ -> },
    val onConfirmDrawing: () -> Unit = {},
    val onCancelDrawing: () -> Unit = {},
    val onSelectManualConstraint: (String?) -> Unit = {},
    val onDeleteSelectedManualConstraint: () -> Unit = {},
    val onConfirmTrace: () -> Unit = {},
    val onSendTrace: () -> Unit = {},
    val onResumeTraceEditing: () -> Unit = {},
    val onToggleEdge: (Double, Double) -> Unit = { _, _ -> },
)

data class SimulationActions(
    val onSetSeekerPin: (Double, Double) -> Unit = { _, _ -> },
    val onSetEndPin: (Double, Double) -> Unit = { _, _ -> },
    val onSetRadius: (Int?) -> Unit = {},
    val onSetDistance: (Int?) -> Unit = {},
    val onCustomRadiusChange: (String) -> Unit = {},
    val onSetFeatureType: (String?) -> Unit = {},
    val onSelectTransitLineOption: () -> Unit = {},
    val onSelectStationNameLengthOption: () -> Unit = {},
    val onSelectSeaLevelOption: () -> Unit = {},
    val onSetTransitLine: (TransitLine) -> Unit = {},
    val onSetAnswer: (SimAnswer) -> Unit = {},
    val onSetCategory: (QuestionCategory) -> Unit = {},
    val onSetChosenFeature: (String?) -> Unit = {},
    val onSetWithinMeters: (Int?) -> Unit = {},
    val onSetPhotoTarget: (PhotoTarget?) -> Unit = {},
    val onAsk: () -> Unit = {},
    val onDismiss: () -> Unit = {},
    val onTogglePreviewMode: () -> Unit = {},
    val onStartThermometer: () -> Unit = {},
    val onConfirmThermometerArrival: () -> Unit = {},
    val onCancelQuestion: () -> Unit = {},
)

data class MapBounds(
    val swLat: Double,
    val swLng: Double,
    val neLat: Double,
    val neLng: Double,
)

/**
 * The screen combines the six concern ViewModels into the single state the map draws. Splitting it
 * into [sessionAndZoneUiState] and a [MapUiState.copy] keeps every field covered without one long
 * constructor call.
 */
internal fun assembleMapUiState(
    session: MapSessionUiState,
    zone: MapZoneUiState,
    drawing: MapDrawingUiState,
    traps: MapTimeTrapUiState,
    seekerMarkers: List<SeekerMarker>,
    question: MapQuestionUiState,
): MapUiState {
    val base = sessionAndZoneUiState(session, zone)
    return base.copy(
        markers = session.markers,
        outsideZone = computeOutsideZone(session.selfPlayerUuid, session.locations, zone.submittedZone),
        halfZoneExcludesSelf = computeHalfZoneExcludesSelf(
            session.selfPlayerUuid,
            session.locations,
            zone.submittedZone,
        ),
        currentZoneRadiusMeters = zone.currentZoneRadiusMeters ?: session.hidingRadiusMeters,
        hasHidingZone = session.roundHasHidingZone || zone.submittedZone != null,
        possibleAreaGeoJson = question.possibleAreaGeoJson,
        exclusionGeoJson = question.exclusionGeoJson,
        simulation = enrichSimulation(question, session),
        outstandingQuestion = question.outstandingQuestion,
        askedQuestions = question.askedQuestions,
        drawing = drawing.drawing.copy(edition = session.edition),
        selectedManualConstraintUuid = drawing.selectedManualConstraintUuid,
        traceReview = drawing.traceReview,
        manualConstraints = drawing.manualConstraints,
        seekerMarkers = seekerMarkers,
        timeTraps = traps.timeTraps,
        isPlacingTimeTrap = traps.isPlacingTimeTrap,
        pendingTrapPin = traps.pendingTrapPin,
        pendingTrapStationName = traps.pendingTrapStationName,
        pendingTrapDetection = traps.pendingTrapDetection,
        isSubmittingTimeTrap = traps.isSubmittingTimeTrap,
        timeTrapError = traps.timeTrapError,
        timeTrapErrorKey = traps.timeTrapErrorKey,
        timeTrapErrorArgs = traps.timeTrapErrorArgs,
    )
}

private fun sessionAndZoneUiState(session: MapSessionUiState, zone: MapZoneUiState): MapUiState {
    return MapUiState(
        gameUuid = session.gameUuid,
        gameName = session.gameName,
        side = session.side,
        edition = session.edition,
        gameSize = session.gameSize,
        boundary = session.boundary,
        isPlacingZone = zone.isPlacingZone,
        pendingZonePin = zone.pendingZonePin,
        pendingZoneStationName = zone.pendingZoneStationName,
        selectedZoneRadiusMeters = zone.selectedZoneRadiusMeters,
        customZoneRadiusText = zone.customZoneRadiusText,
        customZoneRadiusMeters = zone.customZoneRadiusMeters,
        submittedZone = zone.submittedZone,
        isSubmittingZone = zone.isSubmittingZone,
        zoneError = zone.zoneError,
        zoneErrorKey = zone.zoneErrorKey,
        zoneErrorArgs = zone.zoneErrorArgs,
        roundStatus = session.roundStatus,
        roundTimerSeconds = session.roundTimerSeconds,
        isEndingRound = session.isEndingRound,
        roundError = session.roundError,
        roundErrorKey = session.roundErrorKey,
        roundErrorArgs = session.roundErrorArgs,
        transitOverlayGeoJson = session.transitOverlayGeoJson,
        boundaryGeoJson = session.boundaryGeoJson,
        inMovePeriod = session.inMovePeriod,
        mapStyleAvailable = session.mapStyleAvailable,
        selectedMapStyle = session.selectedMapStyle,
        stadiaApiKey = session.stadiaApiKey,
        thunderforestApiKey = session.thunderforestApiKey,
        maptilerApiKey = session.maptilerApiKey,
        seekersAreHunting = session.seekersAreHunting,
    )
}

/**
 * The sheet state carries the outstanding question, the distance the traveling thermometer has
 * covered and whether asking is legal: all three derive from flows the sheet ViewModel does not own.
 */
internal fun enrichSimulation(question: MapQuestionUiState, session: MapSessionUiState): SimulationState? {
    val outstanding = question.outstandingQuestion
    val traveling = outstanding?.takeIf { it.isTravelingThermometer }
    val traveled = traveling?.let { t ->
        val slat = t.startLat ?: return@let null
        val slng = t.startLng ?: return@let null
        val g = session.selfGps ?: return@let null
        haversineMeters(slat, slng, g.latitude, g.longitude)
    }
    val askingBlocked = session.roundStatus != null && !session.seekersAreHunting
    return question.simulation?.copy(
        outstandingQuestion = outstanding,
        thermometerTraveledMeters = traveled,
        askingBlocked = askingBlocked,
    )
}

internal fun buildMarkers(
    rosterMap: Map<String, String>,
    selfUuid: String?,
    locationMap: Map<String, LocationUpdate>,
    selfGps: ZonePin?,
): List<PlayerMarker> {
    val markers = locationMap.map { (playerUuid, update) ->
        playerMarker(rosterMap, playerUuid, update.latitude, update.longitude, playerUuid == selfUuid)
    }.toMutableList()

    if (selfUuid != null && markers.none { it.isSelf } && selfGps != null) {
        markers.add(playerMarker(rosterMap, selfUuid, selfGps.latitude, selfGps.longitude, isSelf = true))
    }

    return markers
}

private fun playerMarker(
    rosterMap: Map<String, String>,
    playerUuid: String,
    latitude: Double,
    longitude: Double,
    isSelf: Boolean,
): PlayerMarker {
    val name = rosterMap[playerUuid] ?: playerUuid
    return PlayerMarker(
        playerUuid = playerUuid,
        displayName = name,
        initials = name.split(" ").take(2).mapNotNull { it.firstOrNull()?.uppercaseChar() }.joinToString(""),
        latitude = latitude,
        longitude = longitude,
        isSelf = isSelf,
    )
}

// Client-side haversine check, no backend dependency.
private fun computeOutsideZone(
    selfUuid: String?,
    locationMap: Map<String, LocationUpdate>,
    zone: HidingZone?,
): Boolean {
    val self = selfUuid?.let { locationMap[it] }
    return self != null && zone != null &&
        haversineMeters(self.latitude, self.longitude, zone.lat, zone.lng) > zone.radiusMeters
}

/**
 * The expansion rules forbid Tiny Home when the hider would land outside the halved zone. The
 * server takes the hider's word for where they stand, so this only warns before the tap.
 */
private fun computeHalfZoneExcludesSelf(
    selfUuid: String?,
    locationMap: Map<String, LocationUpdate>,
    zone: HidingZone?,
): Boolean {
    val self = selfUuid?.let { locationMap[it] }
    return self != null && zone != null &&
        haversineMeters(self.latitude, self.longitude, zone.lat, zone.lng) > zone.radiusMeters / 2.0
}

internal fun haversineMeters(
    lat1: Double, lng1: Double, lat2: Double, lng2: Double,
): Double {
    val dLat = (lat2 - lat1) * DEG_TO_RAD
    val dLng = (lng2 - lng1) * DEG_TO_RAD
    val a = sin(dLat / 2.0).let { it * it } +
        cos(lat1 * DEG_TO_RAD) * cos(lat2 * DEG_TO_RAD) *
        sin(dLng / 2.0).let { it * it }
    return EARTH_RADIUS_METERS * 2.0 * atan2(sqrt(a), sqrt(1.0 - a))
}

private const val EARTH_RADIUS_METERS = 6_371_000.0
private const val DEG_TO_RAD = PI / 180.0
