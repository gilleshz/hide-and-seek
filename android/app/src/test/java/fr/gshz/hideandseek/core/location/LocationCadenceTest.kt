package fr.gshz.hideandseek.core.location

import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.Side
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class LocationCadenceTest {

    @Test
    fun `an ended round slows to the idle cadence instead of the endgame one`() {
        assertEquals(
            LocationCadence.ENDED_INTERVAL_MS,
            LocationCadence.selectInterval(Side.Hider, RoundStatus.Ended),
        )
        assertEquals(
            LocationCadence.ENDED_INTERVAL_MS,
            LocationCadence.selectInterval(Side.Seeker, RoundStatus.Ended),
        )
    }

    @Test
    fun `endgame proximity ramps only the hider - seekers never receive the endgame signal`() {
        assertEquals(
            LocationCadence.Hider.ENDGAME_MS,
            LocationCadence.selectInterval(Side.Hider, RoundStatus.Seeking, endgameProximity = true),
        )
        assertEquals(
            LocationCadence.Seeker.SEEKING_PHASE_MS,
            LocationCadence.selectInterval(Side.Seeker, RoundStatus.Seeking, endgameProximity = true),
        )
    }

    @Test
    fun `an ended round overrides a lingering endgame proximity signal`() {
        assertEquals(
            LocationCadence.ENDED_INTERVAL_MS,
            LocationCadence.selectInterval(Side.Hider, RoundStatus.Ended, endgameProximity = true),
        )
    }

    @Test
    fun `active phases keep their per-side cadence`() {
        assertEquals(
            LocationCadence.Hider.HIDING_PHASE_MS,
            LocationCadence.selectInterval(Side.Hider, RoundStatus.Hiding),
        )
        assertEquals(
            LocationCadence.Hider.SEEKING_PHASE_MS,
            LocationCadence.selectInterval(Side.Hider, RoundStatus.Seeking),
        )
        assertEquals(
            LocationCadence.Seeker.HIDING_PHASE_MS,
            LocationCadence.selectInterval(Side.Seeker, RoundStatus.Hiding),
        )
        assertEquals(
            LocationCadence.Seeker.SEEKING_PHASE_MS,
            LocationCadence.selectInterval(Side.Seeker, RoundStatus.Seeking),
        )
    }

    @Test
    fun `unknown side or phase falls back to the default cadence`() {
        assertEquals(
            LocationCadence.DEFAULT_INTERVAL_MS,
            LocationCadence.selectInterval(null, null),
        )
        assertEquals(
            LocationCadence.DEFAULT_INTERVAL_MS,
            LocationCadence.selectInterval(null, RoundStatus.Seeking),
        )
        assertEquals(
            LocationCadence.DEFAULT_INTERVAL_MS,
            LocationCadence.selectInterval(Side.Hider, RoundStatus.Lobby),
        )
    }
}
