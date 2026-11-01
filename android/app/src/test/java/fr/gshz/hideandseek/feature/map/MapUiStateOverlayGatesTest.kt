package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.HidingZone
import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.Side
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class MapUiStateOverlayGatesTest {

    @Test
    fun `a hider with an answerable question sees the chip`() {
        assertTrue(hiderState().showHiderQuestionChip)
    }

    @Test
    fun `tracing hides the chip so it cannot swallow a vertex tap`() {
        val state = hiderState().copy(drawing = DrawingUiState(isActive = true, kind = DrawKind.Trace))

        assertFalse(state.showHiderQuestionChip)
    }

    @Test
    fun `a seeker never sees the hider chip`() {
        assertFalse(hiderState().copy(side = Side.Seeker).showHiderQuestionChip)
    }

    @Test
    fun `a question with no reveal deadline is not answerable by the hider`() {
        assertFalse(hiderState().copy(outstandingQuestion = question(deadline = null)).showHiderQuestionChip)
    }

    @Test
    fun `a hider browsing POIs does not see the question chip`() {
        assertFalse(hiderState().copy(poiBrowse = PoiBrowseState()).showHiderQuestionChip)
    }

    @Test
    fun `tracing hides the zone placement panel it would otherwise cover`() {
        val placing = hiderState().copy(isPlacingZone = true)
        val tracing = placing.copy(drawing = DrawingUiState(isActive = true, kind = DrawKind.Trace))

        assertTrue(placing.showZonePlacementPanel)
        assertFalse(tracing.showZonePlacementPanel)
    }

    @Test
    fun `tracing hides the trap placement panel it would otherwise cover`() {
        val placing = hiderState().copy(isPlacingTimeTrap = true)
        val tracing = placing.copy(drawing = DrawingUiState(isActive = true, kind = DrawKind.Trace))

        assertTrue(placing.showTimeTrapPanel)
        assertFalse(tracing.showTimeTrapPanel)
    }

    @Test
    fun `a pin inside the hiders' own zone warns, one outside it does not`() {
        val zone = HidingZone("r1", ZONE_LAT, ZONE_LNG, ZONE_RADIUS_METERS)
        val inside = hiderState().copy(submittedZone = zone, pendingTrapPin = ZonePin(ZONE_LAT, ZONE_LNG))
        val outside = inside.copy(pendingTrapPin = ZonePin(ZONE_LAT + FAR_DEGREES, ZONE_LNG))

        assertTrue(inside.trapTargetsOwnZone)
        assertFalse(outside.trapTargetsOwnZone)
    }

    @Test
    fun `no pending pin and no zone means no warning`() {
        val zone = HidingZone("r1", ZONE_LAT, ZONE_LNG, ZONE_RADIUS_METERS)

        assertFalse(hiderState().copy(submittedZone = zone).trapTargetsOwnZone)
        assertFalse(hiderState().copy(pendingTrapPin = ZonePin(ZONE_LAT, ZONE_LNG)).trapTargetsOwnZone)
    }

    @Test
    fun `only a hider mid-hunt may place a trap`() {
        val hunting = hiderState().copy(roundStatus = RoundStatus.Seeking)

        assertTrue(hunting.canPlaceTimeTraps)
        assertFalse(hunting.copy(side = Side.Seeker).canPlaceTimeTraps)
        assertFalse(hunting.copy(roundStatus = RoundStatus.Hiding).canPlaceTimeTraps)
    }

    @Test
    fun `a seeker never sees the hider on the map`() {
        val markers = buildMarkers(ROSTER, selfUuid = null, LOCATIONS, selfGps = null, viewerSide = Side.Seeker)

        assertEquals(listOf("seeker-1"), markers.map { it.playerUuid })
    }

    @Test
    fun `a spectator sees the hider and the seekers`() {
        val markers = buildMarkers(ROSTER, selfUuid = null, LOCATIONS, selfGps = null, viewerSide = Side.Spectator)

        assertEquals(setOf("hider-1", "seeker-1"), markers.map { it.playerUuid }.toSet())
    }

    @Test
    fun `a spectator recenter targets the hider`() {
        val markers = buildMarkers(ROSTER, selfUuid = null, LOCATIONS, selfGps = null, viewerSide = Side.Spectator)

        assertEquals("hider-1", recenterTarget(markers)?.playerUuid)
    }

    @Test
    fun `a spectator has every game action gated off`() {
        val state = MapUiState(
            side = Side.Spectator,
            roundStatus = RoundStatus.Seeking,
            hasHidingZone = true,
            outstandingQuestion = question(),
        )

        assertFalse(state.canEndRound)
        assertFalse(state.canPlayZoneCards)
        assertFalse(state.canPlaceTimeTraps)
        assertFalse(state.showHiderQuestionChip)
        assertFalse(state.showSeekersFrozenBanner)
        assertTrue(state.canViewSeekerMarkers)
        assertFalse(state.canPlaceZone)
    }

    @Test
    fun `a hider can only place a zone after the round starts`() {
        val lobby = MapUiState(side = Side.Hider, roundStatus = RoundStatus.Lobby)

        assertFalse(lobby.canPlaceZone)
        assertTrue(lobby.copy(roundStatus = RoundStatus.Hiding).canPlaceZone)
        assertTrue(lobby.copy(roundStatus = RoundStatus.Seeking).canPlaceZone)
    }

    private fun hiderState() = MapUiState(side = Side.Hider, outstandingQuestion = question())

    private fun question(deadline: String? = "2026-07-28T15:00:00Z") = AskedQuestion(
        uuid = "q1",
        roundUuid = "r1",
        category = QuestionCategory.Photos,
        askedAt = "2026-07-28T14:00:00Z",
        revealDeadlineAt = deadline,
        revealedAt = null,
        radarAnswer = null,
        thermometerResult = null,
        photoTarget = PhotoTarget.StreetsTraced,
    )

    private companion object {
        const val ZONE_LAT = 46.52
        const val ZONE_LNG = 6.63
        const val ZONE_RADIUS_METERS = 500.0

        // One degree of latitude is about 111 km, so this lands well outside a 500 m zone.
        const val FAR_DEGREES = 0.02

        val ROSTER = mapOf(
            "hider-1" to RosterPlayer("Alice", side = Side.Hider),
            "seeker-1" to RosterPlayer("Bob", side = Side.Seeker),
        )
        val LOCATIONS = mapOf(
            "hider-1" to LocationUpdate("hider-1", 46.52, 6.63, "2026-07-28T14:00:00Z"),
            "seeker-1" to LocationUpdate("seeker-1", 46.53, 6.64, "2026-07-28T14:00:00Z"),
        )
    }
}
