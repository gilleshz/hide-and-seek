package fr.gshz.hideandseek.core.location

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class PingErrorGateTest {

    private val gate = PingErrorGate()

    @Test
    fun `current-generation errors count consecutively`() {
        val generation = gate.arm()

        assertEquals(1, gate.countError(generation))
        assertEquals(2, gate.countError(generation))
        assertEquals(3, gate.countError(generation))
    }

    @Test
    fun `a stale-generation error is ignored and does not count`() {
        val staleGeneration = gate.arm()
        repeat(4) { gate.countError(staleGeneration) }
        val freshGeneration = gate.arm()

        assertNull(gate.countError(staleGeneration))
        assertEquals(1, gate.countError(freshGeneration))
    }

    @Test
    fun `re-arming resets the consecutive count for the new generation`() {
        val first = gate.arm()
        repeat(4) { gate.countError(first) }
        val second = gate.arm()

        assertEquals(1, gate.countError(second))
    }

    @Test
    fun `reset clears the count without invalidating the generation`() {
        val generation = gate.arm()
        repeat(4) { gate.countError(generation) }
        gate.reset()

        assertEquals(1, gate.countError(generation))
        assertTrue(gate.isCurrent(generation))
    }

    @Test
    fun `isCurrent flips once a newer arm happens`() {
        val staleGeneration = gate.arm()
        assertTrue(gate.isCurrent(staleGeneration))

        gate.arm()

        assertFalse(gate.isCurrent(staleGeneration))
    }

    @Test
    fun `five current-generation errors still reach the pause threshold`() {
        val generation = gate.arm()

        val counts = (1..5).map { gate.countError(generation) }

        assertEquals(listOf(1, 2, 3, 4, 5), counts)
    }
}
