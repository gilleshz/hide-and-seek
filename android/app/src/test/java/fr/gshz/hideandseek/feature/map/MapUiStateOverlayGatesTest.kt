package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.HidingZone
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.Side
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
    }
}
