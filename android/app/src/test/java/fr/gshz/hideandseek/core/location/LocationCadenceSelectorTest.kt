package fr.gshz.hideandseek.core.location

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class LocationCadenceSelectorTest {

    private val selector = LocationCadenceSelector()

    @Test
    fun `two stationary fixes do not escalate the interval`() {
        assertEquals(10_000L, selector.onFix(1.0, 10_000L, canEscalate = true))
        assertEquals(10_000L, selector.onFix(1.0, 10_000L, canEscalate = true))
    }

    @Test
    fun `three stationary fixes escalate to the still tier`() {
        selector.onFix(1.0, 10_000L, canEscalate = true)
        selector.onFix(1.0, 10_000L, canEscalate = true)
        assertEquals(30_000L, selector.onFix(1.0, 10_000L, canEscalate = true))
    }

    @Test
    fun `escalation counts consecutive fixes only after movement`() {
        selector.onFix(10.0, 10_000L, canEscalate = true)
        assertEquals(10_000L, selector.onFix(1.0, 10_000L, canEscalate = true))
        assertEquals(10_000L, selector.onFix(1.0, 10_000L, canEscalate = true))
        assertEquals(30_000L, selector.onFix(1.0, 10_000L, canEscalate = true))
    }

    @Test
    fun `a moving fix drops back to the base interval`() {
        repeat(3) { selector.onFix(1.0, 10_000L, canEscalate = true) }
        assertEquals(10_000L, selector.onFix(10.0, 10_000L, canEscalate = true))
    }

    @Test
    fun `a displacement of exactly the move threshold counts as movement`() {
        repeat(2) { selector.onFix(1.0, 10_000L, canEscalate = true) }
        assertEquals(10_000L, selector.onFix(3.0, 10_000L, canEscalate = true))
    }

    @Test
    fun `the endgame never escalates`() {
        repeat(3) { selector.onFix(1.0, 10_000L, canEscalate = false) }
        assertEquals(10_000L, selector.onArm(10_000L, canEscalate = false))
    }

    @Test
    fun `reset clears the stationary streak`() {
        repeat(3) { selector.onFix(1.0, 10_000L, canEscalate = true) }
        selector.reset()
        assertEquals(10_000L, selector.onFix(1.0, 10_000L, canEscalate = true))
    }

    @Test
    fun `every phase cadence has a still tier`() {
        assertEquals(30_000L, LocationCadenceSelector.STATIONARY_TIER_MS[10_000L])
        assertEquals(90_000L, LocationCadenceSelector.STATIONARY_TIER_MS[30_000L])
        assertEquals(180_000L, LocationCadenceSelector.STATIONARY_TIER_MS[60_000L])
    }

    @Test
    fun `a cadence without a tier never escalates`() {
        repeat(3) { selector.onFix(1.0, 15_000L, canEscalate = true) }
        assertEquals(15_000L, selector.onFix(1.0, 15_000L, canEscalate = true))
    }

    @Test
    fun `onArm reflects the streak without feeding a fix`() {
        repeat(3) { selector.onFix(1.0, 10_000L, canEscalate = true) }
        assertEquals(30_000L, selector.onArm(10_000L, canEscalate = true))
        assertEquals(10_000L, selector.onArm(10_000L, canEscalate = false))
    }
}
