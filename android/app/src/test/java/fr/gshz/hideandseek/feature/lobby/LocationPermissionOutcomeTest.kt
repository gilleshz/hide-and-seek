package fr.gshz.hideandseek.feature.lobby

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class LocationPermissionOutcomeTest {

    @Test
    fun `fine location wins even when coarse is also granted`() {
        assertEquals(LocationPermissionOutcome.Precise, locationPermissionOutcome(true, true))
        assertEquals(LocationPermissionOutcome.Precise, locationPermissionOutcome(true, false))
    }

    @Test
    fun `coarse without fine needs an explanation`() {
        assertEquals(LocationPermissionOutcome.CoarseOnly, locationPermissionOutcome(false, true))
    }

    @Test
    fun `no grant is denied`() {
        assertEquals(LocationPermissionOutcome.Denied, locationPermissionOutcome(false, false))
    }
}
