package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.HidingZone
import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.Side
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

/**
 * The six concern states meet in [assembleMapUiState]; these tests pin the cross-VM composition that
 * no single ViewModel test can see.
 */
class MapUiStateAssemblerTest {

    @Test
    fun `the current zone radius falls back to the round's seeded radius`() {
        val session = MapSessionUiState(hidingRadiusMeters = 800.0)

        val state = assembleMapUiState(
            session, MapZoneUiState(), MapDrawingUiState(), MapTimeTrapUiState(), emptyList(), MapQuestionUiState(),
        )

        assertEquals(800.0, state.currentZoneRadiusMeters)
    }

    @Test
    fun `a live zone radius wins over the round's seeded radius`() {
        val session = MapSessionUiState(hidingRadiusMeters = 800.0)
        val zone = MapZoneUiState(currentZoneRadiusMeters = 1234.0)

        val state = assembleMapUiState(
            session, zone, MapDrawingUiState(), MapTimeTrapUiState(), emptyList(), MapQuestionUiState(),
        )

        assertEquals(1234.0, state.currentZoneRadiusMeters)
    }

    @Test
    fun `a hider who never saw the zone set still gets the cards on the round's word`() {
        val session = MapSessionUiState(
            side = Side.Hider,
            roundStatus = RoundStatus.Seeking,
            roundHasHidingZone = true,
        )

        val state = assembleMapUiState(
            session, MapZoneUiState(), MapDrawingUiState(), MapTimeTrapUiState(), emptyList(), MapQuestionUiState(),
        )

        assertNull(state.submittedZone)
        assertTrue(state.hasHidingZone)
        assertTrue(state.canPlayZoneCards)
    }

    @Test
    fun `a submitted zone alone is enough for the cards`() {
        val zone = MapZoneUiState(
            submittedZone = HidingZone(roundUuid = "round-1", lat = 1.0, lng = 2.0, radiusMeters = 500.0),
        )
        val session = MapSessionUiState(side = Side.Hider, roundStatus = RoundStatus.Seeking)

        val state = assembleMapUiState(
            session, zone, MapDrawingUiState(), MapTimeTrapUiState(), emptyList(), MapQuestionUiState(),
        )

        assertTrue(state.hasHidingZone)
    }

    @Test
    fun `the player outside the submitted zone is warned`() {
        val session = MapSessionUiState(
            selfPlayerUuid = "player-1",
            locations = mapOf("player-1" to LocationUpdate("player-1", 10.0, 10.0, "t1")),
        )
        val zone = MapZoneUiState(
            submittedZone = HidingZone(roundUuid = "round-1", lat = 0.0, lng = 0.0, radiusMeters = 500.0),
        )

        val state = assembleMapUiState(
            session, zone, MapDrawingUiState(), MapTimeTrapUiState(), emptyList(), MapQuestionUiState(),
        )

        assertTrue(state.outsideZone)
        assertTrue(state.halfZoneExcludesSelf)
    }

    @Test
    fun `the player inside the zone is not warned`() {
        val session = MapSessionUiState(
            selfPlayerUuid = "player-1",
            locations = mapOf("player-1" to LocationUpdate("player-1", 0.0001, 0.0001, "t1")),
        )
        val zone = MapZoneUiState(
            submittedZone = HidingZone(roundUuid = "round-1", lat = 0.0, lng = 0.0, radiusMeters = 500.0),
        )

        val state = assembleMapUiState(
            session, zone, MapDrawingUiState(), MapTimeTrapUiState(), emptyList(), MapQuestionUiState(),
        )

        assertFalse(state.outsideZone)
        assertFalse(state.halfZoneExcludesSelf)
    }

    @Test
    fun `asking is blocked while the round is not seeking`() {
        val session = MapSessionUiState(roundStatus = RoundStatus.Hiding, seekersAreHunting = false)
        val question = MapQuestionUiState(simulation = SimulationState(category = QuestionCategory.Radar))

        val state = assembleMapUiState(
            session, MapZoneUiState(), MapDrawingUiState(), MapTimeTrapUiState(), emptyList(), question,
        )

        assertEquals(true, state.simulation?.askingBlocked)
    }

    @Test
    fun `asking is free once the seekers are hunting`() {
        val session = MapSessionUiState(roundStatus = RoundStatus.Seeking, seekersAreHunting = true)
        val question = MapQuestionUiState(simulation = SimulationState(category = QuestionCategory.Radar))

        val state = assembleMapUiState(
            session, MapZoneUiState(), MapDrawingUiState(), MapTimeTrapUiState(), emptyList(), question,
        )

        assertEquals(false, state.simulation?.askingBlocked)
    }

    @Test
    fun `a traveling thermometer carries the traveled distance and the outstanding question`() {
        val question = MapQuestionUiState(
            outstandingQuestion = travelingThermometer(),
            simulation = SimulationState(category = QuestionCategory.Thermometer, seeker = ZonePin(0.0, 0.0)),
        )
        val session = MapSessionUiState(selfGps = ZonePin(0.001, 0.0))

        val state = assembleMapUiState(
            session, MapZoneUiState(), MapDrawingUiState(), MapTimeTrapUiState(), emptyList(), question,
        )

        assertEquals("q-1", state.simulation?.outstandingQuestion?.uuid)
        assertEquals("q-1", state.outstandingQuestion?.uuid)
        assertTrue((state.simulation?.thermometerTraveledMeters ?: 0.0) > 0.0)
    }

    @Test
    fun `the drawing state carries the game's edition for the trace minimum`() {
        val session = MapSessionUiState(edition = Edition.Imperial)
        val drawing = MapDrawingUiState(
            drawing = DrawingUiState(
                isActive = true,
                kind = DrawKind.Trace,
                photoTarget = PhotoTarget.StreetsTraced,
                selectedEdgeIds = setOf(1),
                lengthMeters = 900.0,
            ),
        )

        val state = assembleMapUiState(
            session, MapZoneUiState(), drawing, MapTimeTrapUiState(), emptyList(), MapQuestionUiState(),
        )

        assertEquals(Edition.Imperial, state.drawing.edition)
        assertTrue(state.drawing.canConfirmTrace)
    }

    @Test
    fun `the seeker markers and trap pins pass through`() {
        val traps = MapTimeTrapUiState(isPlacingTimeTrap = true, pendingTrapPin = ZonePin(1.0, 2.0))
        val marker = fr.gshz.hideandseek.domain.model.SeekerMarker("m-1", "player-2", 1.0, 2.0)

        val state = assembleMapUiState(
            MapSessionUiState(), MapZoneUiState(), MapDrawingUiState(), traps, listOf(marker), MapQuestionUiState(),
        )

        assertEquals(listOf(marker), state.seekerMarkers)
        assertTrue(state.isPlacingTimeTrap)
        assertEquals(ZonePin(1.0, 2.0), state.pendingTrapPin)
    }

    @Test
    fun `the possible-area and exclusion geometry flow through the assembly`() {
        val question = MapQuestionUiState(possibleAreaGeoJson = "geo-1", exclusionGeoJson = "excl-1")

        val state = assembleMapUiState(
            MapSessionUiState(), MapZoneUiState(), MapDrawingUiState(), MapTimeTrapUiState(), emptyList(), question,
        )

        assertEquals("geo-1", state.possibleAreaGeoJson)
        assertEquals("excl-1", state.exclusionGeoJson)
    }

    @Test
    fun `a trap pin inside the hiders' own zone warns and one outside does not`() {
        val zone = MapZoneUiState(submittedZone = HidingZone("round-1", 46.52, 6.63, 500.0))
        val session = MapSessionUiState(side = Side.Hider)

        val inside = assembleMapUiState(
            session, zone, MapDrawingUiState(),
            MapTimeTrapUiState(pendingTrapPin = ZonePin(46.5205, 6.6305)), emptyList(), MapQuestionUiState(),
        )
        assertTrue(inside.trapTargetsOwnZone)

        val outside = assembleMapUiState(
            session, zone, MapDrawingUiState(),
            MapTimeTrapUiState(pendingTrapPin = ZonePin(46.60, 6.80)), emptyList(), MapQuestionUiState(),
        )
        assertFalse(outside.trapTargetsOwnZone)
    }

    private fun travelingThermometer() = AskedQuestion(
        uuid = "q-1",
        roundUuid = "round-1",
        category = QuestionCategory.Thermometer,
        askedAt = "2026-01-01T00:00:00Z",
        revealDeadlineAt = null,
        revealedAt = null,
        radarAnswer = null,
        thermometerResult = null,
        startLat = 0.0,
        startLng = 0.0,
    )
}
