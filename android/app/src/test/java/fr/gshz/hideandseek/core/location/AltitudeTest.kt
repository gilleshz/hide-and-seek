package fr.gshz.hideandseek.core.location

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class AltitudeTest {

    @Test
    fun `mean-sea-level altitude is preferred when available`() {
        val result = pickAltitudeMeters(
            hasMslAltitude = true,
            mslAltitudeMeters = 120.0,
            hasAltitude = true,
            altitudeMeters = 155.0,
        )

        assertEquals(120.0, result!!, 0.0)
    }

    @Test
    fun `falls back to the ellipsoidal altitude when no MSL value exists`() {
        val result = pickAltitudeMeters(
            hasMslAltitude = false,
            mslAltitudeMeters = 0.0,
            hasAltitude = true,
            altitudeMeters = 155.0,
        )

        assertEquals(155.0, result!!, 0.0)
    }

    @Test
    fun `is null when the fix carries no altitude`() {
        val result = pickAltitudeMeters(
            hasMslAltitude = false,
            mslAltitudeMeters = 0.0,
            hasAltitude = false,
            altitudeMeters = 0.0,
        )

        assertNull(result)
    }
}
