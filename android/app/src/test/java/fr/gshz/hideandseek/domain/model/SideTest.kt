package fr.gshz.hideandseek.domain.model

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class SideTest {

    @Test
    fun `spectator parses from the wire value`() {
        assertEquals(Side.Spectator, Side.fromWireValue("spectator"))
        assertEquals(Side.Spectator, Side.fromWireValueOrNull("spectator"))
    }

    @Test
    fun `only hider and seeker are players`() {
        assertTrue(Side.Hider.isPlayer())
        assertTrue(Side.Seeker.isPlayer())
        assertFalse(Side.Spectator.isPlayer())
    }

    @Test
    fun `an unknown wire value has no side`() {
        assertNull(Side.fromWireValueOrNull("referee"))
    }
}
