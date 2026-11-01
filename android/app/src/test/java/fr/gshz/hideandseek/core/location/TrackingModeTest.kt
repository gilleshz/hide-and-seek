package fr.gshz.hideandseek.core.location

import fr.gshz.hideandseek.domain.model.Side
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class TrackingModeTest {

    @Test
    fun `a spectator host runs SSE without GPS`() {
        assertEquals(TrackingMode.DataSync, trackingModeFor(Side.Spectator))
    }

    @Test
    fun `players keep location tracking`() {
        assertEquals(TrackingMode.Location, trackingModeFor(Side.Hider))
        assertEquals(TrackingMode.Location, trackingModeFor(Side.Seeker))
    }

    @Test
    fun `an unresolved side stays on the safe dataSync path`() {
        assertEquals(TrackingMode.DataSync, trackingModeFor(null))
    }
}
